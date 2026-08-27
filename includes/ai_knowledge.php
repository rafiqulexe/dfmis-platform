<?php
// ============================================================================
// KNOWLEDGE BASE & RETRIEVAL — the RAG foundation.
//
// FOUR SEPARATE CONCERNS, deliberately not collapsed:
//
//   SOURCE     a curated collection with provenance ("our farm protocols",
//              "transcribed national FMD guidance"). Carries attribution,
//              because a citation must say whose words these are.
//   DOCUMENT   one titled body of text inside a source. The authoritative copy.
//   CHUNK      the retrievable unit. Derived from the document, rebuilt
//              wholesale whenever the body changes, so a chunk can never claim
//              to quote text the document no longer contains.
//   RETRIEVAL  aiKnowledgeRetrieve(). The ONLY function that knows how a chunk
//              is found.
//
// WHY CHUNKS ARE NOT DOCUMENTS
// A document is far too large to put in a prompt, and retrieving whole
// documents is what makes naive RAG useless — the model drowns in irrelevant
// text and cites a page instead of a passage.
//
// ---------------------------------------------------------------------------
// RETRIEVAL IS LEXICAL, NOT VECTOR — and that is a deliberate choice
//
// InnoDB FULLTEXT gives genuine relevance-ranked retrieval with no new
// dependency and no new service. It is a real index, not a pretence.
//
// Its known weakness is handled rather than ignored: innodb_ft_min_token_size
// is 3 on the reference engine, so short or stopword-heavy queries can match
// nothing. A scored LIKE pass runs when FULLTEXT returns nothing, and when
// BOTH find nothing the caller is told so honestly.
//
// FUTURE VECTOR SEARCH: add an `embedding` column and an ANN index to
// ai_knowledge_chunks, and change the BODY of aiKnowledgeRetrieve(). Nothing
// above it — the context builder, the assistant, the UI, the API — depends on
// how a chunk was found, only on the shape this function returns. That shape
// is the contract, and it is documented on the function.
// ---------------------------------------------------------------------------
//
// NOTHING IS SEEDED. This file creates no knowledge. Every passage the
// assistant can retrieve was written by a person at the farm, which is the
// only honest basis on which this project can put text in front of a
// veterinary decision.
// ============================================================================

require_once __DIR__ . '/tenant.php';
require_once __DIR__ . '/repository.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/livestock.php';

// ---------------------------------------------------------------------------
// Source types. Provenance matters: a farm's own standing order and a
// transcribed national guideline carry different authority, and the citation
// shown under an answer says which one it was.
// ---------------------------------------------------------------------------
const DFMS_KNOWLEDGE_SOURCE_TYPES = [
    'farm_protocol'      => 'Farm protocol or standing order',
    'vet_guidance'       => 'Guidance from our veterinarian',
    'official_guideline' => 'Official or regulatory guideline',
    'product_label'      => 'Product label or datasheet',
    'reference'          => 'Reference material',
    'other'              => 'Other',
];

function dfms_knowledge_source_types(): array {
    return DFMS_KNOWLEDGE_SOURCE_TYPES;
}

function dfms_is_valid_knowledge_source_type(?string $type): bool {
    return $type !== null && isset(DFMS_KNOWLEDGE_SOURCE_TYPES[$type]);
}

// ---------------------------------------------------------------------------
// Chunking parameters.
//
// ~900 characters is small enough that several passages fit in a prompt
// alongside the animal record, and large enough that a single instruction
// ("withhold milk for four days") is not split from its condition. The overlap
// stops a sentence that straddles a boundary from becoming unretrievable.
// ---------------------------------------------------------------------------
const DFMS_CHUNK_TARGET_CHARS = 900;
const DFMS_CHUNK_OVERLAP_CHARS = 120;
const DFMS_CHUNK_MIN_CHARS = 40;

/** A domain rule the user broke, shown verbatim — contains nothing internal. */
class KnowledgeRuleViolation extends RuntimeException {}

// ===========================================================================
// SOURCES
// ===========================================================================

function aiKnowledgeSources(PDO $conn, bool $activeOnly = false): array {
    $clause = $activeOnly ? ' AND s.is_active = 1' : '';
    return tenantQuery(
        $conn,
        "SELECT s.*,
                (SELECT COUNT(*) FROM ai_knowledge_documents d
                  WHERE d.source_id = s.source_id AND d.farm_id = s.farm_id) AS document_count
           FROM ai_knowledge_sources s
          WHERE {scope}" . $clause . "
          ORDER BY s.name",
        [],
        's'
    );
}

