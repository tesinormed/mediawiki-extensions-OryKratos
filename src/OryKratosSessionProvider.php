<?php

namespace MediaWiki\Extension\OryKratos;

use GuzzleHttp\Client;
use InvalidArgumentException;
use MediaWiki\Request\WebRequest;
use MediaWiki\Session\ImmutableSessionProviderWithCookie;
use MediaWiki\Session\SessionInfo;
use MediaWiki\Session\UserInfo;
use MediaWiki\User\User;
use Ory\Kratos\Client\Api\FrontendApi;
use Ory\Kratos\Client\Api\IdentityApi;
use Ory\Kratos\Client\ApiException;
use Ory\Kratos\Client\Configuration;
use Wikimedia\Rdbms\IConnectionProvider;

class OryKratosSessionProvider extends ImmutableSessionProviderWithCookie {
	private IConnectionProvider $dbProvider;
	private FrontendApi $frontendApi;
	private IdentityApi $identityApi;
	private string $kratosCookieName;

	public function __construct( IConnectionProvider $dbProvider, array $params = [] ) {
		parent::__construct();

		if ( !isset( $params['publicHost'] ) ) {
			throw new InvalidArgumentException( __METHOD__ . ': publicHost must be specified' );
		}
		if ( !isset( $params['adminHost'] ) ) {
			throw new InvalidArgumentException( __METHOD__ . ': adminHost must be specified' );
		}
		if ( !isset( $params['priority'] ) ) {
			throw new InvalidArgumentException( __METHOD__ . ': priority must be specified' );
		}
		if ( $params['priority'] < SessionInfo::MIN_PRIORITY || $params['priority'] > SessionInfo::MAX_PRIORITY ) {
			throw new InvalidArgumentException( __METHOD__ . ': invalid priority' );
		}

		$this->dbProvider = $dbProvider;
		$this->frontendApi = new FrontendApi(
			new Client(),
			( new Configuration() )->setHost( $params['publicHost'] )
		);
		$this->identityApi = new IdentityApi(
			new Client(),
			( new Configuration() )->setHost( $params['adminHost'] )
		);
		$this->priority = $params['priority'];
		$this->kratosCookieName = $params['cookieName'] ?? 'ory_kratos_session';
	}

	public function provideSessionInfo( WebRequest $request ): ?SessionInfo {
		try {
			// try to convert the Cookie header to a session
			$session = $this->frontendApi->toSession( cookie: $request->getHeader( 'Cookie' ) );
			if ( !$session->getActive() ) {
				return null;
			}
		} catch ( ApiException ) {
			return null;
		}

		// see if there's a mapping for this identity
		$dbr = $this->dbProvider->getReplicaDatabase();
		$userId = $dbr->newSelectQueryBuilder()
			->select( 'user_id' )
			->from( 'user' )
			->join( 'ory_kratos', conds: 'user_id=kratos_user' )
			->where( [
				'kratos_id' => $session->getIdentity()->getId(),
				'kratos_host' => $this->frontendApi->getConfig()->getHost()
			] )
			->useIndex( 'ory_kratos_id' )
			->caller( __METHOD__ )->fetchField();

		if ( $userId === false ) {
			return null;
		}

		// found the MediaWiki user mapped to the identity
		return new SessionInfo( $this->priority, [
			'provider' => $this,
			'id' => $session->getId(),
			'userInfo' => UserInfo::newFromId( $userId, verified: true ),
			'persisted' => true
		] );
	}

	public function invalidateSessionsForUser( User $user ): void {
		OryKratos::logoutAllSessions( $user, $this->dbProvider, $this->frontendApi, $this->identityApi );
	}

	public function getVaryCookies(): array {
		return [ $this->kratosCookieName ];
	}

	public function safeAgainstCsrf(): bool {
		return true;
	}
}
