<?php
// ============================================================================
// Migration 009 — AI Veterinarian Assistant + RAG foundation (Phase 5D)
//
// WHAT THE AUDIT FOUND BEFORE THIS FILE WAS WRITTEN
//   ai_service.php      exists, has ZERO call sites, is hardcoded to one
//                       provider and is single-turn only.
//   ai_usage            exists (migration 003/004). ORGANIZATION-scoped, with a
//                       nullable farm_id, and NOT in DFMS_TENANT_TABLES. It is
//                       reused UNCHANGED for cost attribution and plan gating.
//   stored_files        already provides secure, tenant-scoped, access-
//                       controlled document ownership. Reused; not duplicated.
//   audit_log           DFMS_AUDIT_DENY_KEYS already redacts 'api_key' and
//                       'anthropic_api_key'. Reused; not re-implemented.
//   RAG                 nothing whatsoever. No knowledge, document, chunk,
//                       embedding or retrieval table existed.
//
// WHY SIX TABLES AND NOT FEWER
// The brief requires knowledge sources, documents, chunks and retrieval to be
// SEPARATE concerns, and they genuinely are:
//   - a SOURCE is a curated collection with provenance ("our farm protocols")
//   - a DOCUMENT is one titled body of text inside it
//   - a CHUNK is the retrievable unit, which is not a document (a document is
//     far too large to put in a prompt, and retrieving whole documents is what
//     makes naive RAG useless)
// Collapsing them would mean either retrieving whole documents or losing the
// provenance a citation needs.
//
// WHY NO VECTOR DATABASE
// Retrieval is InnoDB FULLTEXT with a LIKE fallback. That is a REAL lexical
// index with relevance ranking, not a pretence, and it needs no new dependency
// or service. `ai_knowledge_chunks` is deliberately shaped so an `embedding`
// column and an ANN index can be added later WITHOUT touching the veterinary
// domain: everything above retrieval depends only on the shape
// aiKnowledgeRetrieve() returns, never on how it found the rows.
//
// NON-DESTRUCTIVE. No existing table is altered, renamed or dropped. No
// historical animal or health data is touched or reinterpreted. Nothing is
// seeded — no fabricated veterinary knowledge enters the database.
// ============================================================================

