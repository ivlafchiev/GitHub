<?php
/**
 * The unified pricing service. Every price shown or stored comes from here.
 *
 * quote() runs an ordered list of steps over a quote array:
 *
 *   10  nightly room price (season or room price, weekday/weekend)   Day 1
 *   20  rate-plan adjustment (children priced by age)                 Day 3
 *   40  promo discount (room + rate plan, never taxes)                Day 3
 *   50  tourist tax (per person per night)                            Day 3
 *   90  totals                                                        Day 1
 *   95  payment schedule (deposit / pay now / at property)            Day 5
 *
 * Steps are registered through the `flexo_booking_pricing_steps` filter and
 * only when their feature is enabled. The result is JSON-safe and stored on
 * each booking (price_breakdown), so a booking's price never changes later.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Pricing {

	const VERSION = 1;

	/**
	 * @param array $request {
	 *     @type int|string|array $room      Room ID, slug, or Flexo_Booking_Rooms::to_array() result.
	 *     @type string           $check_in  Y-m-d.
	 *     @type string           $check_out Y-m-d.
	 *     @type int              $adults
	 *     @type int              $children      Used when children's ages are not asked (feature off).
	 *     @type int[]|string     $children_ages Ages, e.g. array( 4, 11 ) or "4,11" (feature "children").
	 *     @type int              $rate_plan_id  0 = the room's only plan (or no plan).
	 *     @type string           $promo_code
	 *     @type string           $booking_date  Y-m-d the booking is made on, for promo validity (default today).
	 *     @type string           $context       search | booking | admin.
	 * }
	 * @return array|WP_Error
	 */
	public static function quote( array $request ) {
		$room = isset( $request['room'] ) ? $request['room'] : 0;
		if ( ! is_array( $room ) ) {
			$post = Flexo_Booking_Rooms::find( $room );
			if ( ! $post ) {
				$post = is_numeric( $room ) ? get_post( (int) $room ) : null;
			}
			if ( ! $post || Flexo_Booking_Rooms::POST_TYPE !== $post->post_type ) {
				return new WP_Error( 'flexo_invalid_room', __( 'Please choose a room.', 'flexo-booking' ) );
			}
			$room = Flexo_Booking_Rooms::to_array( $post );
		}

		$stay = Flexo_Booking_Bookings::validate_dates(
			isset( $request['check_in'] ) ? $request['check_in'] : '',
			isset( $request['check_out'] ) ? $request['check_out'] : '',
			false
		);
		if ( is_wp_error( $stay ) ) {
			return $stay;
		}

		$request = wp_parse_args(
			$request,
			array(
				'adults'        => 1,
				'children'      => 0,
				'children_ages' => array(),
				'rate_plan_id'  => 0,
				'promo_code'    => '',
				'booking_date'  => '',
				'context'       => 'search',
			)
		);

		$ages = array();
		if ( Flexo_Booking_Children::enabled() ) {
			$ages = Flexo_Booking_Children::parse_ages( $request['children_ages'] );
			if ( is_wp_error( $ages ) ) {
				return $ages;
			}
		}
		$children = max( count( $ages ), max( 0, (int) $request['children'] ) );

		$plan = self::resolve_rate_plan( $room, (int) $request['rate_plan_id'], $request['context'] );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		$quote = array(
			'version'        => self::VERSION,
			'currency'       => Flexo_Booking_Money::currency(),
			'room_id'        => (int) $room['id'],
			'check_in'       => $stay['check_in'],
			'check_out'      => $stay['check_out'],
			'nights'         => $stay['nights'],
			'adults'         => max( 1, (int) $request['adults'] ),
			'children'       => $children,
			'children_ages'  => $ages,
			'rate_plan'      => $plan ? Flexo_Booking_Rate_Plans::snapshot( $plan ) : null,
			'promo'          => null,
			'promo_error'    => null,
			'nights_detail'  => array(),
			'lines'          => array(),
			'subtotal'       => 0.0,
			'discount_total' => 0.0,
			'tax_total'      => 0.0,
			'total'          => 0.0,
			'due_at_property' => 0.0,
			'payable'        => array(
				'now'         => 0.0,
				'deposit'     => 0.0,
				'at_property' => 0.0,
			),
			'min_nights'     => 1,
			'messages'       => array(),
		);

		foreach ( self::steps( $request, $room ) as $step ) {
			$quote = call_user_func( $step, $quote, $request, $room );
		}

		return apply_filters( 'flexo_booking_quote', $quote, $request, $room );
	}

	/**
	 * The rate plan a quote uses. A room that offers no plan (or rate plans
	 * switched off) has none. With several plans the guest must choose one;
	 * a search quotes the first until then.
	 *
	 * @return array|null|WP_Error
	 */
	private static function resolve_rate_plan( array $room, $plan_id, $context ) {
		$plans = Flexo_Booking_Rate_Plans::for_room( $room['id'] );
		if ( ! $plans ) {
			return null;
		}
		foreach ( $plans as $plan ) {
			if ( $plan['id'] === $plan_id ) {
				return $plan;
			}
		}
		if ( $plan_id ) {
			return new WP_Error( 'flexo_rate_plan', __( 'The selected rate is not available for this room. Please choose another one.', 'flexo-booking' ) );
		}
		if ( 1 === count( $plans ) || 'search' === $context ) {
			return $plans[0];
		}
		return new WP_Error( 'flexo_rate_plan', __( 'Please choose a rate for this room.', 'flexo-booking' ) );
	}

	/**
	 * @return callable[] Ordered by priority.
	 */
	private static function steps( array $request, array $room ) {
		$steps = array(
			10 => array( __CLASS__, 'step_nightly' ),
			90 => array( __CLASS__, 'step_totals' ),
		);
		if ( Flexo_Booking_Rate_Plans::enabled() ) {
			$steps[20] = array( __CLASS__, 'step_rate_plan' );
		}
		if ( Flexo_Booking_Promo_Codes::enabled() && '' !== Flexo_Booking_Promo_Codes::normalize_code( $request['promo_code'] ) ) {
			$steps[40] = array( __CLASS__, 'step_promo' );
		}
		if ( Flexo_Booking_Features::is_enabled( 'tourist_tax' ) && (float) Flexo_Booking_Settings::get( 'tourist_tax_amount' ) > 0 ) {
			$steps[50] = array( __CLASS__, 'step_tourist_tax' );
		}
		$steps = apply_filters( 'flexo_booking_pricing_steps', $steps, $request, $room );
		ksort( $steps );
		return $steps;
	}

	/**
	 * Step 10: each night is priced by the season it falls in (when seasonal
	 * prices are on), otherwise by the room. Friday and Saturday nights use
	 * the weekend price when one is set.
	 */
	public static function step_nightly( array $quote, array $request, array $room ) {
		$seasons = Flexo_Booking_Seasons::enabled() ? Flexo_Booking_Seasons::for_stay( $room['id'], $quote['check_in'], $quote['check_out'] ) : array();
		$groups  = array();
		$sum     = 0.0;

		foreach ( Flexo_Booking_Dates::nights( $quote['check_in'], $quote['check_out'] ) as $date ) {
			$weekend = Flexo_Booking_Dates::is_weekend_night( $date );
			$season  = $seasons ? Flexo_Booking_Seasons::season_for_night( $seasons, $date ) : null;

			if ( $season ) {
				$use_weekend = $weekend && null !== $season['weekend_price'] && $season['weekend_price'] > 0;
				$amount      = $use_weekend ? $season['weekend_price'] : $season['price'];
				$label       = $season['name'];
			} else {
				$use_weekend = $weekend && $room['weekend_price'] > 0;
				$amount      = $use_weekend ? $room['weekend_price'] : $room['price'];
				$label       = __( 'Standard rate', 'flexo-booking' );
			}
			$amount = (float) $amount;
			$sum   += $amount;

			$quote['nights_detail'][] = array(
				'date'      => $date,
				'amount'    => $amount,
				'season_id' => $season ? $season['id'] : 0,
				'season'    => $season ? $season['name'] : '',
				'weekend'   => $use_weekend,
			);

			$group_label = $use_weekend ? sprintf( /* translators: %s: season or rate name */ __( '%s – weekend', 'flexo-booking' ), $label ) : $label;
			$group_key   = $group_label . '|' . $amount;
			if ( ! isset( $groups[ $group_key ] ) ) {
				$groups[ $group_key ] = array(
					'label'  => $group_label,
					'nights' => 0,
					'unit'   => $amount,
					'amount' => 0.0,
				);
			}
			++$groups[ $group_key ]['nights'];
			$groups[ $group_key ]['amount'] += $amount;
		}

		$accommodation = round( $sum, 2 );

		$quote['lines'][] = array(
			'key'     => 'accommodation',
			'type'    => 'accommodation',
			/* translators: %d: number of nights */
			'label'   => sprintf( _n( 'Accommodation, %d night', 'Accommodation, %d nights', $quote['nights'], 'flexo-booking' ), $quote['nights'] ),
			'amount'  => $accommodation,
			'collect' => 'booking',
			'groups'  => array_values( $groups ),
		);

		// 1.0.0 filter, applied to the accommodation amount as before.
		$filtered = (float) apply_filters( 'flexo_booking_calculate_total', $accommodation, $room, $quote['check_in'], $quote['check_out'] );
		if ( abs( $filtered - $accommodation ) >= 0.005 ) {
			$quote['lines'][] = array(
				'key'     => 'adjustment',
				'type'    => 'adjustment',
				'label'   => __( 'Price adjustment', 'flexo-booking' ),
				'amount'  => $filtered - $accommodation,
				'collect' => 'booking',
			);
		}

		$min                 = Flexo_Booking_Seasons::min_nights( $room, $quote['check_in'] );
		$quote['min_nights'] = $min['nights'];
		if ( $min['season'] ) {
			$quote['min_nights_season'] = $min['season']['name'];
		}

		return $quote;
	}

	/**
	 * The room price of a quote: accommodation plus any legacy adjustment.
	 * Rate-plan percentages apply to this amount only.
	 */
	public static function room_amount( array $quote ) {
		$sum = 0.0;
		foreach ( $quote['lines'] as $line ) {
			if ( in_array( $line['type'], array( 'accommodation', 'adjustment' ), true ) ) {
				$sum += (float) $line['amount'];
			}
		}
		return $sum;
	}

	/**
	 * An amount per person per night, with children priced by age.
	 *
	 * @param float      $unit     Adult amount per night.
	 * @param int        $nights
	 * @param int        $adults
	 * @param int[]      $ages     Known children's ages.
	 * @param int        $children Number of children (children without a known age pay the adult amount).
	 * @param array|null $rules    Child rules, or null to charge children like adults.
	 * @return array { amount: float, items: array[] }
	 */
	public static function per_person( $unit, $nights, $adults, array $ages, $children, $rules ) {
		$unit   = (float) $unit;
		$items  = array();
		$amount = $adults * $nights * $unit;
		$items[] = array(
			/* translators: 1: number of adults, 2: number of nights */
			'text'   => sprintf( _n( '%1$d adult × %2$s', '%1$d adults × %2$s', $adults, 'flexo-booking' ), $adults, sprintf( _n( '%d night', '%d nights', $nights, 'flexo-booking' ), $nights ) ),
			'unit'   => $unit,
			'amount' => Flexo_Booking_Money::round( $adults * $nights * $unit ),
		);

		$unknown = max( 0, (int) $children - count( $ages ) );
		foreach ( $ages as $age ) {
			$factor    = $rules ? Flexo_Booking_Children::factor( $rules, $age ) : 1.0;
			$per_night = $unit * $factor;
			$amount   += $per_night * $nights;
			if ( $factor <= 0 ) {
				$items[] = array(
					/* translators: %d: child's age */
					'text'   => sprintf( __( 'Child, age %d', 'flexo-booking' ), $age ),
					'unit'   => null,
					'amount' => 0.0,
					'free'   => true,
				);
				continue;
			}
			$items[] = array(
				'text'   => sprintf(
					/* translators: 1: child's age, 2: number of nights, 3: share of the adult price, e.g. " (50%)" */
					__( 'Child, age %1$d × %2$s%3$s', 'flexo-booking' ),
					$age,
					/* translators: %d: number of nights */
					sprintf( _n( '%d night', '%d nights', $nights, 'flexo-booking' ), $nights ),
					$factor < 1 ? ' (' . Flexo_Booking_Children::percent_text( $factor * 100 ) . '%)' : ''
				),
				'unit'   => Flexo_Booking_Money::round( $per_night ),
				'amount' => Flexo_Booking_Money::round( $per_night * $nights ),
			);
		}
		if ( $unknown ) {
			$amount += $unknown * $nights * $unit;
			$items[] = array(
				/* translators: 1: number of children, 2: number of nights */
				'text'   => sprintf( _n( '%1$d child × %2$s', '%1$d children × %2$s', $unknown, 'flexo-booking' ), $unknown, sprintf( _n( '%d night', '%d nights', $nights, 'flexo-booking' ), $nights ) ),
				'unit'   => $unit,
				'amount' => Flexo_Booking_Money::round( $unknown * $nights * $unit ),
			);
		}
		return array(
			'amount' => $amount,
			'items'  => $items,
		);
	}

	/**
	 * Step 20: the chosen rate plan's price change (feature "rate_plans").
	 */
	public static function step_rate_plan( array $quote, array $request, array $room ) {
		if ( empty( $quote['rate_plan'] ) ) {
			return $quote;
		}
		$plan          = $quote['rate_plan'];
		$plan['value'] = $plan['adjustment_value'];
		$result        = Flexo_Booking_Rate_Plans::adjustment( $plan, $quote, $room );
		if ( abs( $result['amount'] ) < 0.005 ) {
			return $quote; // "Room Only": nothing to add, the plan name is shown on its own.
		}
		$quote['lines'][] = array(
			'key'     => 'rate_plan',
			'type'    => 'rate_plan',
			'label'   => $plan['name'],
			'amount'  => $result['amount'],
			'collect' => 'booking',
			'items'   => $result['items'],
		);
		return $quote;
	}

	/**
	 * Step 40: promo discount on the room price and rate plan (feature
	 * "promo_codes"). An invalid code adds no discount and records why.
	 */
	public static function step_promo( array $quote, array $request ) {
		$code  = Flexo_Booking_Promo_Codes::normalize_code( $request['promo_code'] );
		$promo = Flexo_Booking_Promo_Codes::validate( $code, $quote, $request['booking_date'] );
		if ( is_wp_error( $promo ) ) {
			$quote['promo_error'] = array(
				'code'    => $promo->get_error_code(),
				'message' => $promo->get_error_message(),
			);
			return $quote;
		}
		$base     = Flexo_Booking_Promo_Codes::discountable( $quote );
		$discount = Flexo_Booking_Promo_Codes::discount( $promo, $base );

		$quote['promo']   = array(
			'id'             => $promo['id'],
			'code'           => $promo['code'],
			'discount_type'  => $promo['discount_type'],
			'discount_value' => $promo['discount_value'],
		);
		$quote['lines'][] = array(
			'key'     => 'discount',
			'type'    => 'discount',
			/* translators: %s: promo code */
			'label'   => sprintf( __( 'Promo code %s', 'flexo-booking' ), $promo['code'] ),
			'amount'  => -$discount,
			'collect' => 'booking',
			'items'   => 'percent' === $promo['discount_type'] ? array(
				array(
					/* translators: 1: percentage, 2: amount it applies to */
					'text'   => sprintf( __( '%1$s%% of %2$s', 'flexo-booking' ), Flexo_Booking_Children::percent_text( $promo['discount_value'] ), Flexo_Booking_Money::format( $base ) ),
					'unit'   => null,
					'amount' => -$discount,
				),
			) : array(),
		);
		return $quote;
	}

	/**
	 * Step 50: tourist tax per person per night (feature "tourist_tax").
	 * Collected with the booking or paid at the property.
	 */
	public static function step_tourist_tax( array $quote, array $request, array $room ) {
		$settings = Flexo_Booking_Settings::all();
		switch ( $settings['tourist_tax_children'] ) {
			case 'exempt':
				$rules = array(
					'free_under' => (int) $settings['tourist_tax_exempt_under'],
					'percent'    => 100,
					'adult_from' => (int) $settings['tourist_tax_exempt_under'],
				);
				break;
			case 'rules':
				$rules = Flexo_Booking_Children::rules( $room );
				break;
			default:
				$rules = null;
		}
		$result  = self::per_person( (float) $settings['tourist_tax_amount'], $quote['nights'], $quote['adults'], $quote['children_ages'], $quote['children'], $rules );
		$collect = 'property' === $settings['tourist_tax_collect'] ? 'property' : 'booking';

		$quote['lines'][] = array(
			'key'     => 'tourist_tax',
			'type'    => 'tax',
			'label'   => 'property' === $collect ? __( 'Tourist tax (paid at the property)', 'flexo-booking' ) : __( 'Tourist tax', 'flexo-booking' ),
			'amount'  => $result['amount'],
			'collect' => $collect,
			'items'   => $result['items'],
		);
		return $quote;
	}

	/**
	 * Step 90: round each line, then sum, so the breakdown always adds up.
	 * Lines paid at the property are shown but not part of the total.
	 */
	public static function step_totals( array $quote ) {
		$subtotal = 0.0;
		$discount = 0.0;
		$tax      = 0.0;
		$property = 0.0;
		$total    = 0.0;

		foreach ( $quote['lines'] as $i => $line ) {
			$amount                         = Flexo_Booking_Money::round( $line['amount'] );
			$quote['lines'][ $i ]['amount'] = $amount;
			if ( 'discount' === $line['type'] ) {
				$discount += -$amount;
			} elseif ( 'tax' === $line['type'] ) {
				$tax += $amount;
			} else {
				$subtotal += $amount;
			}
			if ( isset( $line['collect'] ) && 'property' === $line['collect'] ) {
				$property += $amount;
			} else {
				$total += $amount;
			}
		}

		$quote['subtotal']        = Flexo_Booking_Money::round( $subtotal );
		$quote['discount_total']  = Flexo_Booking_Money::round( $discount );
		$quote['tax_total']       = Flexo_Booking_Money::round( $tax );
		$quote['total']           = max( 0.0, Flexo_Booking_Money::round( $total ) );
		$quote['due_at_property'] = Flexo_Booking_Money::round( $property );
		$quote['payable']         = array(
			'now'         => 0.0,
			'deposit'     => 0.0,
			'at_property' => Flexo_Booking_Money::round( $quote['total'] + $property ),
		);
		return $quote;
	}

	/**
	 * The stored breakdown of a booking. Bookings made before 1.1.0 have none;
	 * they get a single accommodation line built from their total.
	 */
	public static function snapshot( array $booking ) {
		if ( ! empty( $booking['price_breakdown'] ) ) {
			$snapshot = json_decode( $booking['price_breakdown'], true );
			if ( is_array( $snapshot ) && isset( $snapshot['lines'] ) ) {
				return $snapshot;
			}
		}
		$nights = isset( $booking['nights'] ) ? (int) $booking['nights'] : 1;
		return array(
			'version'  => 0,
			'currency' => isset( $booking['currency'] ) ? $booking['currency'] : Flexo_Booking_Money::currency(),
			'lines'    => array(
				array(
					'key'     => 'accommodation',
					'type'    => 'accommodation',
					/* translators: %d: number of nights */
					'label'   => sprintf( _n( 'Accommodation, %d night', 'Accommodation, %d nights', $nights, 'flexo-booking' ), $nights ),
					'amount'  => (float) $booking['total'],
					'collect' => 'booking',
					'groups'  => array(),
				),
			),
			'total'    => (float) $booking['total'],
		);
	}

	/**
	 * Display rows for the booking form, emails, admin and CSV.
	 *
	 * @return array[] Each: key, type, label, amount, formatted, collect, details (string[]).
	 */
	public static function format_lines( array $quote ) {
		$currency = isset( $quote['currency'] ) ? $quote['currency'] : null;
		$rows     = array();
		foreach ( $quote['lines'] as $line ) {
			$details = array();
			if ( ! empty( $line['groups'] ) && count( $line['groups'] ) > 1 ) {
				foreach ( $line['groups'] as $group ) {
					$details[] = sprintf(
						/* translators: 1: season/rate name, 2: nights, 3: price per night, 4: amount */
						_n( '%1$s: %2$d night × %3$s = %4$s', '%1$s: %2$d nights × %3$s = %4$s', $group['nights'], 'flexo-booking' ),
						$group['label'],
						$group['nights'],
						Flexo_Booking_Money::format( $group['unit'], $currency ),
						Flexo_Booking_Money::format( $group['amount'], $currency )
					);
				}
			}
			foreach ( isset( $line['items'] ) ? $line['items'] : array() as $item ) {
				if ( ! empty( $item['free'] ) ) {
					/* translators: %s: e.g. "Child, age 2" */
					$details[] = sprintf( __( '%s: free', 'flexo-booking' ), $item['text'] );
				} elseif ( null !== $item['unit'] ) {
					$details[] = $item['text'] . ' × ' . Flexo_Booking_Money::format( $item['unit'], $currency ) . ' = ' . Flexo_Booking_Money::format( $item['amount'], $currency );
				} else {
					$details[] = $item['text'];
				}
			}
			$amount = (float) $line['amount'];
			$rows[] = array(
				'key'       => isset( $line['key'] ) ? $line['key'] : $line['type'],
				'type'      => $line['type'],
				'label'     => $line['label'],
				'amount'    => $amount,
				'formatted' => $amount < 0 ? '−' . Flexo_Booking_Money::format( -$amount, $currency ) : Flexo_Booking_Money::format( $amount, $currency ),
				'collect'   => isset( $line['collect'] ) ? $line['collect'] : 'booking',
				'details'   => $details,
			);
		}
		return $rows;
	}

	/**
	 * Plain-text breakdown (emails, CSV).
	 */
	public static function summary_text( array $quote ) {
		$currency = isset( $quote['currency'] ) ? $quote['currency'] : null;
		$lines    = array();
		if ( ! empty( $quote['rate_plan']['name'] ) ) {
			$lines[] = __( 'Rate', 'flexo-booking' ) . ': ' . $quote['rate_plan']['name'];
		}
		foreach ( self::format_lines( $quote ) as $row ) {
			$lines[] = $row['label'] . ': ' . $row['formatted'];
			foreach ( $row['details'] as $detail ) {
				$lines[] = '  ' . $detail;
			}
		}
		$lines[] = __( 'Total', 'flexo-booking' ) . ': ' . Flexo_Booking_Money::format( $quote['total'], $currency );
		if ( ! empty( $quote['payable']['mode'] ) && $quote['payable']['now'] > 0 ) {
			$label   = '' !== $quote['payable']['label'] ? $quote['payable']['label'] : __( 'Pay when booking', 'flexo-booking' );
			$lines[] = $label . ': ' . Flexo_Booking_Money::format( $quote['payable']['now'], $currency );
			if ( $quote['payable']['at_property'] > 0 ) {
				$lines[] = __( 'Payable at the property', 'flexo-booking' ) . ': ' . Flexo_Booking_Money::format( $quote['payable']['at_property'], $currency );
			}
		} elseif ( ! empty( $quote['due_at_property'] ) ) {
			$lines[] = __( 'Payable at the property', 'flexo-booking' ) . ': ' . Flexo_Booking_Money::format( $quote['due_at_property'], $currency );
		}
		return implode( "\n", $lines );
	}

	/**
	 * What the booking form shows for a quote (search results, the quote
	 * endpoint and the booking response use the same shape).
	 */
	public static function public_view( array $quote ) {
		$currency = isset( $quote['currency'] ) ? $quote['currency'] : null;
		$plan     = empty( $quote['rate_plan'] ) ? null : $quote['rate_plan'];
		$discount = isset( $quote['discount_total'] ) ? (float) $quote['discount_total'] : 0.0;
		$property = isset( $quote['due_at_property'] ) ? (float) $quote['due_at_property'] : 0.0;
		return array(
			'lines'                     => self::format_lines( $quote ),
			'subtotal'                  => isset( $quote['subtotal'] ) ? (float) $quote['subtotal'] : (float) $quote['total'],
			'subtotal_formatted'        => Flexo_Booking_Money::format( isset( $quote['subtotal'] ) ? $quote['subtotal'] : $quote['total'], $currency ),
			'discount_total'            => $discount,
			'discount_formatted'        => $discount > 0 ? '−' . Flexo_Booking_Money::format( $discount, $currency ) : '',
			'tax_total'                 => isset( $quote['tax_total'] ) ? (float) $quote['tax_total'] : 0.0,
			'total'                     => (float) $quote['total'],
			'total_formatted'           => Flexo_Booking_Money::format( $quote['total'], $currency ),
			'due_at_property'           => $property,
			'due_at_property_formatted' => $property > 0 ? Flexo_Booking_Money::format( $property, $currency ) : '',
			'rate_plan'                 => $plan ? array(
				'id'                  => (int) $plan['id'],
				'name'                => $plan['name'],
				'description'         => $plan['description'],
				'refundable'          => (bool) $plan['refundable'],
				'refundable_label'    => Flexo_Booking_Rate_Plans::refundable_label( $plan['refundable'] ),
				'cancellation_policy' => $plan['cancellation_policy'],
			) : null,
			'promo'                     => empty( $quote['promo'] ) ? null : array(
				'code'  => $quote['promo']['code'],
				'label' => Flexo_Booking_Promo_Codes::describe( $quote['promo'] ),
			),
			'promo_error'               => empty( $quote['promo_error'] ) ? null : $quote['promo_error']['message'],
			// What is paid when booking and at the property (payments features).
			'payment'                   => Flexo_Booking_Payments::public_view( $quote ),
		);
	}

	/**
	 * Average nightly price and whether nightly prices vary within the stay.
	 */
	public static function nightly_average( array $quote ) {
		$amounts = wp_list_pluck( $quote['nights_detail'], 'amount' );
		if ( ! $amounts ) {
			return array(
				'average' => 0.0,
				'varies'  => false,
			);
		}
		return array(
			'average' => Flexo_Booking_Money::round( array_sum( $amounts ) / count( $amounts ) ),
			'varies'  => count( array_unique( array_map( 'strval', $amounts ) ) ) > 1,
		);
	}
}
