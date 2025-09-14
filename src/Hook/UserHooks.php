<?php

namespace MediaWiki\Extension\OryKratos\Hook;

use MediaWiki\Auth\Hook\LocalUserCreatedHook;
use MediaWiki\Extension\OryKratos\OryKratos;
use MediaWiki\RenameUser\Hook\RenameUserCompleteHook;
use Ory\Kratos\Client\Api\IdentityApi;
use Ory\Kratos\Client\ApiException;
use Ory\Kratos\Client\Model\JsonPatch;
use Wikimedia\Rdbms\IConnectionProvider;

class UserHooks implements LocalUserCreatedHook, RenameUserCompleteHook {
	public function __construct(
		private readonly IConnectionProvider $dbProvider,
		private readonly IdentityApi $identityApi
	) {
	}

	/**
	 * @inheritDoc
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/LocalUserCreated
	 */
	public function onLocalUserCreated( $user, $autocreated ): void {
		if ( $user->isTemp() ) {
			return;
		}

		$this->dbProvider->getPrimaryDatabase()->newInsertQueryBuilder()
			->insertInto( 'orykratos_equiv' )
			->row( [
				'equiv_user' => $user->getId(),
				'equiv_normalized' => OryKratos::getEquivset()->normalize( $user->getName() )
			] )
			->caller( __METHOD__ )->execute();
	}

	/**
	 * @inheritDoc
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/RenameUserComplete
	 */
	public function onRenameUserComplete( int $uid, string $old, string $new ): void {
		$this->dbProvider->getPrimaryDatabase()->newUpdateQueryBuilder()
			->update( 'orykratos_equiv' )
			->set( [ 'equiv_normalized' => OryKratos::getEquivset()->normalize( $new ) ] )
			->where( [ 'equiv_user' => $uid ] )
			->caller( __METHOD__ )->execute();

		$identityId = OryKratos::findIdentityIdFromUser( $uid );
		if ( $identityId === false ) {
			return;
		}

		try {
			$this->identityApi->patchIdentity( $identityId, [ new JsonPatch( [
				'op' => 'replace',
				'path' => '/traits/username',
				'value' => $new
			] ) ] );
		} catch ( ApiException $exception ) {
			wfLogWarning( 'failed to patch identity: ' . $exception->getMessage() );
		}
	}
}
