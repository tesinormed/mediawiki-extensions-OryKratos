<?php

namespace MediaWiki\Extension\OryKratos;

use GuzzleHttp\Client;
use MediaWiki\Auth\AuthManager;
use MediaWiki\Extension\PluggableAuth\PluggableAuth;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityLookup;
use Ory\Kratos\Client\Api\FrontendApi;
use Ory\Kratos\Client\ApiException;
use Ory\Kratos\Client\Configuration;
use RuntimeException;
use Wikimedia\Rdbms\IConnectionProvider;

class OryKratos extends PluggableAuth {
	private const IDENTITY_ID_SESSION_KEY = 'OryKratosIdentityId';

	private AuthManager $authManager;
	private IConnectionProvider $dbProvider;
	private UserIdentityLookup $userIdentityLookup;

	private Configuration $kratosClientConfiguration;
	private FrontendApi $kratosFrontendApi;

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

		if ( !$this->getData()->has( 'host' ) ) {
			throw new RuntimeException( '"host" required in "data" block' );
		}

		$this->kratosClientConfiguration = Configuration::getDefaultConfiguration()
			->setHost( $this->getData()->get( 'host' ) );
		$this->kratosFrontendApi = new FrontendApi( new Client(), $this->kratosClientConfiguration );
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

		try {
			$session = $this->kratosFrontendApi->toSession( cookie: $request->getHeader( 'Cookie' ) );
			if ( !$session->getActive() ) {
				throw new ApiException( code: 401 );
			}
		} catch ( ApiException $exception ) {
			if ( $exception->getCode() == 401 || $exception->getCode() == 403 ) {
				$location = $this->kratosClientConfiguration->getHost()
					. '/self-service/login/browser?return_to='
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
				'kratos_host' => $this->kratosClientConfiguration->getHost()
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
	public function deauthenticate( UserIdentity &$user ): void {
		$request = $this->authManager->getRequest();
		$cookieHeader = $request->getHeader( 'Cookie' );
		$returnTo = $request->getVal( 'returnto' );

		$title = null;
		if ( $returnTo !== null ) {
			$title = Title::newFromText( $returnTo );
		}
		if ( $title === null ) {
			$title = Title::newMainPage();
		}

		try {
			$location = $this->kratosFrontendApi
				->createBrowserLogoutFlow( cookie: $cookieHeader, returnTo: $title->getFullURL() )
				->getLogoutUrl();
			header( "Location: $location" );
			exit;
		} catch ( ApiException $exception ) {
			// silently fail
			wfLogWarning( $exception->getMessage() );
		}
	}

	/** @inheritDoc */
	public function shouldOverrideDefaultLogout(): bool {
		return true;
	}

	/** @inheritDoc */
	public function saveExtraAttributes( int $id ): void {
		$kratosId = $this->authManager->getAuthenticationSessionData( self::IDENTITY_ID_SESSION_KEY );
		$dbw = $this->dbProvider->getPrimaryDatabase();
		$dbw->newInsertQueryBuilder()
			->insertInto( 'ory_kratos' )
			->row( [
				'kratos_user' => $id,
				'kratos_id' => $kratosId,
				'kratos_host' => $this->kratosClientConfiguration->getHost()
			] )
			->caller( __METHOD__ )->execute();
	}
}
