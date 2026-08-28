<?php
// ============================================================================
// AI MODULE — shared bootstrap and render helpers.
//
// Defines NO business rules and NO safety policy. Those live in
// includes/ai_vet.php and includes/ai_knowledge.php, which are the single
// places a rule is written. This file only assembles requires and markup.
//
// DIRECT EXECUTION GUARD
// A partial, not a page. Reaching it directly would run a file that has
// deliberately not called requireAuth() — the including page does that,
// because the required permission differs between consulting and curating.
// ============================================================================

if (!defined('DFMS_AI_PAGE')) {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/errors.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/repository.php';
require_once __DIR__ . '/../../includes/validation.php';
require_once __DIR__ . '/../../includes/settings.php';
require_once __DIR__ . '/../../includes/features.php';
require_once __DIR__ . '/../../includes/livestock.php';
require_once __DIR__ . '/../../includes/health.php';
require_once __DIR__ . '/../../includes/ai_vet.php';
require_once __DIR__ . '/../../includes/ai_knowledge.php';

/**
 * Refuse the whole module when the farm has switched the feature off.
 *
 * A feature flag is NOT authorization — every page still calls requireAuth()
 * with its own permission. This is the separate question of whether the farm
 * wants this surface at all, and it is enforced server-side rather than by
 * hiding a nav link.
 */
function aiRequireFeature(): void {
    if (dfms_feature_enabled('ai_vet_assistant')) {
        return;
    }
    if (function_exists('dfms_abort')) {
        dfms_abort(404, 'ai_vet_assistant feature disabled for farm ' . (currentFarmId() ?? '?'));
    }
    http_response_code(404);
    exit('Not found.');
}

/**
 * Read an OPTIONAL resource id from the query string.
 *
 * Absent  -> null, and the page renders its default view.
 * Present but malformed -> 404.
 *
 * The distinction matters. DfmsValidator::id() with ['nullable' => true]
 * returns null for BOTH cases, which would silently render the default view
 * for `?c=abc` — a 200 where every other DFMIS page returns 404. That breaks
 * the Phase 1 convention that a malformed id and a foreign one are
 * indistinguishable, and it lets a malformed id be quietly reinterpreted as
 * "no id at all".
 *
 * This is for ids that identify a RESOURCE BEING VIEWED. A convenience
 * preselect (`?cow_id=` on a form) is different and may safely be ignored,
 * exactly as the Phase 4 health forms do.
 */
function aiOptionalResourceId(string $key): ?int {
    if (!isset($_GET[$key]) || $_GET[$key] === '') {
        return null;
    }
    $v = new DfmsValidator($_GET);
    $id = $v->id($key);
    if ($v->fails()) {
        if (function_exists('dfms_abort')) {
            dfms_abort(404, "Malformed resource id for '$key'");
        }
        http_response_code(404);
        exit('Not found.');
    }
    return $id;
}

/** Animals a consultation may be attached to, on the active farm. */
function aiSelectableAnimals(PDO $conn): array {
    return tenantQuery(
        $conn,
        "SELECT cow_id, unique_id, name, species
           FROM cows
          WHERE {scope} AND deleted_at IS NULL AND lifecycle_status = 'active'
          ORDER BY unique_id"
    );
}

/**
 * Styles scoped to the AI module.
 *
 * Namespaced under .dfms-ai-scope so nothing here can alter a Phase 1–4 page.
 * The design system in assets/css/style.css stays authoritative and untouched;
 * every colour, space and radius below is one of its existing tokens.
 */
function aiScopedStyles(): void {
    echo '<style>
    .dfms-ai-scope .dfms-btn { touch-action: manipulation; }
    .dfms-ai-scope h1, .dfms-ai-scope h2, .dfms-ai-scope h3 { text-wrap: balance; }
    /* A long document title or source name must wrap inside its cell rather
       than force the whole page sideways. */
    .dfms-ai-scope td { overflow-wrap: anywhere; }

    .ai-layout { display: grid; grid-template-columns: 17rem 1fr; gap: var(--space-5); align-items: start; }
    .ai-thread { display: flex; flex-direction: column; gap: var(--space-4); }

    .ai-msg { border-radius: var(--radius-md); padding: var(--space-4); font-size: var(--text-md); line-height: var(--leading-normal); }
    .ai-msg-user { background: var(--surface-sunken); border: 1px solid var(--border); }
    .ai-msg-assistant { background: var(--card-bg); border: 1px solid var(--border); }
    .ai-msg-degraded { background: var(--warning-bg); border: 1px solid var(--warning); color: var(--warning-fg); }
    .ai-msg-role { font-size: var(--text-xs); text-transform: uppercase; letter-spacing: var(--tracking-wide); color: var(--text-muted); margin-bottom: var(--space-2); }
    .ai-msg-body { white-space: pre-wrap; overflow-wrap: anywhere; }

    .ai-urgent {
        border-left: 4px solid var(--danger); background: var(--danger-bg); color: var(--danger-fg);
        padding: var(--space-4); border-radius: var(--radius-sm); margin-bottom: var(--space-4);
        font-size: var(--text-md);
    }
    .ai-urgent strong { display: block; margin-bottom: var(--space-2); }

    .ai-notice {
        border-left: 3px solid var(--info); background: var(--info-bg); color: var(--info-fg);
        padding: var(--space-3) var(--space-4); border-radius: var(--radius-sm);
        font-size: var(--text-sm); margin-bottom: var(--space-4);
    }
    .ai-disclaimer { font-size: var(--text-xs); color: var(--text-muted); margin-top: var(--space-3); }

    .ai-sources { margin-top: var(--space-4); padding-top: var(--space-3); border-top: 1px solid var(--border); }
    .ai-sources-title { font-size: var(--text-xs); text-transform: uppercase; letter-spacing: var(--tracking-wide); color: var(--text-muted); margin-bottom: var(--space-2); }
    .ai-sources ol { margin: 0; padding-left: 1.2rem; font-size: var(--text-sm); }
    .ai-sources li { margin-bottom: var(--space-1); }
    /* Phase 5F — record lookups. Deliberately the SAME visual treatment as
       .ai-sources: both answer "where did this come from", and giving one
       provenance block a louder style than the other would imply a difference
       in authority that does not exist. */
    .ai-records { margin-top: var(--space-4); padding-top: var(--space-3); border-top: 1px solid var(--border); }
    .ai-records-title { font-size: var(--text-xs); text-transform: uppercase; letter-spacing: var(--tracking-wide); color: var(--text-muted); margin-bottom: var(--space-2); }
    .ai-records ul { margin: 0; padding-left: 1.2rem; font-size: var(--text-sm); }
    .ai-records li { margin-bottom: var(--space-1); }

    .ai-conv-list { list-style: none; margin: 0; padding: 0; }
    .ai-conv-list li { border-bottom: 1px solid var(--border); }
    .ai-conv-list a { display: block; padding: var(--space-3); font-size: var(--text-sm); text-decoration: none; color: var(--text); }
    .ai-conv-list a:hover { background: var(--surface-sunken); }
    .ai-conv-list a[aria-current="true"] { background: var(--surface-sunken); font-weight: 600; }
    .ai-conv-meta { display: block; font-size: var(--text-xs); color: var(--text-muted); margin-top: 2px; }

    @media (max-width: 900px) {
        .ai-layout { grid-template-columns: 1fr; }
    }
    </style>';
}

/**
 * Render one message.
 *
 * Content is escaped and rendered as pre-wrapped text. The model's output is
 * NEVER treated as markup — it is untrusted text from outside the application
 * and interpreting it as HTML would be an injection route straight into the
 * page.
 */
function aiRenderMessage(array $message): void {
    $isAssistant = $message['role'] === 'assistant';
    $isDegraded = $isAssistant && ($message['status'] ?? 'ok') !== 'ok';

    $class = 'ai-msg ' . ($isDegraded ? 'ai-msg-degraded' : ($isAssistant ? 'ai-msg-assistant' : 'ai-msg-user'));
    echo '<article class="' . $class . '">';
    echo '<p class="ai-msg-role">'
        . ($isAssistant ? ($isDegraded ? 'Assistant — unavailable' : 'Assistant') : 'You')
        . ' · ' . e(fmt_instant($message['created_at'])) . '</p>';
    echo '<div class="ai-msg-body">' . e((string) $message['content']) . '</div>';

    // Phase 5F — which of the farm's own records the answer consulted.
    //
    // Rendered as plain sentences from a fixed label map, never as registry
    // tool names, JSON arguments or protocol data. Only SUCCESSFUL lookups are
    // stored, so this cannot announce a refusal: telling a stockperson that
    // the assistant tried to read something it was not permitted to read is
    // alarming, unactionable, and a description of the security layer working.
    if ($isAssistant && !$isDegraded) {
        $toolsUsed = aiVetMessageToolsUsed($message);
        if ($toolsUsed !== []) {
            echo '<div class="ai-records">';
            echo '<p class="ai-records-title">Records checked while answering</p>';
            echo '<ul>';
            foreach ($toolsUsed as $tool) {
                echo '<li>' . e(aiAgentToolLabel($tool)) . '</li>';
            }
            echo '</ul></div>';
        }
    }

    if ($isAssistant && !$isDegraded && !empty($message['sources'])) {
        echo '<div class="ai-sources">';
        echo '<p class="ai-sources-title">Knowledge used from your farm\'s own documents</p>';
        echo '<ol>';
        foreach ($message['sources'] as $source) {
            echo '<li>' . e($source['document_title'])
                . ' <span class="dfms-muted">— ' . e($source['source_name']);
            if (!empty($source['attribution'])) {
                echo ', ' . e($source['attribution']);
            }
            echo '</span></li>';
        }
        echo '</ol></div>';
    }

    if ($isAssistant && !$isDegraded) {
        echo '<p class="ai-disclaimer">' . e(aiVetDisclaimer()) . '</p>';
    }
    echo '</article>';
}
