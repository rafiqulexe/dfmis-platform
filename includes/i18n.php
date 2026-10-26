<?php
// ============================================================================
// INTERNATIONALIZATION PRIMITIVES
//
// Reference data (countries, currencies, timezones), validators, formatters
// and the translation mechanism.
//
// This file is deliberately PURE: nothing here reads tenant context or the
// database. Every function takes the locale / currency / timezone it should
// use as an explicit argument. includes/settings.php resolves those values
// from the farm -> organization -> platform hierarchy and provides the
// convenience wrappers that most call sites actually use.
//
// Keeping the split means the formatters are trivially unit-testable without
// a session, a database or a logged-in user.
// ============================================================================

// ---------------------------------------------------------------------------
// Countries — ISO 3166-1 alpha-2.
//
// Stored as a compact string and parsed once, so the source file stays
// readable. Codes are the stable identifier; names are display-only and are
// deliberately NOT the thing we persist.
// ---------------------------------------------------------------------------
const DFMS_COUNTRY_DATA =
    'AD:Andorra|AE:United Arab Emirates|AF:Afghanistan|AG:Antigua and Barbuda|AI:Anguilla|AL:Albania|AM:Armenia|'
  . 'AO:Angola|AQ:Antarctica|AR:Argentina|AS:American Samoa|AT:Austria|AU:Australia|AW:Aruba|AX:Åland Islands|'
  . 'AZ:Azerbaijan|BA:Bosnia and Herzegovina|BB:Barbados|BD:Bangladesh|BE:Belgium|BF:Burkina Faso|BG:Bulgaria|'
  . 'BH:Bahrain|BI:Burundi|BJ:Benin|BL:Saint Barthélemy|BM:Bermuda|BN:Brunei Darussalam|BO:Bolivia|'
  . 'BQ:Bonaire, Sint Eustatius and Saba|BR:Brazil|BS:Bahamas|BT:Bhutan|BV:Bouvet Island|BW:Botswana|BY:Belarus|'
  . 'BZ:Belize|CA:Canada|CC:Cocos (Keeling) Islands|CD:Congo (Democratic Republic)|CF:Central African Republic|'
  . 'CG:Congo|CH:Switzerland|CI:Côte d\'Ivoire|CK:Cook Islands|CL:Chile|CM:Cameroon|CN:China|CO:Colombia|'
  . 'CR:Costa Rica|CU:Cuba|CV:Cabo Verde|CW:Curaçao|CX:Christmas Island|CY:Cyprus|CZ:Czechia|DE:Germany|'
  . 'DJ:Djibouti|DK:Denmark|DM:Dominica|DO:Dominican Republic|DZ:Algeria|EC:Ecuador|EE:Estonia|EG:Egypt|'
  . 'EH:Western Sahara|ER:Eritrea|ES:Spain|ET:Ethiopia|FI:Finland|FJ:Fiji|FK:Falkland Islands|'
  . 'FM:Micronesia|FO:Faroe Islands|FR:France|GA:Gabon|GB:United Kingdom|GD:Grenada|GE:Georgia|GF:French Guiana|'
  . 'GG:Guernsey|GH:Ghana|GI:Gibraltar|GL:Greenland|GM:Gambia|GN:Guinea|GP:Guadeloupe|GQ:Equatorial Guinea|'
  . 'GR:Greece|GS:South Georgia|GT:Guatemala|GU:Guam|GW:Guinea-Bissau|GY:Guyana|HK:Hong Kong|'
  . 'HM:Heard Island and McDonald Islands|HN:Honduras|HR:Croatia|HT:Haiti|HU:Hungary|ID:Indonesia|IE:Ireland|'
  . 'IL:Israel|IM:Isle of Man|IN:India|IO:British Indian Ocean Territory|IQ:Iraq|IR:Iran|IS:Iceland|IT:Italy|'
  . 'JE:Jersey|JM:Jamaica|JO:Jordan|JP:Japan|KE:Kenya|KG:Kyrgyzstan|KH:Cambodia|KI:Kiribati|KM:Comoros|'
  . 'KN:Saint Kitts and Nevis|KP:North Korea|KR:South Korea|KW:Kuwait|KY:Cayman Islands|KZ:Kazakhstan|'
  . 'LA:Laos|LB:Lebanon|LC:Saint Lucia|LI:Liechtenstein|LK:Sri Lanka|LR:Liberia|LS:Lesotho|LT:Lithuania|'
  . 'LU:Luxembourg|LV:Latvia|LY:Libya|MA:Morocco|MC:Monaco|MD:Moldova|ME:Montenegro|MF:Saint Martin|'
  . 'MG:Madagascar|MH:Marshall Islands|MK:North Macedonia|ML:Mali|MM:Myanmar|MN:Mongolia|MO:Macao|'
  . 'MP:Northern Mariana Islands|MQ:Martinique|MR:Mauritania|MS:Montserrat|MT:Malta|MU:Mauritius|MV:Maldives|'
  . 'MW:Malawi|MX:Mexico|MY:Malaysia|MZ:Mozambique|NA:Namibia|NC:New Caledonia|NE:Niger|NF:Norfolk Island|'
  . 'NG:Nigeria|NI:Nicaragua|NL:Netherlands|NO:Norway|NP:Nepal|NR:Nauru|NU:Niue|NZ:New Zealand|OM:Oman|'
  . 'PA:Panama|PE:Peru|PF:French Polynesia|PG:Papua New Guinea|PH:Philippines|PK:Pakistan|PL:Poland|'
  . 'PM:Saint Pierre and Miquelon|PN:Pitcairn|PR:Puerto Rico|PS:Palestine|PT:Portugal|PW:Palau|PY:Paraguay|'
  . 'QA:Qatar|RE:Réunion|RO:Romania|RS:Serbia|RU:Russia|RW:Rwanda|SA:Saudi Arabia|SB:Solomon Islands|'
  . 'SC:Seychelles|SD:Sudan|SE:Sweden|SG:Singapore|SH:Saint Helena|SI:Slovenia|SJ:Svalbard and Jan Mayen|'
  . 'SK:Slovakia|SL:Sierra Leone|SM:San Marino|SN:Senegal|SO:Somalia|SR:Suriname|SS:South Sudan|'
  . 'ST:Sao Tome and Principe|SV:El Salvador|SX:Sint Maarten|SY:Syria|SZ:Eswatini|TC:Turks and Caicos Islands|'
  . 'TD:Chad|TF:French Southern Territories|TG:Togo|TH:Thailand|TJ:Tajikistan|TK:Tokelau|TL:Timor-Leste|'
  . 'TM:Turkmenistan|TN:Tunisia|TO:Tonga|TR:Türkiye|TT:Trinidad and Tobago|TV:Tuvalu|TW:Taiwan|TZ:Tanzania|'
  . 'UA:Ukraine|UG:Uganda|UM:United States Minor Outlying Islands|US:United States|UY:Uruguay|UZ:Uzbekistan|'
  . 'VA:Holy See|VC:Saint Vincent and the Grenadines|VE:Venezuela|VG:Virgin Islands (British)|'
  . 'VI:Virgin Islands (U.S.)|VN:Viet Nam|VU:Vanuatu|WF:Wallis and Futuna|WS:Samoa|YE:Yemen|YT:Mayotte|'
  . 'ZA:South Africa|ZM:Zambia|ZW:Zimbabwe';