function aiKnowledgeFindSource(PDO $conn, $sourceId): ?array {
    return tenantFind($conn, 'ai_knowledge_sources', 'source_id', $sourceId);
}

/** @throws KnowledgeRuleViolation */
function aiKnowledgeCreateSource(PDO $conn, array $data): int {
    $name = trim((string) ($data['name'] ?? ''));
    if ($name === '') {
        throw new KnowledgeRuleViolation('Give the knowledge source a name.');
    }
    $type = (string) ($data['source_type'] ?? 'farm_protocol');
    if (!dfms_is_valid_knowledge_source_type($type)) {
        throw new KnowledgeRuleViolation('That is not a recognised source type.');
    }

    // The unique index enforces this too; checking first turns a database
    // error into a sentence the user can act on.
    $existing = tenantQueryOne(
        $conn,
        "SELECT source_id FROM ai_knowledge_sources s WHERE {scope} AND s.name = ?",
        [$name],
        's'
    );
    if ($existing !== null) {
        throw new KnowledgeRuleViolation('A knowledge source with that name already exists.');
    }

    $sourceId = tenantInsert($conn, 'ai_knowledge_sources', [
        'name'        => $name,
        'source_type' => $type,
        'description' => $data['description'] ?? null,
        'attribution' => $data['attribution'] ?? null,
        'is_active'   => !empty($data['is_active']) ? 1 : 0,
        'created_by'  => currentUserId(),
    ]);

    auditCreate('ai_knowledge_source', $sourceId, [
        'name' => $name, 'source_type' => $type,
    ]);
    return $sourceId;
}

/** @throws KnowledgeRuleViolation */
function aiKnowledgeSetSourceActive(PDO $conn, int $sourceId, bool $active): bool {
    $source = tenantFindOr404($conn, 'ai_knowledge_sources', 'source_id', $sourceId);
    tenantExec(
        $conn,
        "UPDATE ai_knowledge_sources SET is_active = ?, updated_at = NOW()
          WHERE source_id = ? AND {scope}",
        [$active ? 1 : 0, $sourceId]
    );
    auditUpdate('ai_knowledge_source', $sourceId,
        ['is_active' => (int) $source['is_active']], ['is_active' => $active ? 1 : 0]);
    return true;
}

// ===========================================================================
// DOCUMENTS
// ===========================================================================

function aiKnowledgeDocuments(PDO $conn, ?int $sourceId = null): array {
    $params = [];
    $clause = '';
    if ($sourceId !== null) {
        $clause = ' AND d.source_id = ?';
        $params[] = $sourceId;
    }
    return tenantQuery(
        $conn,
        "SELECT d.*, s.name AS source_name, s.source_type, s.attribution, s.is_active AS source_active
           FROM ai_knowledge_documents d
           JOIN ai_knowledge_sources s ON s.source_id = d.source_id AND s.farm_id = d.farm_id
          WHERE {scope}" . $clause . "
          ORDER BY d.created_at DESC, d.document_id DESC",
        $params,
        'd'
    );
}

function aiKnowledgeFindDocument(PDO $conn, $documentId): ?array {
    return tenantFind($conn, 'ai_knowledge_documents', 'document_id', $documentId);
}

/**
 * Store a document and (re)build its chunks in ONE transaction.
 *
 * A document whose chunks do not match its body is worse than no document at
 * all: the assistant would cite a passage the farm can no longer find in the
 * text it thinks it wrote.
 *
 * @throws KnowledgeRuleViolation
 */
