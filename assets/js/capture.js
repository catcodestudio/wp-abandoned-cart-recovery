/**
 * Hands the shopper's e-mail address (and, when the Pro Viber/SMS reminder is
 * on, the phone) to the plugin as soon as it is typed on the checkout, so a
 * guest cart can be recovered later.
 *
 * Works on both checkouts: the block checkout renders #email / #billing-phone,
 * the classic one #billing_email / #billing_phone (which are also covered
 * server-side by the update_order_review AJAX call — this script simply makes
 * capture immediate).
 */
( function () {
	'use strict';

	if ( typeof window.catcodeAbandonedCart === 'undefined' ) {
		return;
	}

	const config = window.catcodeAbandonedCart;
	const emailSelector = '#email, #billing_email, input[type="email"][id$="-email"], input[autocomplete="email"]';
	const phoneSelector = '#billing_phone, #billing-phone, #shipping-phone, #phone, input[type="tel"], input[autocomplete="tel"]';
	const firstNameSelector = '#billing_first_name, input[id$="-first_name"]';
	const lastNameSelector = '#billing_last_name, input[id$="-last_name"]';

	const lastSent = { email: '', phone: '' };
	const timers = { email: null, phone: null };

	const isEmail = function ( value ) {
		return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test( value );
	};

	// Server-side normalisation decides; this only avoids posting half-typed numbers.
	const isPhone = function ( value ) {
		return value.replace( /\D+/g, '' ).length >= 9;
	};

	const readName = function () {
		const first = document.querySelector( firstNameSelector );
		const last = document.querySelector( lastNameSelector );
		return [
			first && first.value ? first.value.trim() : '',
			last && last.value ? last.value.trim() : '',
		].join( ' ' ).trim();
	};

	const push = function ( kind, value ) {
		if ( value === lastSent[ kind ] ) {
			return;
		}
		lastSent[ kind ] = value;

		const body = { name: readName() };
		body[ kind ] = value;

		fetch( config.endpoint, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce,
			},
			body: JSON.stringify( body ),
		} ).catch( function () {
			// A failed capture must never disturb the checkout.
			lastSent[ kind ] = '';
		} );
	};

	const maybeCapture = function ( element ) {
		if ( ! element || ! element.value || ! element.matches ) {
			return;
		}

		let kind = '';
		if ( element.matches( emailSelector ) ) {
			kind = 'email';
		} else if ( config.phone && element.matches( phoneSelector ) ) {
			kind = 'phone';
		}
		if ( ! kind ) {
			return;
		}

		const value = element.value.trim();
		if ( ( 'email' === kind && ! isEmail( value ) ) || ( 'phone' === kind && ! isPhone( value ) ) ) {
			return;
		}

		window.clearTimeout( timers[ kind ] );
		timers[ kind ] = window.setTimeout( function () {
			push( kind, value );
		}, 800 );
	};

	// Delegated: the block checkout mounts its fields asynchronously, so binding
	// on the document survives re-renders without any MutationObserver.
	[ 'change', 'blur', 'input' ].forEach( function ( type ) {
		document.addEventListener( type, function ( event ) {
			maybeCapture( event.target );
		}, true );
	} );
}() );