function dfms_countries(): array {
    static $countries = null;
    if ($countries === null) {
        $countries = [];
        foreach (explode('|', DFMS_COUNTRY_DATA) as $entry) {
            [$code, $name] = explode(':', $entry, 2);
            $countries[$code] = $name;
        }
        asort($countries, SORT_STRING);
    }
    return $countries;
}

function dfms_is_valid_country(?string $code): bool {
    return $code !== null && isset(dfms_countries()[strtoupper($code)]);
}

function dfms_country_name(?string $code): ?string {
    if (!dfms_is_valid_country($code)) {
        return null;
    }
    return dfms_countries()[strtoupper($code)];
}

// ---------------------------------------------------------------------------
// Currencies — ISO 4217, with the minor-unit count that determines decimal
// places.
//
// The minor unit is NOT always 2. Japanese yen and Korean won have none;
// Bahraini, Kuwaiti, Omani, Jordanian and Tunisian dinars have three.
// Formatting money with a hardcoded 2 is wrong in those currencies, so the
// exponent is carried as reference data rather than assumed.
// ---------------------------------------------------------------------------
const DFMS_CURRENCY_DATA =
    'AED:2:UAE Dirham|AFN:2:Afghan Afghani|ALL:2:Albanian Lek|AMD:2:Armenian Dram|ARS:2:Argentine Peso|'
  . 'AUD:2:Australian Dollar|AZN:2:Azerbaijani Manat|BAM:2:Bosnia-Herzegovina Mark|BDT:2:Bangladeshi Taka|'
  . 'BGN:2:Bulgarian Lev|BHD:3:Bahraini Dinar|BND:2:Brunei Dollar|BOB:2:Bolivian Boliviano|BRL:2:Brazilian Real|'
  . 'BWP:2:Botswanan Pula|BYN:2:Belarusian Ruble|CAD:2:Canadian Dollar|CHF:2:Swiss Franc|CLP:0:Chilean Peso|'
  . 'CNY:2:Chinese Yuan|COP:2:Colombian Peso|CRC:2:Costa Rican Colón|CZK:2:Czech Koruna|DKK:2:Danish Krone|'
  . 'DOP:2:Dominican Peso|DZD:2:Algerian Dinar|EGP:2:Egyptian Pound|ETB:2:Ethiopian Birr|EUR:2:Euro|'
  . 'GBP:2:British Pound|GEL:2:Georgian Lari|GHS:2:Ghanaian Cedi|GTQ:2:Guatemalan Quetzal|HKD:2:Hong Kong Dollar|'
  . 'HNL:2:Honduran Lempira|HRK:2:Croatian Kuna|HUF:2:Hungarian Forint|IDR:2:Indonesian Rupiah|ILS:2:Israeli Shekel|'
  . 'INR:2:Indian Rupee|IQD:3:Iraqi Dinar|IRR:2:Iranian Rial|ISK:0:Icelandic Króna|JMD:2:Jamaican Dollar|'
  . 'JOD:3:Jordanian Dinar|JPY:0:Japanese Yen|KES:2:Kenyan Shilling|KGS:2:Kyrgystani Som|KHR:2:Cambodian Riel|'
  . 'KRW:0:South Korean Won|KWD:3:Kuwaiti Dinar|KZT:2:Kazakhstani Tenge|LAK:2:Laotian Kip|LBP:2:Lebanese Pound|'
  . 'LKR:2:Sri Lankan Rupee|LYD:3:Libyan Dinar|MAD:2:Moroccan Dirham|MDL:2:Moldovan Leu|MKD:2:Macedonian Denar|'
  . 'MMK:2:Myanmar Kyat|MNT:2:Mongolian Tugrik|MUR:2:Mauritian Rupee|MVR:2:Maldivian Rufiyaa|MWK:2:Malawian Kwacha|'
  . 'MXN:2:Mexican Peso|MYR:2:Malaysian Ringgit|MZN:2:Mozambican Metical|NAD:2:Namibian Dollar|NGN:2:Nigerian Naira|'
  . 'NIO:2:Nicaraguan Córdoba|NOK:2:Norwegian Krone|NPR:2:Nepalese Rupee|NZD:2:New Zealand Dollar|OMR:3:Omani Rial|'
  . 'PAB:2:Panamanian Balboa|PEN:2:Peruvian Sol|PGK:2:Papua New Guinean Kina|PHP:2:Philippine Peso|'
  . 'PKR:2:Pakistani Rupee|PLN:2:Polish Zloty|PYG:0:Paraguayan Guarani|QAR:2:Qatari Rial|RON:2:Romanian Leu|'
  . 'RSD:2:Serbian Dinar|RUB:2:Russian Ruble|RWF:0:Rwandan Franc|SAR:2:Saudi Riyal|SEK:2:Swedish Krona|'
  . 'SGD:2:Singapore Dollar|THB:2:Thai Baht|TND:3:Tunisian Dinar|TRY:2:Turkish Lira|TTD:2:Trinidad Dollar|'
  . 'TWD:2:New Taiwan Dollar|TZS:2:Tanzanian Shilling|UAH:2:Ukrainian Hryvnia|UGX:0:Ugandan Shilling|'
  . 'USD:2:US Dollar|UYU:2:Uruguayan Peso|UZS:2:Uzbekistani Som|VND:0:Vietnamese Dong|XAF:0:Central African Franc|'
  . 'XOF:0:West African Franc|YER:2:Yemeni Rial|ZAR:2:South African Rand|ZMW:2:Zambian Kwacha';

