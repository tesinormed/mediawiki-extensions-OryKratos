<?php

namespace MediaWiki\Extension\OryKratos;

use MediaWiki\Rest\Handler;
use MediaWiki\User\UserIdentityLookup;
use MediaWiki\User\UserNameUtils;
use MediaWiki\User\UserRigorOptions;
use Wikimedia\Equivset\Equivset;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\Rdbms\IConnectionProvider;

class RestApiOryKratosUsernameUsable extends Handler {
	public function __construct(
		private readonly UserNameUtils $userNameUtils,
		private readonly UserIdentityLookup $userIdentityLookup,
		private readonly IConnectionProvider $dbProvider,
		private readonly Equivset $equivset
	) {
	}

	/** @inheritDoc */
	public function execute(): array {
		$username = $this->getValidatedBody()['username'];

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

		$equivalentUsername = $this->dbProvider->getReplicaDatabase()->newSelectQueryBuilder()
			->select( 'user_name' )
			->from( 'user' )
			->join( 'orykratos_equiv', conds: 'user_id=equiv_user' )
			->where( [ 'equiv_normalized' => $this->equivset->normalize( $canonicalUsername ) ] )
			->limit( 1 )
			->caller( __METHOD__ )->fetchField();
		if ( $equivalentUsername !== false ) {
			return [
				'usable' => false,
				'reason' => 'Username is too similar to a taken username: ' . $equivalentUsername
			];
		}

		return [ 'usable' => true ];
	}

	/** @inheritDoc */
	public function getBodyParamSettings(): array {
		return [
			'username' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true
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
