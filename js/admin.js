/*
 * EngineScript Site Exporter — Admin Scripts
 *
 * Enqueued only on the registered single-site or Network Admin exporter page.
 *
 * Package: EngineScript_Site_Exporter
 * Since:   2.0.0
 */

( () => {
	function handleDeleteSubmit( event ) {
		const confirmMessage = event.currentTarget?.dataset?.sseConfirmMessage;

		if ( ! confirmMessage ) {
			console.warn(
				'Site Exporter delete confirmation form is missing data-sse-confirm-message.'
			);
			event.preventDefault();
			return;
		}

		if ( ! globalThis.confirm( confirmMessage ) ) {
			event.preventDefault();
		}
	}

	document.querySelectorAll( '.sse-confirm-delete' ).forEach( ( form ) => {
		form.addEventListener( 'submit', handleDeleteSubmit );
	} );
} )();
