<?php

namespace MediaWiki\Extension\OryKratos;

use GuzzleHttp\Client;
use MediaWiki\Auth\AuthManager;
use MediaWiki\Extension\PluggableAuth\PluggableAuth;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityLookup;
use Ory\Kratos\Client\Api\FrontendApi;
use Ory\Kratos\Client\Api\IdentityApi;
use Ory\Kratos\Client\ApiException;
use Ory\Kratos\Client\Configuration;
use RuntimeException;
use Wikimedia\Rdbms\IConnectionProvider;

class OryKratos extends PluggableAuth {
	private const IDENTITY_ID_SESSION_KEY = 'OryKratosIdentityId';

	private AuthManager $authManager;
	private IConnectionProvider $dbProvider;
	private UserIdentityLookup $userIdentityLookup;

	private FrontendApi $frontendApi;
	private IdentityApi $identityApi;

	public function __construct(
		AuthManager $authManager,
		IConnectionProvider $dbProvider,
		UserIdentityLookup $userIdentityLookup,
	) {
		$this->authManager = $authManager;
		$this->dbProvider = $dbProvider;
		$this->userIdentityLookup = $userIdentityLookup;
	}

	/** @inheritDoc */
	public function init( string $configId, array $config ): void {
		parent::init( $configId, $config );

		if ( !$this->getData()->has( 'publicHost' ) ) {
			throw new RuntimeException( '"publicHost" required in "data" block' );
		}

		$this->frontendApi = new FrontendApi(
			new Client(),
			( new Configuration() )->setHost( $this->getData()->get( 'publicHost' ) )
		);

		if ( !$this->getData()->has( 'adminHost' ) ) {
			throw new RuntimeException( '"adminHost" required in "data" block' );
		}

		$this->identityApi = new IdentityApi(
			new Client(),
			( new Configuration() )->setHost( $this->getData()->get( 'adminHost' ) )
		);
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
			$session = $this->frontendApi->toSession( cookie: $request->getHeader( 'Cookie' ) );
			if ( !$session->getActive() ) {
				throw new ApiException( code: 401 );
			}
		} catch ( ApiException $exception ) {
			if ( $exception->getCode() == 401 || $exception->getCode() == 403 ) {
				$location = $publicHost . '/self-service/login/browser?return_to='
					. urlencode( SpecialPage::getTitleFor( 'PluggableAuthLogin' )->getFullURL() );
				$request->response()->header( "Location: $location" );
				exit;
			} else {
				$errorMessage = $exception->getMessage();
				return false;
			}
		}

		$identityId = $session->getIdentity()->getId();
		$this->authManager->setAuthenticationSessionData( self::IDENTITY_ID_SESSION_KEY, $identityId );

		$username = $session->getIdentity()->getTraits()->username;
		$email = $session->getIdentity()->getTraits()->email;
		if ( isset( $session->getIdentity()->getTraits()->name ) ) {
			$realname = $session->getIdentity()->getTraits()->name;
		}

		$dbr = $this->dbProvider->getReplicaDatabase();
		$field = $dbr->newSelectQueryBuilder()
			->select( [ 'user_id' ] )
			->from( 'user' )
			->join(
				'ory_kratos',
				conds: 'user_id=kratos_user'
			)
			->where( [
				'kratos_id' => $identityId,
				'kratos_host' => $publicHost
			] )
			->useIndex( 'ory_kratos_id' )
			->caller( __METHOD__ )->fetchField();

		if ( $field !== false ) {
			$id = $field;
		} else {
			$userIdentity = $this->userIdentityLookup->getUserIdentityByName( $username );

			if ( $userIdentity !== null && $userIdentity->isRegistered() ) {
				$id = $userIdentity->getId();
				$this->saveExtraAttributes( $id );
			} else {
				$id = null;
			}
		}

		return true;
	}

	/** @inheritDoc */
	public function shouldOverrideDefaultLogout(): bool {
		return true;
	}

	/** @inheritDoc */
	public function deauthenticate( UserIdentity &$user ): void {
		$dbr = $this->dbProvider->getReplicaDatabase();
		$identityId = $dbr->newSelectQueryBuilder()
			->select( 'kratos_id' )
			->from( 'ory_kratos' )
			->where( [
				'kratos_user' => $user->getId(),
				'kratos_host' => $this->frontendApi->getConfig()->getHost()
			] )
			->useIndex( 'ory_kratos_id' )
			->caller( __METHOD__ )->fetchField();

		if ( $identityId === false ) {
			return;
		}

		try {
			$this->identityApi->deleteIdentitySessions( $identityId );
		} catch ( ApiException $exception ) {
			wfLogWarning( $exception->getMessage() );
		}
	}

	/** @inheritDoc */
	public function saveExtraAttributes( int $id ): void {
		$identityId = $this->authManager->getAuthenticationSessionData( self::IDENTITY_ID_SESSION_KEY );

		$dbw = $this->dbProvider->getPrimaryDatabase();
		$dbw->newInsertQueryBuilder()
			->insertInto( 'ory_kratos' )
			->row( [
				'kratos_user' => $id,
				'kratos_id' => $identityId,
				'kratos_host' => $this->frontendApi->getConfig()->getHost()
			] )
			->caller( __METHOD__ )->execute();
	}
}
