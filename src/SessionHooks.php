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
		$identityId = OryKratosTable::findIdentityIdFromUser( $user );
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
			$user->setEmail( $identity->getTraits()->email );
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
