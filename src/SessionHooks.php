<?php

namespace MediaWiki\Extension\OryKratos;

use MediaWiki\User\Hook\UserLoadAfterLoadFromSessionHook;
use Ory\Kratos\Client\Api\IdentityApi;
use Ory\Kratos\Client\ApiException;

class SessionHooks implements UserLoadAfterLoadFromSessionHook {
	public function __construct( private readonly IdentityApi $identityApi ) {
	}

	/**
	 * @inheritDoc
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/UserLoadAfterLoadFromSession
	 */
	public function onUserLoadAfterLoadFromSession( $user ): void {
		// see if there's a mapping for this MediaWiki user
		$identityId = OryKratos::findIdentityIdFromUser( $user );
		if ( $identityId === false ) {
			// no mapping
			return;
		}

		try {
			$identity = $this->identityApi->getIdentity( $identityId );
		} catch ( ApiException $exception ) {
			wfLogWarning( 'failed to load identity: ' . $exception->getMessage() );
			return;
		}

		$dirty = false;

		if ( $user->getEmail() !== $identity->getTraits()->email ) {
			// new email (implicit invalidateEmail())
			$user->setEmail( $identity->getTraits()->email );
			if ( $identity->getVerifiableAddresses()[0]->getVerified() ) {
				// verified email
				$user->confirmEmail();
			}
			$dirty = true;
		} elseif ( !$user->isEmailConfirmed() && $identity->getVerifiableAddresses()[0]->getVerified() ) {
			// previously unverified email has been verified
			$user->confirmEmail();
			$dirty = true;
		}

		if ( property_exists( $identity->getTraits(), 'name' )
			&& $user->getRealName() !== $identity->getTraits()->name
		) {
			$user->setRealName( $identity->getTraits()->name );
			$dirty = true;
		}

		if ( $dirty ) {
			$user->saveSettings();
		}
	}
}
