<?php
// ============================================================================
// Migration 010 — AI platform foundation (Phase 5E)
//
// THE SMALLEST MIGRATION THAT PHASE 5E ACTUALLY NEEDS.
//
// NO NEW TABLES. That is the notable thing about this file, and it is
// deliberate. Phase 5E adds structured output, a prompt registry, request
// configuration, a retry policy, rate limiting and a read-only tool registry —
// and not one of those needs somewhere new to store rows:
//
//   structured output   pure validation; nothing is persisted, by design
//   prompt registry     code, versioned in git where a prompt change is
//                       reviewable — a prompts TABLE would move the safety
//                       contract out of code review and into a form
//   configuration       environment; the same tier as the API key
//   rate limiting       counts rows already in ai_usage (see the header of
//                       includes/ai_ratelimit.php for why a counters table
//                       would be a second source of truth nobody reconciles)
//   AI tools            code; a tool registry built from database rows would
//                       mean a row could grant a capability
//
// SPECULATIVE TABLES ARE NOT CREATED HERE. There is no disease-prediction
// table, no image-analysis table, no IoT table, no embeddings table. Those
// phases are not built, and a table shaped for an unbuilt feature is a
// guess that later code has to work around.
//
// WHAT IT DOES DO — three columns and one index on ai_usage:
//
//   provider        which vendor served the call
//   prompt_key      which registered prompt was in force
//   prompt_version  which version of it
//   idx_ai_usage_admin_created   makes the per-user rate-limit window indexed
//
// The three columns exist because of a question that gets asked after a bad
// answer is found, often months later: what were we asking the model at the
// time? `model` alone cannot answer it — the prompt has been edited since, and
// without a version the row points at whatever the file says today.
//
// NON-DESTRUCTIVE AND IDEMPOTENT. Every column is nullable with no default, so
// existing ai_usage rows keep their meaning: NULL means "recorded before this
// migration", which is true, rather than being backfilled with a value that
// would be a fabrication.
// ============================================================================

return function (PDO $conn): array {
    $log = [];

    if (!schemaTableExists($conn, 'ai_usage')) {
        throw new RuntimeException('ai_usage is missing — run earlier migrations first.');
    }

    // -----------------------------------------------------------------------
    // Provenance columns.
    //
    // NULLABLE ON PURPOSE. A row written before Phase 5E genuinely does not
    // know which prompt version produced it, and back-filling 'v1' would state
    // something nobody verified. An honest NULL is more useful than a
    // confident guess when this column is read in an incident.
    //
    // Sized to the identifiers that exist: a provider name is a short machine
    // token (see DfmsAiProvider::name()), and a prompt key is an array key in
    // includes/ai_prompts.php. Neither is user-supplied.
    // -----------------------------------------------------------------------
    $log[] = schemaAddColumn($conn, 'ai_usage', 'provider',
        "VARCHAR(32) NULL COMMENT 'DfmsAiProvider::name() — NULL for rows predating Phase 5E' AFTER model");

    $log[] = schemaAddColumn($conn, 'ai_usage', 'prompt_key',
        "VARCHAR(64) NULL COMMENT 'key in includes/ai_prompts.php; NULL when the caller named none' AFTER provider");

    $log[] = schemaAddColumn($conn, 'ai_usage', 'prompt_version',
        "SMALLINT UNSIGNED NULL COMMENT 'version of prompt_key at the time of the call' AFTER prompt_key");

    // -----------------------------------------------------------------------
    // The per-user rate-limit window.
    //
    // includes/ai_ratelimit.php counts a user's calls in the last minute and
    // the last hour. idx_ai_usage_org_created (migration 003) already serves
    // the organization side of that query; this covers the per-user side.
    //
    // WHY THIS INDEX AND NOT (admin_id, status, created_at): the status filter
    // eliminates very little — almost every row is 'success' — while widening
    // the index. Leading with admin_id and ranging on created_at is what
    // actually reduces the rows examined.
    //
    // A rate limiter that costs a table scan is a denial-of-service amplifier:
    // the more a system is hammered, the more expensive its own defence gets.
    // -----------------------------------------------------------------------
    $log[] = schemaAddIndex($conn, 'ai_usage', 'idx_ai_usage_admin_created', 'admin_id, created_at');

    // No FOREIGN KEY is added for admin_id. ai_usage rows are an accounting
    // and abuse-protection record and must SURVIVE a user being deleted —
    // the spend still happened, and the organization was still billed for it.
    // The existing fk_ai_usage_org already ties a row to a real tenant.

    return $log;
};
