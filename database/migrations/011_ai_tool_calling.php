<?php
// ============================================================================
// Migration 011 — AI tool calling (Phase 5F)
//
// ONE NULLABLE COLUMN. No new table, and no change to any existing one beyond
// this addition.
//
// ---------------------------------------------------------------------------
// WHY TOOL CALLS ARE NOT STORED AS CONVERSATION MESSAGES
//
// The obvious design is a third value in ai_messages.role — 'tool' alongside
// 'user' and 'assistant' — with one row per tool request and one per result.
// It was rejected, for reasons the schema itself makes plain:
//
//   1. aiVetMessages() is read by TWO consumers with opposite needs: the UI
//      thread, and the history replayed to the model. Protocol rows would be
//      rendered to a stockperson as chat, and replayed to the model as prose
//      claiming to be something a person said.
//
//   2. Every existing reader — the module page, /api/v1/ai-conversations, the
//      message renderer, the citation join — assumes exactly two roles. A
//      third would need each of them changed to filter it out, and the one
//      that got missed would be the leak.
//
//   3. ai_conversations.message_count is maintained as `+ 2` per exchange.
//      Variable-length tool traffic makes that counter meaningless.
//
//   4. The tool exchange is only meaningful WITHIN one request. Replaying a
//      week-old tool result to the model would present stale farm data as
//      current — the opposite of what looking it up was for.
//
// So the exchange lives in memory for the duration of one consultation, and
// what survives is PROVENANCE: which lookups informed this answer.
//
// ---------------------------------------------------------------------------
// WHY PROVENANCE IS WORTH A COLUMN AT ALL
//
// This product already treats "where did this claim come from" as a
// first-class concern: ai_message_sources exists so a cited passage can be
// traced to a titled document the farm can go and read. A record lookup is the
// same kind of claim from a different source, and an answer that silently
// consulted an animal's medication history should be able to say so.
//
// WHAT IT HOLDS: a short JSON array of registry tool NAMES that SUCCEEDED —
// e.g. ["get_animal","get_animal_health_summary"]. Names only.
//
// WHAT IT NEVER HOLDS: tool arguments, tool results, clinical content,
// provider identifiers, error text, or anything a credential could reach. It
// is written from aiAgentToolsUsed(), which filters to successful names and
// nothing else.
//
// NULL means "no lookups, or an answer from before this migration" — both
// true, and neither back-filled with a guess.
// ============================================================================

return function (PDO $conn): array {
    $log = [];

    if (!schemaTableExists($conn, 'ai_messages')) {
        throw new RuntimeException('ai_messages is missing — run migration 009 first.');
    }

    // VARCHAR(255) is a deliberate ceiling, not a guess. The registry holds
    // five tools whose names total well under 200 bytes with JSON syntax, and
    // max_tool_calls caps how many can appear. A column that cannot overflow
    // needs no truncation logic at the write site.
    //
    // Not a JSON column: this schema targets MySQL/MariaDB across versions
    // where JSON support differs, nothing queries inside the value, and the
    // rest of the codebase stores small structured values the same way.
    $log[] = schemaAddColumn($conn, 'ai_messages', 'tools_used',
        "VARCHAR(255) NULL COMMENT 'JSON list of registry tool names that informed this answer; NULL for none' AFTER model");

    // No index. Nothing filters or sorts on it — it is read only alongside the
    // message row it belongs to, which is already fetched by conversation_id.
    // An index here would cost every write and serve no query.

    return $log;
};