function dfms_currencies(): array {
    static $currencies = null;
    if ($currencies === null) {
        $currencies = [];
        foreach (explode('|', DFMS_CURRENCY_DATA) as $entry) {
            [$code, $exponent, $name] = explode(':', $entry, 3);
            $currencies[$code] = ['exponent' => (int) $exponent, 'name' => $name];
        }
        ksort($currencies);
    }
    return $currencies;
}

function dfms_is_valid_currency(?string $code): bool {
    return $code !== null && isset(dfms_currencies()[strtoupper($code)]);
}

// Decimal places for a currency. Defaults to 2 for an unknown code, but
// callers should validate first — this is a rendering fallback, not a licence
// to store unvalidated codes.
function dfms_currency_exponent(string $code): int {
    $currencies = dfms_currencies();
    return $currencies[strtoupper($code)]['exponent'] ?? 2;
}

function dfms_currency_name(string $code): ?string {
    return dfms_currencies()[strtoupper($code)]['name'] ?? null;
}

// ---------------------------------------------------------------------------
// Timezones — taken from PHP's own IANA database rather than a hardcoded list,
// so the set stays correct as the tzdata package is updated.
// ---------------------------------------------------------------------------
function dfms_timezones(): array {
    static $zones = null;
    if ($zones === null) {
        $zones = DateTimeZone::listIdentifiers();
    }
    return $zones;
}

