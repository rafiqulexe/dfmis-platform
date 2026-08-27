<?php
// ============================================================================
// FARM TYPE & CAPABILITIES
//
// THE PROBLEM THIS PREVENTS
// The natural way to add a second vertical is to write
//
//     if ($farmType === 'dairy') { … }
//
// wherever behaviour differs. Those checks then multiply through unrelated
// code, and adding a third vertical means finding all of them. The vertical
// becomes impossible to remove and impossible to add.
//
// THE MODEL
// A farm type declares CAPABILITIES. Code asks "can this farm do X?", never
// "is this farm a dairy?". Adding a vertical is then one entry in the table
// below, and no change anywhere else.
//
//     farmHasCapability('milk_production')      // right
//     currentFarmType() === 'livestock'         // wrong, outside this file
//
// SCOPE FOR PHASE 2
// This is the abstraction only. No new vertical is implemented, and the
// capability set is derived from what DFMIS ALREADY does, so current dairy
// behaviour is bit-for-bit unchanged: farm 1 is livestock/dairy and every
// capability it needs resolves true.
//
// The existing `farms.farm_type` and `farms.farm_subtype` columns (migration
// 004) are the source of truth. No new column was added — the capability map
// is derived, not stored, because storing it would let a farm's declared type
// and its declared capabilities disagree.
// ============================================================================

require_once __DIR__ . '/settings.php';

/**
 * Capability registry.
 *
 * Every capability names a MODULE or BEHAVIOUR, not a species. 'milk_production'
 * is right; 'is_dairy' would just be the type check wearing a disguise.
 */
const DFMS_CAPABILITIES = [
    'animal_registry'  => 'Track individual animals with identity and status',
    'milk_production'  => 'Record milk yield',
    'health_events'    => 'Record health events and treatments',
    'feeding'          => 'Feeding schedules and consumption',
    'inventory'        => 'Feed, medicine and supply stock',
    'reproduction'     => 'Breeding, gestation and calving records',
    'egg_production'   => 'Record egg yield',
    'crop_cycles'      => 'Planting, growth stage and harvest',
    'water_quality'    => 'Pond and tank water measurements',
];

/**
 * Which capabilities each farm type has.
 *
 * `subtypes` refines a type where the distinction genuinely changes behaviour:
 * a beef operation records no milk, a dairy does. A subtype entry REPLACES the
 * type's capability list when it matches.
 *
 * Only the livestock/dairy row is exercised today. The others are declared so
 * that the shape of the model is visible and testable, not because those
 * verticals exist — none of their modules are built, and the navigation only
 * ever shows a module that is both capable AND implemented.
 */
const DFMS_FARM_TYPE_CAPABILITIES = [
    'livestock' => [
        'label'        => 'Livestock',
        'capabilities' => ['animal_registry', 'milk_production', 'health_events', 'feeding', 'inventory', 'reproduction'],
        'subtypes'     => [
            'dairy'    => ['animal_registry', 'milk_production', 'health_events', 'feeding', 'inventory', 'reproduction'],
            'beef'     => ['animal_registry', 'health_events', 'feeding', 'inventory', 'reproduction'],
            'breeding' => ['animal_registry', 'health_events', 'feeding', 'inventory', 'reproduction'],
            'mixed'    => ['animal_registry', 'milk_production', 'health_events', 'feeding', 'inventory', 'reproduction'],
        ],
    ],
    'poultry' => [
        'label'        => 'Poultry',
        'capabilities' => ['animal_registry', 'egg_production', 'health_events', 'feeding', 'inventory'],
        'subtypes'     => [],
    ],
    'aquaculture' => [
        'label'        => 'Aquaculture',
        'capabilities' => ['animal_registry', 'health_events', 'feeding', 'inventory', 'water_quality'],
        'subtypes'     => [],
    ],
    'crop' => [
        'label'        => 'Crop',
        'capabilities' => ['crop_cycles', 'inventory'],
        'subtypes'     => [],
    ],
    'greenhouse' => [
        'label'        => 'Greenhouse',
        'capabilities' => ['crop_cycles', 'inventory', 'water_quality'],
        'subtypes'     => [],
    ],
    'mixed' => [
        'label'        => 'Mixed',
        'capabilities' => ['animal_registry', 'milk_production', 'health_events', 'feeding', 'inventory', 'reproduction', 'crop_cycles'],
        'subtypes'     => [],
    ],
];

function dfms_farm_types(): array {
    return DFMS_FARM_TYPE_CAPABILITIES;
}

function dfms_is_valid_farm_type(?string $type): bool {
    return $type !== null && isset(DFMS_FARM_TYPE_CAPABILITIES[$type]);
}

/**
 * The active farm's type and subtype. Confined to this file — no other code
 * should branch on these values directly.
 */
function currentFarmType(): ?string {
    $farm = dfms_farm_row();
    return $farm['farm_type'] ?? null;
}

function currentFarmSubtype(): ?string {
    $farm = dfms_farm_row();
    return $farm['farm_subtype'] ?? null;
}

/**
 * Capabilities for a given type/subtype pair.
 *
 * An UNKNOWN type resolves to the livestock capability set rather than an
 * empty one. That is deliberate: an empty set would silently hide every module
 * from a farm whose type was mistyped, which looks like data loss to the user.
 * Falling back to the current behaviour is the safer failure.
 */
function dfms_capabilities_for(?string $type, ?string $subtype = null): array {
    if (!dfms_is_valid_farm_type($type)) {
        return DFMS_FARM_TYPE_CAPABILITIES['livestock']['capabilities'];
    }
    $definition = DFMS_FARM_TYPE_CAPABILITIES[$type];
    if ($subtype !== null && isset($definition['subtypes'][$subtype])) {
        return $definition['subtypes'][$subtype];
    }
    return $definition['capabilities'];
}

/** Capabilities of the ACTIVE farm. */
function currentFarmCapabilities(): array {
    static $cache = null;
    static $cachedFarm = null;

    $farmId = currentFarmId();
    if ($cache !== null && $cachedFarm === $farmId) {
        return $cache;
    }
    $cachedFarm = $farmId;
    return $cache = dfms_capabilities_for(currentFarmType(), currentFarmSubtype());
}

/**
 * Can the active farm do X?
 *
 * This is the ONLY question application code should ask about farm type.
 *
 * NOT an authorization check. A capability says the farm's business model
 * includes this activity; it says nothing about whether this user may perform
 * it. Both are required:
 *
 *     requireAuth('milk.create');              // may this user?
 *     if (farmHasCapability('milk_production')) // does this farm do it?
 */
function farmHasCapability(string $capability): bool {
    if (!isset(DFMS_CAPABILITIES[$capability])) {
        error_log("farmHasCapability(): unknown capability '$capability' — treating as absent.");
        return false;
    }
    return in_array($capability, currentFarmCapabilities(), true);
}

/**
 * Capabilities that have a BUILT module behind them today.
 *
 * The gap between this and DFMS_CAPABILITIES is the honest statement of what
 * is modelled versus what is implemented. Navigation uses this so a farm type
 * never advertises a module that does not exist.
 */
const DFMS_IMPLEMENTED_CAPABILITIES = [
    'animal_registry',
    'milk_production',
    'health_events',
    'feeding',
    'inventory',
];

function dfms_capability_is_implemented(string $capability): bool {
    return in_array($capability, DFMS_IMPLEMENTED_CAPABILITIES, true);
}

/** True only when the active farm has the capability AND it is built. */
function farmCapabilityAvailable(string $capability): bool {
    return dfms_capability_is_implemented($capability) && farmHasCapability($capability);
}