function aiKnowledgeSaveDocument(PDO $conn, array $data, ?int $documentId = null): int {
    $title = trim((string) ($data['title'] ?? ''));
    $body = trim((string) ($data['body'] ?? ''));

    if ($title === '') {
        throw new KnowledgeRuleViolation('Give the document a title.');
    }
    if (mb_strlen($body, 'UTF-8') < DFMS_CHUNK_MIN_CHARS) {
        throw new KnowledgeRuleViolation(
            'The document text is too short to be useful. Enter at least '
            . DFMS_CHUNK_MIN_CHARS . ' characters.'
        );
    }

    // The source must belong to THIS farm. A source id arriving from a form is
    // never trusted — tenantFind() returns null for a foreign one exactly as
    // it does for a missing one.
    $source = aiKnowledgeFindSource($conn, $data['source_id'] ?? null);
    if ($source === null) {
        throw new KnowledgeRuleViolation('Choose a knowledge source that belongs to this farm.');
    }

    $species = $data['species_scope'] ?? null;
    if ($species !== null && $species !== '' && !dfms_is_valid_species($species)) {
        throw new KnowledgeRuleViolation('That is not a recognised species.');
    }
    $species = ($species === '' ? null : $species);

    $existing = $documentId !== null ? aiKnowledgeFindDocument($conn, $documentId) : null;
    if ($documentId !== null && $existing === null) {
        throw new KnowledgeRuleViolation('That document does not belong to this farm.');
    }

    return tenantTransaction($conn, function (PDO $conn) use (
        $data, $title, $body, $source, $species, $existing
    ): int {
        if ($existing === null) {
            $id = tenantInsert($conn, 'ai_knowledge_documents', [
                'source_id'     => (int) $source['source_id'],
                'title'         => $title,
                'body'          => $body,
                'species_scope' => $species,
                'is_active'     => !empty($data['is_active']) ? 1 : 0,
                'chunk_count'   => 0,
                'created_by'    => currentUserId(),
            ]);
        } else {
            $id = (int) $existing['document_id'];
            tenantExec(
                $conn,
                "UPDATE ai_knowledge_documents
                    SET source_id = ?, title = ?, body = ?, species_scope = ?, is_active = ?, updated_at = NOW()
                  WHERE document_id = ? AND {scope}",
                [
                    (int) $source['source_id'], $title, $body, $species,
                    !empty($data['is_active']) ? 1 : 0, $id,
                ]
            );
        }

        $chunkCount = aiKnowledgeRebuildChunks($conn, $id, (int) $source['source_id'], $body);

        // Audit the document's IDENTITY and size, never its full body. A
        // knowledge base can run to many pages and the audit log is not a
        // second copy of it.
        if ($existing === null) {
            auditCreate('ai_knowledge_document', $id, [
                'source_id' => (int) $source['source_id'],
                'title'     => $title,
                'chars'     => mb_strlen($body, 'UTF-8'),
                'chunks'    => $chunkCount,
            ]);
        } else {
            auditUpdate('ai_knowledge_document', $id,
                ['title' => $existing['title'], 'chars' => mb_strlen((string) $existing['body'], 'UTF-8')],
                ['title' => $title, 'chars' => mb_strlen($body, 'UTF-8'), 'chunks' => $chunkCount]
            );
        }
        return $id;
    });
}

/**
 * Replace every chunk for a document. Called only inside a transaction owned
 * by the document write, so chunks and body commit together.
 *
 * @return int the number of chunks written
 */
function aiKnowledgeRebuildChunks(PDO $conn, int $documentId, int $sourceId, string $body): int {
    tenantExec($conn, "DELETE FROM ai_knowledge_chunks WHERE document_id = ? AND {scope}", [$documentId]);

    $chunks = aiKnowledgeChunkText($body);
    foreach ($chunks as $index => $chunk) {
        tenantInsert($conn, 'ai_knowledge_chunks', [
            'document_id' => $documentId,
            'source_id'   => $sourceId,
            'chunk_index' => $index,
            'body'        => $chunk,
            'char_length' => mb_strlen($chunk, 'UTF-8'),
        ]);
    }

    tenantExec(
        $conn,
        "UPDATE ai_knowledge_documents SET chunk_count = ? WHERE document_id = ? AND {scope}",
        [count($chunks), $documentId]
    );
    return count($chunks);
}

/**
 * Split text into retrievable passages.
 *
 * Splits on PARAGRAPH boundaries first and only falls back to a hard character
 * cut for a paragraph that is itself oversized. Splitting mid-sentence by
 * default would routinely separate a dose from the animal it applies to.
 *
 * Deterministic and side-effect free, so it is directly unit-testable.
 *
 * @return list<string>
 */
