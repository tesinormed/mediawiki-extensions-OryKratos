<?php

namespace MediaWiki\Extension\OryKratos;

use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

class SchemaHooks implements LoadExtensionSchemaUpdatesHook
{
	/**
	 * @inheritDoc
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/LoadExtensionSchemaUpdates
	 */
	public function onLoadExtensionSchemaUpdates($updater): void
	{
		$dir = __DIR__ . '/../sql/' . $updater->getDB()->getType() . '/';
		$updater->addExtensionTable('ory_kratos', $dir . 'ory_kratos.sql');
	}
}
