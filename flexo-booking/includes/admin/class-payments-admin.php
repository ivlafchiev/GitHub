<?php
/**
 * Payments in the admin: the Settings → Payments tab, the payment card on a
 * booking (history, balance, "Payment received", refunds), list badges and
 * the payment conflict notice.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Payments_Admin {

	public static function init() {
		add_action( 'admin_post_flexo_booking_payment', array( __CLASS__, 'handle_action' ) );
		add_action( 'admin_notices', array( __CLASS__, 'settings_notice' ) );
	}

	/* ---------------------------------------------------------------------
	 * Settings → Payments
	 * ------------------------------------------------------------------- */

	/**
	 * Fields of the Payments tab (inside the settings form).
	 */
	public static function render_settings( array $s, $name ) {
		$instant  = 'instant' === Flexo_Booking_Features::booking_mode();
		$card     = Flexo_Booking_Features::is_enabled( 'online_payment' );
		$bank     = Flexo_Booking_Features::is_enabled( 'bank_transfer' );
		$deposit  = Flexo_Booking_Features::is_enabled( 'deposit' );
		$stripe   = Flexo_Booking_Payments::gateway( 'stripe' );
		$transfer = Flexo_Booking_Payments::gateway( 'bank_transfer' );
		$secrets  = Flexo_Booking_Payments::secrets();
		$sname    = Flexo_Booking_Payments::SECRETS_OPTION;
		$example  = 800;
		?>
		<h2><?php esc_html_e( 'What guests pay when booking', 'flexo-booking' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Payment', 'flexo-booking' ); ?></th>
				<td>
					<label class="flexo-feature-choice"><input type="radio" name="<?php echo esc_attr( $name ); ?>[payment_mode]" value="property" <?php checked( $s['payment_mode'], 'property' ); ?>> <strong><?php esc_html_e( 'Nothing online', 'flexo-booking' ); ?></strong> – <?php esc_html_e( 'guests pay everything at the property.', 'flexo-booking' ); ?></label>
					<?php if ( $deposit ) : ?>
						<label class="flexo-feature-choice"><input type="radio" name="<?php echo esc_attr( $name ); ?>[payment_mode]" value="deposit" <?php checked( $s['payment_mode'], 'deposit' ); ?>> <strong><?php esc_html_e( 'A deposit', 'flexo-booking' ); ?></strong> – <?php esc_html_e( 'part of the price when booking, the rest at the property.', 'flexo-booking' ); ?></label>
						<p class="flexo-inline-form" style="margin-left:24px">
							<input type="number" min="0" step="0.01" class="small-text" name="<?php echo esc_attr( $name ); ?>[deposit_value]" value="<?php echo esc_attr( $s['deposit_value'] ); ?>" aria-label="<?php esc_attr_e( 'Deposit', 'flexo-booking' ); ?>">
							<select name="<?php echo esc_attr( $name ); ?>[deposit_type]" aria-label="<?php esc_attr_e( 'Deposit type', 'flexo-booking' ); ?>">
								<option value="percent" <?php selected( $s['deposit_type'], 'percent' ); ?>><?php esc_html_e( '% of the total', 'flexo-booking' ); ?></option>
								<option value="fixed" <?php selected( $s['deposit_type'], 'fixed' ); ?>><?php echo esc_html( sprintf( /* translators: %s: currency symbol */ __( '%s (fixed amount)', 'flexo-booking' ), $s['currency_symbol'] ? $s['currency_symbol'] : $s['currency'] ) ); ?></option>
							</select>
						</p>
						<p class="description" style="margin-left:24px">
							<?php
							$flexo_now = 'fixed' === $s['deposit_type'] ? min( $example, (float) $s['deposit_value'] ) : $example * min( 100, (float) $s['deposit_value'] ) / 100;
							printf(
								/* translators: 1: example total, 2: paid now, 3: paid at the property */
								esc_html__( 'Example: for a stay of %1$s the guest pays %2$s when booking and %3$s at the property.', 'flexo-booking' ),
								esc_html( Flexo_Booking_Money::format( $example ) ),
								'<strong>' . esc_html( Flexo_Booking_Money::format( $flexo_now ) ) . '</strong>',
								esc_html( Flexo_Booking_Money::format( $example - $flexo_now ) )
							);
							?>
						</p>
					<?php endif; ?>
					<label class="flexo-feature-choice"><input type="radio" name="<?php echo esc_attr( $name ); ?>[payment_mode]" value="full" <?php checked( $s['payment_mode'], 'full' ); ?>> <strong><?php esc_html_e( 'The full amount', 'flexo-booking' ); ?></strong> – <?php esc_html_e( 'guests pay the whole stay when booking.', 'flexo-booking' ); ?></label>
					<p class="description"><?php esc_html_e( 'A tourist tax set to be paid at the property (Settings → Tourist tax) is always paid there. Amounts are always calculated by the website, never taken from the guest\'s browser.', 'flexo-booking' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Ways to pay', 'flexo-booking' ); ?></th>
				<td>
					<?php if ( $card ) : ?>
						<p><strong><?php echo esc_html( $stripe->admin_label() ); ?>:</strong>
							<?php if ( ! $instant ) : ?>
								<span class="flexo-badge flexo-badge--conflict"><?php esc_html_e( 'Paused', 'flexo-booking' ); ?></span>
								<?php esc_html_e( 'You use booking requests. A guest must never be charged before you have accepted the booking, so card payments only work with Instant booking (Settings → Features). Bank transfer works with both.', 'flexo-booking' ); ?>
							<?php elseif ( ! $stripe->is_ready() ) : ?>
								<span class="flexo-badge flexo-badge--conflict"><?php esc_html_e( 'Not set up', 'flexo-booking' ); ?></span>
								<?php esc_html_e( 'Enter your Stripe secret key and webhook signing secret below.', 'flexo-booking' ); ?>
							<?php else : ?>
								<span class="flexo-status flexo-status--confirmed">✓ <?php echo esc_html( $stripe->is_live() ? __( 'Ready – live payments', 'flexo-booking' ) : __( 'Ready – test mode (no real money)', 'flexo-booking' ) ); ?></span>
							<?php endif; ?>
						</p>
					<?php endif; ?>
					<?php if ( $bank ) : ?>
						<p><strong><?php echo esc_html( $transfer->admin_label() ); ?>:</strong>
							<?php if ( ! $transfer->is_ready() ) : ?>
								<span class="flexo-badge flexo-badge--conflict"><?php esc_html_e( 'Not set up', 'flexo-booking' ); ?></span>
								<?php esc_html_e( 'Enter the beneficiary and IBAN below.', 'flexo-booking' ); ?>
							<?php else : ?>
								<span class="flexo-status flexo-status--confirmed">✓ <?php esc_html_e( 'Ready', 'flexo-booking' ); ?></span>
							<?php endif; ?>
						</p>
					<?php endif; ?>
					<?php if ( in_array( Flexo_Booking_Payments::mode(), array( 'deposit', 'full' ), true ) && ! Flexo_Booking_Payments::methods() ) : ?>
						<div class="notice notice-warning inline"><p><?php esc_html_e( 'Guests can\'t pay online yet because no way of paying is ready. Until then, new bookings are made without payment and guests are told to pay at the property.', 'flexo-booking' ); ?></p></div>
					<?php endif; ?>
					<p class="description"><?php esc_html_e( 'When both are ready, guests choose between card and bank transfer.', 'flexo-booking' ); ?></p>
				</td>
			</tr>
		</table>

		<?php if ( $card ) : ?>
			<h2><?php esc_html_e( 'Card payments (Stripe)', 'flexo-booking' ); ?></h2>
			<p><?php esc_html_e( 'Guests pay on Stripe\'s secure payment page; card details never reach this website. The booking is confirmed when Stripe reports the payment (webhook), not when the guest comes back to the website.', 'flexo-booking' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Mode', 'flexo-booking' ); ?></th>
					<td>
						<label class="flexo-feature-choice"><input type="radio" name="<?php echo esc_attr( $name ); ?>[stripe_mode]" value="test" <?php checked( $s['stripe_mode'], 'test' ); ?>> <strong><?php esc_html_e( 'Test mode', 'flexo-booking' ); ?></strong> – <?php esc_html_e( 'no real money; pay with Stripe\'s test card 4242 4242 4242 4242.', 'flexo-booking' ); ?></label>
						<label class="flexo-feature-choice"><input type="radio" name="<?php echo esc_attr( $name ); ?>[stripe_mode]" value="live" <?php checked( $s['stripe_mode'], 'live' ); ?>> <strong><?php esc_html_e( 'Live', 'flexo-booking' ); ?></strong> – <?php esc_html_e( 'real payments from guests.', 'flexo-booking' ); ?></label>
					</td>
				</tr>
				<?php
				$flexo_groups = array(
					'test' => __( 'Test keys', 'flexo-booking' ),
					'live' => __( 'Live keys', 'flexo-booking' ),
				);
				foreach ( $flexo_groups as $flexo_mode => $flexo_label ) :
					?>
					<tr class="flexo-stripe-keys flexo-stripe-keys--<?php echo esc_attr( $flexo_mode ); ?>">
						<th scope="row"><?php echo esc_html( $flexo_label ); ?></th>
						<td>
							<?php
							self::secret_field( $sname, 'stripe_' . $flexo_mode . '_secret_key', $secrets, __( 'Secret key', 'flexo-booking' ), 'test' === $flexo_mode ? 'sk_test_…' : 'sk_live_…' );
							self::secret_field( $sname, 'stripe_' . $flexo_mode . '_webhook_secret', $secrets, __( 'Webhook signing secret', 'flexo-booking' ), 'whsec_…' );
							?>
						</td>
					</tr>
				<?php endforeach; ?>
				<tr>
					<th scope="row"><label for="fb-webhook-url"><?php esc_html_e( 'Webhook', 'flexo-booking' ); ?></label></th>
					<td>
						<input id="fb-webhook-url" type="text" class="large-text code" readonly value="<?php echo esc_attr( Flexo_Booking_Gateway_Stripe::webhook_url() ); ?>" onfocus="this.select()">
						<p class="description">
							<?php esc_html_e( 'In your Stripe Dashboard go to Developers → Webhooks → Add endpoint, paste this address and select these events:', 'flexo-booking' ); ?>
							<code>checkout.session.completed</code>, <code>checkout.session.expired</code>, <code>checkout.session.async_payment_succeeded</code>, <code>checkout.session.async_payment_failed</code>, <code>payment_intent.payment_failed</code>, <code>charge.refunded</code>.
							<?php esc_html_e( 'Then copy the endpoint\'s signing secret (whsec_…) into the field above. Do this once in test mode and once in live mode.', 'flexo-booking' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="fb-hold"><?php esc_html_e( 'Hold the room for', 'flexo-booking' ); ?></label></th>
					<td>
						<input id="fb-hold" type="number" min="20" max="30" class="small-text" name="<?php echo esc_attr( $name ); ?>[hold_minutes]" value="<?php echo esc_attr( $s['hold_minutes'] ); ?>"> <?php esc_html_e( 'minutes while the guest pays', 'flexo-booking' ); ?>
						<p class="description"><?php esc_html_e( 'Nobody else can book the room meanwhile. If the guest doesn\'t pay in time, the room is released automatically. If a payment still arrives after someone else took the room, the booking is not confirmed and you get an alert to refund or offer another room – the room is never double-booked.', 'flexo-booking' ); ?></p>
					</td>
				</tr>
			</table>
			<p class="description"><?php esc_html_e( 'Keys and secrets are stored only on this website and are never included in Import / Export – enter them on each website. Refunds are made in your Stripe account; they appear on the booking automatically.', 'flexo-booking' ); ?></p>
		<?php endif; ?>

		<?php if ( $bank ) : ?>
			<h2><?php esc_html_e( 'Bank transfer', 'flexo-booking' ); ?></h2>
			<p><?php esc_html_e( 'Guests see these details after booking and in their email. With booking requests, they receive them when you confirm the request. Mark the payment as received on the booking when it arrives.', 'flexo-booking' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="fb-bank-ben"><?php esc_html_e( 'Beneficiary', 'flexo-booking' ); ?></label></th>
					<td><input id="fb-bank-ben" type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[bank_beneficiary]" value="<?php echo esc_attr( $s['bank_beneficiary'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. Hotel Sunrise Ltd.', 'flexo-booking' ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="fb-bank-iban">IBAN</label></th>
					<td><input id="fb-bank-iban" type="text" class="regular-text code" name="<?php echo esc_attr( $name ); ?>[bank_iban]" value="<?php echo esc_attr( Flexo_Booking_Gateway_Bank_Transfer::format_iban( $s['bank_iban'] ) ); ?>" placeholder="BG80 BNBG 9661 1020 3456 78"></td>
				</tr>
				<tr>
					<th scope="row"><label for="fb-bank-bic">BIC / SWIFT</label></th>
					<td><input id="fb-bank-bic" type="text" class="regular-text code" name="<?php echo esc_attr( $name ); ?>[bank_bic]" value="<?php echo esc_attr( $s['bank_bic'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="fb-bank-name"><?php esc_html_e( 'Bank', 'flexo-booking' ); ?></label></th>
					<td><input id="fb-bank-name" type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[bank_name]" value="<?php echo esc_attr( $s['bank_name'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="fb-bank-ref"><?php esc_html_e( 'Payment reference', 'flexo-booking' ); ?></label></th>
					<td>
						<input id="fb-bank-ref" type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[bank_reference]" value="<?php echo esc_attr( $s['bank_reference'] ); ?>">
						<p class="description"><?php esc_html_e( 'What the guest writes as the payment reason. Default: the booking reference. You can use {booking_ref}, {guest_name} and {check_in}, e.g. "Booking {booking_ref}".', 'flexo-booking' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="fb-bank-days"><?php esc_html_e( 'Payment deadline', 'flexo-booking' ); ?></label></th>
					<td><input id="fb-bank-days" type="number" min="1" max="60" class="small-text" name="<?php echo esc_attr( $name ); ?>[bank_transfer_days]" value="<?php echo esc_attr( $s['bank_transfer_days'] ); ?>"> <?php esc_html_e( 'days after booking (or after you confirm a request)', 'flexo-booking' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><label for="fb-bank-remind"><?php esc_html_e( 'Reminder', 'flexo-booking' ); ?></label></th>
					<td><input id="fb-bank-remind" type="number" min="0" max="30" class="small-text" name="<?php echo esc_attr( $name ); ?>[bank_transfer_reminder_days]" value="<?php echo esc_attr( $s['bank_transfer_reminder_days'] ); ?>"> <?php esc_html_e( 'days before the deadline (0 = no reminder)', 'flexo-booking' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Not paid in time', 'flexo-booking' ); ?></th>
					<td>
						<input type="hidden" name="<?php echo esc_attr( $name ); ?>[bank_transfer_auto_cancel]" value="0">
						<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[bank_transfer_auto_cancel]" value="1" <?php checked( $s['bank_transfer_auto_cancel'], 1 ); ?>> <?php esc_html_e( 'Cancel the booking automatically after the deadline and email the guest and you', 'flexo-booking' ); ?></label>
						<p class="description"><?php esc_html_e( 'Checked once an hour. When off, unpaid bookings stay "Awaiting payment" until you cancel them.', 'flexo-booking' ); ?></p>
					</td>
				</tr>
			</table>
			<p class="description"><?php esc_html_e( 'Bank details are not included in Import / Export, so a copied website never shows another hotel\'s account.', 'flexo-booking' ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * A key field: saved keys are never shown, only their last characters.
	 */
	private static function secret_field( $option, $key, array $secrets, $label, $placeholder ) {
		$saved = (string) $secrets[ $key ];
		$id    = 'fb-' . str_replace( '_', '-', $key );
		?>
		<p>
			<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label><br>
			<input id="<?php echo esc_attr( $id ); ?>" type="password" class="regular-text code" autocomplete="off" name="<?php echo esc_attr( $option . '[' . $key . ']' ); ?>" value="" placeholder="<?php echo esc_attr( '' !== $saved ? '•••• ' . substr( $saved, -4 ) : $placeholder ); ?>">
			<?php if ( '' !== $saved ) : ?>
				<span class="description"><?php esc_html_e( 'Saved. Leave empty to keep it.', 'flexo-booking' ); ?></span>
				<label><input type="checkbox" name="<?php echo esc_attr( $option . '[' . $key . '_clear]' ); ?>" value="1"> <?php esc_html_e( 'Remove', 'flexo-booking' ); ?></label>
			<?php endif; ?>
		</p>
		<?php
	}

	/**
	 * Warns on the plugin's screens when card payments can't be used.
	 */
	public static function settings_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, 'flexo-booking' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( Flexo_Booking_Features::is_enabled( 'online_payment' ) && 'request' === Flexo_Booking_Features::booking_mode() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Card payments are paused because you use booking requests: guests are never charged before you accept a booking. Switch to Instant booking under Settings → Features to take card payments.', 'flexo-booking' ) . '</p></div>';
		}
	}

	/* ---------------------------------------------------------------------
	 * Bookings list and calendar
	 * ------------------------------------------------------------------- */

	/**
	 * Payment status as a badge (text + icon, not only colour).
	 */
	public static function badge( array $b ) {
		if ( $b['payment_conflict'] ) {
			return '<span class="flexo-badge flexo-badge--conflict">⚠ ' . esc_html__( 'Payment conflict', 'flexo-booking' ) . '</span>';
		}
		if ( '' === $b['payment_method'] || 'blocked' === $b['status'] ) {
			return '';
		}
		if ( 'property' === $b['payment_method'] && '' === $b['payment_status'] ) {
			return '<span class="flexo-badge flexo-badge--pay">' . esc_html__( 'Pays at the property', 'flexo-booking' ) . '</span>';
		}
		$label = Flexo_Booking_Payments::status_label( $b );
		if ( '' === $label ) {
			return '';
		}
		$icon  = 'bank_transfer' === $b['payment_method'] ? '🏦' : '💳';
		$class = in_array( $b['payment_status'], array( 'paid', 'deposit_paid' ), true ) ? 'flexo-badge--paid' : ( in_array( $b['payment_status'], array( 'failed', 'expired' ), true ) ? 'flexo-badge--unpaid' : 'flexo-badge--pay' );
		$extra = '';
		if ( 'awaiting_payment' === $b['status'] && ! empty( $b['payment_due_at'] ) ) {
			/* translators: %s: date */
			$extra = ' · ' . sprintf( __( 'until %s', 'flexo-booking' ), mysql2date( 'd.m.', $b['payment_due_at'] ) );
		}
		return '<span class="flexo-badge ' . esc_attr( $class ) . '">' . $icon . ' ' . esc_html( $label . $extra ) . '</span>';
	}

	/**
	 * Lines for the calendar dialog.
	 *
	 * @return array[] label, value.
	 */
	public static function calendar_lines( array $b ) {
		if ( '' === $b['payment_method'] || 'blocked' === $b['status'] ) {
			return array();
		}
		$balance = Flexo_Booking_Payments::balance( $b );
		$lines   = array( array( __( 'Payment', 'flexo-booking' ), Flexo_Booking_Payments::method_label( $b['payment_method'] ) . ( Flexo_Booking_Payments::status_label( $b ) ? ' – ' . Flexo_Booking_Payments::status_label( $b ) : '' ) ) );
		if ( $balance['net'] > 0 ) {
			$lines[] = array( __( 'Paid', 'flexo-booking' ), Flexo_Booking_Money::format( $balance['net'], $b['currency'] ) );
		}
		if ( $balance['outstanding'] > 0 ) {
			$lines[] = array( __( 'Still to pay', 'flexo-booking' ), Flexo_Booking_Money::format( $balance['outstanding'], $b['currency'] ) );
		}
		if ( 'awaiting_payment' === $b['status'] && ! empty( $b['payment_due_at'] ) ) {
			$lines[] = array( __( 'Pay by', 'flexo-booking' ), mysql2date( 'd.m.Y', $b['payment_due_at'] ) );
		}
		if ( Flexo_Booking_Bookings::HOLD_STATUS === $b['status'] && ! empty( $b['hold_expires_at'] ) ) {
			$lines[] = array( __( 'Room held until', 'flexo-booking' ), mysql2date( 'd.m.Y H:i', $b['hold_expires_at'] ) );
		}
		return $lines;
	}

	/**
	 * Bookings paid while the room was no longer free (top of the bookings list).
	 */
	public static function render_conflicts() {
		$conflicts = Flexo_Booking_Payments::open_conflicts();
		if ( ! $conflicts ) {
			return;
		}
		?>
		<div class="flexo-conflicts flexo-payment-conflicts" id="flexo-payment-conflicts">
			<h2>⚠ <?php esc_html_e( 'Payments received for rooms that are no longer free', 'flexo-booking' ); ?></h2>
			<p><?php esc_html_e( 'These guests paid after their reservation had expired (or was cancelled) and the room was taken meanwhile. They were not confirmed, so nothing is double-booked. Contact the guest: offer another room or dates, or refund the payment in Stripe. Then mark the conflict as resolved.', 'flexo-booking' ); ?></p>
			<table class="widefat">
				<thead><tr>
					<th><?php esc_html_e( 'Booking', 'flexo-booking' ); ?></th>
					<th><?php esc_html_e( 'Guest', 'flexo-booking' ); ?></th>
					<th><?php esc_html_e( 'Stay', 'flexo-booking' ); ?></th>
					<th><?php esc_html_e( 'Paid', 'flexo-booking' ); ?></th>
					<th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'flexo-booking' ); ?></span></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $conflicts as $b ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Flexo_Booking_Admin::MENU_SLUG . '&booking=' . $b['id'] ) ); ?>"><?php echo esc_html( $b['reference'] ); ?></a><div class="flexo-muted"><?php echo esc_html( $b['room_title'] ); ?></div></td>
						<td><?php echo esc_html( $b['guest_name'] ); ?><div><a href="mailto:<?php echo esc_attr( $b['guest_email'] ); ?>"><?php echo esc_html( $b['guest_email'] ); ?></a></div></td>
						<td><?php echo esc_html( Flexo_Booking_Dates::display( $b['check_in'] ) . ' → ' . Flexo_Booking_Dates::display( $b['check_out'] ) ); ?></td>
						<td><?php echo esc_html( Flexo_Booking_Money::format( $b['amount_paid'] - $b['amount_refunded'], $b['currency'] ) ); ?></td>
						<td class="flexo-actions">
							<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Flexo_Booking_Admin::MENU_SLUG . '&booking=' . $b['id'] ) ); ?>"><?php esc_html_e( 'Open booking', 'flexo-booking' ); ?></a>
							<a class="button button-small button-primary" href="<?php echo esc_url( self::action_url( 'resolve', $b['id'] ) ); ?>"><?php esc_html_e( 'Mark as resolved', 'flexo-booking' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Booking details: payments card
	 * ------------------------------------------------------------------- */

	public static function render_booking_card( array $b ) {
		if ( 'blocked' === $b['status'] ) {
			return;
		}
		$history = Flexo_Booking_Payments::history( $b['id'] );
		if ( '' === $b['payment_method'] && ! $history && ! Flexo_Booking_Payments::enabled() ) {
			return;
		}
		$balance  = Flexo_Booking_Payments::balance( $b );
		$currency = $b['currency'];
		$types    = array(
			'payment' => __( 'Payment', 'flexo-booking' ),
			'refund'  => __( 'Refund', 'flexo-booking' ),
			'attempt' => __( 'Failed attempt', 'flexo-booking' ),
		);
		$live     = Flexo_Booking_Payments::gateway( 'stripe' ) && Flexo_Booking_Payments::gateway( 'stripe' )->is_live();
		?>
		<div class="flexo-tools-card flexo-payments-card" id="flexo-payments">
			<h2><?php esc_html_e( 'Payments', 'flexo-booking' ); ?></h2>
			<?php if ( $b['payment_conflict'] ) : ?>
				<div class="notice notice-error inline"><p>
					<strong><?php esc_html_e( 'Payment conflict:', 'flexo-booking' ); ?></strong>
					<?php esc_html_e( 'the guest paid, but the room was no longer free, so the booking was not confirmed. Offer another room or dates (and confirm the booking), or refund the payment in Stripe.', 'flexo-booking' ); ?>
					<a class="button button-small" href="<?php echo esc_url( self::action_url( 'resolve', $b['id'] ) ); ?>"><?php esc_html_e( 'Mark as resolved', 'flexo-booking' ); ?></a>
				</p></div>
			<?php endif; ?>
			<table class="form-table flexo-detail-table" role="presentation">
				<tr><th><?php esc_html_e( 'Payment method', 'flexo-booking' ); ?></th><td><?php echo esc_html( '' !== $b['payment_method'] ? Flexo_Booking_Payments::method_label( $b['payment_method'] ) : '—' ); ?> <?php echo self::badge( $b ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badge(). ?></td></tr>
				<?php if ( $b['amount_due'] > 0 ) : ?>
					<tr><th><?php echo esc_html( $b['amount_due'] + 0.005 < $b['total'] ? __( 'Deposit', 'flexo-booking' ) : __( 'To pay when booking', 'flexo-booking' ) ); ?></th><td><?php echo esc_html( Flexo_Booking_Money::format( $b['amount_due'], $currency ) ); ?></td></tr>
				<?php endif; ?>
				<tr><th><?php esc_html_e( 'Paid', 'flexo-booking' ); ?></th><td><strong><?php echo esc_html( Flexo_Booking_Money::format( $balance['paid'], $currency ) ); ?></strong></td></tr>
				<?php if ( $balance['refunded'] > 0 ) : ?>
					<tr><th><?php esc_html_e( 'Refunded', 'flexo-booking' ); ?></th><td><?php echo esc_html( Flexo_Booking_Money::format( $balance['refunded'], $currency ) ); ?></td></tr>
				<?php endif; ?>
				<tr><th><?php esc_html_e( 'Remaining balance', 'flexo-booking' ); ?></th><td>
					<?php echo esc_html( Flexo_Booking_Money::format( $balance['outstanding'], $currency ) ); ?>
					<?php if ( $balance['overall'] > $balance['total'] ) : ?>
						<div class="flexo-muted"><?php esc_html_e( 'Includes charges paid at the property (e.g. tourist tax).', 'flexo-booking' ); ?></div>
					<?php endif; ?>
				</td></tr>
				<?php if ( 'awaiting_payment' === $b['status'] && $b['payment_due_at'] ) : ?>
					<tr><th><?php esc_html_e( 'Pay by', 'flexo-booking' ); ?></th><td><?php echo esc_html( mysql2date( 'd.m.Y', $b['payment_due_at'] ) ); ?></td></tr>
				<?php endif; ?>
				<?php if ( Flexo_Booking_Bookings::HOLD_STATUS === $b['status'] && $b['hold_expires_at'] ) : ?>
					<tr><th><?php esc_html_e( 'Room held until', 'flexo-booking' ); ?></th><td><?php echo esc_html( mysql2date( 'd.m.Y H:i', $b['hold_expires_at'] ) ); ?></td></tr>
				<?php endif; ?>
			</table>

			<?php if ( $history ) : ?>
				<table class="widefat striped flexo-payment-history">
					<thead><tr>
						<th><?php esc_html_e( 'Date', 'flexo-booking' ); ?></th>
						<th><?php esc_html_e( 'Type', 'flexo-booking' ); ?></th>
						<th><?php esc_html_e( 'Method', 'flexo-booking' ); ?></th>
						<th class="flexo-num"><?php esc_html_e( 'Amount', 'flexo-booking' ); ?></th>
						<th><?php esc_html_e( 'Transaction ID', 'flexo-booking' ); ?></th>
						<th><?php esc_html_e( 'Note', 'flexo-booking' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $history as $row ) : ?>
						<tr class="flexo-payment-row flexo-payment-row--<?php echo esc_attr( $row['type'] ); ?>">
							<td><?php echo esc_html( mysql2date( 'd.m.Y H:i', $row['created_at'] ) ); ?></td>
							<td><?php echo esc_html( isset( $types[ $row['type'] ] ) ? $types[ $row['type'] ] : $row['type'] ); ?></td>
							<td><?php echo esc_html( Flexo_Booking_Payments::method_label( $row['gateway'] ) ); ?></td>
							<td class="flexo-num"><?php echo esc_html( ( 'refund' === $row['type'] ? '−' : '' ) . Flexo_Booking_Money::format( $row['amount'], $row['currency'] ? $row['currency'] : $currency ) ); ?></td>
							<td>
								<?php if ( 'stripe' === $row['gateway'] && 0 === strpos( $row['transaction_id'], 'pi_' ) ) : ?>
									<a href="<?php echo esc_url( 'https://dashboard.stripe.com/' . ( $live ? '' : 'test/' ) . 'payments/' . rawurlencode( $row['transaction_id'] ) ); ?>" target="_blank" rel="noopener"><code><?php echo esc_html( $row['transaction_id'] ); ?></code></a>
								<?php else : ?>
									<code><?php echo esc_html( $row['transaction_id'] ? $row['transaction_id'] : '—' ); ?></code>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $row['note'] ); ?><?php echo $row['created_by'] ? '<div class="flexo-muted">' . esc_html( self::user_name( (int) $row['created_by'] ) ) . '</div>' : ''; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php if ( 'awaiting_payment' === $b['status'] && $balance['due_now'] > 0 ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="flexo-inline-form flexo-payment-form">
					<?php self::form_fields( 'received', $b['id'] ); ?>
					<label><?php esc_html_e( 'Amount received', 'flexo-booking' ); ?> <input type="number" step="0.01" min="0.01" name="amount" class="small-text" value="<?php echo esc_attr( $balance['due_now'] ); ?>" required></label>
					<?php submit_button( __( 'Payment received', 'flexo-booking' ), 'primary', 'submit', false ); ?>
					<p class="description"><?php esc_html_e( 'Confirms the booking and emails the guest.', 'flexo-booking' ); ?></p>
				</form>
			<?php endif; ?>

			<details class="flexo-payment-more">
				<summary><?php esc_html_e( 'Record a payment or refund', 'flexo-booking' ); ?></summary>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="flexo-inline-form flexo-payment-form">
					<?php self::form_fields( 'payment', $b['id'] ); ?>
					<label><?php esc_html_e( 'Payment of', 'flexo-booking' ); ?> <input type="number" step="0.01" min="0.01" name="amount" class="small-text" required></label>
					<select name="method" aria-label="<?php esc_attr_e( 'Method', 'flexo-booking' ); ?>">
						<option value="cash"><?php esc_html_e( 'Cash', 'flexo-booking' ); ?></option>
						<option value="card_terminal"><?php esc_html_e( 'Card at the property', 'flexo-booking' ); ?></option>
						<option value="bank_transfer"><?php esc_html_e( 'Bank transfer', 'flexo-booking' ); ?></option>
						<option value="manual"><?php esc_html_e( 'Other', 'flexo-booking' ); ?></option>
					</select>
					<input type="text" name="note" class="regular-text" placeholder="<?php esc_attr_e( 'Note (optional)', 'flexo-booking' ); ?>">
					<?php submit_button( __( 'Record payment', 'flexo-booking' ), 'secondary', 'submit', false ); ?>
				</form>
				<?php if ( $balance['net'] > 0 ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="flexo-inline-form flexo-payment-form">
						<?php self::form_fields( 'refund', $b['id'] ); ?>
						<label><?php esc_html_e( 'Refund of', 'flexo-booking' ); ?> <input type="number" step="0.01" min="0.01" max="<?php echo esc_attr( $balance['net'] ); ?>" name="amount" class="small-text" required></label>
						<input type="text" name="note" class="regular-text" placeholder="<?php esc_attr_e( 'Note (optional)', 'flexo-booking' ); ?>">
						<?php submit_button( __( 'Record refund', 'flexo-booking' ), 'secondary', 'submit', false ); ?>
					</form>
					<p class="description"><?php esc_html_e( 'This only records money you already paid back (by bank or in your Stripe account). Refunds made in Stripe are recorded automatically.', 'flexo-booking' ); ?></p>
				<?php endif; ?>
			</details>
		</div>
		<?php
	}

	private static function user_name( $user_id ) {
		$user = get_userdata( $user_id );
		/* translators: %s: user name */
		return $user ? sprintf( __( 'by %s', 'flexo-booking' ), $user->display_name ) : '';
	}

	private static function form_fields( $op, $booking_id ) {
		echo '<input type="hidden" name="action" value="flexo_booking_payment">';
		echo '<input type="hidden" name="op" value="' . esc_attr( $op ) . '">';
		echo '<input type="hidden" name="id" value="' . esc_attr( $booking_id ) . '">';
		wp_nonce_field( 'flexo_booking_payment_' . $op . '_' . $booking_id );
	}

	private static function action_url( $op, $booking_id ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=flexo_booking_payment&op=' . $op . '&id=' . (int) $booking_id ), 'flexo_booking_payment_' . $op . '_' . (int) $booking_id );
	}

	/**
	 * Payment received, other payments, refunds, conflict resolved.
	 */
	public static function handle_action() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified below.
		$op = isset( $_REQUEST['op'] ) ? sanitize_key( $_REQUEST['op'] ) : '';
		$id = isset( $_REQUEST['id'] ) ? absint( $_REQUEST['id'] ) : 0;
		// phpcs:enable
		check_admin_referer( 'flexo_booking_payment_' . $op . '_' . $id );
		if ( ! current_user_can( Flexo_Booking_Admin::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to manage bookings.', 'flexo-booking' ) );
		}
		$back   = admin_url( 'admin.php?page=' . Flexo_Booking_Admin::MENU_SLUG . '&booking=' . $id );
		$amount = isset( $_POST['amount'] ) ? Flexo_Booking_Money::round( (float) str_replace( ',', '.', sanitize_text_field( wp_unslash( $_POST['amount'] ) ) ) ) : 0.0;
		$note   = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';
		$result = true;

		switch ( $op ) {
			case 'received':
			case 'payment':
				if ( $amount <= 0 ) {
					$result = new WP_Error( 'flexo_amount', __( 'Please enter the amount received.', 'flexo-booking' ) );
					break;
				}
				$method = 'received' === $op ? 'bank_transfer' : ( isset( $_POST['method'] ) ? sanitize_key( $_POST['method'] ) : 'manual' );
				$method = in_array( $method, array( 'cash', 'card_terminal', 'bank_transfer', 'manual' ), true ) ? $method : 'manual';
				$result = Flexo_Booking_Payments::complete( $id, $amount, $method, '', array( 'note' => $note ) );
				break;
			case 'refund':
				$result = Flexo_Booking_Payments::refund( $id, $amount, 'manual', '', $note );
				break;
			case 'resolve':
				Flexo_Booking_Payments::resolve_conflict( $id );
				break;
			default:
				$result = new WP_Error( 'flexo_invalid', __( 'Invalid action.', 'flexo-booking' ) );
		}
		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( 'flexo_error', rawurlencode( $result->get_error_message() ), $back ) . '#flexo-payments' );
			exit;
		}
		if ( is_array( $result ) && 'conflict' === $result['result'] ) {
			wp_safe_redirect( add_query_arg( 'flexo_error', rawurlencode( __( 'The payment was recorded, but the room is no longer free for these dates, so the booking was not confirmed.', 'flexo-booking' ) ), $back ) . '#flexo-payments' );
			exit;
		}
		$to = $back . '#flexo-payments';
		if ( 'resolve' === $op && false === strpos( (string) wp_get_referer(), 'booking=' ) ) {
			$to = admin_url( 'admin.php?page=' . Flexo_Booking_Admin::MENU_SLUG );
		}
		wp_safe_redirect( add_query_arg( 'flexo_msg', 'resolve' === $op ? 'updated' : 'payment', $to ) );
		exit;
	}
}