function dfms_is_valid_timezone(?string $tz): bool {
    if ($tz === null || $tz === '') {
        return false;
    }
    // in_array against the identifier list rather than try/catch on the
    // constructor: DateTimeZone accepts offsets like "+06:00" and abbreviations
    // like "CEST", neither of which survives a daylight-saving transition
    // correctly. Only full IANA identifiers are acceptable.
    return in_array($tz, dfms_timezones(), true);
}

// ---------------------------------------------------------------------------
// Locales
//
// English is the fallback and the only complete catalogue. Bangla is included
// to prove the mechanism works with a non-Latin script and a different digit
// system — it is a DEMONSTRATION, not a claim of full localization.
// ---------------------------------------------------------------------------
const DFMS_LOCALES = [
    'en' => 'English',
    'bn' => 'বাংলা (Bangla)',
];

const DFMS_FALLBACK_LOCALE = 'en';

function dfms_is_valid_locale(?string $locale): bool {
    return $locale !== null && isset(DFMS_LOCALES[$locale]);
}

// ---------------------------------------------------------------------------
// Translation
//
// Catalogues live in lang/<locale>.php and return a flat key => string array.
// An unknown key returns the KEY ITSELF rather than an empty string, so a
// missing translation is visible in the interface instead of silently
// rendering a blank label.
// ---------------------------------------------------------------------------
function dfms_load_catalogue(string $locale): array {
    static $cache = [];
    if (isset($cache[$locale])) {
        return $cache[$locale];
    }
    $path = __DIR__ . '/../lang/' . basename($locale) . '.php';
    if (!is_file($path)) {
        return $cache[$locale] = [];
    }
    $catalogue = require $path;
    return $cache[$locale] = is_array($catalogue) ? $catalogue : [];
}

/**
 * Translate a key.
 *
 * Resolution: requested locale -> fallback locale -> the key itself.
 * $params are substituted as :name placeholders.
 */
function dfms_translate(string $key, string $locale, array $params = []): string {
    $catalogue = dfms_load_catalogue($locale);
    $text = $catalogue[$key] ?? null;

    if ($text === null && $locale !== DFMS_FALLBACK_LOCALE) {
        $text = dfms_load_catalogue(DFMS_FALLBACK_LOCALE)[$key] ?? null;
    }
    if ($text === null) {
        // Visible, not blank. A missing key should be obvious in review.
        $text = $key;
    }

    foreach ($params as $name => $value) {
        $text = str_replace(':' . $name, (string) $value, $text);
    }
    return $text;
}

// ---------------------------------------------------------------------------
// Formatting
//
// ext-intl is not guaranteed to be present (it is off by default in stock
// XAMPP), so every formatter degrades to a correct, if less idiomatic,
// non-intl implementation rather than fataling.
// ---------------------------------------------------------------------------
function dfms_has_intl(): bool {
    static $has = null;
    if ($has === null) {
        $has = extension_loaded('intl') && class_exists('NumberFormatter');
    }
    return $has;
}

