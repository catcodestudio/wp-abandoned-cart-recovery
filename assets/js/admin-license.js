/**
 * Licence box on the settings screen: activate a key, release it, or start the
 * 7-day trial from the modal. No framework — plain fetch against admin-ajax.
 */
( function () {
	'use strict';

	var cfg = window.catcodeAbandonedCartLicense || {};

	function el( id ) {
		return document.getElementById( id );
	}

	function say( text, isError ) {
		var box = el( 'catcode-acr-license-msg' );
		if ( ! box ) {
			return;
		}
		box.textContent = text;
		box.className = 'catcode-abandoned-cart-msg' + ( isError ? ' catcode-abandoned-cart-msg--error' : ' catcode-abandoned-cart-msg--ok' );
	}

	function post( body, done ) {
		fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body
		} )
			.then( function ( r ) {
				return r.json();
			} )
			.then( done )
			.catch( function () {
				done( { success: false, data: { message: cfg.i18n.network } } );
			} );
	}

	function busy( button, on ) {
		if ( ! button ) {
			return;
		}
		button.disabled = on;
		button.classList.toggle( 'catcode-abandoned-cart-busy', on );
	}

	function reload() {
		window.setTimeout( function () {
			window.location.href = cfg.settingsUrl;
		}, 1200 );
	}

	document.addEventListener( 'click', function ( e ) {
		var target = e.target;

		// Activate / re-check the key typed in the field.
		if ( target.id === 'catcode-acr-activate' ) {
			e.preventDefault();
			var field = el( 'catcode-abandoned-cart-license' );
			busy( target, true );
			post(
				'action=catcode_abandoned_cart_license&nonce=' + encodeURIComponent( cfg.nonce ) +
					'&key=' + encodeURIComponent( field ? field.value : '' ),
				function ( res ) {
					busy( target, false );
					say( res.data && res.data.message ? res.data.message : '', ! res.success );
					if ( res.success ) {
						reload();
					}
				}
			);
			return;
		}

		// Release the key from this site.
		if ( target.id === 'catcode-acr-deactivate' ) {
			e.preventDefault();
			if ( ! window.confirm( cfg.i18n.confirmDeactivate ) ) {
				return;
			}
			busy( target, true );
			post(
				'action=catcode_abandoned_cart_license&nonce=' + encodeURIComponent( cfg.nonce ) + '&mode=deactivate',
				function ( res ) {
					busy( target, false );
					say( res.data && res.data.message ? res.data.message : '', ! res.success );
					reload();
				}
			);
			return;
		}

		// Open / close the trial modal.
		if ( target.id === 'catcode-acr-trial-open' ) {
			e.preventDefault();
			var modal = el( 'catcode-acr-modal' );
			if ( modal ) {
				modal.hidden = false;
				var mail = el( 'catcode-acr-trial-email' );
				if ( mail ) {
					mail.focus();
				}
			}
			return;
		}

		if ( target.classList && target.classList.contains( 'catcode-acr-modal-close' ) ) {
			e.preventDefault();
			var box = el( 'catcode-acr-modal' );
			if ( box ) {
				box.hidden = true;
			}
			return;
		}

		// Start the trial: this is the only place a trial is ever born.
		if ( target.id === 'catcode-acr-trial-start' ) {
			e.preventDefault();
			var email = el( 'catcode-acr-trial-email' );
			var out = el( 'catcode-acr-trial-msg' );
			busy( target, true );
			post(
				'action=catcode_abandoned_cart_trial&nonce=' + encodeURIComponent( cfg.nonce ) +
					'&email=' + encodeURIComponent( email ? email.value : '' ),
				function ( res ) {
					busy( target, false );
					var message = res.data && res.data.message ? res.data.message : '';
					if ( out ) {
						out.textContent = message;
						out.className = 'catcode-abandoned-cart-msg' + ( res.success ? ' catcode-abandoned-cart-msg--ok' : ' catcode-abandoned-cart-msg--error' );
					}
					if ( res.success ) {
						var keyBox = el( 'catcode-acr-trial-key' );
						if ( keyBox && res.data.key ) {
							keyBox.hidden = false;
							keyBox.querySelector( 'code' ).textContent = res.data.key;
						}
						reload();
					}
				}
			);
		}
	} );
}() );
