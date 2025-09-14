<?php

namespace MediaWiki\Extension\OryKratos;

use MediaWiki\Config\Config;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\Hook\SkinTemplateNavigation__UniversalHook;
use MediaWiki\MainConfigNames;
use MediaWiki\Preferences\Hook\GetPreferencesHook;
use MediaWiki\Session\CookieSessionProvider;
use MediaWiki\Settings\SettingsBuilder;
use MediaWiki\SpecialPage\DisabledSpecialPage;
use MediaWiki\SpecialPage\Hook\SpecialPage_initListHook;
use MediaWiki\SpecialPage\SpecialPage;
use RuntimeException;

class MainHooks implements
	SkinTemplateNavigation__UniversalHook,
	SpecialPage_initListHook,
	GetPreferencesHook
{
	private const DISABLED_SPECIAL_PAGES = [
		'Userlogout',
		'CreateAccount',
		'LinkAccounts',
		'UnlinkAccounts',
		'ChangeCredentials',
		'RemoveCredentials',
		'ChangePassword',
		'PasswordReset',
		'ChangeEmail',
		'Invalidateemail',
	];

	private readonly Config $config;

	public function __construct(
		private readonly Config $mainConfig,
		ConfigFactory $configFactory
	) {
		$this->config = $configFactory->makeConfig( 'orykratos' );
	}

	/**
	 * @see https://www.mediawiki.org/wiki/Manual:Extension.json/Schema#callback
	 */
	public static function onRegistration( array $extensionInfo, SettingsBuilder $settings ): void {
		global $wgSessionProviders;
		if ( isset( $wgSessionProviders[CookieSessionProvider::class] )
			&& isset( $wgSessionProviders[OryKratosSessionProvider::class] )
		) {
			unset( $wgSessionProviders[CookieSessionProvider::class] );
		}

		if ( $settings->getConfig()->get( 'OryKratosPublicHost' ) === '' ) {
			throw new RuntimeException( '$wgOryKratosPublicHost must be set' );
		}
		if ( $settings->getConfig()->get( 'OryKratosAdminHost' ) === '' ) {
			throw new RuntimeException( '$wgOryKratosAdminHost must be set' );
		}
		if ( $settings->getConfig()->get( 'OryKratosUiUrl' ) === '' ) {
			throw new RuntimeException( '$wgOryKratosUiUrl must be set' );
		}
		if ( $settings->getConfig()->get( 'OryKratosLogoutUrl' ) === '' ) {
			throw new RuntimeException( '$wgOryKratosLogoutUrl must be set' );
		}
	}

	/**
	 * @inheritDoc
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/SkinTemplateNavigation::Universal
	 */
	public function onSkinTemplateNavigation__Universal( $sktemplate, &$links ): void {
		if ( $sktemplate->loggedin ) {
			$links['user-menu']['orykratos'] = [
				'single-id' => 'pt-orykratos',
				'text' => $sktemplate->msg( 'orykratos-usermenu-text' )->text(),
				'href' => $this->config->get( 'OryKratosUiUrl' ),
				'active' => false,
				'icon' => 'settings'
			];

			$links['user-menu']['logout'] = [
				'single-id' => 'pt-logout',
				'text' => $sktemplate->msg( 'pt-userlogout' )->text(),
				'href' => $this->generateReturnToUrl(
					$this->config->get( 'OryKratosLogoutUrl' ),
					returnTo: $sktemplate->getTitle()->getFullURL()
				),
				'active' => false,
				'icon' => 'logOut'
			];
		} else {
			$links['user-menu']['createaccount'] = [
				'single-id' => 'pt-createaccount',
				'text' => $sktemplate->msg( 'pt-createaccount' )->text(),
				'href' => $this->generateFlowUrl(
					'registration',
					returnTo: $sktemplate->getTitle()->getFullURL()
				),
				'active' => false,
				'icon' => 'userAdd'
			];

			$links['user-menu']['login'] = [
				'single-id' => 'pt-login',
				'text' => $sktemplate->msg( 'pt-login' )->text(),
				'href' => $this->generateFlowUrl(
					'login',
					returnTo: $sktemplate->getTitle()->getFullURL()
				),
				'active' => false,
				'icon' => 'logIn'
			];
		}
	}

	/**
	 * @inheritDoc
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/SpecialPage_initList
	 */
	public function onSpecialPage_initList( &$list ): void {
		foreach ( self::DISABLED_SPECIAL_PAGES as $name ) {
			$list[$name] = DisabledSpecialPage::getCallback( $name );
		}

		$list['Userlogin'] = ExternalRedirectSpecialPage::getCallback( 'Userlogin',
			url: $this->generateFlowUrl( 'login' )
		);

		$list['Confirmemail'] = ExternalRedirectSpecialPage::getCallback( 'Confirmemail',
			url: $this->generateFlowUrl( 'verification' ),
			defaultReturnTo: SpecialPage::getTitleFor( 'Preferences' )->getFullURL()
		);
	}

	/**
	 * @inheritDoc
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/GetPreferences
	 */
	public function onGetPreferences( $user, &$preferences ): void {
		// disable password
		unset( $preferences['password'] );

		// disable editing of realname
		$preferences['realname']['type'] = 'info';

		if ( $this->mainConfig->get( MainConfigNames::EnableEmail ) ) {
			// remove Special:ChangeEmail link
			$preferences['emailaddress']['default'] = $user->getEmail() ? htmlspecialchars( $user->getEmail() ) : '';

			// disable requireemail
			unset( $preferences['requireemail'] );
		}
	}

	private function generateFlowUrl( string $flow, ?string $returnTo = null ): string {
		$result = $this->config->get( 'OryKratosPublicHost' ) . '/self-service/' . $flow . '/browser';

		if ( $returnTo !== null ) {
			$result = $this->generateReturnToUrl( $result, $returnTo );
		}

		return $result;
	}

	private function generateReturnToUrl( string $url, string $returnTo ): string {
		return $url . '?return_to=' . urlencode( $returnTo );
	}
}
