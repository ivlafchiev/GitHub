<?php
/**
 * Bookings → Rate plans (feature "rate_plans"): the ways rooms are sold,
 * e.g. "Breakfast Included" or "Non-refundable", and which rooms offer them.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Rate_Plans_Admin {

	const SLUG = 'flexo-booking-rate-plans';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 10 );
		add_action( 'admin_post_flexo_booking_save_rate_plan', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_flexo_booking_delete_rate_plan', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_flexo_booking_toggle_rate_plan', array( __CLASS__, 'handle_toggle' ) );
		add_action( 'admin_post_flexo_booking_rate_plan_presets', array( __CLASS__, 'handle_presets' ) );
	}

	public static function menu() {
		if ( Flexo_Booking_Rate_Plans::enabled() ) {
			add_submenu_page( Flexo_Booking_Admin::MENU_SLUG, __( 'Rate plans', 'flexo-booking' ), __( 'Rate plans', 'flexo-booking' ), Flexo_Booking_Admin::capability(), self::SLUG, array( __CLASS__, 'render' ) );
		}
	}

	private static function page_url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	private static function rooms() {
		return Flexo_Booking_Rooms::all( array( 'publish', 'draft', 'private', 'pending', 'future' ) );
	}

	private static function check_access( $nonce_action ) {
		check_admin_referer( $nonce_action );
		if ( ! current_user_can( Flexo_Booking_Admin::capability() ) || ! Flexo_Booking_Rate_Plans::enabled() ) {
			wp_die( esc_html__( 'You are not allowed to manage rate plans.', 'flexo-booking' ) );
		}
	}

	private static function action_url( $action, $id, $extra = array() ) {
		return wp_nonce_url( add_query_arg( array_merge( array( 'action' => $action, 'id' => $id ), $extra ), admin_url( 'admin-post.php' ) ), $action . '_' . $id );
	}

	public static function render() {
		if ( ! current_user_can( Flexo_Booking_Admin::capability() ) ) {
			return;
		}
		$plans   = Flexo_Booking_Rate_Plans::all();
		$rooms   = self::rooms();
		$titles  = array();
		$offered = array();
		foreach ( $rooms as $room ) {
			$titles[ $room->ID ] = get_the_title( $room );
			foreach ( Flexo_Booking_Rate_Plans::room_assignments( $room->ID ) as $plan_id => $override ) {
				$offered[ $plan_id ][ $room->ID ] = $override;
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$edit_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$adding  = isset( $_GET['add'] );
		$editing = $edit_id ? Flexo_Booking_Rate_Plans::get( $edit_id ) : null;
		$form    = get_transient( 'flexo_plan_form_' . get_current_user_id() );
		delete_transient( 'flexo_plan_form_' . get_current_user_id() );
		if ( ! is_array( $form ) && $editing ) {
			$form          = $editing;
			$form['rooms'] = array();
			foreach ( isset( $offered[ $editing['id'] ] ) ? $offered[ $editing['id'] ] : array() as $room_id => $override ) {
				$form['rooms'][ $room_id ] = array(
					'on'       => 1,
					'override' => null === $override ? '' : $override,
				);
			}
		}
		$form = wp_parse_args(
			is_array( $form ) ? $form : array(),
			array(
				'id'                  => 0,
				'name'                => '',
				'description'         => '',
				'adjustment_type'     => 'per_guest_night',
				'adjustment_value'    => '',
				'refundable'          => 1,
				'cancellation_policy' => '',
				'active'              => 1,
				'sort_order'          => count( $plans ) + 1,
				'rooms'               => array(),
			)
		);
		$show_form = $editing || $adding || ! empty( $form['name'] ) || ! empty( $form['id'] );
		$missing   = array_diff( array_keys( Flexo_Booking_Rate_Plans::presets() ), wp_list_pluck( $plans, 'preset' ) );
		?>
		<div class="wrap flexo-admin flexo-plans">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Rate plans', 'flexo-booking' ); ?></h1>
			<a class="page-title-action" href="<?php echo esc_url( self::page_url( array( 'add' => 1 ) ) . '#flexo-plan-form' ); ?>"><?php esc_html_e( 'Add rate plan', 'flexo-booking' ); ?></a>
			<hr class="wp-header-end">
			<p class="description"><?php esc_html_e( 'Rate plans are the different ways you sell a room – for example with breakfast, half board, or a cheaper non-refundable price. Create only the combinations you really sell, then choose which rooms offer them. Rooms without a rate plan are booked at their normal price.', 'flexo-booking' ); ?></p>
			<p class="description"><?php esc_html_e( 'Guests choose a plan after choosing a room. If a room offers only one plan, it is used automatically.', 'flexo-booking' ); ?></p>
			<?php Flexo_Booking_Seasons_Admin::notices(); ?>

			<table class="widefat striped flexo-plans-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Rate plan', 'flexo-booking' ); ?></th>
						<th><?php esc_html_e( 'Price change', 'flexo-booking' ); ?></th>
						<th><?php esc_html_e( 'Cancellation', 'flexo-booking' ); ?></th>
						<th><?php esc_html_e( 'Offered for', 'flexo-booking' ); ?></th>
						<th><?php esc_html_e( 'Status', 'flexo-booking' ); ?></th>
						<th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'flexo-booking' ); ?></span></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! $plans ) : ?>
						<tr><td colspan="6"><?php esc_html_e( 'No rate plans yet. Guests book rooms at their normal price.', 'flexo-booking' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $plans as $plan ) : ?>
						<?php $plan_rooms = isset( $offered[ $plan['id'] ] ) ? $offered[ $plan['id'] ] : array(); ?>
						<tr class="<?php echo $plan['active'] ? '' : 'flexo-past'; ?>">
							<td>
								<strong><?php echo esc_html( $plan['name'] ); ?></strong>
								<?php if ( $plan['preset'] ) : ?>
									<span class="flexo-badge"><?php esc_html_e( 'Ready-made', 'flexo-booking' ); ?></span>
								<?php endif; ?>
								<?php if ( $plan['description'] ) : ?>
									<div class="flexo-muted"><?php echo esc_html( $plan['description'] ); ?></div>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( Flexo_Booking_Rate_Plans::describe_adjustment( $plan['adjustment_type'], $plan['adjustment_value'] ) ); ?></td>
							<td><?php echo esc_html( Flexo_Booking_Rate_Plans::refundable_label( $plan['refundable'] ) ); ?></td>
							<td>
								<?php if ( ! $plan_rooms ) : ?>
									<span class="flexo-muted"><?php esc_html_e( 'No rooms yet', 'flexo-booking' ); ?></span>
								<?php else : ?>
									<?php
									$names = array();
									foreach ( $plan_rooms as $room_id => $override ) {
										$names[] = ( isset( $titles[ $room_id ] ) ? $titles[ $room_id ] : '#' . $room_id ) . ( null === $override ? '' : ' (' . Flexo_Booking_Rate_Plans::describe_adjustment( $plan['adjustment_type'], $override ) . ')' );
									}
									echo esc_html( implode( ', ', $names ) );
									?>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( $plan['active'] ) : ?>
									<span class="flexo-status flexo-status--confirmed">✓ <?php esc_html_e( 'Offered to guests', 'flexo-booking' ); ?></span>
								<?php else : ?>
									<span class="flexo-status flexo-status--cancelled">○ <?php esc_html_e( 'Switched off', 'flexo-booking' ); ?></span>
								<?php endif; ?>
							</td>
							<td class="flexo-actions">
								<a class="button button-small" href="<?php echo esc_url( self::page_url( array( 'edit' => $plan['id'] ) ) . '#flexo-plan-form' ); ?>"><?php esc_html_e( 'Edit', 'flexo-booking' ); ?></a>
								<a class="button button-small" href="<?php echo esc_url( self::action_url( 'flexo_booking_toggle_rate_plan', $plan['id'], array( 'active' => $plan['active'] ? 0 : 1 ) ) ); ?>"><?php echo $plan['active'] ? esc_html__( 'Switch off', 'flexo-booking' ) : esc_html__( 'Switch on', 'flexo-booking' ); ?></a>
								<a class="button button-small button-link-delete" href="<?php echo esc_url( self::action_url( 'flexo_booking_delete_rate_plan', $plan['id'] ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this rate plan? Existing bookings keep their plan and price.', 'flexo-booking' ) ); ?>');"><?php esc_html_e( 'Delete', 'flexo-booking' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $missing ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="flexo-inline-form" style="margin-top:8px">
					<input type="hidden" name="action" value="flexo_booking_rate_plan_presets">
					<?php wp_nonce_field( 'flexo_booking_rate_plan_presets' ); ?>
					<?php submit_button( __( 'Add the ready-made plans', 'flexo-booking' ), 'secondary small', 'submit', false ); ?>
					<span class="description"><?php esc_html_e( 'Room Only, Breakfast Included, Half Board, Full Board, All Inclusive and Non-refundable – added switched off, for you to edit.', 'flexo-booking' ); ?></span>
				</form>
			<?php endif; ?>

			<?php if ( $show_form ) : ?>
			<div class="flexo-tools-card" id="flexo-plan-form">
				<h2><?php echo $form['id'] ? esc_html__( 'Edit rate plan', 'flexo-booking' ) : esc_html__( 'Add a rate plan', 'flexo-booking' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="flexo_booking_save_rate_plan">
					<input type="hidden" name="id" value="<?php echo esc_attr( $form['id'] ); ?>">
					<?php wp_nonce_field( 'flexo_booking_save_rate_plan' ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="fp-name"><?php esc_html_e( 'Name', 'flexo-booking' ); ?></label></th>
							<td><input id="fp-name" type="text" name="name" class="regular-text" required value="<?php echo esc_attr( $form['name'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. Breakfast Included', 'flexo-booking' ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="fp-desc"><?php esc_html_e( 'Description for guests', 'flexo-booking' ); ?></label></th>
							<td><textarea id="fp-desc" name="description" rows="2" class="large-text"><?php echo esc_textarea( $form['description'] ); ?></textarea></td>
						</tr>
						<tr>
							<th scope="row"><label for="fp-type"><?php esc_html_e( 'Price change', 'flexo-booking' ); ?></label></th>
							<td>
								<select id="fp-type" name="adjustment_type">
									<?php foreach ( Flexo_Booking_Rate_Plans::types() as $value => $label ) : ?>
										<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $form['adjustment_type'], $value ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
								<input type="text" inputmode="decimal" name="adjustment_value" class="small-text" value="<?php echo esc_attr( '' === $form['adjustment_value'] ? '' : Flexo_Booking_Children::percent_text( $form['adjustment_value'] ) ); ?>" aria-label="<?php esc_attr_e( 'Amount', 'flexo-booking' ); ?>" placeholder="0">
								<p class="description"><?php esc_html_e( 'Added to the room price. Use a minus sign for a lower price, e.g. -10 for 10% off a non-refundable rate. Percentages apply to the room price only.', 'flexo-booking' ); ?></p>
								<p class="description">
									<?php
									/* translators: 1: child price rules */
									echo Flexo_Booking_Children::enabled() ? esc_html( sprintf( __( '"Per guest per night" is charged for each adult; children pay by age (%s).', 'flexo-booking' ), Flexo_Booking_Children::describe( Flexo_Booking_Children::global_rules() ) ) ) : esc_html__( '"Per guest per night" is charged for each adult and child.', 'flexo-booking' );
									?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Cancellation', 'flexo-booking' ); ?></th>
							<td>
								<label><input type="checkbox" name="refundable" value="1" <?php checked( ! empty( $form['refundable'] ) ); ?>> <?php esc_html_e( 'Refundable', 'flexo-booking' ); ?></label>
								<p><textarea name="cancellation_policy" rows="2" class="large-text" aria-label="<?php esc_attr_e( 'Cancellation policy', 'flexo-booking' ); ?>" placeholder="<?php esc_attr_e( 'e.g. Free cancellation up to 7 days before arrival.', 'flexo-booking' ); ?>"><?php echo esc_textarea( $form['cancellation_policy'] ); ?></textarea></p>
								<p class="description"><?php esc_html_e( 'Shown to guests before they book, and in their emails.', 'flexo-booking' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Rooms', 'flexo-booking' ); ?></th>
							<td>
								<?php if ( ! $rooms ) : ?>
									<p class="description"><?php esc_html_e( 'Add rooms first.', 'flexo-booking' ); ?></p>
								<?php else : ?>
								<table class="widefat flexo-plan-rooms">
									<thead><tr>
										<th><?php esc_html_e( 'Offer for this room', 'flexo-booking' ); ?></th>
										<th><?php esc_html_e( 'Different amount for this room (optional)', 'flexo-booking' ); ?></th>
									</tr></thead>
									<tbody>
									<?php foreach ( $rooms as $room ) : ?>
										<?php $state = isset( $form['rooms'][ $room->ID ] ) ? $form['rooms'][ $room->ID ] : array( 'on' => 0, 'override' => '' ); ?>
										<tr>
											<td><label><input type="checkbox" name="rooms[<?php echo esc_attr( $room->ID ); ?>][on]" value="1" <?php checked( ! empty( $state['on'] ) ); ?>> <?php echo esc_html( get_the_title( $room ) ); ?></label></td>
											<td><input type="text" inputmode="decimal" class="small-text" name="rooms[<?php echo esc_attr( $room->ID ); ?>][override]" value="<?php echo esc_attr( '' === $state['override'] || null === $state['override'] ? '' : Flexo_Booking_Children::percent_text( $state['override'] ) ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: room name */ __( 'Different amount for %s', 'flexo-booking' ), get_the_title( $room ) ) ); ?>"></td>
										</tr>
									<?php endforeach; ?>
									</tbody>
								</table>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Status', 'flexo-booking' ); ?></th>
							<td>
								<label><input type="checkbox" name="active" value="1" <?php checked( ! empty( $form['active'] ) ); ?>> <?php esc_html_e( 'Offer this plan to guests', 'flexo-booking' ); ?></label>
								<p><label><?php esc_html_e( 'Order', 'flexo-booking' ); ?> <input type="number" name="sort_order" class="small-text" value="<?php echo esc_attr( $form['sort_order'] ); ?>"></label></p>
							</td>
						</tr>
					</table>
					<?php submit_button( $form['id'] ? __( 'Save rate plan', 'flexo-booking' ) : __( 'Add rate plan', 'flexo-booking' ), 'primary', 'submit', false ); ?>
					<a class="button" href="<?php echo esc_url( self::page_url() ); ?>"><?php esc_html_e( 'Cancel', 'flexo-booking' ); ?></a>
				</form>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function handle_save() {
		self::check_access( 'flexo_booking_save_rate_plan' );
		$id    = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$input = array();
		foreach ( array( 'name', 'adjustment_type', 'adjustment_value', 'sort_order' ) as $field ) {
			$input[ $field ] = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
		}
		foreach ( array( 'description', 'cancellation_policy' ) as $field ) {
			$input[ $field ] = isset( $_POST[ $field ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $field ] ) ) : '';
		}
		$input['refundable'] = empty( $_POST['refundable'] ) ? 0 : 1;
		$input['active']     = empty( $_POST['active'] ) ? 0 : 1;

		$rooms = array();
		$raw   = isset( $_POST['rooms'] ) && is_array( $_POST['rooms'] ) ? wp_unslash( $_POST['rooms'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised per field below.
		foreach ( $raw as $room_id => $state ) {
			$override = isset( $state['override'] ) ? str_replace( ',', '.', trim( sanitize_text_field( $state['override'] ) ) ) : '';
			if ( '' !== $override && ! is_numeric( $override ) ) {
				$input['rooms'] = $rooms;
				set_transient( 'flexo_plan_form_' . get_current_user_id(), array_merge( $input, array( 'id' => $id ) ), 120 );
				wp_safe_redirect( self::page_url( array( 'flexo_error' => rawurlencode( __( 'Please enter room amounts as numbers, e.g. 12 or -15.', 'flexo-booking' ) ) ) ) . '#flexo-plan-form' );
				exit;
			}
			$rooms[ absint( $room_id ) ] = array(
				'on'       => empty( $state['on'] ) ? 0 : 1,
				'override' => $override,
			);
		}

		$result = Flexo_Booking_Rate_Plans::save( $input, $id );
		if ( is_wp_error( $result ) ) {
			$input['rooms'] = $rooms;
			set_transient( 'flexo_plan_form_' . get_current_user_id(), array_merge( $input, array( 'id' => $id ) ), 120 );
			wp_safe_redirect( self::page_url( array( 'flexo_error' => rawurlencode( $result->get_error_message() ) ) ) . '#flexo-plan-form' );
			exit;
		}

		// Which rooms offer the plan (stored on each room).
		foreach ( self::rooms() as $room ) {
			$assigned = Flexo_Booking_Rate_Plans::room_assignments( $room->ID );
			$state    = isset( $rooms[ $room->ID ] ) ? $rooms[ $room->ID ] : array( 'on' => 0 );
			if ( ! empty( $state['on'] ) ) {
				$assigned[ $result ] = '' === $state['override'] ? null : (float) $state['override'];
			} else {
				unset( $assigned[ $result ] );
			}
			Flexo_Booking_Rate_Plans::set_room_assignments( $room->ID, $assigned );
		}

		wp_safe_redirect( self::page_url( array( 'flexo_msg' => 'saved' ) ) );
		exit;
	}

	public static function handle_delete() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		self::check_access( 'flexo_booking_delete_rate_plan_' . $id );
		Flexo_Booking_Rate_Plans::delete( $id );
		wp_safe_redirect( self::page_url( array( 'flexo_msg' => 'deleted' ) ) );
		exit;
	}

	public static function handle_toggle() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		self::check_access( 'flexo_booking_toggle_rate_plan_' . $id );
		Flexo_Booking_Rate_Plans::set_active( $id, ! empty( $_GET['active'] ) );
		wp_safe_redirect( self::page_url( array( 'flexo_msg' => 'saved' ) ) );
		exit;
	}

	public static function handle_presets() {
		self::check_access( 'flexo_booking_rate_plan_presets' );
		$added = Flexo_Booking_Rate_Plans::add_presets();
		/* translators: %d: number of plans */
		wp_safe_redirect( self::page_url( array( 'flexo_info' => rawurlencode( sprintf( _n( '%d ready-made plan added (switched off).', '%d ready-made plans added (switched off).', $added, 'flexo-booking' ), $added ) ) ) ) );
		exit;
	}
}
