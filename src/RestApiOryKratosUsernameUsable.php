<?php

namespace MediaWiki\Extension\OryKratos;

use MediaWiki\Rest\SimpleHandler;
use MediaWiki\User\UserIdentityLookup;
use MediaWiki\User\UserNameUtils;
use MediaWiki\User\UserRigorOptions;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\Rdbms\IConnectionProvider;

class RestApiOryKratosUsernameUsable extends SimpleHandler {
	public function __construct(
		private readonly UserNameUtils $userNameUtils,
		private readonly UserIdentityLookup $userIdentityLookup,
		private readonly IConnectionProvider $dbProvider
	) {
	}

	public function run( string $username ): array {
		$canonicalUsername = $this->userNameUtils->getCanonical( $username, UserRigorOptions::RIGOR_CREATABLE );
		if ( $canonicalUsername === false ) {
			return [
				'usable' => false,
				'reason' => 'Username is not valid'
			];
		}

		if ( $this->userIdentityLookup->getUserIdentityByName( $canonicalUsername ) !== null ) {
			return [
				'usable' => false,
				'reason' => 'Username is taken'
			];
		}

		$equivalentUserId = $this->dbProvider->getReplicaDatabase()->newSelectQueryBuilder()
			->select( 'equiv_user' )
			->from( 'orykratos_equiv' )
			->where( [ 'equiv_normalized' => OryKratos::getEquivset()->normalize( $canonicalUsername ) ] )
			->limit( 1 )
			->caller( __METHOD__ )->fetchField();
		if ( $equivalentUserId !== false ) {
			return [
				'usable' => false,
				'reason' => 'Username is too similar to another username: '
					. $this->userIdentityLookup->getUserIdentityByUserId( $equivalentUserId )->getName()
			];
		}

		return [ 'usable' => true ];
	}

	/** @inheritDoc */
	public function getParamSettings(): array {
		return [
			'username' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
		];
	}

	/** @inheritDoc */
	public function needsReadAccess(): bool {
		return true;
	}

	/** @inheritDoc */
	public function needsWriteAccess(): bool {
		return false;
	}
}
