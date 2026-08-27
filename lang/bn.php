<?php
// ============================================================================
// Bangla catalogue — a DEMONSTRATION that the mechanism handles a non-Latin
// script, not a claim of full Bangla localization.
//
// It is deliberately INCOMPLETE. Any key missing here falls back to the
// English catalogue, which is exactly the behaviour a partial translation
// must have: a gap shows the English string, never a blank label.
// ============================================================================

return [
    // Navigation
    'nav.dashboard'        => 'ড্যাশবোর্ড',
    'nav.livestock'        => 'গবাদি পশু',
    'nav.animals'          => 'পশু',
    'nav.milk'             => 'দুধ উৎপাদন',
    'nav.health'           => 'স্বাস্থ্য',
    'nav.feeding'          => 'খাদ্য',
    'nav.inventory'        => 'মজুদ',
    'nav.alerts'           => 'সতর্কতা',
    'nav.settings'         => 'সেটিংস',

    // Actions
    'action.save'          => 'সংরক্ষণ',
    'action.cancel'        => 'বাতিল',
    'action.delete'        => 'মুছুন',
    'action.edit'          => 'সম্পাদনা',
    'action.add'           => 'যোগ করুন',
    'action.sign_out'      => 'সাইন আউট',

    // Common states
    'state.unauthorized'   => 'অননুমোদিত',
    'state.not_found'      => 'পাওয়া যায়নি',
    'state.saved'          => 'সংরক্ষিত হয়েছে।',

    // Settings
    'settings.title'       => 'সেটিংস',
    'settings.country'     => 'দেশ',
    'settings.timezone'    => 'সময় অঞ্চল',
    'settings.locale'      => 'ভাষা',
    'settings.currency'    => 'মুদ্রা',

    // NOTE: nav.reports, nav.audit, settings.* remainder and unit.* are
    // intentionally absent — they exercise the English fallback path.
];
