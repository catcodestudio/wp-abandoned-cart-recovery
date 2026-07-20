/**
 * Remembers that the admin dismissed the Pro trial notice.
 */
document.addEventListener( 'click', function ( e ) {
	if ( ! e.target.closest( '[data-catcode-abandoned-cart-trial] .notice-dismiss' ) ) {
		return;
	}
	if ( typeof window.catcodeAbandonedCartNotice === 'undefined' ) {
		return;
	}
	const body = new FormData();
	body.append( 'action', 'catcode_abandoned_cart_dismiss_trial' );
	body.append( '_wpnonce', window.catcodeAbandonedCartNotice.nonce );
	fetch( window.catcodeAbandonedCartNotice.ajaxUrl, {
		method: 'POST',
		credentials: 'same-origin',
		body: body,
	} );
} );
