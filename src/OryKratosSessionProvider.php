<?php

namespace MediaWiki\Extension\OryKratos;

use GuzzleHttp\Client;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\Request\WebRequest;
use MediaWiki\Session\SessionBackend;
use MediaWiki\Session\SessionInfo;
use MediaWiki\Session\SessionProvider;
use MediaWiki\Session\UserInfo;
use MediaWiki\User\User;
use MediaWiki\User\UserIdentityLookup;
use Ory\Kratos\Client\Api\FrontendApi;
use Ory\Kratos\Client\Api\IdentityApi;
use Ory\Kratos\Client\ApiException;
use Ory\Kratos\Client\Configuration;
use Wikimedia\Rdbms\IConnectionProvider;

class OryKratosSessionProvider extends SessionProvider {
	private readonly FrontendApi $frontendApi;
	private readonly IdentityApi $identityApi;
	private readonly string $kratosSessionCookie;

	public function __construct(
		ConfigFactory $configFactory,
		private readonly IConnectionProvider $dbProvider,
		private readonly UserIdentityLookup $userIdentityLookup
	) {
		parent::__construct();

		$config = $configFactory->makeConfig( 'orykratos' );

		$this->frontendApi = new FrontendApi(
			new Client(),
			( new Configuration() )->setHost( $config->get( 'OryKratosPublicHost' ) )
		);
		$this->identityApi = new IdentityApi(
			new Client(),
			( new Configuration() )->setHost( $config->get( 'OryKratosAdminHost' ) )
		);
		$this->kratosSessionCookie = $config->get( 'OryKratosSessionCookie' ) ?? 'ory_kratos_session';
		$this->priority = 30;
	}

	/** @inheritDoc */
	public function provideSessionInfo( WebRequest $request ): ?SessionInfo {
		try {
			// try to convert the Cookie header to a session
			$cookieHeader = $request->getHeader( 'Cookie' );
			if ( $cookieHeader === false ) {
				return null;
			}

			$session = $this->frontendApi->toSession( cookie: $cookieHeader );
			if ( !$session->getActive() ) {
				return null;
			}
		} catch ( ApiException ) {
			return null;
		}

		$identity = $session->getIdentity();
		// see if there's a mapping for this identity
		$userId = $this->dbProvider->getReplicaDatabase()->newSelectQueryBuilder()
			->select( 'kratos_user' )
			->from( 'orykratos' )
			->where( [ 'kratos_identity' => $identity->getId() ] )
			->useIndex( 'orykratos_user_identity' )
			->caller( __METHOD__ )->fetchField();

		if ( $userId === false ) {
			// find the MediaWiki user by the identity username trait
			$userIdentity = $this->userIdentityLookup->getUserIdentityByName( $identity->getTraits()->username );

			if ( $userIdentity !== null && $userIdentity->isRegistered() ) {
				// MediaWiki user exists and is registered, save the mapping
				$userId = $userIdentity->getId();
				$this->dbProvider->getPrimaryDatabase()->newInsertQueryBuilder()
					->insertInto( 'orykratos' )
					->row( [
						'kratos_user' => $userId,
						'kratos_identity' => $identity->getId()
					] )
					->caller( __METHOD__ )->execute();
			} else {
				return null;
			}
		}

		// found the MediaWiki user mapped to the identity
		return new SessionInfo( $this->priority, [
			'provider' => $this,
			'id' => $session->getId(),
			'userInfo' => UserInfo::newFromId( $userId, verified: true ),
			'persisted' => true
		] );
	}

	/** @inheritDoc */
	public function newSessionInfo( $id = null ): ?SessionInfo {
		return new SessionInfo( $this->priority, [
			'provider' => $this,
			'id' => $id,
			'idIsSafe' => true,
			'userInfo' => UserInfo::newAnonymous(),
			'persisted' => false
		] );
	}

	/** @inheritDoc */
	public function persistsSessionId(): bool {
		return false;
	}

	/** @inheritDoc */
	public function canChangeUser(): bool {
		return false;
	}

	/** @inheritDoc */
	public function persistSession( SessionBackend $session, WebRequest $request ): void {
	}

	/** @inheritDoc */
	public function unpersistSession( WebRequest $request ): void {
	}

	/** @inheritDoc */
	public function invalidateSessionsForUser( User $user ): void {
		// see if there's a mapping for this MediaWiki user
		$identityId = $this->dbProvider->getReplicaDatabase()->newSelectQueryBuilder()
			->select( 'kratos_identity' )
			->from( 'orykratos' )
			->where( [ 'kratos_user' => $user->getId() ] )
			->useIndex( 'orykratos_user_identity' )
			->caller( __METHOD__ )->fetchField();
		if ( $identityId === false ) {
			// no mapping
			return;
		}

		try {
			// logout all sessions for the mapped identity
			$this->identityApi->deleteIdentitySessions( $identityId );
		} catch ( ApiException $exception ) {
			wfLogWarning( $exception->getMessage() );
		}
	}

	/** @inheritDoc */
	public function getVaryCookies(): array {
		return [ $this->kratosSessionCookie ];
	}

	/** @inheritDoc */
	public function safeAgainstCsrf(): bool {
		return true;
	}
}
