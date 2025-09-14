<?php

namespace MediaWiki\Extension\OryKratos\SpecialPage;

use Closure;
use MediaWiki\SpecialPage\UnlistedSpecialPage;

class ExternalRedirectSpecialPage extends UnlistedSpecialPage {
	/**
	 * @param string $name Canonical name of the replaced special page
	 * @param string $url URL to redirect to
	 * @return Closure
	 */
	public static function getCallback( string $name, string $url ): Closure {
		return static fn () => new ExternalRedirectSpecialPage( $name, $url );
	}

	/**
	 * @param string $name Name of the special page
	 * @param string $url URL to redirect to
	 */
	public function __construct( string $name, private readonly string $url ) {
		parent::__construct( $name );
	}

	/** @inheritDoc */
	public function execute( $subPage ): void {
		$this->getOutput()->redirect( $this->url );
	}
}
