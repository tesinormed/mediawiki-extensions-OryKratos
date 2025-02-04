<?php

namespace MediaWiki\Extension\OryKratos;

use GuzzleHttp\Client;
use MediaWiki\Auth\AuthManager;
use MediaWiki\Config\Config;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\Extension\PluggableAuth\PluggableAuth;
use MediaWiki\Extension\PluggableAuth\PluggableAuthLogin;
use MediaWiki\Settings\SettingsBuilder;
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

	private Config $config;
	private AuthManager $authManager;
	private IConnectionProvider $dbProvider;
	private UserIdentityLookup $userIdentityLookup;

	private Configuration $kratosClientConfiguration;
	private FrontendApi $kratosFrontendApi;

	public function __construct(
		ConfigFactory $configFactory,
		AuthManager $authManager,
		IConnectionProvider $dbProvider,
		UserIdentityLookup $userIdentityLookup,
	) {
		$this->config = $configFactory->makeConfig( 'orykratos' );
		$this->authManager = $authManager;
		$this->dbProvider = $dbProvider;
		$this->userIdentityLookup = $userIdentityLookup;

		$this->kratosClientConfiguration = Configuration::getDefaultConfiguration()
			->setHost( $this->config->get( 'OryKratosHost' ) );
		$this->kratosFrontendApi = new FrontendApi( new Client(), $this->kratosClientConfiguration );
	}

	/**
	 * @see https://www.mediawiki.org/wiki/Manual:Extension.json/Schema#callback
	 */
	public static function onRegistration( array $extensionInfo, SettingsBuilder $settings ): void {
		if ( $settings->getConfig()->get( 'OryKratosHost' ) === null ) {
			throw new RuntimeException( '$wgOryKratosHost must be set to the Ory Kratos host URL' );
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
		try {
			$session = $this->kratosFrontendApi->toSession( cookie: $request->getHeader( 'Cookie' ) );
			if ( !$session->getActive() ) {
				throw new ApiException( code: 401 );
			}
		} catch ( ApiException $exception ) {
			if ( $exception->getCode() == 401 || $exception->getCode() == 403 ) {
				$location = $this->kratosClientConfiguration->getHost()
					. '/self-service/login/browser?return_to='
					. urlencode( $request->getSessionData( PluggableAuthLogin::RETURNTOURL_SESSION_KEY ) );
				$request->response()->header(
					"Location: $location",
					http_response_code: 303
				);
				exit;
			} else {
				$errorMessage = $exception->getMessage();
				return false;
			}
		}

		$identityId = $session->getIdentity()->getId();
		$this->authManager->setAuthenticationSessionData( self::IDENTITY_ID_SESSION_KEY, $identityId );
		$username = $session->getIdentity()->getTraits()->username ?? $session->getIdentity()->getTraits()['username'];
		$email = $session->getIdentity()->getTraits()->email ?? $session->getIdentity()->getTraits()['email'];

		$dbr = $this->dbProvider->getReplicaDatabase();
		$field = $dbr->newSelectQueryBuilder()
			->select( [ 'user_id' ] )
			->from( 'user' )
			->join(
				'ory_kratos',
				conds: 'user_id=kratos_user'
			)
			->where( [
				'kratos_id' => self::uuidToHex( $identityId ),
				'kratos_host' => $this->kratosClientConfiguration->getHost()
			] )
			->caller( __METHOD__ )->fetchField();
		if ( $field !== false ) {
			$id = $field;
		} else {
			$id = $this->userIdentityLookup->getUserIdentityByName( $username )?->getId();
			if ( $id !== null ) {
				$this->saveExtraAttributes( $id );
			}
		}
		return true;
	}

	/** @inheritDoc */
	public function deauthenticate( UserIdentity &$user ): void {
		$request = $this->authManager->getRequest();

		try {
			$token = $this->kratosFrontendApi->createBrowserLogoutFlow(
				cookie: $request->getHeader( 'Cookie' )
			)->getLogoutToken();
			$this->kratosFrontendApi->updateLogoutFlow(
				cookie: $request->getHeader( 'Cookie' ),
				token: $token
			);
		} catch ( ApiException $exception ) {
			// silently fail
			wfWarn( $exception->getMessage() );
		}
	}

	/** @inheritDoc */
	public function saveExtraAttributes( int $id ): void {
		$kratosId = $this->authManager->getAuthenticationSessionData( self::IDENTITY_ID_SESSION_KEY );
		$dbw = $this->dbProvider->getPrimaryDatabase();
		$dbw->newInsertQueryBuilder()
			->insertInto( 'ory_kratos' )
			->row( [
				'kratos_user' => $id,
				'kratos_id' => self::uuidToHex( $kratosId ),
				'kratos_host' => $this->kratosClientConfiguration->getHost()
			] )
			->caller( __METHOD__ )->execute();
	}

	private static function uuidToHex( string $uuid ): string {
		return hex2bin( str_replace( '-', '', $uuid ) );
	}
}
