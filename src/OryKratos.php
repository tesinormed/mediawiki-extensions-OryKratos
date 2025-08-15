<?php

namespace MediaWiki\Extension\OryKratos;

use GuzzleHttp\Client;
use MediaWiki\Auth\AuthManager;
use MediaWiki\Extension\PluggableAuth\BackchannelLogoutAwarePlugin;
use MediaWiki\Extension\PluggableAuth\PluggableAuth;
use MediaWiki\Rest\RequestInterface;
use MediaWiki\Rest\ResponseInterface;
use MediaWiki\Session\SessionManagerInterface;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityLookup;
use Ory\Kratos\Client\Api\FrontendApi;
use Ory\Kratos\Client\Api\IdentityApi;
use Ory\Kratos\Client\ApiException;
use Ory\Kratos\Client\Configuration;
use RuntimeException;
use Wikimedia\Rdbms\IConnectionProvider;

class OryKratos extends PluggableAuth implements BackchannelLogoutAwarePlugin {
	private const IDENTITY_ID_SESSION_KEY = 'OryKratosIdentityId';

	private AuthManager $authManager;
	private IConnectionProvider $dbProvider;
	private UserIdentityLookup $userIdentityLookup;
	private UserFactory $userFactory;
	private FrontendApi $frontendApi;
	private IdentityApi $identityApi;
	private ?string $backchannelLogoutToken = null;

	public function __construct(
		AuthManager $authManager,
		IConnectionProvider $dbProvider,
		UserIdentityLookup $userIdentityLookup,
		UserFactory $userFactory,
	) {
		$this->authManager = $authManager;
		$this->dbProvider = $dbProvider;
		$this->userIdentityLookup = $userIdentityLookup;
		$this->userFactory = $userFactory;
	}

	/** @inheritDoc */
	public function init( string $configId, array $config ): void {
		parent::init( $configId, $config );

		if ( !$this->getData()->has( 'publicHost' ) ) {
			throw new RuntimeException( '"publicHost" required in "data" block' );
		} elseif ( !$this->getData()->has( 'adminHost' ) ) {
			throw new RuntimeException( '"adminHost" required in "data" block' );
		}

		$this->frontendApi = new FrontendApi(
			new Client(),
			( new Configuration() )->setHost( $this->getData()->get( 'publicHost' ) )
		);
		$this->identityApi = new IdentityApi(
			new Client(),
			( new Configuration() )->setHost( $this->getData()->get( 'adminHost' ) )
		);
		if ( $this->getData()->has( 'backchannelLogoutToken' ) ) {
			$this->backchannelLogoutToken = $this->getData()->get( 'backchannelLogoutToken' );
		}
	}

	/** @inheritDoc */
	public function authenticate(
		?int &$id,
		?string &$username,
		?string &$realname,
		?string &$email,
		?string &$errorMessage
	): bool {
		$request = $this->authManager->getRequest();
		$publicHost = $this->frontendApi->getConfig()->getHost();

		try {
			// try to convert the Cookie header to a session
			$session = $this->frontendApi->toSession( cookie: $request->getHeader( 'Cookie' ) );
			if ( !$session->getActive() ) {
				throw new ApiException( code: 401 );
			}
		} catch ( ApiException $exception ) {
			if ( $exception->getCode() == 401 || $exception->getCode() == 403 ) {
				// no session or session is inactive; make the user reauthenticate
				$location = $publicHost . '/self-service/login/browser?return_to='
					. urlencode( SpecialPage::getTitleFor( 'PluggableAuthLogin' )->getFullURL() );
				$request->response()->header( "Location: $location" );
				exit;
			} else {
				$errorMessage = $exception->getMessage();
				return false;
			}
		}

		$identity = $session->getIdentity();
		// save the identity ID for later
		$this->authManager->setAuthenticationSessionData( self::IDENTITY_ID_SESSION_KEY, $identity->getId() );

		// grab the identity traits
		$identityTraits = $identity->getTraits();
		$username = $identityTraits->username;
		$email = $identityTraits->email;
		if ( property_exists( $identityTraits, 'name' ) ) {
			$realname = $identityTraits->name;
		}

		// see if there's a mapping for this identity
		$field = $this->findUserByIdentityId( $identity->getId(), $publicHost );

		if ( $field !== false ) {
			// identity already mapped to MediaWiki user
			$id = $field;
		} else {
			// find the MediaWiki user by the identity username trait
			$userIdentity = $this->userIdentityLookup->getUserIdentityByName( $username );

			if ( $userIdentity !== null && $userIdentity->isRegistered() ) {
				// MediaWiki user exists and is registered, save the mapping
				$id = $userIdentity->getId();
				$this->saveExtraAttributes( $id );
			} else {
				// create a new MediaWiki user
				$id = null;
			}
		}

		return true;
	}

