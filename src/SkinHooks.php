<?php

namespace MediaWiki\Extension\OryKratos;

use MediaWiki\Hook\SkinTemplateNavigation__UniversalHook;

class SkinHooks implements SkinTemplateNavigation__UniversalHook {
	private const TEXT_MESSAGE = 'orykratos-usermenu-text';
	private const HREF_MESSAGE = 'orykratos-usermenu-href';

	/**
	 * @inheritDoc
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/SkinTemplateNavigation::Universal
	 */
	public function onSkinTemplateNavigation__Universal( $sktemplate, &$links ): void {
		// don't add the link if the messages don't exist
		if ( !wfMessage( self::HREF_MESSAGE )->exists() ) {
			return;
		}

		$links['user-menu'] = wfArrayInsertAfter( $links['user-menu'],
			[ 'orykratos' => [
				'single-id' => 'pt-orykratos',
				'text' => $sktemplate->msg( self::TEXT_MESSAGE )->text(),
				'href' => $sktemplate->msg( self::HREF_MESSAGE )->text(),
				'active' => false,
				'icon' => 'settings',
			] ],
			after: 'preferences'
		);
	}
}
