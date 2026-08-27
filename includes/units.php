<?php
// ============================================================================
// MEASUREMENT & UNITS
//
// CANONICAL STORAGE STRATEGY
// ---------------------------------------------------------------------------
// Every physical quantity is STORED in exactly one canonical (metric) unit and
// CONVERTED only at the moment it is displayed or parsed from user input.
//
//   quantity      canonical stored unit    column(s)
//   ------------  ----------------------   ----------------------------------
//   mass          kilogram (kg)            feeding_schedules.feed_amount
//                                          feed_consumption.total_feed_consumed
//   volume        litre (L)                milk_production.total_milk_yield
//   temperature   degrees Celsius (°C)     temperature_readings.temperature
//
// WHY THIS IS SAFE FOR EXISTING DATA
// Every one of those columns ALREADY holds metric values — verified against
// the live database before this file was written (milk ~108 L/day, feed
// ~5 kg per schedule, temperature ~36.5 °C). Declaring metric canonical
// therefore reinterprets NOTHING. No historical value changes meaning, and no
// backfill is required. Had the existing data been imperial, the honest move
// would have been a migration that converted it explicitly, not a redefinition
// of what the numbers mean.
//
// WHY NOT weight_kg / weight_lb COLUMN PAIRS
// Two columns for one fact drift apart the moment anything writes one and not
// the other, and the pair still cannot express a third unit. One canonical
// column plus a conversion at the edge is smaller, cannot desynchronise, and
// extends to any unit.
//
// NOT IN SCOPE
// inventory_levels.quantity is deliberately excluded. Its unit is a free-text
// label on inventory_items.unit ('Bottle', 'Pack', 'Bundle', 'Kg') chosen by
// the user — it is a COUNT of user-defined packaging, not a physical
// measurement, and converting it would be meaningless.
// ============================================================================

const DFMS_MEASUREMENT_SYSTEMS = ['metric', 'imperial'];

// Canonical unit per quantity kind. Storage always uses these.
const DFMS_CANONICAL_UNITS = [
    'mass'        => 'kg',
    'volume'      => 'l',
    'temperature' => 'c',
];

// Display unit per quantity kind, per measurement system.
const DFMS_DISPLAY_UNITS = [
    'metric' => [
        'mass'        => 'kg',
        'volume'      => 'l',
        'temperature' => 'c',
    ],
    'imperial' => [
        'mass'        => 'lb',
        'volume'      => 'gal',
        'temperature' => 'f',
    ],
];

// Short labels for rendering. Kept separate from the unit keys so a label can
// be localized later without changing any stored or computed value.
const DFMS_UNIT_LABELS = [
    'kg'  => 'kg',
    'lb'  => 'lb',
    'l'   => 'L',
    'gal' => 'gal',
    'c'   => '°C',
    'f'   => '°F',
];

// Exact conversion factors, canonical -> target.
// Mass and volume are ratios; temperature is affine and handled separately.
const DFMS_CONVERSION_FACTORS = [
    'mass' => [
        'kg' => 1.0,
        'lb' => 2.20462262185,      // 1 kg in international pounds
    ],
    'volume' => [
        'l'   => 1.0,
        'gal' => 0.264172052358,    // 1 L in US liquid gallons
    ],
];

function dfms_is_valid_measurement_system(?string $system): bool {
    return $system !== null && in_array($system, DFMS_MEASUREMENT_SYSTEMS, true);
}

function dfms_canonical_unit(string $kind): string {
    if (!isset(DFMS_CANONICAL_UNITS[$kind])) {
        throw new InvalidArgumentException("Unknown quantity kind: $kind");
    }
    return DFMS_CANONICAL_UNITS[$kind];
}

/** The unit a given measurement system displays a quantity kind in. */
function dfms_display_unit(string $kind, string $system): string {
    if (!isset(DFMS_CANONICAL_UNITS[$kind])) {
        throw new InvalidArgumentException("Unknown quantity kind: $kind");
    }
    if (!dfms_is_valid_measurement_system($system)) {
        $system = 'metric';
    }
    return DFMS_DISPLAY_UNITS[$system][$kind];
}

function dfms_unit_label(string $unit): string {
    return DFMS_UNIT_LABELS[$unit] ?? $unit;
}

/**
 * Convert a canonical (stored) value into a display unit.
 *
 * @throws InvalidArgumentException on an unknown kind or unit — an unknown
 *         unit must never be silently treated as the canonical one, because
 *         that returns a plausible-looking number that is wrong.
 */
function dfms_from_canonical($value, string $kind, string $unit): float {
    $value = (float) $value;
    $unit = strtolower($unit);

    if ($kind === 'temperature') {
        if ($unit === 'c') {
            return $value;
        }
        if ($unit === 'f') {
            return ($value * 9 / 5) + 32;
        }
        throw new InvalidArgumentException("Unknown temperature unit: $unit");
    }

    if (!isset(DFMS_CONVERSION_FACTORS[$kind])) {
        throw new InvalidArgumentException("Unknown quantity kind: $kind");
    }
    if (!isset(DFMS_CONVERSION_FACTORS[$kind][$unit])) {
        throw new InvalidArgumentException("Unknown $kind unit: $unit");
    }
    return $value * DFMS_CONVERSION_FACTORS[$kind][$unit];
}

/**
 * Convert a value the user entered in $unit back into the canonical unit for
 * storage. The exact inverse of dfms_from_canonical().
 */
function dfms_to_canonical($value, string $kind, string $unit): float {
    $value = (float) $value;
    $unit = strtolower($unit);

    if ($kind === 'temperature') {
        if ($unit === 'c') {
            return $value;
        }
        if ($unit === 'f') {
            return ($value - 32) * 5 / 9;
        }
        throw new InvalidArgumentException("Unknown temperature unit: $unit");
    }

    if (!isset(DFMS_CONVERSION_FACTORS[$kind][$unit])) {
        throw new InvalidArgumentException("Unknown $kind unit: $unit");
    }
    return $value / DFMS_CONVERSION_FACTORS[$kind][$unit];
}

/**
 * Convert a canonical value into the given system and format it with its unit.
 *
 * Temperature gets one decimal place; mass and volume get two — matching the
 * DECIMAL(x,2) precision the columns actually store, so the display never
 * implies more precision than exists.
 */
function dfms_format_measurement($canonicalValue, string $kind, string $system, string $locale = 'en'): string {
    $unit = dfms_display_unit($kind, $system);
    $converted = dfms_from_canonical($canonicalValue, $kind, $unit);
    $decimals = $kind === 'temperature' ? 1 : 2;

    $number = function_exists('dfms_format_number')
        ? dfms_format_number($converted, $locale, $decimals)
        : number_format($converted, $decimals);

    // Degree symbols sit tight against the number; other units take a space.
    $label = dfms_unit_label($unit);
    $separator = in_array($unit, ['c', 'f'], true) ? '' : ' ';

    return $number . $separator . $label;
}
