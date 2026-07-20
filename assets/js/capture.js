/**
 * Hands the shopper's e-mail address to the plugin as soon as it is typed on
 * the checkout, so a guest cart can be recovered later.
 *
 * Works on both checkouts: the block checkout renders #email, the classic one
 * #billing_email (which is also covered server-side by the update_order_review
 * AJAX call — this script simply makes capture immediate).
 */
( function () {
	'use strict';

	if ( typeof window.catcodeAbandonedCart === 'undefined' ) {
		return;
	}

	const config = window.catcodeAbandonedCart;
	const emailSelector = '#email, #billing_email, input[type="email"][id$="-email"], input[autocomplete="email"]';
	const firstNameSelector = '#billing_first_name, input[id$="-first_name"]';
	const lastNameSelector = '#billing_last_name, input[id$="-last_name"]';

	let lastSent = '';
	let timer = null;

	const isEmail = function ( value ) {
		return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test( value );
	};

	const readName = function () {
		const first = document.querySelector( firstNameSelector );
		const last = document.querySelector( lastNameSelector );
		return [
			first && first.value ? first.value.trim() : '',
			last && last.value ? last.value.trim() : '',
		].join( ' ' ).trim();
	};

	const push = function ( email ) {
		if ( email === lastSent ) {
			return;
		}
		lastSent = email;

		fetch( config.endpoint, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce,
			},
			body: JSON.stringify( { email: email, name: readName() } ),
		} ).catch( function () {
			// A failed capture must never disturb the checkout.
			lastSent = '';
		} );
	};

	const maybeCapture = function ( element ) {
		if ( ! element || ! element.value ) {
			return;
		}
		const email = element.value.trim();
		if ( ! isEmail( email ) ) {
			return;
		}
		window.clearTimeout( timer );
		timer = window.setTimeout( function () {
			push( email );
		}, 800 );
	};

	// Delegated: the block checkout mounts its fields asynchronously, so binding
	// on the document survives re-renders without any MutationObserver.
	document.addEventListener( 'change', function ( event ) {
		if ( event.target && event.target.matches && event.target.matches( emailSelector ) ) {
			maybeCapture( event.target );
		}
	}, true );

	document.addEventListener( 'blur', function ( event ) {
		if ( event.target && event.target.matches && event.target.matches( emailSelector ) ) {
			maybeCapture( event.target );
		}
	}, true );

	document.addEventListener( 'input', function ( event ) {
		if ( event.target && event.target.matches && event.target.matches( emailSelector ) ) {
			maybeCapture( event.target );
		}
	}, true );
}() );
