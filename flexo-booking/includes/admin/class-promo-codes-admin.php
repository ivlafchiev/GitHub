<?php
/**
 * Bookings → Promo codes (feature "promo_codes").
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Promo_Codes_Admin {

	const SLUG = 'flexo-booking-promo-codes';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 10 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_flexo_booking_save_promo', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_flexo_booking_delete_promo', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_flexo_booking_toggle_promo', array( __CLASS__, 'handle_toggle' ) );
	}

	public static function menu() {
		if ( Flexo_Booking_Promo_Codes::enabled() ) {
			add_submenu_page( Flexo_Booking_Admin::MENU_SLUG, __( 'Promo codes', 'flexo-booking' ), __( 'Promo codes', 'flexo-booking' ), Flexo_Booking_Admin::capability(), self::SLUG, array( __CLASS__, 'render' ) );
		}
	}

	public static function assets( $hook ) {
		if ( false !== strpos( $hook, self::SLUG ) ) {
			Flexo_Booking_Seasons_Admin::enqueue_datepicker();
		}
	}

	private static function page_url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	private static function check_access( $nonce_action ) {
		check_admin_referer( $nonce_action );
		if ( ! current_user_can( Flexo_Booking_Admin::capability() ) || ! Flexo_Booking_Promo_Codes::enabled() ) {
			wp_die( esc_html__( 'You are not allowed to manage promo codes.', 'flexo-booking' ) );
		}
	}

	private static function action_url( $action, $id, $extra = array() ) {
		return wp_nonce_url( add_query_arg( array_merge( array( 'action' => $action, 'id' => $id ), $extra ), admin_url( 'admin-post.php' ) ), $action . '_' . $id );
	}

	/**
	 * Plain-language summary of a code's conditions.
	 *
	 * @return string[]
	 */
	public static function conditions( array $promo, array $room_titles, array $plan_names ) {
		$out = array();
		if ( $promo['book_from'] || $promo['book_to'] ) {
			$out[] = sprintf(
				/* translators: 1: from date, 2: until date */
				__( 'Book %1$s – %2$s', 'flexo-booking' ),
				$promo['book_from'] ? Flexo_Booking_Dates::display( $promo['book_from'] ) : '…',
				$promo['book_to'] ? Flexo_Booking_Dates::display( $promo['book_to'] ) : '…'
			);
		}
		if ( $promo['stay_from'] || $promo['stay_to'] ) {
			$out[] = sprintf(
				/* translators: 1: first night, 2: last night */
				__( 'Stays (nights) %1$s – %2$s', 'flexo-booking' ),
				$promo['stay_from'] ? Flexo_Booking_Dates::display( $promo['stay_from'] ) : '…',
				$promo['stay_to'] ? Flexo_Booking_Dates::display( $promo['stay_to'] ) : '…'
			);
		}
		if ( $promo['min_amount'] ) {
			/* translators: %s: amount */
			$out[] = sprintf( __( 'From %s', 'flexo-booking' ), Flexo_Booking_Money::format( $promo['min_amount'] ) );
		}
		if ( $promo['min_nights'] ) {
			/* translators: %d: nights */
			$out[] = sprintf( _n( 'At least %d night', 'At least %d nights', $promo['min_nights'], 'flexo-booking' ), $promo['min_nights'] );
		}
		if ( $promo['room_ids'] ) {
			$names = array();
			foreach ( $promo['room_ids'] as $id ) {
				$names[] = isset( $room_titles[ $id ] ) ? $room_titles[ $id ] : '#' . $id;
			}
			/* translators: %s: room names */
			$out[] = sprintf( __( 'Rooms: %s', 'flexo-booking' ), implode( ', ', $names ) );
		}
		if ( $promo['rate_plan_ids'] ) {
			$names = array();
			foreach ( $promo['rate_plan_ids'] as $id ) {
				$names[] = isset( $plan_names[ $id ] ) ? $plan_names[ $id ] : '#' . $id;
			}
			/* translators: %s: rate plan names */
			$out[] = sprintf( __( 'Rate plans: %s', 'flexo-booking' ), implode( ', ', $names ) );
		}
		return $out;
	}

	public static function render() {
		if ( ! current_user_can( Flexo_Booking_Admin::capability() ) ) {
			return;
		}
		$codes  = Flexo_Booking_Promo_Codes::all();
		$uses   = Flexo_Booking_Promo_Codes::uses_map();
		$rooms  = Flexo_Booking_Rooms::all( array( 'publish', 'draft', 'private', 'pending', 'future' ) );
		$titles = array();
		foreach ( $rooms as $room ) {
			$titles[ $room->ID ] = get_the_title( $room );
		}
		$plans = array();
		foreach ( Flexo_Booking_Rate_Plans::all() as $plan ) {
			$plans[ $plan['id'] ] = $plan['name'];
		}
		$today = wp_date( 'Y-m-d' );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$edit_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
		$adding  = isset( $_GET['add'] );
		// phpcs:enable
		$editing = $edit_id ? Flexo_Booking_Promo_Codes::get( $edit_id ) : null;
		$form    = get_transient( 'flexo_promo_form_' . get_current_user_id() );
		delete_transient( 'flexo_promo_form_' . get_current_user_id() );
		if ( ! is_array( $form ) && $editing ) {
			$form = $editing;
			foreach ( array( 'book_from', 'book_to', 'stay_from', 'stay_to' ) as $field ) {
				$form[ $field ] = $editing[ $field ] ? Flexo_Booking_Dates::display( $editing[ $field ] ) : '';
			}
		}
		$form      = wp_parse_args(
			is_array( $form ) ? $form : array(),
			array(
				'id'             => 0,
				'code'           => '',
				'description'    => '',
				'active'         => 1,
				'discount_type'  => 'percent',
				'discount_value' => '',
				'book_from'      => '',
				'book_to'        => '',
				'stay_from'      => '',
				'stay_to'        => '',
				'min_amount'     => '',
				'min_nights'     => '',
				'max_uses'       => '',
				'room_ids'       => array(),
				'rate_plan_ids'  => array(),
			)
		);
		$form['room_ids']      = array_map( 'absint', (array) $form['room_ids'] );
		$form['rate_plan_ids'] = array_map( 'absint', (array) $form['rate_plan_ids'] );
		$show_form             = $editing || $adding || '' !== $form['code'] || ! $codes;
		?>
		<div class="wrap flexo-admin flexo-promos">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Promo codes', 'flexo-booking' ); ?></h1>
			<a class="page-title-action" href="<?php echo esc_url( self::page_url( array( 'add' => 1 ) ) . '#flexo-promo-form' ); ?>"><?php esc_html_e( 'Add promo code', 'flexo-booking' ); ?></a>
			<hr class="wp-header-end">
			<p class="description"><?php esc_html_e( 'Give guests a discount with a code such as DIRECT10. The discount applies to the room price and rate plan, never to the tourist tax. Guests enter the code before they send their booking; one code per booking.', 'flexo-booking' ); ?></p>
			<?php Flexo_Booking_Seasons_Admin::notices(); ?>

			<table class="widefat striped flexo-promos-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Code', 'flexo-booking' ); ?></th>
						<th><?php esc_html_e( 'Discount', 'flexo-booking' ); ?></th>
						<th><?php esc_html_e( 'Conditions', 'flexo-booking' ); ?></th>
						<th><?php esc_html_e( 'Used', 'flexo-booking' ); ?></th>
						<th><?php esc_html_e( 'Status', 'flexo-booking' ); ?></th>
						<th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'flexo-booking' ); ?></span></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! $codes ) : ?>
						<tr><td colspan="6"><?php esc_html_e( 'No promo codes yet.', 'flexo-booking' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $codes as $promo ) : ?>
						<?php
						$used    = isset( $uses[ $promo['id'] ] ) ? $uses[ $promo['id'] ] : 0;
						$expired = $promo['book_to'] && $promo['book_to'] < $today;
						$full    = $promo['max_uses'] && $used >= $promo['max_uses'];
						$conds   = self::conditions( $promo, $titles, $plans );
						?>
						<tr class="<?php echo ( ! $promo['active'] || $expired ) ? 'flexo-past' : ''; ?>">
							<td>
								<strong class="flexo-code"><?php echo esc_html( $promo['code'] ); ?></strong>
								<?php if ( $promo['description'] ) : ?>
									<div class="flexo-muted"><?php echo esc_html( $promo['description'] ); ?></div>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( Flexo_Booking_Promo_Codes::describe( $promo ) ); ?></td>
							<td><?php echo $conds ? esc_html( implode( ' · ', $conds ) ) : '<span class="flexo-muted">' . esc_html__( 'Any stay', 'flexo-booking' ) . '</span>'; ?></td>
							<td>
								<?php
								echo esc_html( $promo['max_uses'] ? sprintf( /* translators: 1: uses, 2: limit */ __( '%1$d of %2$d', 'flexo-booking' ), $used, $promo['max_uses'] ) : (string) $used );
								?>
								<div class="flexo-muted"><?php esc_html_e( 'confirmed bookings', 'flexo-booking' ); ?></div>
							</td>
							<td>
								<?php if ( ! $promo['active'] ) : ?>
									<span class="flexo-status flexo-status--cancelled">○ <?php esc_html_e( 'Switched off', 'flexo-booking' ); ?></span>
								<?php elseif ( $expired ) : ?>
									<span class="flexo-status flexo-status--cancelled">⌛ <?php esc_html_e( 'Expired', 'flexo-booking' ); ?></span>
								<?php elseif ( $full ) : ?>
									<span class="flexo-status flexo-status--pending">■ <?php esc_html_e( 'Fully used', 'flexo-booking' ); ?></span>
								<?php else : ?>
									<span class="flexo-status flexo-status--confirmed">✓ <?php esc_html_e( 'Active', 'flexo-booking' ); ?></span>
								<?php endif; ?>
							</td>
							<td class="flexo-actions">
								<a class="button button-small" href="<?php echo esc_url( self::page_url( array( 'edit' => $promo['id'] ) ) . '#flexo-promo-form' ); ?>"><?php esc_html_e( 'Edit', 'flexo-booking' ); ?></a>
								<a class="button button-small" href="<?php echo esc_url( self::action_url( 'flexo_booking_toggle_promo', $promo['id'], array( 'active' => $promo['active'] ? 0 : 1 ) ) ); ?>"><?php echo $promo['active'] ? esc_html__( 'Switch off', 'flexo-booking' ) : esc_html__( 'Switch on', 'flexo-booking' ); ?></a>
								<a class="button button-small button-link-delete" href="<?php echo esc_url( self::action_url( 'flexo_booking_delete_promo', $promo['id'] ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this promo code? Bookings made with it keep their discount.', 'flexo-booking' ) ); ?>');"><?php esc_html_e( 'Delete', 'flexo-booking' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $show_form ) : ?>
			<div class="flexo-tools-card" id="flexo-promo-form">
				<h2><?php echo $form['id'] ? esc_html__( 'Edit promo code', 'flexo-booking' ) : esc_html__( 'Add a promo code', 'flexo-booking' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="flexo_booking_save_promo">
					<input type="hidden" name="id" value="<?php echo esc_attr( $form['id'] ); ?>">
					<?php wp_nonce_field( 'flexo_booking_save_promo' ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="fpc-code"><?php esc_html_e( 'Code', 'flexo-booking' ); ?></label></th>
							<td>
								<input id="fpc-code" type="text" name="code" class="regular-text flexo-code" required value="<?php echo esc_attr( $form['code'] ); ?>" placeholder="SUMMER20" maxlength="50">
								<p class="description"><?php esc_html_e( 'Letters, numbers, - and _. Guests can type it in small or capital letters.', 'flexo-booking' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="fpc-desc"><?php esc_html_e( 'Note (only for you)', 'flexo-booking' ); ?></label></th>
							<td><input id="fpc-desc" type="text" name="description" class="large-text" value="<?php echo esc_attr( $form['description'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. Newsletter summer campaign', 'flexo-booking' ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Discount', 'flexo-booking' ); ?></th>
							<td>
								<input type="text" inputmode="decimal" name="discount_value" class="small-text" required value="<?php echo esc_attr( '' === $form['discount_value'] ? '' : Flexo_Booking_Children::percent_text( $form['discount_value'] ) ); ?>" aria-label="<?php esc_attr_e( 'Discount amount', 'flexo-booking' ); ?>">
								<select name="discount_type" aria-label="<?php esc_attr_e( 'Discount type', 'flexo-booking' ); ?>">
									<option value="percent" <?php selected( $form['discount_type'], 'percent' ); ?>><?php esc_html_e( '% off', 'flexo-booking' ); ?></option>
									<option value="fixed" <?php selected( $form['discount_type'], 'fixed' ); ?>><?php echo esc_html( sprintf( /* translators: %s: currency */ __( '%s off the booking', 'flexo-booking' ), Flexo_Booking_Money::currency() ) ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Can be used when booking', 'flexo-booking' ); ?></th>
							<td>
								<label><?php esc_html_e( 'From', 'flexo-booking' ); ?> <input type="text" name="book_from" class="flexo-date" autocomplete="off" placeholder="DD.MM.YYYY" value="<?php echo esc_attr( $form['book_from'] ); ?>"></label>
								<label><?php esc_html_e( 'Until', 'flexo-booking' ); ?> <input type="text" name="book_to" class="flexo-date" autocomplete="off" placeholder="DD.MM.YYYY" value="<?php echo esc_attr( $form['book_to'] ); ?>"></label>
								<p class="description"><?php esc_html_e( 'Optional. The day the guest makes the booking. After "until" the code has expired.', 'flexo-booking' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'For stays', 'flexo-booking' ); ?></th>
							<td>
								<label><?php esc_html_e( 'From', 'flexo-booking' ); ?> <input type="text" name="stay_from" class="flexo-date" autocomplete="off" placeholder="DD.MM.YYYY" value="<?php echo esc_attr( $form['stay_from'] ); ?>"></label>
								<label><?php esc_html_e( 'To (last night)', 'flexo-booking' ); ?> <input type="text" name="stay_to" class="flexo-date" autocomplete="off" placeholder="DD.MM.YYYY" value="<?php echo esc_attr( $form['stay_to'] ); ?>"></label>
								<p class="description"><?php esc_html_e( 'Optional. Every night of the stay must be inside these dates.', 'flexo-booking' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Conditions', 'flexo-booking' ); ?></th>
							<td>
								<p><label><?php esc_html_e( 'Minimum booking amount', 'flexo-booking' ); ?> <input type="text" inputmode="decimal" name="min_amount" class="small-text" value="<?php echo esc_attr( null === $form['min_amount'] ? '' : $form['min_amount'] ); ?>"> <?php echo esc_html( Flexo_Booking_Money::currency() ); ?></label></p>
								<p><label><?php esc_html_e( 'Minimum nights', 'flexo-booking' ); ?> <input type="number" min="0" name="min_nights" class="small-text" value="<?php echo esc_attr( null === $form['min_nights'] ? '' : $form['min_nights'] ); ?>"></label></p>
								<p><label><?php esc_html_e( 'Can be used', 'flexo-booking' ); ?> <input type="number" min="0" name="max_uses" class="small-text" value="<?php echo esc_attr( null === $form['max_uses'] ? '' : $form['max_uses'] ); ?>"> <?php esc_html_e( 'times in total', 'flexo-booking' ); ?></label></p>
								<p class="description"><?php esc_html_e( 'All optional – leave empty for no limit. The minimum amount is the room price with the rate plan, before the discount. Only confirmed bookings count as uses; a cancelled booking gives its use back.', 'flexo-booking' ); ?></p>
							</td>
						</tr>
						<?php if ( $rooms ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Only for these rooms', 'flexo-booking' ); ?></th>
							<td>
								<?php foreach ( $rooms as $room ) : ?>
									<label class="flexo-check"><input type="checkbox" name="room_ids[]" value="<?php echo esc_attr( $room->ID ); ?>" <?php checked( in_array( $room->ID, $form['room_ids'], true ) ); ?>> <?php echo esc_html( get_the_title( $room ) ); ?></label>
								<?php endforeach; ?>
								<p class="description"><?php esc_html_e( 'Leave all unticked for every room.', 'flexo-booking' ); ?></p>
							</td>
						</tr>
						<?php endif; ?>
						<?php if ( Flexo_Booking_Rate_Plans::enabled() && $plans ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Only for these rate plans', 'flexo-booking' ); ?></th>
							<td>
								<?php foreach ( $plans as $plan_id => $plan_name ) : ?>
									<label class="flexo-check"><input type="checkbox" name="rate_plan_ids[]" value="<?php echo esc_attr( $plan_id ); ?>" <?php checked( in_array( $plan_id, $form['rate_plan_ids'], true ) ); ?>> <?php echo esc_html( $plan_name ); ?></label>
								<?php endforeach; ?>
								<p class="description"><?php esc_html_e( 'Leave all unticked for every rate plan.', 'flexo-booking' ); ?></p>
							</td>
						</tr>
						<?php endif; ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Status', 'flexo-booking' ); ?></th>
							<td><label><input type="checkbox" name="active" value="1" <?php checked( ! empty( $form['active'] ) ); ?>> <?php esc_html_e( 'Guests can use this code', 'flexo-booking' ); ?></label></td>
						</tr>
					</table>
					<?php submit_button( $form['id'] ? __( 'Save promo code', 'flexo-booking' ) : __( 'Add promo code', 'flexo-booking' ), 'primary', 'submit', false ); ?>
					<?php if ( $codes ) : ?>
						<a class="button" href="<?php echo esc_url( self::page_url() ); ?>"><?php esc_html_e( 'Cancel', 'flexo-booking' ); ?></a>
					<?php endif; ?>
				</form>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function handle_save() {
		self::check_access( 'flexo_booking_save_promo' );
		$id    = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$input = array();
		foreach ( array( 'code', 'description', 'discount_type', 'discount_value', 'book_from', 'book_to', 'stay_from', 'stay_to', 'min_amount', 'min_nights', 'max_uses' ) as $field ) {
			$input[ $field ] = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
		}
		$input['active']        = empty( $_POST['active'] ) ? 0 : 1;
		$input['room_ids']      = isset( $_POST['room_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['room_ids'] ) ) : array();
		$input['rate_plan_ids'] = isset( $_POST['rate_plan_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['rate_plan_ids'] ) ) : array();

		// Keep rate-plan limits when the rate-plan list is hidden (feature off).
		if ( $id && ! isset( $_POST['rate_plan_ids'] ) && ! Flexo_Booking_Rate_Plans::enabled() ) {
			$existing               = Flexo_Booking_Promo_Codes::get( $id );
			$input['rate_plan_ids'] = $existing ? $existing['rate_plan_ids'] : array();
		}

		$result = Flexo_Booking_Promo_Codes::save( $input, $id );
		if ( is_wp_error( $result ) ) {
			set_transient( 'flexo_promo_form_' . get_current_user_id(), array_merge( $input, array( 'id' => $id ) ), 120 );
			wp_safe_redirect( self::page_url( array( 'flexo_error' => rawurlencode( $result->get_error_message() ) ) ) . '#flexo-promo-form' );
			exit;
		}
		wp_safe_redirect( self::page_url( array( 'flexo_msg' => 'saved' ) ) );
		exit;
	}

	public static function handle_delete() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		self::check_access( 'flexo_booking_delete_promo_' . $id );
		Flexo_Booking_Promo_Codes::delete( $id );
		wp_safe_redirect( self::page_url( array( 'flexo_msg' => 'deleted' ) ) );
		exit;
	}

	public static function handle_toggle() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		self::check_access( 'flexo_booking_toggle_promo_' . $id );
		Flexo_Booking_Promo_Codes::set_active( $id, ! empty( $_GET['active'] ) );
		wp_safe_redirect( self::page_url( array( 'flexo_msg' => 'saved' ) ) );
		exit;
	}
}