	private function findUserByIdentityId( string $identityId, string $publicHost ): mixed {
		return $this->dbProvider->getReplicaDatabase()->newSelectQueryBuilder()
			->select( 'user_id' )
			->from( 'user' )
			->join( 'ory_kratos', conds: 'user_id=kratos_user' )
			->where( [
				'kratos_id' => $identityId,
				'kratos_host' => $publicHost
			] )
			->useIndex( 'ory_kratos_id' )
			->caller( __METHOD__ )->fetchField();
	}

	/** @inheritDoc */
	public function shouldOverrideDefaultLogout(): bool {
		return true;
	}

	/** @inheritDoc */
	public function deauthenticate( UserIdentity &$user ): void {
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
	public function saveExtraAttributes( int $id ): void {
		$this->dbProvider->getPrimaryDatabase()->newInsertQueryBuilder()
			->insertInto( 'ory_kratos' )
			->row( [
				'kratos_user' => $id,
				// retrieve the identity ID saved earlier
				'kratos_id' => $this->authManager->getAuthenticationSessionData( self::IDENTITY_ID_SESSION_KEY ),
				'kratos_host' => $this->frontendApi->getConfig()->getHost()
			] )
			->caller( __METHOD__ )->execute();
	}

	/** @inheritDoc */
	public function canHandle( RequestInterface $request ): bool {
		if ( $request->getMethod() !== 'POST'
			|| !$request->hasBody()
			|| $request->getBodyType() !== 'application/json'
		) {
			return false;
		}

		$authorizationHeaders = $request->getHeader( 'Authorization' );
		if ( empty( $authorizationHeaders ) ) {
			// no Authorization header
			return false;
		}

		foreach ( $authorizationHeaders as $authorizationHeader ) {
			if ( !str_starts_with( $authorizationHeader, 'Bearer ' ) ) {
				// not using Bearer
				return false;
			}

			$token = str_replace( 'Bearer ', '', $authorizationHeader );
			if ( $token === $this->backchannelLogoutToken ) {
				// valid Bearer token
				return true;
			}
		}

		// invalid Bearer token(s)
		return false;
	}

	/** @inheritDoc */
	public function performBackchannelLogout(
		RequestInterface $request,
		ResponseInterface $response,
		SessionManagerInterface $sessionManager
	): void {
		$parsedBody = json_decode( (string)$request->getBody(), associative: true );
		if ( !is_array( $parsedBody ) ) {
			$response->setStatus( 400 );
			return;
		}

		$userId = $this->findUserByIdentityId( $parsedBody['identity_id'], $this->frontendApi->getConfig()->getHost() );
		if ( $userId === false ) {
			$response->setStatus( 400 );
			return;
		}

		$user = $this->userFactory->newFromUserIdentity( $this->userIdentityLookup->getUserIdentityByName( $userId ) );
		$this->getLogger()->debug( 'Logging out {username} ({userid})', [
			'username' => $user->getName(),
			'userid' => $user->getId()
		] );
		$sessionManager->invalidateSessionsForUser( $user );
		$response->setStatus( 200 );
	}
}
