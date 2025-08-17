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
use RuntimeException;

class MainHooks implements
	SkinTemplateNavigation__UniversalHook,
	SpecialPage_initListHook,
	GetPreferencesHook
{
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
				'href' => $this->config->get( 'OryKratosLogoutUrl' )
					. '?return_to='
					. urlencode( $sktemplate->getTitle()->getFullURL() ),
				'active' => false,
				'icon' => 'logOut'
			];
		} else {
			$links['user-menu']['login'] = [
				'single-id' => 'pt-login',
				'text' => $sktemplate->msg( 'pt-login' )->text(),
				'href' => $this->config->get( 'OryKratosPublicHost' )
					. '/self-service/login/browser?return_to='
					. urlencode( $sktemplate->getTitle()->getFullURL() ),
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
		$disabledSpecialPages = [
			'Userlogin' => true,
			'Userlogout' => true,
			'CreateAccount' => true,
			'LinkAccounts' => true,
			'UnlinkAccounts' => true,
			'ChangeCredentials' => true,
			'RemoveCredentials' => true,
			'ChangePassword' => true,
			'PasswordReset' => true,
			'ChangeEmail' => $this->mainConfig->get( MainConfigNames::EnableEmail ),
			'Confirmemail' => $this->mainConfig->get( MainConfigNames::EnableEmail ),
			'Invalidateemail' => $this->mainConfig->get( MainConfigNames::EnableEmail ),
		];

		foreach ( array_keys( array_filter( $disabledSpecialPages ) ) as $page ) {
			$list[$page] = DisabledSpecialPage::getCallback( $page );
		}
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
			// disable editing of emailaddress
			$preferences['emailaddress']['default'] = $user->getEmail() ? htmlspecialchars( $user->getEmail() ) : '';

			// disable requireemail
			unset( $preferences['requireemail'] );
		}
	}
}
