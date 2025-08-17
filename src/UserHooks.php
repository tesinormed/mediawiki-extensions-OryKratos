<?php

namespace MediaWiki\Extension\OryKratos;

use MediaWiki\Auth\Hook\LocalUserCreatedHook;
use MediaWiki\RenameUser\Hook\RenameUserCompleteHook;
use Wikimedia\Equivset\Equivset;
use Wikimedia\Rdbms\IConnectionProvider;

class UserHooks implements LocalUserCreatedHook, RenameUserCompleteHook {
	public function __construct(
		private readonly IConnectionProvider $dbProvider,
		private readonly Equivset $equivset
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
				'equiv_normalized' => $this->equivset->normalize( $user->getName() )
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
			->set( [ 'equiv_normalized' => $this->equivset->normalize( $new ) ] )
			->where( [ 'equiv_user' => $uid ] )
			->caller( __METHOD__ )->execute();
	}
}
