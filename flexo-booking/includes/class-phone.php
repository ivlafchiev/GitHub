<?php
/**
 * Phone numbers with a country code: the country list of the booking form
 * and "+359 888 123 456" formatting. Numbers stored before 1.6.0 are
 * never changed.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Phone {

	/**
	 * ISO code => calling code and English name.
	 */
	const DATA = 'AF:93:Afghanistan|AL:355:Albania|DZ:213:Algeria|AD:376:Andorra|AO:244:Angola|AG:1:Antigua and Barbuda|AR:54:Argentina|AM:374:Armenia|AU:61:Australia|AT:43:Austria|AZ:994:Azerbaijan|BS:1:Bahamas|BH:973:Bahrain|BD:880:Bangladesh|BB:1:Barbados|BY:375:Belarus|BE:32:Belgium|BZ:501:Belize|BJ:229:Benin|BT:975:Bhutan|BO:591:Bolivia|BA:387:Bosnia and Herzegovina|BW:267:Botswana|BR:55:Brazil|BN:673:Brunei|BG:359:Bulgaria|BF:226:Burkina Faso|BI:257:Burundi|KH:855:Cambodia|CM:237:Cameroon|CA:1:Canada|CV:238:Cape Verde|CF:236:Central African Republic|TD:235:Chad|CL:56:Chile|CN:86:China|CO:57:Colombia|KM:269:Comoros|CG:242:Congo|CD:243:Congo (DRC)|CR:506:Costa Rica|CI:225:Côte d’Ivoire|HR:385:Croatia|CU:53:Cuba|CY:357:Cyprus|CZ:420:Czechia|DK:45:Denmark|DJ:253:Djibouti|DM:1:Dominica|DO:1:Dominican Republic|EC:593:Ecuador|EG:20:Egypt|SV:503:El Salvador|GQ:240:Equatorial Guinea|ER:291:Eritrea|EE:372:Estonia|SZ:268:Eswatini|ET:251:Ethiopia|FJ:679:Fiji|FI:358:Finland|FR:33:France|GA:241:Gabon|GM:220:Gambia|GE:995:Georgia|DE:49:Germany|GH:233:Ghana|GI:350:Gibraltar|GR:30:Greece|GD:1:Grenada|GT:502:Guatemala|GN:224:Guinea|GW:245:Guinea-Bissau|GY:592:Guyana|HT:509:Haiti|HN:504:Honduras|HK:852:Hong Kong|HU:36:Hungary|IS:354:Iceland|IN:91:India|ID:62:Indonesia|IR:98:Iran|IQ:964:Iraq|IE:353:Ireland|IL:972:Israel|IT:39:Italy|JM:1:Jamaica|JP:81:Japan|JO:962:Jordan|KZ:7:Kazakhstan|KE:254:Kenya|KI:686:Kiribati|XK:383:Kosovo|KW:965:Kuwait|KG:996:Kyrgyzstan|LA:856:Laos|LV:371:Latvia|LB:961:Lebanon|LS:266:Lesotho|LR:231:Liberia|LY:218:Libya|LI:423:Liechtenstein|LT:370:Lithuania|LU:352:Luxembourg|MO:853:Macao|MG:261:Madagascar|MW:265:Malawi|MY:60:Malaysia|MV:960:Maldives|ML:223:Mali|MT:356:Malta|MH:692:Marshall Islands|MR:222:Mauritania|MU:230:Mauritius|MX:52:Mexico|FM:691:Micronesia|MD:373:Moldova|MC:377:Monaco|MN:976:Mongolia|ME:382:Montenegro|MA:212:Morocco|MZ:258:Mozambique|MM:95:Myanmar|NA:264:Namibia|NR:674:Nauru|NP:977:Nepal|NL:31:Netherlands|NZ:64:New Zealand|NI:505:Nicaragua|NE:227:Niger|NG:234:Nigeria|KP:850:North Korea|MK:389:North Macedonia|NO:47:Norway|OM:968:Oman|PK:92:Pakistan|PW:680:Palau|PS:970:Palestine|PA:507:Panama|PG:675:Papua New Guinea|PY:595:Paraguay|PE:51:Peru|PH:63:Philippines|PL:48:Poland|PT:351:Portugal|QA:974:Qatar|RO:40:Romania|RU:7:Russia|RW:250:Rwanda|KN:1:Saint Kitts and Nevis|LC:1:Saint Lucia|VC:1:Saint Vincent and the Grenadines|WS:685:Samoa|SM:378:San Marino|ST:239:São Tomé and Príncipe|SA:966:Saudi Arabia|SN:221:Senegal|RS:381:Serbia|SC:248:Seychelles|SL:232:Sierra Leone|SG:65:Singapore|SK:421:Slovakia|SI:386:Slovenia|SB:677:Solomon Islands|SO:252:Somalia|ZA:27:South Africa|KR:82:South Korea|SS:211:South Sudan|ES:34:Spain|LK:94:Sri Lanka|SD:249:Sudan|SR:597:Suriname|SE:46:Sweden|CH:41:Switzerland|SY:963:Syria|TW:886:Taiwan|TJ:992:Tajikistan|TZ:255:Tanzania|TH:66:Thailand|TL:670:Timor-Leste|TG:228:Togo|TO:676:Tonga|TT:1:Trinidad and Tobago|TN:216:Tunisia|TR:90:Türkiye|TM:993:Turkmenistan|TV:688:Tuvalu|UG:256:Uganda|UA:380:Ukraine|AE:971:United Arab Emirates|GB:44:United Kingdom|US:1:United States|UY:598:Uruguay|UZ:998:Uzbekistan|VU:678:Vanuatu|VA:39:Vatican City|VE:58:Venezuela|VN:84:Vietnam|YE:967:Yemen|ZM:260:Zambia|ZW:263:Zimbabwe';

	/**
	 * @var array|null Per language.
	 */
	private static $cache = array();

	/**
	 * Countries sorted by name in the current language (names from PHP's
	 * intl extension when available, else English).
	 *
	 * @return array ISO code => array( 'code' => '359', 'name' => 'Bulgaria' ).
	 */
	public static function countries( $locale = '' ) {
		$locale = '' !== $locale ? $locale : determine_locale();
		if ( isset( self::$cache[ $locale ] ) ) {
			return self::$cache[ $locale ];
		}
		$list = array();
		$intl = class_exists( 'Locale' );
		foreach ( explode( '|', self::DATA ) as $row ) {
			list( $iso, $code, $name ) = explode( ':', $row, 3 );
			if ( $intl && 'XK' !== $iso ) {
				$local = Locale::getDisplayRegion( '-' . $iso, $locale );
				$name  = $local && $local !== $iso ? $local : $name;
			}
			$list[ $iso ] = array(
				'code' => $code,
				'name' => $name,
			);
		}
		if ( class_exists( 'Collator' ) ) {
			$collator = new Collator( $locale );
			uasort(
				$list,
				static function ( $a, $b ) use ( $collator ) {
					return $collator->compare( $a['name'], $b['name'] );
				}
			);
		} else {
			uasort(
				$list,
				static function ( $a, $b ) {
					return strcasecmp( remove_accents( $a['name'] ), remove_accents( $b['name'] ) );
				}
			);
		}
		self::$cache[ $locale ] = $list;
		return $list;
	}

	/**
	 * Calling code of a country ('' when unknown).
	 */
	public static function code( $iso ) {
		foreach ( explode( '|', self::DATA ) as $row ) {
			if ( 0 === strpos( $row, strtoupper( (string) $iso ) . ':' ) ) {
				return explode( ':', $row )[1];
			}
		}
		return '';
	}

	/**
	 * "0888 123 456" + BG → "+359 888 123 456". Numbers typed with their
	 * own code (+44…, 0044…) keep it; without a known country the number is
	 * stored as typed.
	 */
	public static function normalize( $number, $iso = '' ) {
		$number = trim( preg_replace( '/[^\d+()\s\-\/.]/', '', (string) $number ) );
		if ( '' === $number ) {
			return '';
		}
		if ( 0 === strpos( $number, '00' ) ) {
			return '+' . ltrim( substr( $number, 2 ) );
		}
		if ( 0 === strpos( $number, '+' ) ) {
			return $number;
		}
		$code = self::code( $iso );
		if ( '' === $code ) {
			return $number;
		}
		// The national trunk "0" is dropped after the country code (not in Italy).
		$national = 'IT' === strtoupper( $iso ) ? $number : preg_replace( '/^0+/', '', $number );
		return '+' . $code . ' ' . $national;
	}

	/**
	 * 6–15 digits: enough to catch typos without refusing real numbers.
	 */
	public static function looks_valid( $number ) {
		$digits = strlen( preg_replace( '/\D/', '', (string) $number ) );
		return $digits >= 6 && $digits <= 15;
	}
}
