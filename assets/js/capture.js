/**
 * Hands the shopper's e-mail address (and, when the Pro Viber/SMS reminder is
 * on, the phone) to the plugin as soon as it is typed on the checkout, so a
 * guest cart can be recovered later.
 *
 * Works on both checkouts: the block checkout renders #email / #billing-phone,
 * the classic one #billing_email / #billing_phone (which are also covered
 * server-side by the update_order_review AJAX call — this script simply makes
 * capture immediate).
 *
 * With the checkout error log on, it also reports JavaScript errors and the
 * block checkout's in-browser field errors.
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

	/*
	 * Checkout error log: JavaScript errors on this page, and block checkout
	 * fields that failed validation in the browser (those never reach the
	 * server, so only the page can tell). Server-side errors are logged by PHP.
	 */
	if ( ! config.errors ) {
		return;
	}

	const MAX_REPORTS = 5;
	let reported = 0;
	const seen = {};
	const queue = [];
	let flushTimer = null;

	const chosenGateway = function () {
		const radio = document.querySelector( 'input[name="payment_method"]:checked, input[name="radio-control-wc-payment-method-options"]:checked' );
		return radio && radio.value ? String( radio.value ).slice( 0, 100 ) : '';
	};

	const flush = function () {
		flushTimer = null;
		if ( ! queue.length ) {
			return;
		}
		const items = queue.splice( 0, queue.length );
		fetch( config.errors, {
			method: 'POST',
			credentials: 'same-origin',
			keepalive: true,
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce,
			},
			body: JSON.stringify( { page: window.location.pathname, items: items } ),
		} ).catch( function () {} );
	};

	const report = function ( item ) {
		const message = String( item.message || '' ).trim().slice( 0, 300 );
		if ( ! message || reported >= MAX_REPORTS ) {
			return;
		}
		const key = item.kind + '|' + ( item.field || '' ) + '|' + message;
		if ( seen[ key ] ) {
			return;
		}
		seen[ key ] = true;
		reported++;
		item.message = message;
		item.gateway = chosenGateway();
		queue.push( item );
		if ( ! flushTimer ) {
			flushTimer = window.setTimeout( flush, 1500 );
		}
	};

	window.addEventListener( 'error', function ( event ) {
		// Resource load failures (img/script tags) bubble here without a message.
		if ( ! event || ! event.message ) {
			return;
		}
		report( {
			kind: 'js',
			message: event.message,
			file: event.filename || '',
			line: event.lineno || 0,
			col: event.colno || 0,
		} );
	} );

	window.addEventListener( 'unhandledrejection', function ( event ) {
		const reason = event ? event.reason : null;
		let message = '';
		if ( reason && reason.message ) {
			message = reason.message;
		} else if ( 'string' === typeof reason ) {
			message = reason;
		}
		if ( message ) {
			report( { kind: 'js', message: 'Unhandled promise rejection: ' + message } );
		}
	} );

	// Block checkout: after "Place order" is pressed, collect the inline field
	// errors the checkout rendered instead of sending the order.
	const collectBlockErrors = function () {
		document.querySelectorAll( '.wc-block-components-validation-error' ).forEach( function ( node ) {
			const text = node.textContent ? node.textContent.trim() : '';
			if ( ! text ) {
				return;
			}
			const holder = node.closest( '.wc-block-components-text-input, .wc-block-components-combobox, .wc-block-components-checkbox, .wc-block-components-address-form__field, div' );
			const input = holder ? holder.querySelector( 'input, select, textarea' ) : null;
			report( {
				kind: 'validation',
				message: text,
				field: input && input.id ? input.id : '',
			} );
		} );
	};

	document.addEventListener( 'click', function ( event ) {
		const target = event.target && event.target.closest ? event.target.closest( '.wc-block-components-checkout-place-order-button' ) : null;
		if ( target ) {
			window.setTimeout( collectBlockErrors, 400 );
		}
	}, true );

	window.addEventListener( 'pagehide', flush );
}() );
