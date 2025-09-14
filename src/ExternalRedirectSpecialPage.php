<?php

namespace MediaWiki\Extension\OryKratos;

use Closure;
use MediaWiki\SpecialPage\UnlistedSpecialPage;
use MediaWiki\Title\Title;

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
		$returnTo = Title::newFromText( $this->getRequest()->getText( 'returnto' ) )
			?: Title::newMainPage();

		$url = $this->url;
		if ( $returnTo !== null ) {
			$url .= '?return_to=' . $returnTo->getFullURL();
		}

		$this->getOutput()->redirect( $url );
	}
}
