<?php

namespace MediaWiki\Extension\OryKratos\Hook;

use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

class SchemaHooks implements LoadExtensionSchemaUpdatesHook {
	/**
	 * @inheritDoc
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/LoadExtensionSchemaUpdates
	 */
	public function onLoadExtensionSchemaUpdates( $updater ): void {
		$updater->addExtensionTable(
			'orykratos',
			__DIR__ . '/../../sql/' . $updater->getDB()->getType() . '/orykratos.sql'
		);
		$updater->addExtensionTable(
			'orykratos_equiv',
			__DIR__ . '/../../sql/' . $updater->getDB()->getType() . '/orykratos_equiv.sql'
		);
	}
}