function dfms_format_number($value, string $locale, int $decimals = 2): string {
    $value = (float) $value;
    if (dfms_has_intl()) {
        $formatter = new NumberFormatter($locale, NumberFormatter::DECIMAL);
        $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $decimals);

        // Rounding mode is set EXPLICITLY so the two branches of this function
        // cannot disagree. ICU defaults to half-even ("banker's rounding"), so
        // it renders 1234.5 at zero decimals as 1,234, while the number_format()
        // fallback below rounds half away from zero and renders 1,235. That
        // made the formatting of a monetary amount depend on whether the intl
        // extension happened to be installed on the server — a difference of
        // one whole yen on an exact half, silently, with no way for a reader to
        // tell which engine produced the figure.
        //
        // CI found this: it runs on a PHP build that includes intl, and the
        // assertion `dfms_format_money(1234.5, 'JPY', 'en') === 'JPY 1,235'`
        // failed there while passing on the intl-less development machine.
        // Half-up is chosen because it is what the fallback already did and so
        // is what every stored figure was produced with.
        $formatter->setAttribute(NumberFormatter::ROUNDING_MODE, NumberFormatter::ROUND_HALFUP);

        $formatted = $formatter->format($value);
        if ($formatted !== false) {
            return $formatted;
        }
    }
    return number_format($value, $decimals, '.', ',');
}

/**
 * Format a monetary amount.
 *
 * Decimal places come from the currency's ISO 4217 minor unit, never from a
 * hardcoded 2 — see the currency table above.
 *
 * The currency CODE is always rendered, not a bare symbol. '$1,000' is
 * ambiguous across at least a dozen currencies; 'USD 1,000.00' is not. Until
 * DFMIS has real multi-currency accounting, unambiguous beats pretty.
 */
function dfms_format_money($amount, string $currency, string $locale): string {
    $currency = strtoupper($currency);
    $decimals = dfms_currency_exponent($currency);
    return $currency . ' ' . dfms_format_number($amount, $locale, $decimals);
}

// ---------------------------------------------------------------------------
// Date and time rendering
//
// See docs/DFMIS_INTERNATIONAL_ARCHITECTURE.md for the storage strategy. In
// short: instants are stored UTC and rendered in the resolved timezone;
// date-only values are calendar facts and are NEVER timezone-converted.
// ---------------------------------------------------------------------------

/**
 * Render a stored instant (TIMESTAMP / DATETIME) in the given timezone.
 *
 * $stored is interpreted as UTC, which is what the application writes after
 * Phase 2. Returns null for null/empty input so callers can render a dash.
 */
function dfms_format_instant(?string $stored, string $timezone, string $locale, string $format = 'Y-m-d H:i'): ?string {
    if ($stored === null || $stored === '' || $stored === '0000-00-00 00:00:00') {
        return null;
    }
    try {
        $utc = new DateTimeImmutable($stored, new DateTimeZone('UTC'));
        $local = $utc->setTimezone(new DateTimeZone(dfms_is_valid_timezone($timezone) ? $timezone : 'UTC'));
        return $local->format($format);
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Render a date-only value.
 *
 * NO timezone conversion is applied, deliberately. A birth date or a
 * production day is a calendar fact, not an instant: shifting it by a
 * timezone offset can move it to the previous or next day and silently
 * change what the record means.
 */
function dfms_format_date(?string $date, string $locale, string $format = 'Y-m-d'): ?string {
    if ($date === null || $date === '' || $date === '0000-00-00') {
        return null;
    }
    try {
        return (new DateTimeImmutable($date))->format($format);
    } catch (Exception $e) {
        return null;
    }
}

/**
 * "Today" in a given timezone, as a Y-m-d string.
 *
 * This is what date-only queries must compare against. Using the database's
 * CURDATE() instead compares against the SERVER's calendar day, which is a
 * different day from the farm's for several hours of every day.
 */
function dfms_today(string $timezone): string {
    $tz = new DateTimeZone(dfms_is_valid_timezone($timezone) ? $timezone : 'UTC');
    return (new DateTimeImmutable('now', $tz))->format('Y-m-d');
}

/**
 * A date offset from today in the given timezone, as Y-m-d.
 * Negative $days goes backwards.
 */
function dfms_date_offset(string $timezone, int $days): string {
    $tz = new DateTimeZone(dfms_is_valid_timezone($timezone) ? $timezone : 'UTC');
    $date = new DateTimeImmutable('now', $tz);
    return $date->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
}

/** Current instant as a UTC 'Y-m-d H:i:s' string, for writing to the database. */
function dfms_utc_now(): string {
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
}
