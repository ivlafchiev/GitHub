<?php
/**
 * Languages: the guest's language, switching to it for REST requests and
 * emails, translating texts the hotel typed (Polylang / WPML string
 * translation), language-specific pages and date formats.
 *
 * Every built-in text uses gettext with the "flexo-booking" text domain;
 * translations live in /languages (bg_BG included).
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_I18n {

	const CONTEXT = 'Flexo Booking';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_strings' ), 30 );
	}

	/**
	 * Languages this site can use: English, installed WordPress languages,
	 * and the languages of Polylang / WPML.
	 *
	 * @return string[] Locales, e.g. array( 'en_US', 'bg_BG' ).
	 */
	public static function locales() {
		$locales = array_merge( array( 'en_US', get_locale() ), get_available_languages() );
		foreach ( self::multilingual_languages() as $language ) {
			$locales[] = $language['locale'];
		}
		return array_values( array_unique( array_filter( $locales ) ) );
	}

	/**
	 * @return string A known locale, or '' when unknown.
	 */
	public static function sanitize_locale( $locale ) {
		$locale = preg_replace( '/[^A-Za-z_@-]/', '', (string) $locale );
		if ( '' === $locale ) {
			return '';
		}
		foreach ( self::locales() as $known ) {
			if ( 0 === strcasecmp( $known, $locale ) ) {
				return $known;
			}
		}
		return '';
	}

	/**
	 * Language of the page being shown to the visitor.
	 */
	public static function current() {
		if ( function_exists( 'pll_current_language' ) && pll_current_language( 'locale' ) ) {
			return pll_current_language( 'locale' );
		}
		$wpml = apply_filters( 'wpml_current_language', null );
		if ( $wpml ) {
			foreach ( self::multilingual_languages() as $language ) {
				if ( $language['slug'] === $wpml ) {
					return $language['locale'];
				}
			}
		}
		return determine_locale();
	}

	/**
	 * Polylang / WPML languages.
	 *
	 * @return array[] Each: slug, locale.
	 */
	public static function multilingual_languages() {
		$out = array();
		if ( function_exists( 'pll_languages_list' ) ) {
			$slugs   = (array) pll_languages_list( array( 'fields' => 'slug' ) );
			$locales = (array) pll_languages_list( array( 'fields' => 'locale' ) );
			foreach ( $slugs as $i => $slug ) {
				if ( isset( $locales[ $i ] ) ) {
					$out[] = array(
						'slug'   => $slug,
						'locale' => $locales[ $i ],
					);
				}
			}
			return $out;
		}
		$wpml = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
		if ( is_array( $wpml ) ) {
			foreach ( $wpml as $code => $language ) {
				$out[] = array(
					'slug'   => $code,
					'locale' => isset( $language['default_locale'] ) ? $language['default_locale'] : $code,
				);
			}
		}
		return $out;
	}

	private static function slug_for( $locale ) {
		foreach ( self::multilingual_languages() as $language ) {
			if ( $language['locale'] === $locale ) {
				return $language['slug'];
			}
		}
		return '';
	}

	/**
	 * Runs $callback with WordPress switched to $locale (plugin texts,
	 * month names, number format), then switches back.
	 *
	 * @return mixed The callback's result.
	 */
	public static function with_locale( $locale, callable $callback ) {
		$locale = self::sanitize_locale( $locale );
		if ( '' === $locale || $locale === determine_locale() ) {
			return $callback();
		}
		$switched = switch_to_locale( $locale );
		try {
			return $callback();
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			}
		}
	}

	/**
	 * The hotel's own language for hotel emails: the site language (the
	 * default language with Polylang / WPML). Not get_locale(), which follows
	 * the guest's language while a guest's request is answered.
	 */
	public static function site_locale() {
		if ( function_exists( 'pll_default_language' ) && pll_default_language( 'locale' ) ) {
			return pll_default_language( 'locale' );
		}
		$wpml = apply_filters( 'wpml_default_language', null );
		if ( $wpml ) {
			foreach ( self::multilingual_languages() as $language ) {
				if ( $language['slug'] === $wpml ) {
					return $language['locale'];
				}
			}
		}
		$locale = get_option( 'WPLANG' );
		if ( ! $locale && is_multisite() ) {
			$locale = get_site_option( 'WPLANG' );
		}
		return $locale ? $locale : 'en_US';
	}

	/**
	 * Texts the hotel typed that guests see. They are registered with
	 * Polylang / WPML string translation so they can be translated there.
	 *
	 * @return array Name => text.
	 */
	public static function translatable_strings() {
		$settings = Flexo_Booking_Settings::all();
		$strings  = array();
		// Only texts the hotel changed: built-in texts are translated automatically.
		foreach ( $settings as $key => $value ) {
			$is_template = 0 === strpos( $key, 'email_' ) && ( '_subject' === substr( $key, -8 ) || '_body' === substr( $key, -5 ) );
			if ( ( $is_template || 'privacy_consent_text' === $key ) && is_string( $value ) && '' !== trim( $value ) && ! Flexo_Booking_Emails::is_default_text( $key, $value ) ) {
				$strings[ $key ] = $value;
			}
		}
		foreach ( Flexo_Booking_Rate_Plans::all() as $plan ) {
			$strings[ 'rate_plan_' . $plan['id'] . '_name' ] = $plan['name'];
			if ( '' !== $plan['description'] ) {
				$strings[ 'rate_plan_' . $plan['id'] . '_description' ] = $plan['description'];
			}
			if ( '' !== $plan['cancellation_policy'] ) {
				$strings[ 'rate_plan_' . $plan['id'] . '_cancellation' ] = $plan['cancellation_policy'];
			}
		}
		return $strings;
	}

	public static function register_strings() {
		if ( ! is_admin() || ( ! function_exists( 'pll_register_string' ) && ! has_action( 'wpml_register_single_string' ) ) ) {
			return;
		}
		foreach ( self::translatable_strings() as $name => $text ) {
			if ( function_exists( 'pll_register_string' ) ) {
				pll_register_string( $name, $text, self::CONTEXT, false !== strpos( $text, "\n" ) );
			} else {
				do_action( 'wpml_register_single_string', self::CONTEXT, $name, $text );
			}
		}
	}

	/**
	 * A text the hotel typed, in the given language when Polylang / WPML
	 * has a translation for it.
	 */
	public static function translate( $text, $name, $locale = '' ) {
		if ( '' === (string) $text ) {
			return $text;
		}
		$locale = $locale ? $locale : determine_locale();
		$slug   = self::slug_for( $locale );
		if ( function_exists( 'pll_translate_string' ) && $slug ) {
			return pll_translate_string( $text, $slug );
		}
		if ( $slug && has_filter( 'wpml_translate_single_string' ) ) {
			return apply_filters( 'wpml_translate_single_string', $text, self::CONTEXT, $name, $slug );
		}
		return $text;
	}

	/**
	 * A page setting (path such as /terms/) as a URL in the given language:
	 * with Polylang / WPML the translated page is used when there is one.
	 */
	public static function page_url( $url, $locale = '' ) {
		if ( '' === $url ) {
			return '';
		}
		$slug = self::slug_for( $locale ? $locale : self::current() );
		if ( ! $slug ) {
			return $url;
		}
		$post_id = url_to_postid( $url );
		if ( ! $post_id ) {
			return $url;
		}
		$translated = 0;
		if ( function_exists( 'pll_get_post' ) ) {
			$translated = (int) pll_get_post( $post_id, $slug );
		} else {
			$translated = (int) apply_filters( 'wpml_object_id', $post_id, get_post_type( $post_id ), false, $slug );
		}
		return $translated ? get_permalink( $translated ) : $url;
	}

	/**
	 * Date format for guests in a language: DD.MM.YYYY for Bulgarian (and
	 * other languages that write dates that way), otherwise the language's
	 * usual format.
	 */
	public static function date_format( $locale = '' ) {
		$locale = $locale ? $locale : determine_locale();
		$lang   = strtolower( substr( $locale, 0, 2 ) );
		if ( in_array( $lang, array( 'bg', 'de', 'ru', 'uk', 'pl', 'ro', 'cs', 'sk', 'tr', 'fi', 'nb', 'da', 'sr', 'mk', 'hr', 'sl', 'el' ), true ) ) {
			$format = 'd.m.Y';
		} elseif ( 'en_US' === $locale ) {
			$format = 'F j, Y';
		} elseif ( 'en' === $lang ) {
			$format = 'j F Y';
		} else {
			$format = get_option( 'date_format' );
		}
		return apply_filters( 'flexo_booking_date_format', $format, $locale );
	}

	/**
	 * Y-m-d → a date for guests in the current (or given) language.
	 */
	public static function format_date( $ymd, $locale = '' ) {
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', substr( (string) $ymd, 0, 10 ), wp_timezone() );
		return $date ? wp_date( self::date_format( $locale ), $date->getTimestamp() ) : (string) $ymd;
	}

	/**
	 * Name of a language for the admin, e.g. "Български" / "English (United States)".
	 */
	public static function language_name( $locale ) {
		if ( '' === (string) $locale ) {
			return '';
		}
		// A short list instead of wp_get_available_translations(), which calls wordpress.org.
		$names = array(
			'en_US' => 'English (US)',
			'en_GB' => 'English (UK)',
			'bg_BG' => 'Български',
			'de_DE' => 'Deutsch',
			'el'    => 'Ελληνικά',
			'es_ES' => 'Español',
			'fr_FR' => 'Français',
			'it_IT' => 'Italiano',
			'ro_RO' => 'Română',
			'ru_RU' => 'Русский',
			'tr_TR' => 'Türkçe',
			'uk'    => 'Українська',
			'sr_RS' => 'Српски',
			'mk_MK' => 'Македонски',
			'pl_PL' => 'Polski',
		);
		return isset( $names[ $locale ] ) ? $names[ $locale ] : $locale;
	}
}