function aiKnowledgeChunkText(string $text): array {
    $text = str_replace(["\r\n", "\r"], "\n", trim($text));
    if ($text === '') {
        return [];
    }

    $paragraphs = preg_split('/\n\s*\n/', $text) ?: [$text];
    $chunks = [];
    $current = '';

    foreach ($paragraphs as $paragraph) {
        $paragraph = trim($paragraph);
        if ($paragraph === '') {
            continue;
        }

        // An oversized paragraph is cut on its own, with overlap, rather than
        // being allowed to blow past the target.
        if (mb_strlen($paragraph, 'UTF-8') > DFMS_CHUNK_TARGET_CHARS) {
            if ($current !== '') {
                $chunks[] = $current;
                $current = '';
            }
            foreach (aiKnowledgeSplitLongText($paragraph) as $piece) {
                $chunks[] = $piece;
            }
            continue;
        }

        $candidate = $current === '' ? $paragraph : $current . "\n\n" . $paragraph;
        if (mb_strlen($candidate, 'UTF-8') > DFMS_CHUNK_TARGET_CHARS) {
            $chunks[] = $current;
            $current = $paragraph;
        } else {
            $current = $candidate;
        }
    }
    if ($current !== '') {
        $chunks[] = $current;
    }

    // Drop fragments too small to carry meaning, but never return nothing for
    // a document that had text — a single short document is still one chunk.
    $useful = array_values(array_filter($chunks, static function (string $chunk): bool {
        return mb_strlen(trim($chunk), 'UTF-8') >= DFMS_CHUNK_MIN_CHARS;
    }));
    if ($useful === [] && $chunks !== []) {
        return [trim($chunks[0])];
    }
    return $useful;
}

/**
 * Hard-cut an oversized paragraph, preferring a sentence boundary near the
 * target and carrying an overlap so a straddling sentence stays retrievable.
 *
 * @return list<string>
 */
function aiKnowledgeSplitLongText(string $paragraph): array {
    $pieces = [];
    $length = mb_strlen($paragraph, 'UTF-8');
    $position = 0;

    while ($position < $length) {
        $take = min(DFMS_CHUNK_TARGET_CHARS, $length - $position);
        $slice = mb_substr($paragraph, $position, $take, 'UTF-8');

        // Prefer to end on a sentence boundary in the last third of the slice.
        if ($position + $take < $length) {
            $searchFrom = (int) floor(mb_strlen($slice, 'UTF-8') * 0.6);
            $tail = mb_substr($slice, $searchFrom, null, 'UTF-8');
            if (preg_match('/^(.*[.!?])\s/su', $tail, $match)) {
                $slice = mb_substr($slice, 0, $searchFrom, 'UTF-8') . $match[1];
                $take = mb_strlen($slice, 'UTF-8');
            }
        }

        $pieces[] = trim($slice);
        if ($position + $take >= $length) {
            break;
        }
        // Always advance, even if the overlap would otherwise stall the loop.
        $position += max(1, $take - DFMS_CHUNK_OVERLAP_CHARS);
    }
    return $pieces;
}

/** @throws KnowledgeRuleViolation */
function aiKnowledgeDeleteDocument(PDO $conn, int $documentId): bool {
    $document = tenantFindOr404($conn, 'ai_knowledge_documents', 'document_id', $documentId);

    // Chunks cascade. Citations on past answers cascade with them, which is
    // deliberate: a citation pointing at a passage that no longer exists is
    // worse than an answer that shows no citation.
    tenantExec($conn, "DELETE FROM ai_knowledge_documents WHERE document_id = ? AND {scope}", [$documentId]);

    auditDelete('ai_knowledge_document', $documentId, [
        'title'     => $document['title'],
        'source_id' => (int) $document['source_id'],
    ]);
    return true;
}

// ===========================================================================
// RETRIEVAL
// ===========================================================================

/**
 * Find the passages most relevant to a question.
 *
 * THE STABLE CONTRACT — everything above retrieval depends on this shape and
 * on nothing else. Swapping the body of this function for embeddings and an
 * ANN index requires no change anywhere else in the application.
 *
 * @return array{
 *   chunks: list<array{chunk_id:int, document_id:int, source_id:int, chunk_index:int,
 *                      body:string, relevance:float, document_title:string,
 *                      source_name:string, source_type:string, attribution:?string}>,
 *   strategy: string,
 *   query_terms: list<string>
 * }
 *
 * Tenant-scoped by construction: both passes go through tenantQuery(), so a
 * passage belonging to another farm is unreachable, and inactive sources and
 * documents are excluded rather than merely hidden in the UI.
 */
