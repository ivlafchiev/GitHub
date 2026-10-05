<?php
/**
 * Rooms in several languages (Polylang, WPML).
 *
 * A room's translation is a separate post holding the texts guests read in
 * that language: name, descriptions, view, beds, amenities, details, search
 * engine texts and its own address. Prices, guests, identical rooms,
 * seasons, closed dates, calendars and bookings always belong to the room
 * in the site's main language (the "main room"), so translations never
 * become extra rooms in the booking form and never split availability.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Room_I18n {

	const LANG_OPTION = 'flexo_booking_rooms_languages';

	/**
	 * Room data that belongs to the main room (copied to translations so
	 * they show the same, but always read from the main room).
	 */
	const BOOKING_META = array( '_flexo_price', '_flexo_weekend_price', '_flexo_capacity', '_flexo_units', '_flexo_min_nights', '_flexo_max_adults', '_flexo_size', '_flexo_rate_plans', '_flexo_child_rules', '_flexo_hidden', '_flexo_demo', '_flexo_gallery', '_thumbnail_id' );

	/**
	 * Texts copied once when a translation is created, then translated.
	 */
	const TEXT_META = array( '_flexo_beds', '_flexo_view', '_flexo_amenity_items', '_flexo_amenities', '_flexo_details' );

	public static function init() {
		add_filter( 'pll_get_post_types', array( __CLASS__, 'polylang_post_types' ), 10, 2 );
		add_filter( 'pll_get_taxonomies', array( __CLASS__, 'polylang_taxonomies' ), 10, 2 );
		add_filter( 'pll_copy_post_metas', array( __CLASS__, 'polylang_copy_metas' ), 10, 3 );
		add_action( 'admin_init', array( __CLASS__, 'assign_languages' ) );
	}

	public static function active() {
		return function_exists( 'pll_default_language' ) || has_filter( 'wpml_object_id' );
	}

	public static function polylang_post_types( $types, $is_settings ) {
		$types[ Flexo_Booking_Rooms::POST_TYPE ] = Flexo_Booking_Rooms::POST_TYPE;
		return $types;
	}

	public static function polylang_taxonomies( $taxonomies, $is_settings ) {
		$taxonomies[ Flexo_Booking_Room_Content::TAXONOMY ] = Flexo_Booking_Room_Content::TAXONOMY;
		return $taxonomies;
	}

	/**
	 * Polylang: booking data is kept in step between a room and its
	 * translations; texts are copied once, when the translation is made.
	 */
	public static function polylang_copy_metas( $keys, $sync, $from = 0 ) {
		if ( $from && Flexo_Booking_Rooms::POST_TYPE !== get_post_type( $from ) ) {
			return $keys;
		}
		$keys = array_merge( (array) $keys, self::BOOKING_META );
		if ( ! $sync ) {
			$keys = array_merge( $keys, self::TEXT_META );
		}
		return array_values( array_unique( $keys ) );
	}

	/**
	 * Rooms made before languages were set up get the main language
	 * (once; Polylang).
	 */
	public static function assign_languages() {
		if ( ! function_exists( 'pll_default_language' ) || ! function_exists( 'pll_set_post_language' ) || ! function_exists( 'pll_get_post_language' ) ) {
			return;
		}
		$default = pll_default_language();
		if ( ! $default || get_option( self::LANG_OPTION ) === $default ) {
			return;
		}
		foreach ( Flexo_Booking_Rooms::all( array( 'publish', 'draft', 'private', 'pending', 'future' ) ) as $post ) {
			if ( ! pll_get_post_language( $post->ID ) ) {
				pll_set_post_language( $post->ID, $default );
			}
		}
		update_option( self::LANG_OPTION, $default, false );
	}

	/**
	 * The main room of a room or translation (itself without a plugin, or
	 * when it has no main-language version).
	 */
	public static function canonical_id( $id ) {
		$id = (int) $id;
		if ( ! $id ) {
			return 0;
		}
		if ( function_exists( 'pll_get_post' ) && function_exists( 'pll_default_language' ) && pll_default_language() ) {
			$main = (int) pll_get_post( $id, pll_default_language() );
			return $main ? $main : $id;
		}
		if ( has_filter( 'wpml_object_id' ) ) {
			$default = apply_filters( 'wpml_default_language', null );
			$main    = $default ? (int) apply_filters( 'wpml_object_id', $id, Flexo_Booking_Rooms::POST_TYPE, false, $default ) : 0;
			return $main ? $main : $id;
		}
		return $id;
	}

	public static function is_canonical( $id ) {
		return self::canonical_id( $id ) === (int) $id;
	}

	/**
	 * The room's version in a language (the room itself when there is none).
	 *
	 * @param int    $id     Any version of the room.
	 * @param string $locale Locale, e.g. en_GB ('' = the current language).
	 */
	public static function translation_id( $id, $locale = '' ) {
		$id   = (int) $id;
		$slug = Flexo_Booking_I18n::slug_for( '' !== $locale ? $locale : Flexo_Booking_I18n::current() );
		if ( ! $slug ) {
			return $id;
		}
		if ( function_exists( 'pll_get_post' ) ) {
			$translated = (int) pll_get_post( $id, $slug );
			return $translated ? $translated : $id;
		}
		if ( has_filter( 'wpml_object_id' ) ) {
			$translated = (int) apply_filters( 'wpml_object_id', $id, Flexo_Booking_Rooms::POST_TYPE, false, $slug );
			return $translated ? $translated : $id;
		}
		return $id;
	}

	/**
	 * Name of the main room of the editor's translation, for notes.
	 */
	public static function main_language_name() {
		if ( function_exists( 'pll_default_language' ) ) {
			return (string) pll_default_language( 'name' );
		}
		return (string) apply_filters( 'wpml_default_language', '' );
	}
}
