<?php

use GuzzleHttp\Client;
use MediaWiki\MediaWikiServices;
use Ory\Kratos\Client\Api\FrontendApi;
use Ory\Kratos\Client\Api\IdentityApi;
use Ory\Kratos\Client\Configuration;
use Wikimedia\Equivset\Equivset;

return [
	'OryKratos.FrontendApi' => static fn ( MediaWikiServices $services ) => new FrontendApi(
		new Client(),
		( new Configuration() )->setHost(
			$services->getConfigFactory()
				->makeConfig( 'orykratos' )
				->get( 'OryKratosPublicHost' )
		)
	),

	'OryKratos.IdentityApi' => static fn ( MediaWikiServices $services ) => new IdentityApi(
		new Client(),
		( new Configuration() )->setHost(
			$services->getConfigFactory()
				->makeConfig( 'orykratos' )
				->get( 'OryKratosAdminHost' )
		)
	),

	'OryKratos.Equivset' => static fn ( MediaWikiServices $services ) => new Equivset(),
];