function aiKnowledgeRetrieve(PDO $conn, string $question, int $limit = 5, ?string $species = null): array {
    $limit = max(1, min($limit, 20));
    $terms = aiKnowledgeQueryTerms($question);

    $empty = ['chunks' => [], 'strategy' => 'none', 'query_terms' => $terms];
    if ($terms === []) {
        return $empty;
    }

    // Species narrowing: a document scoped to goats should not be retrieved
    // for a question about cattle. NULL scope always applies.
    $speciesClause = '';
    $speciesParams = [];
    if ($species !== null && dfms_is_valid_species($species)) {
        $speciesClause = ' AND (d.species_scope IS NULL OR d.species_scope = ?)';
        $speciesParams[] = $species;
    }

    $select = "SELECT c.chunk_id, c.document_id, c.source_id, c.chunk_index, c.body,
                      d.title AS document_title, s.name AS source_name,
                      s.source_type, s.attribution";
    $from = " FROM ai_knowledge_chunks c
              JOIN ai_knowledge_documents d ON d.document_id = c.document_id AND d.farm_id = c.farm_id
              JOIN ai_knowledge_sources s   ON s.source_id  = c.source_id   AND s.farm_id = c.farm_id
             WHERE {scope} AND d.is_active = 1 AND s.is_active = 1" . $speciesClause;

    // ---- pass 1: FULLTEXT, relevance-ranked -------------------------------
    //
    // THE BOOLEAN QUERY IS INLINED, NOT BOUND — and that needs justifying.
    //
    // tenantQuery() binds the farm id as parameter #1 POSITIONALLY, so the
    // {scope} token must be the first placeholder in the statement. A `?` in
    // the SELECT clause necessarily precedes the WHERE, which would hand the
    // farm id to MATCH() and the search string to the tenant filter. That
    // fails closed rather than leaking (a string coerces to farm_id 0, which
    // matches nothing) but it silently disables the index — this was a real
    // defect during development, caught only because the strategy came back
    // as 'like' when it should have been 'fulltext'.
    //
    // Inlining is safe HERE because the value is provably constrained:
    // aiKnowledgeQueryTerms() has already stripped everything that is not a
    // letter, digit or space, and the only other character is the trailing '*'
    // this function adds. The assertion below proves that rather than assuming
    // it, and refuses to build a query if it is ever untrue — the same
    // pattern dfms_assert_identifier() uses for table names.
    $booleanQuery = implode(' ', array_map(static function (string $term): string {
        return $term . '*';
    }, $terms));

    $rows = [];
    if (!preg_match('/^[\p{L}\p{N}* ]+$/u', $booleanQuery)) {
        // Unreachable unless the term extractor changes. Fail to the LIKE
        // pass rather than building a statement we cannot prove is safe.
        error_log('[' . dfms_ai_request_ref() . '] knowledge retrieval: refusing unsafe boolean query');
        $booleanQuery = '';
    }

    try {
        if ($booleanQuery === '') {
            throw new RuntimeException('boolean query unavailable');
        }
        $match = "MATCH(c.body) AGAINST ('" . $booleanQuery . "' IN BOOLEAN MODE)";
        $rows = tenantQuery(
            $conn,
            $select . ', ' . $match . ' AS relevance'
            . $from . ' AND ' . $match . '
                ORDER BY relevance DESC, c.chunk_id ASC
                LIMIT ' . (int) $limit,
            $speciesParams,
            'c'
        );
    } catch (Throwable $e) {
        // A FULLTEXT index the engine will not serve must degrade to the LIKE
        // pass, not take the consultation down with it.
        error_log('[' . dfms_ai_request_ref() . '] knowledge retrieval: fulltext unavailable, falling back');
        $rows = [];
    }

    $strategy = 'fulltext';

    // ---- pass 2: scored LIKE ---------------------------------------------
    // Runs only when FULLTEXT found nothing. innodb_ft_min_token_size is 3 on
    // the reference engine, so a legitimate short query ("cow off feed") can
    // match zero rows through the index while the words are plainly present.
    if ($rows === []) {
        $conditions = [];
        $params = $speciesParams;
        foreach ($terms as $term) {
            $conditions[] = 'c.body LIKE ?';
            $params[] = '%' . $term . '%';
        }
        $rows = tenantQuery(
            $conn,
            $select . ', 0 AS relevance' . $from
            . ' AND (' . implode(' OR ', $conditions) . ')
                ORDER BY c.chunk_id ASC
                LIMIT ' . (int) ($limit * 4),
            $params,
            'c'
        );
        $strategy = $rows === [] ? 'none' : 'like';

        // Score by how many distinct query terms a passage actually contains,
        // so the LIKE pass still ranks rather than returning arbitrary rows.
        foreach ($rows as $index => $row) {
            $body = mb_strtolower((string) $row['body'], 'UTF-8');
            $hits = 0;
            foreach ($terms as $term) {
                if (mb_strpos($body, $term, 0, 'UTF-8') !== false) {
                    $hits++;
                }
            }
            $rows[$index]['relevance'] = $hits;
        }
        usort($rows, static function (array $a, array $b): int {
            return $b['relevance'] <=> $a['relevance'];
        });
        $rows = array_slice($rows, 0, $limit);
    }

    if ($rows === []) {
        return $empty;
    }

    $chunks = array_map(static function (array $row): array {
        return [
            'chunk_id'       => (int) $row['chunk_id'],
            'document_id'    => (int) $row['document_id'],
            'source_id'      => (int) $row['source_id'],
            'chunk_index'    => (int) $row['chunk_index'],
            'body'           => (string) $row['body'],
            'relevance'      => round((float) $row['relevance'], 4),
            'document_title' => (string) $row['document_title'],
            'source_name'    => (string) $row['source_name'],
            'source_type'    => (string) $row['source_type'],
            'attribution'    => $row['attribution'] !== null ? (string) $row['attribution'] : null,
        ];
    }, $rows);

    return ['chunks' => $chunks, 'strategy' => $strategy, 'query_terms' => $terms];
}

