<?php
// ============================================================================
// English catalogue — the FALLBACK locale and the only complete one.
//
// Scope note: Phase 2 establishes the translation MECHANISM. It deliberately
// does not externalize the thousands of strings currently inline in module
// templates; that is incremental work each later phase does for its own
// surfaces. These keys cover the shared navigation and action vocabulary.
//
// Key convention: <area>.<thing>, lowercase, dot-separated.
// ============================================================================

return [
    // Navigation
    'nav.dashboard'        => 'Dashboard',
    'nav.livestock'        => 'Livestock',
    'nav.animals'          => 'Animals',
    'nav.milk'             => 'Milk Production',
    'nav.health'           => 'Health',
    'nav.feeding'          => 'Feeding',
    'nav.inventory'        => 'Inventory',
    'nav.alerts'           => 'Alerts',
    'nav.reports'          => 'Reports',
    'nav.audit'            => 'Audit Log',
    'nav.settings'         => 'Settings',

    // Actions
    'action.save'          => 'Save',
    'action.cancel'        => 'Cancel',
    'action.delete'        => 'Delete',
    'action.edit'          => 'Edit',
    'action.add'           => 'Add',
    'action.back'          => 'Back',
    'action.search'        => 'Search',
    'action.sign_out'      => 'Sign out',

    // Common states
    'state.unauthorized'   => 'Unauthorized',
    'state.not_found'      => 'Not found',
    'state.saved'          => 'Saved.',
    'state.no_changes'     => 'No changes to save.',
    'state.loading'        => 'Loading…',

    // Settings
    'settings.title'               => 'Settings',
    'settings.organization'        => 'Organization',
    'settings.farm'                => 'Farm',
    'settings.features'            => 'Features',
    'settings.country'             => 'Country',
    'settings.timezone'            => 'Timezone',
    'settings.locale'              => 'Language',
    'settings.currency'            => 'Currency',
    'settings.measurement_system'  => 'Measurement system',
    'settings.legal_name'          => 'Legal name',
    'settings.display_name'        => 'Display name',
    'settings.inherit'             => 'Inherit from organization',
    'settings.platform_default'    => 'Platform default',

    // Units
    'unit.metric'          => 'Metric',
    'unit.imperial'        => 'Imperial',
];
