/**
 * Onehop Redirect Checker: the Dashboard widget starts the first scan (admin/class-dashboard-widget.php).
 * Loaded only while the scan queued on activation has not run yet (WP-Cron held up) and the user
 * may run scans. When it is done, the widget is swapped for a fresh render of it, with the results.
 */
( () => {
	const status = document.querySelector( '#onehop-redirect-checker .rdi-widget__first-scan' );
	if ( ! status || ! window.wp?.apiFetch ) {
		return;
	}

	const say = ( text ) => {
		status.textContent = text;
	};

	// The widget as the Dashboard renders it now, or null (then the message asks for a reload).
	const freshWidget = async () => {
		const response = await window.fetch( window.location.href, { credentials: 'same-origin' } );
		const page = new DOMParser().parseFromString( await response.text(), 'text/html' );
		return page.querySelector( '#onehop-redirect-checker .inside .rdi-widget' );
	};

	window.wp
		.apiFetch( { path: status.dataset.path, method: 'POST', data: { first: true } } )
		.then( async () => {
			const fresh = await freshWidget().catch( () => null );
			const widget = status.closest( '.rdi-widget' );
			if ( fresh && widget && ! fresh.querySelector( '.rdi-widget__first-scan' ) ) {
				widget.replaceWith( document.importNode( fresh, true ) );
				return;
			}
			say( status.dataset.done );
		} )
		.catch( ( error ) => {
			say( 409 === error?.data?.status ? status.dataset.busy : `${ status.dataset.failed } ${ error?.message ?? '' }`.trim() );
		} );
} )();
