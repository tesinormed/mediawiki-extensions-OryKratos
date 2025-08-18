<?php

namespace MediaWiki\Extension\OryKratos;

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

class OryKratosSessionProvider extends SessionProvider {
	private readonly string $kratosSessionCookie;

	public function __construct(
		ConfigFactory $configFactory,
		private readonly FrontendApi $frontendApi,
		private readonly IdentityApi $identityApi,
		private readonly UserIdentityLookup $userIdentityLookup
	) {
		parent::__construct();

		$config = $configFactory->makeConfig( 'orykratos' );

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
		$userId = OryKratosTable::findUserIdFromIdentity( $identity );
		if ( $userId !== false ) {
			$userInfo = UserInfo::newFromId( $userId, verified: true );
		} else {
			$username = $identity->getTraits()->username;
			// find the MediaWiki user by the identity username trait
			$userIdentity = $this->userIdentityLookup->getUserIdentityByName( $username );

			if ( $userIdentity !== null && $userIdentity->isRegistered() ) {
				// MediaWiki user exists and is registered, save the mapping
				OryKratosTable::saveUserToIdentityMapping( $userIdentity, $identity );

				$userInfo = UserInfo::newFromId( $userIdentity->getId(), verified: true );
			} else {
				// no user exists, auto-create user later
				$userInfo = UserInfo::newFromName( $username, verified: true );
			}
		}

		// found the MediaWiki user mapped to the identity
		return new SessionInfo( $this->priority, [
			'provider' => $this,
			'id' => $session->getId(),
			'userInfo' => $userInfo,
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
		$identityId = OryKratosTable::findIdentityIdFromUser( $user );
		if ( $identityId === false ) {
			// no mapping
			return;
		}

		try {
			// logout all sessions for the mapped identity
			$this->identityApi->deleteIdentitySessions( $identityId );
		} catch ( ApiException $exception ) {
			wfLogWarning( 'failed to delete identity sessions: ' . $exception->getMessage() );
		}
	}

	/** @inheritDoc */
	public function getVaryCookies(): array {
		return [ $this->kratosSessionCookie ];
	}
}
