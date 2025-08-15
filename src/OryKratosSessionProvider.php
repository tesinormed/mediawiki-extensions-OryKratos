<?php

namespace MediaWiki\Extension\OryKratos;

use GuzzleHttp\Client;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\Request\WebRequest;
use MediaWiki\Session\ImmutableSessionProviderWithCookie;
use MediaWiki\Session\SessionInfo;
use MediaWiki\Session\UserInfo;
use MediaWiki\User\User;
use MediaWiki\User\UserIdentityLookup;
use Ory\Kratos\Client\Api\FrontendApi;
use Ory\Kratos\Client\Api\IdentityApi;
use Ory\Kratos\Client\ApiException;
use Ory\Kratos\Client\Configuration;
use Wikimedia\Rdbms\IConnectionProvider;

class OryKratosSessionProvider extends ImmutableSessionProviderWithCookie {
	private IConnectionProvider $dbProvider;
	private UserIdentityLookup $userIdentityLookup;
	private FrontendApi $frontendApi;
	private IdentityApi $identityApi;
	private string $kratosSessionCookie;

	public function __construct(
		ConfigFactory $configFactory,
		IConnectionProvider $dbProvider,
		UserIdentityLookup $userIdentityLookup
	) {
		parent::__construct();

		$config = $configFactory->makeConfig( 'orykratos' );

		$this->dbProvider = $dbProvider;
		$this->userIdentityLookup = $userIdentityLookup;
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
			->select( 'user_id' )
			->from( 'user' )
			->join( 'ory_kratos', conds: 'user_id=kratos_user' )
			->where( [
				'kratos_id' => $identity->getId(),
				'kratos_host' => $this->frontendApi->getConfig()->getHost()
			] )
			->useIndex( 'ory_kratos_id' )
			->caller( __METHOD__ )->fetchField();

		if ( $userId === false ) {
			// find the MediaWiki user by the identity username trait
			$userIdentity = $this->userIdentityLookup->getUserIdentityByName( $identity->getTraits()->username );

			if ( $userIdentity !== null && $userIdentity->isRegistered() ) {
				// MediaWiki user exists and is registered, save the mapping
				$userId = $userIdentity->getId();
				$this->dbProvider->getPrimaryDatabase()->newInsertQueryBuilder()
					->insertInto( 'ory_kratos' )
					->row( [
						'kratos_user' => $userId,
						'kratos_id' => $identity->getId(),
						'kratos_host' => $this->frontendApi->getConfig()->getHost()
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
	public function invalidateSessionsForUser( User $user ): void {
		// see if there's a mapping for this MediaWiki user
		$identityId = $this->dbProvider->getReplicaDatabase()->newSelectQueryBuilder()
			->select( 'kratos_id' )
			->from( 'ory_kratos' )
			->where( [
				'kratos_user' => $user->getId(),
				'kratos_host' => $this->frontendApi->getConfig()->getHost()
			] )
			->useIndex( 'ory_kratos_id' )
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