return function (PDO $conn): array {
    $log = [];

    // -----------------------------------------------------------------------
    // Pre-flight. This phase reads the Phase 3/4 animal and health record and
    // attributes cost through the Phase 2 usage table.
    // -----------------------------------------------------------------------
    foreach (['cows', 'farms', 'admin_users', 'ai_usage'] as $required) {
        if (!schemaTableExists($conn, $required)) {
            throw new RuntimeException("$required is missing — run earlier migrations first.");
        }
    }

    // =======================================================================
    // 1. KNOWLEDGE SOURCES — a curated collection with provenance.
    //
    // TENANT-OWNED. A farm's own written protocols are its own business data,
    // and one organization's operating procedure must never surface in another
    // organization's consultation. There is deliberately no "global" or
    // "platform" source: shipping seeded veterinary content would mean this
    // project asserting clinical guidance it is not qualified to author, which
    // the brief forbids and which would be dishonest besides.
    //
    // `source_type` records WHERE the text came from, because a farm's own
    // protocol and a transcribed national guideline carry different authority
    // and the citation shown to the user must say which.
    // =======================================================================
    $log[] = schemaCreateTable($conn, 'ai_knowledge_sources',
        "source_id    INT AUTO_INCREMENT PRIMARY KEY,
         farm_id      INT NOT NULL,
         name         VARCHAR(150) NOT NULL,
         source_type  VARCHAR(32) NOT NULL DEFAULT 'farm_protocol' COMMENT 'validated against DFMS_KNOWLEDGE_SOURCE_TYPES',
         description  VARCHAR(500) NULL,
         attribution  VARCHAR(255) NULL COMMENT 'who wrote or published the underlying material',
         is_active    TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'inactive sources are retained but never retrieved',
         created_by   INT NULL,
         created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
         updated_at   TIMESTAMP NULL DEFAULT NULL,
         UNIQUE KEY uq_source_name_per_farm (farm_id, name),
         INDEX idx_ksource_farm_active (farm_id, is_active)"
    );
    $log[] = schemaAddForeignKey($conn, 'ai_knowledge_sources', 'fk_ksource_farm', 'farm_id', 'farms', 'farm_id', 'RESTRICT');

    // =======================================================================
    // 2. KNOWLEDGE DOCUMENTS — one titled body of text within a source.
    //
    // `body` is the authoritative text. Chunks are DERIVED from it and are
    // rebuilt wholesale whenever the body changes, so a chunk can never drift
    // from the document it claims to quote.
    //
    // PDF LIMITATION, STATED HONESTLY: stored_files accepts PDFs (Phase 4) but
    // this codebase has no PDF text extractor and adding one is a dependency
    // this phase does not justify. A document's retrievable content is
    // therefore text that a person actually supplied. A PDF is not silently
    // treated as indexed — see the architecture document.
    // =======================================================================
    $log[] = schemaCreateTable($conn, 'ai_knowledge_documents',
        "document_id  INT AUTO_INCREMENT PRIMARY KEY,
         farm_id      INT NOT NULL,
         source_id    INT NOT NULL,
         title        VARCHAR(200) NOT NULL,
         body         MEDIUMTEXT NOT NULL COMMENT 'the authoritative text; chunks are derived from it',
         species_scope VARCHAR(32) NULL COMMENT 'NULL = applies to every species',
         is_active    TINYINT(1) NOT NULL DEFAULT 1,
         chunk_count  INT NOT NULL DEFAULT 0,
         created_by   INT NULL,
         created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
         updated_at   TIMESTAMP NULL DEFAULT NULL,
         INDEX idx_kdoc_source (source_id, is_active),
         INDEX idx_kdoc_farm (farm_id, is_active),
         INDEX idx_kdoc_species (farm_id, species_scope)"
    );
    $log[] = schemaAddForeignKey($conn, 'ai_knowledge_documents', 'fk_kdoc_farm', 'farm_id', 'farms', 'farm_id', 'RESTRICT');
    $log[] = schemaAddForeignKey($conn, 'ai_knowledge_documents', 'fk_kdoc_source', 'source_id', 'ai_knowledge_sources', 'source_id', 'CASCADE');

    // =======================================================================
    // 3. KNOWLEDGE CHUNKS — the retrievable unit.
    //
    // FULLTEXT on `body` is what makes this real retrieval with relevance
    // ranking rather than a substring scan. innodb_ft_min_token_size is 3 on
    // the reference engine, so short and stopword-heavy queries can match
    // nothing; includes/ai_knowledge.php therefore falls back to a scored LIKE
    // pass, and reports honestly when BOTH find nothing.
    //
    // FUTURE EMBEDDINGS: adding `embedding BLOB` plus an ANN index here, and
    // changing the body of aiKnowledgeRetrieve(), is the entire migration path
    // to vector search. Nothing above retrieval knows how a chunk was found.
    //
    // source_id is denormalised from the document so a citation can be built
    // and filtered without a second join on every retrieval.
    // =======================================================================
    $log[] = schemaCreateTable($conn, 'ai_knowledge_chunks',
        "chunk_id     INT AUTO_INCREMENT PRIMARY KEY,
         farm_id      INT NOT NULL,
         document_id  INT NOT NULL,
         source_id    INT NOT NULL COMMENT 'denormalised from the document for citation without a second join',
         chunk_index  INT NOT NULL COMMENT 'ordinal within the document, so a citation can say where it came from',
         body         TEXT NOT NULL,
         char_length  INT NOT NULL DEFAULT 0,
         created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
         UNIQUE KEY uq_chunk_position (document_id, chunk_index),
         INDEX idx_kchunk_farm (farm_id),
         INDEX idx_kchunk_document (document_id),
         FULLTEXT KEY ft_kchunk_body (body)"
    );
    $log[] = schemaAddForeignKey($conn, 'ai_knowledge_chunks', 'fk_kchunk_farm', 'farm_id', 'farms', 'farm_id', 'RESTRICT');
    $log[] = schemaAddForeignKey($conn, 'ai_knowledge_chunks', 'fk_kchunk_document', 'document_id', 'ai_knowledge_documents', 'document_id', 'CASCADE');

    // =======================================================================
    // 4. CONVERSATIONS.
    //
    // FARM-scoped, and additionally owned by the admin who started it. Both
    // matter: farm_id is the tenant boundary the repository layer enforces,
    // and admin_id keeps one colleague's clinical conversation out of
    // another's list on the same farm.
    //
    // cow_id is NULLABLE — a general veterinary question is not about one
    // animal, and forcing a selection would push users to pick an arbitrary
    // animal and corrupt the context.
    // =======================================================================
    $log[] = schemaCreateTable($conn, 'ai_conversations',
        "conversation_id INT AUTO_INCREMENT PRIMARY KEY,
         farm_id         INT NOT NULL,
         admin_id        INT NOT NULL COMMENT 'the person who started it; scoping is farm_id AND this',
         cow_id          INT NULL COMMENT 'NULL for a general question',
         title           VARCHAR(200) NOT NULL,
         message_count   INT NOT NULL DEFAULT 0,
         last_message_at TIMESTAMP NULL DEFAULT NULL,
         created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
         INDEX idx_conv_farm_admin (farm_id, admin_id, last_message_at),
         INDEX idx_conv_animal (cow_id)"
    );
    $log[] = schemaAddForeignKey($conn, 'ai_conversations', 'fk_conv_farm', 'farm_id', 'farms', 'farm_id', 'RESTRICT');
    // SET NULL, not CASCADE: removing an animal must not delete the clinical
    // reasoning recorded about it.
    $log[] = schemaAddForeignKey($conn, 'ai_conversations', 'fk_conv_animal', 'cow_id', 'cows', 'cow_id', 'SET NULL');

    // =======================================================================
    // 5. MESSAGES.
    //
    // WHAT IS DELIBERATELY NOT STORED: no API key, no provider request or
    // response envelope, no request headers, no IP, no user agent. The audit
    // log already records who acted and from where; duplicating that here
    // would spread request metadata across two tables for no benefit.
    //
    // `status` distinguishes a real answer from a degraded one, so a failed
    // consultation is visible in the history as a failure rather than as
    // silence or, worse, as an answer.
    // =======================================================================
    $log[] = schemaCreateTable($conn, 'ai_messages',
        "message_id      INT AUTO_INCREMENT PRIMARY KEY,
         farm_id         INT NOT NULL,
         conversation_id INT NOT NULL,
         role            ENUM('user','assistant') NOT NULL,
         content         MEDIUMTEXT NOT NULL,
         status          ENUM('ok','degraded') NOT NULL DEFAULT 'ok' COMMENT 'degraded = the provider failed and this records that, not an answer',
         failure_reason  VARCHAR(64) NULL COMMENT 'machine token only; never an upstream error body',
         provider        VARCHAR(32) NULL,
         model           VARCHAR(64) NULL,
         input_tokens    INT NOT NULL DEFAULT 0,
         output_tokens   INT NOT NULL DEFAULT 0,
         urgency_flagged TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'the server-side safety layer flagged this exchange',
         created_by      INT NULL,
         created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
         INDEX idx_msg_conversation (conversation_id, message_id),
         INDEX idx_msg_farm (farm_id, created_at)"
    );
    $log[] = schemaAddForeignKey($conn, 'ai_messages', 'fk_msg_farm', 'farm_id', 'farms', 'farm_id', 'RESTRICT');
    $log[] = schemaAddForeignKey($conn, 'ai_messages', 'fk_msg_conversation', 'conversation_id', 'ai_conversations', 'conversation_id', 'CASCADE');

    // =======================================================================
    // 6. MESSAGE SOURCES — which chunks actually informed which answer.
    //
    // A separate table rather than a JSON column because traceability is a
    // requirement, not a convenience: "which of our documents did the
    // assistant rely on, and where else has that passage been used" must be a
    // query. A JSON blob makes exactly that question unanswerable.
    //
    // `relevance` is the retrieval score AT THE TIME OF THE ANSWER. It is kept
    // even if the chunk is later re-indexed, because the citation is a
    // historical fact about that answer.
    //
    // ON DELETE CASCADE from chunks is correct here and only here: if the
    // underlying passage is deleted, the citation pointing at it is no longer
    // resolvable and a dangling citation is worse than none. The message text
    // itself is untouched.
    // =======================================================================
    $log[] = schemaCreateTable($conn, 'ai_message_sources',
        "message_source_id INT AUTO_INCREMENT PRIMARY KEY,
         farm_id           INT NOT NULL,
         message_id        INT NOT NULL,
         chunk_id          INT NOT NULL,
         document_id       INT NOT NULL,
         relevance         DECIMAL(10,4) NOT NULL DEFAULT 0 COMMENT 'retrieval score at the time of the answer',
         created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
         UNIQUE KEY uq_message_chunk (message_id, chunk_id),
         INDEX idx_msgsrc_farm (farm_id),
         INDEX idx_msgsrc_document (document_id)"
    );
    $log[] = schemaAddForeignKey($conn, 'ai_message_sources', 'fk_msgsrc_farm', 'farm_id', 'farms', 'farm_id', 'RESTRICT');
    $log[] = schemaAddForeignKey($conn, 'ai_message_sources', 'fk_msgsrc_message', 'message_id', 'ai_messages', 'message_id', 'CASCADE');
    $log[] = schemaAddForeignKey($conn, 'ai_message_sources', 'fk_msgsrc_chunk', 'chunk_id', 'ai_knowledge_chunks', 'chunk_id', 'CASCADE');

    // =======================================================================
    // 7. ai_usage — REUSED UNCHANGED.
    //
    // It already carries organization, farm, actor, feature, model, entity,
    // token counts and status, and aiComplete() already gates on it. The
    // provider refactor writes through the same function. `model` is already
    // VARCHAR(64), so recording a different provider's model needs no change.
    // =======================================================================
    $log[] = "  note  ai_usage reused unchanged for cost attribution and plan gating.";
    $log[] = "  note  stored_files reused for document ownership; no second file store.";
    $log[] = "  note  NOTHING seeded — no veterinary knowledge is fabricated by this migration.";

    return $log;
};
