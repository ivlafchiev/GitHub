<?php
/**
 * Invoice request (feature "invoice_request"): the guest ticks "I would
 * like an invoice" and enters details for a person or a company. This only
 * collects the details for the hotel; it is not an invoicing system.
 *
 * Stored in their own table, apart from the guest details.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Invoices {

	public static function enabled() {
		return Flexo_Booking_Features::is_enabled( 'invoice_request' );
	}

	public static function table() {
		return Flexo_Booking_Schema::table( 'invoices' );
	}

	/**
	 * @return array Field => { label, for: individual|company, autocomplete }.
	 */
	public static function fields() {
		return array(
			'full_name'       => array(
				'label'        => __( 'Full name', 'flexo-booking' ),
				'for'          => 'individual',
				'autocomplete' => 'name',
			),
			'address'         => array(
				'label'        => __( 'Address', 'flexo-booking' ),
				'for'          => 'individual',
				'autocomplete' => 'street-address',
			),
			'company_name'    => array(
				'label'        => __( 'Company name', 'flexo-booking' ),
				'for'          => 'company',
				'autocomplete' => 'organization',
			),
			'company_id'      => array(
				'label'        => __( 'Company ID (EIK/BULSTAT)', 'flexo-booking' ),
				'for'          => 'company',
				'autocomplete' => 'off',
			),
			'vat_number'      => array(
				'label'        => __( 'VAT number', 'flexo-booking' ),
				'for'          => 'company',
				'autocomplete' => 'off',
			),
			'company_address' => array(
				'label'        => __( 'Registered address', 'flexo-booking' ),
				'for'          => 'company',
				'autocomplete' => 'street-address',
			),
			'contact_person'  => array(
				'label'        => __( 'Contact person', 'flexo-booking' ),
				'for'          => 'company',
				'autocomplete' => 'name',
			),
		);
	}

	/**
	 * "required", "optional" or "hidden" (Settings → Invoices).
	 */
	public static function mode( $field ) {
		$mode = Flexo_Booking_Settings::get( 'invoice_' . $field );
		return in_array( $mode, array( 'required', 'optional', 'hidden' ), true ) ? $mode : 'optional';
	}

	/**
	 * Checks the invoice part of a booking form.
	 *
	 * @param mixed $input array( 'requested' => bool, 'type' => 'individual'|'company', <field> => value ).
	 * @return array|null|WP_Error Clean details, null when no invoice is requested.
	 */
	public static function validate( $input ) {
		if ( ! is_array( $input ) || empty( $input['requested'] ) ) {
			return null;
		}
		$type  = isset( $input['type'] ) && 'company' === $input['type'] ? 'company' : 'individual';
		$clean = array( 'invoice_type' => $type );
		foreach ( self::fields() as $field => $def ) {
			$value = isset( $input[ $field ] ) ? sanitize_text_field( (string) $input[ $field ] ) : '';
			$mode  = self::mode( $field );
			if ( $def['for'] !== $type || 'hidden' === $mode ) {
				$value = '';
			} elseif ( 'required' === $mode && '' === $value ) {
				/* translators: %s: field name, e.g. "Company name" */
				return new WP_Error( 'flexo_invoice', sprintf( __( 'Please fill in "%s" for the invoice.', 'flexo-booking' ), $def['label'] ) );
			}
			$clean[ $field ] = mb_substr( $value, 0, 'address' === $field || 'company_address' === $field ? 255 : 190 );
		}
		// Light check only: a Bulgarian EIK/BULSTAT has 9 or 13 digits. IDs
		// with letters (other countries) and VAT numbers are not checked.
		$digits = preg_replace( '/\s+/', '', $clean['company_id'] );
		if ( '' !== $digits && ctype_digit( $digits ) && ! in_array( strlen( $digits ), array( 9, 13 ), true ) ) {
			return new WP_Error( 'flexo_invoice', __( 'The company ID (EIK/BULSTAT) should have 9 or 13 digits.', 'flexo-booking' ) );
		}
		if ( '' !== $digits && ctype_digit( $digits ) ) {
			$clean['company_id'] = $digits;
		}
		return $clean;
	}

	public static function save( $booking_id, array $details ) {
		global $wpdb;
		$now = current_time( 'mysql' );
		$row = array_merge(
			array_intersect_key( $details, array_flip( array_merge( array( 'invoice_type' ), array_keys( self::fields() ) ) ) ),
			array(
				'booking_id' => (int) $booking_id,
				'updated_at' => $now,
			)
		);
		if ( self::get( $booking_id ) ) {
			$wpdb->update( self::table(), $row, array( 'booking_id' => (int) $booking_id ) );
		} else {
			$row['created_at'] = $now;
			$wpdb->insert( self::table(), $row );
		}
	}

	/**
	 * @return array|null
	 */
	public static function get( $booking_id ) {
		global $wpdb;
		if ( ! $booking_id || ! Flexo_Booking_Schema::table_exists( 'invoices' ) ) {
			return null;
		}
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE booking_id = %d", $booking_id ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Invoice details of many bookings at once (admin list, CSV).
	 *
	 * @return array Booking ID => details.
	 */
	public static function for_bookings( array $ids ) {
		global $wpdb;
		$ids = array_filter( array_map( 'absint', $ids ) );
		if ( ! $ids || ! Flexo_Booking_Schema::table_exists( 'invoices' ) ) {
			return array();
		}
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE booking_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', $ids ), ARRAY_A );
		return array_column( (array) $rows, null, 'booking_id' );
	}

	public static function delete( $booking_id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'booking_id' => (int) $booking_id ) );
	}

	/**
	 * Label => value of the details that were filled in.
	 */
	public static function rows( array $invoice ) {
		$rows = array(
			__( 'Invoice for', 'flexo-booking' ) => 'company' === $invoice['invoice_type'] ? __( 'Company', 'flexo-booking' ) : __( 'Person', 'flexo-booking' ),
		);
		foreach ( self::fields() as $field => $def ) {
			if ( isset( $invoice[ $field ] ) && '' !== $invoice[ $field ] ) {
				$rows[ $def['label'] ] = $invoice[ $field ];
			}
		}
		return $rows;
	}

	/**
	 * Plain-text block for the hotel's new-booking email ('' without an invoice).
	 */
	public static function text( $booking_id ) {
		$invoice = self::get( $booking_id );
		if ( ! $invoice ) {
			return '';
		}
		$lines = array( __( 'INVOICE REQUESTED', 'flexo-booking' ) );
		foreach ( self::rows( $invoice ) as $label => $value ) {
			$lines[] = $label . ': ' . $value;
		}
		return implode( "\n", $lines );
	}

	/**
	 * The "I would like an invoice" part of the guest form.
	 */
	public static function render_fields( $uid ) {
		$fields = self::fields();
		ob_start();
		?>
		<div class="fb-invoice" data-fb-invoice>
			<label class="fb-check">
				<input type="checkbox" name="invoice_requested" value="1" data-fb-invoice-toggle>
				<?php esc_html_e( 'I would like an invoice', 'flexo-booking' ); ?>
			</label>
			<div class="fb-invoice__fields" hidden>
				<fieldset class="fb-invoice__type">
					<legend class="screen-reader-text"><?php esc_html_e( 'Invoice for', 'flexo-booking' ); ?></legend>
					<label class="fb-check"><input type="radio" name="invoice_type" value="individual" checked> <?php esc_html_e( 'A person', 'flexo-booking' ); ?></label>
					<label class="fb-check"><input type="radio" name="invoice_type" value="company"> <?php esc_html_e( 'A company', 'flexo-booking' ); ?></label>
				</fieldset>
				<div class="fb-grid">
					<?php foreach ( $fields as $field => $def ) : ?>
						<?php
						$mode = self::mode( $field );
						if ( 'hidden' === $mode ) {
							continue;
						}
						$id = $uid . '-inv-' . $field;
						?>
						<div class="fb-field<?php echo false !== strpos( $field, 'address' ) ? ' fb-field--wide' : ''; ?>" data-fb-invoice-for="<?php echo esc_attr( $def['for'] ); ?>" <?php echo 'company' === $def['for'] ? 'hidden' : ''; ?>>
							<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $def['label'] ); ?><?php echo 'required' === $mode ? ' <span aria-hidden="true">*</span>' : ''; ?></label>
							<input id="<?php echo esc_attr( $id ); ?>" type="text" name="invoice_<?php echo esc_attr( $field ); ?>" autocomplete="<?php echo esc_attr( $def['autocomplete'] ); ?>" data-required="<?php echo 'required' === $mode ? '1' : '0'; ?>">
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