/**
 * Reduce a question to searchable terms.
 *
 * Strips punctuation, lowercases, drops very short words and a small English
 * stopword list. The stopword list is intentionally small: over-filtering a
 * veterinary question removes the words that carry the meaning.
 *
 * @return list<string>
 */
function aiKnowledgeQueryTerms(string $question): array {
    $normalised = mb_strtolower($question, 'UTF-8');
    $normalised = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $normalised) ?? '';
    $words = preg_split('/\s+/u', trim($normalised)) ?: [];

    $stopwords = [
        'the', 'and', 'for', 'are', 'but', 'not', 'you', 'all', 'can', 'her', 'was',
        'one', 'our', 'out', 'has', 'have', 'had', 'what', 'when', 'why', 'how',
        'who', 'this', 'that', 'with', 'from', 'they', 'them', 'there', 'their',
        'been', 'were', 'will', 'would', 'should', 'could', 'does', 'did', 'about',
        'into', 'than', 'then', 'some', 'any', 'his', 'she', 'him', 'its',
    ];

    $terms = [];
    foreach ($words as $word) {
        if (mb_strlen($word, 'UTF-8') < 3 || in_array($word, $stopwords, true)) {
            continue;
        }
        if (!in_array($word, $terms, true)) {
            $terms[] = $word;
        }
        if (count($terms) >= 12) {
            break;   // a prompt-length query must not become a 200-term search
        }
    }
    return $terms;
}

/** Counts for the knowledge management screen. */
function aiKnowledgeStats(PDO $conn): array {
    return [
        'sources'   => (int) tenantScalar($conn, "SELECT COUNT(*) FROM ai_knowledge_sources s WHERE {scope}", [], 0, 's'),
        'documents' => (int) tenantScalar($conn, "SELECT COUNT(*) FROM ai_knowledge_documents d WHERE {scope}", [], 0, 'd'),
        'chunks'    => (int) tenantScalar($conn, "SELECT COUNT(*) FROM ai_knowledge_chunks c WHERE {scope}", [], 0, 'c'),
        'retrievable' => (int) tenantScalar(
            $conn,
            "SELECT COUNT(*) FROM ai_knowledge_chunks c
               JOIN ai_knowledge_documents d ON d.document_id = c.document_id AND d.farm_id = c.farm_id
               JOIN ai_knowledge_sources s ON s.source_id = c.source_id AND s.farm_id = c.farm_id
              WHERE {scope} AND d.is_active = 1 AND s.is_active = 1",
            [], 0, 'c'
        ),
    ];
}
