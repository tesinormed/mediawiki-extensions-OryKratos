<?php

namespace MediaWiki\Extension\OryKratos;

use MediaWiki\MediaWikiServices;
use MediaWiki\User\UserIdentity;
use Ory\Kratos\Client\Model\Identity;
use Wikimedia\Equivset\Equivset;

class OryKratos {
	private static ?Equivset $equivset = null;

	public static function getEquivset(): Equivset {
		if ( self::$equivset === null ) {
			self::$equivset = new Equivset();
		}
		return self::$equivset;
	}

	public static function findUserIdFromIdentity( Identity $identity ): int|false {
		return MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase()->newSelectQueryBuilder()
			->select( 'kratos_user' )
			->from( 'orykratos' )
			->where( [ 'kratos_identity' => $identity->getId() ] )
			->useIndex( 'orykratos_user_identity' )
			->caller( __METHOD__ )->fetchField();
	}

	public static function findIdentityIdFromUser( UserIdentity|int $user ): string|false {
		if ( $user instanceof UserIdentity ) {
			$user = $user->getId();
		}

		return MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase()->newSelectQueryBuilder()
			->select( 'kratos_identity' )
			->from( 'orykratos' )
			->where( [ 'kratos_user' => $user ] )
			->useIndex( 'orykratos_user_identity' )
			->caller( __METHOD__ )->fetchField();
	}

	public static function saveUserToIdentityMapping( UserIdentity $user, Identity $identity ): void {
		MediaWikiServices::getInstance()->getConnectionProvider()->getPrimaryDatabase()->newInsertQueryBuilder()
			->insertInto( 'orykratos' )
			->row( [
				'kratos_user' => $user->getId(),
				'kratos_identity' => $identity->getId()
			] )
			->caller( __METHOD__ )->execute();
	}
}
