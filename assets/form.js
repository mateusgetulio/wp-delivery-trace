( function () {
	var loaded = Date.now();

	document.querySelectorAll( '.delivery-trace-form' ).forEach( function ( form ) {
		var summary = form.querySelector( '.delivery-trace-error-summary' );

		if ( summary ) {
			summary.focus();
		}

		form.addEventListener( 'submit', function () {
			var elapsed = form.querySelector( '[name="delivery_trace[elapsed_ms]"]' );

			if ( elapsed ) {
				elapsed.value = String( Date.now() - loaded );
			}
		} );
	} );
} )();
