<?php
// ============================================================================
// Migration 013 — AI animal image analysis (Phase 7)
//
// ONE new table. No change to any existing one.
//
// ---------------------------------------------------------------------------
// WHY THE IMAGE ITSELF IS NOT IN THIS TABLE
//
// It already has an owner. `stored_files` (Phase 2B) writes bytes outside the
// web root under org_/farm_/category, gives every file a random 32-hex name so
// no URL can be derived from an entity id, records who uploaded it, and is
// readable ONLY through modules/files/serve.php, which re-checks tenancy on
// every byte. Copying an image into a second table would produce two sets of
// bytes with one access-control path between them, and the copy is always the
// one that outlives a deletion.
//
// So this table holds a `file_id` and nothing else about the image. Deleting
// the file through deleteStoredFile() removes the bytes; the analysis row
// remains as the record that an analysis happened, which is what an audit
// question actually asks.
//
// ---------------------------------------------------------------------------
// WHY THIS IS NOT A HEALTH TABLE
//
// Phase 4's `health_observations` records a fact a person established about an
// animal — a temperature that was taken, a behaviour that was witnessed. It is
// clinical truth, and the health domain treats it as such.
//
// An AI image analysis is NOT that. It is a description of a photograph
// produced by an untrusted external system, and giving it a row in a clinical
// table would make it indistinguishable from something a stockperson saw with
// their own eyes. Every downstream reader — the health timeline, the case
// summary, the withdrawal logic — would then be reasoning over model output as
// if it were a measurement.
//
// It is therefore a SEPARATE, clearly-named table, and nothing in this phase
// writes from it into the health domain. Promoting an analysis into a health
// record is a deliberate human action for a later phase to design.
//
// ---------------------------------------------------------------------------
// WHY THE RESULT IS ONE JSON COLUMN
//
// Compare `ai_message_sources`, which is a real table because "which of our
// documents has this passage been cited in" must be a QUERY. Nothing asks that
// of an individual observation: the result is read back whole, for one
// analysis, on one page. The facts that ARE queried — status and urgency — are
// columns with indexes.
//
// The column holds only structure that has already passed
// includes/ai_schema.php, which rejects unknown keys, unbounded strings and
// wrong types. Nothing raw from a provider is ever written here.
//
// WHAT IS NEVER STORED: an API key, a provider credential, a raw request or
// response envelope, request headers, the image bytes, or any base64 payload.
// ============================================================================

return function (PDO $conn): array {
    $log = [];

    foreach (['cows', 'stored_files'] as $required) {
        if (!schemaTableExists($conn, $required)) {
            throw new RuntimeException("$required is missing — run the earlier migrations first.");
        }
    }

    // -----------------------------------------------------------------------
    // LIFECYCLE STATES, and why all five are real.
    //
    //   pending      the row exists, nothing has been sent yet
    //   processing   handed to the provider; a row left here means the request
    //                died mid-flight, which is a fact worth being able to see
    //   completed    a validated structured result is stored
    //   failed       the provider answered and the answer was unusable, or it
    //                errored — `failure_reason` says which, as a machine token
    //   unavailable  never attempted: AI not configured, allowance spent, or
    //                rate limited. Distinct from `failed` because nothing was
    //                spent and retrying later is the right advice.
    //
    // The states are not decorative. The row is inserted as `pending` and moved
    // to `processing` BEFORE the provider call, so a crashed or timed-out
    // request cannot leave behind something that looks like a success.
    // -----------------------------------------------------------------------
    $log[] = schemaCreateTable($conn, 'ai_image_analyses',
        "analysis_id     INT AUTO_INCREMENT PRIMARY KEY,
         farm_id         INT NOT NULL,
         cow_id          INT NOT NULL COMMENT 'an image analysis is always about one animal',
         file_id         INT NOT NULL COMMENT 'stored_files; the bytes are never duplicated here',
         requested_by    INT NULL,
         status          ENUM('pending','processing','completed','failed','unavailable') NOT NULL DEFAULT 'pending',
         failure_reason  VARCHAR(64) NULL COMMENT 'machine token only; never an upstream error body',
         provider        VARCHAR(32) NULL,
         model           VARCHAR(64) NULL,
         prompt_key      VARCHAR(64) NULL,
         prompt_version  INT NULL,
         reported_signs  VARCHAR(500) NULL COMMENT 'what the PERSON reported; a report, never an observation',
         urgency         ENUM('routine','monitor','veterinary_review','urgent') NULL COMMENT 'the resolved urgency: never lower than the deterministic floor',
         urgency_flagged TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'the server-side safety layer flagged the reported signs, independently of the model',
         result_json     MEDIUMTEXT NULL COMMENT 'schema-validated observations only; never a raw provider body',
         input_tokens    INT NOT NULL DEFAULT 0,
         output_tokens   INT NOT NULL DEFAULT 0,
         created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
         completed_at    TIMESTAMP NULL DEFAULT NULL,
         INDEX idx_img_farm_animal (farm_id, cow_id, analysis_id),
         INDEX idx_img_farm_created (farm_id, created_at),
         INDEX idx_img_file (file_id)"
    );

    // RESTRICT on the farm, matching every other tenant table: a farm with
    // history is not something a foreign key should quietly erase.
    $log[] = schemaAddForeignKey($conn, 'ai_image_analyses', 'fk_img_farm',
        'farm_id', 'farms', 'farm_id', 'RESTRICT');

    // CASCADE from the animal. Unlike ai_conversations — which holds clinical
    // REASONING worth keeping after an animal leaves the herd — an analysis is
    // a description of one photograph of one animal. With the animal gone it
    // describes nothing, and cow_id is NOT NULL here precisely because an
    // analysis detached from its subject would be meaningless rather than
    // merely incomplete.
    $log[] = schemaAddForeignKey($conn, 'ai_image_analyses', 'fk_img_animal',
        'cow_id', 'cows', 'cow_id', 'CASCADE');

    // CASCADE from the file for the same reason: deleteStoredFile() soft-deletes
    // the row and genuinely removes the bytes, but a hard file deletion must not
    // leave an analysis pointing at an id that will one day be reused.
    $log[] = schemaAddForeignKey($conn, 'ai_image_analyses', 'fk_img_file',
        'file_id', 'stored_files', 'file_id', 'CASCADE');

    return $log;
};
