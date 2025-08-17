<?php

namespace MediaWiki\Extension\OryKratos;

use Wikimedia\Equivset\Equivset;

class OryKratos {
	private static Equivset $equivset;

	public static function getEquivset(): Equivset {
		if ( !self::$equivset ) {
			self::$equivset = new Equivset();
		}

		return self::$equivset;
	}
}
