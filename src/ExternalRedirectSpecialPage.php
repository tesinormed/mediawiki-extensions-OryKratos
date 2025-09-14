<?php

namespace MediaWiki\Extension\OryKratos;

use Closure;
use MediaWiki\SpecialPage\UnlistedSpecialPage;
use MediaWiki\Title\Title;

class ExternalRedirectSpecialPage extends UnlistedSpecialPage {
	/**
	 * @param string $name Canonical name of the replaced special page
	 * @param string $url URL to redirect to
	 * @param string|null $defaultReturnTo Default URL to return to
	 * @return Closure
	 */
	public static function getCallback( string $name, string $url, ?string $defaultReturnTo = null ): Closure {
		return static fn () => new ExternalRedirectSpecialPage( $name, $url, $defaultReturnTo );
	}

	/**
	 * @param string $name Name of the special page
	 * @param string $url URL to redirect to
	 * @param string|null $defaultReturnTo Default URL to return to
	 */
	public function __construct(
		string $name,
		private readonly string $url,
		private readonly ?string $defaultReturnTo = null
	) {
		parent::__construct( $name );
	}

	/** @inheritDoc */
	public function execute( $subPage ): void {
		$returnTo = Title::newFromText( $this->getRequest()->getText( 'returnto' ) )?->getFullURL()
			?? $this->defaultReturnTo
			?? Title::newMainPage()->getFullURL();

		$url = $this->url;
		if ( $returnTo !== null ) {
			$url .= '?return_to=' . urlencode( $returnTo );
		}

		$this->getOutput()->redirect( $url );
	}
}
