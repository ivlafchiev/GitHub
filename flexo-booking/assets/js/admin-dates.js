/**
 * Flexo Booking – DD.MM.YYYY date pickers on the Seasonal prices and Closed
 * dates screens (jQuery UI datepicker bundled with WordPress).
 */
jQuery( function ( $ ) {
	var $inputs = $( 'input.flexo-date' );
	$inputs.datepicker( {
		dateFormat: 'dd.mm.yy',
		firstDay: 1,
		changeMonth: true,
		changeYear: true,
		showOtherMonths: true,
		selectOtherMonths: true,
	} );

	// Open the "To" calendar at the "From" month. (No minDate: it would
	// rewrite a typed "To" value; the server checks the order of the dates.)
	$inputs.filter( '[name="date_from"]' ).on( 'change', function () {
		var from = $( this ).datepicker( 'getDate' );
		var $to = $( this ).closest( 'form' ).find( 'input.flexo-date[name="date_to"]' );
		if ( from && $to.length ) {
			$to.datepicker( 'option', 'defaultDate', from );
		}
	} ).trigger( 'change' );
} );
