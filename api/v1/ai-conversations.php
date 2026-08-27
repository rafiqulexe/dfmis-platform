<?php
// ============================================================================
// GET /api/v1/ai-conversations        the caller's own conversations
// GET /api/v1/ai-conversations/{id}   one conversation with its messages
//
// Requires ai.consult. READ ONLY — consistent with every other DFMIS API
// route. Asking a question is a POST to the HTML module; the API has been
// read-only since Phase 2B and this phase does not change that.
//
// DOUBLE SCOPING, and both halves matter:
//   farm_id   the tenant boundary, enforced by the repository layer
//   admin_id  per-user ownership WITHIN the farm, so a colleague's clinical
//             conversation is not readable just because they share a farm
//
// aiVetFindConversation() applies both and returns null if either fails, so a
// foreign conversation is indistinguishable from one that does not exist.
// ============================================================================

if (!defined('DFMS_API_DISPATCHED')) {
    http_response_code(404);
    exit;
}

// The API front controller loads the Phase 1 gates, not every domain file, so
// each handler requires what it actually uses.
require_once __DIR__ . '/../../includes/features.php';
require_once __DIR__ . '/../../includes/ai_vet.php';

// The feature flag gates EVERY AI surface uniformly — this endpoint, the
// status endpoint and the HTML module. A farm that switches the assistant
// off should not find one route still serving it. /v1/ai-status is the one
// place that reports the disabled state rather than hiding it, because
// answering "is this available" is its entire purpose.
if (!dfms_feature_enabled('ai_vet_assistant')) {
    http_error('NOT_FOUND', 'No such endpoint.', 404);
}

$conn = getDBConnection();
$input = http_input();

$conversationParam = $GLOBALS['dfms_route_param'] ?? null;

if ($conversationParam !== null) {
    // ---- one conversation -------------------------------------------------
    $v = new DfmsValidator(['id' => $conversationParam]);
    $id = $v->id('id');
    if ($v->fails()) {
        // Malformed and foreign ids are indistinguishable, as everywhere else.
        http_error('NOT_FOUND', 'Conversation not found.', 404);
    }

    $conversation = aiVetFindConversation($conn, (int) $id);
    if ($conversation === null) {
        http_error('NOT_FOUND', 'Conversation not found.', 404);
    }

    $messages = aiVetMessages($conn, (int) $conversation['conversation_id']);

    http_success([
        'conversation' => [
            'id'            => (int) $conversation['conversation_id'],
            'title'         => $conversation['title'],
            'animal_id'     => $conversation['cow_id'] !== null ? (int) $conversation['cow_id'] : null,
            'message_count' => (int) $conversation['message_count'],
            'created_at'    => $conversation['created_at'],
            // admin_id is NOT returned: the caller already knows it is theirs,
            // and echoing an internal user id serves no consumer.
        ],
        'messages' => array_map(static function (array $message): array {
            return [
                'role'    => $message['role'],
                'content' => (string) $message['content'],
                // A degraded turn is reported AS degraded. A client must never
                // render a failure message as if it were veterinary advice.
                'status'  => $message['status'],
                'failure_reason' => $message['failure_reason'],
                'urgency_flagged' => (bool) $message['urgency_flagged'],
                'created_at' => $message['created_at'],
                // Citations, so an answer stays traceable to the farm's own
                // documents. Chunk BODIES are not returned — a client that
                // wants the passage reads the document in the knowledge base,
                // which enforces ai.knowledge.
                'sources' => array_map(static function (array $source): array {
                    return [
                        'document_id'    => (int) $source['document_id'],
                        'document_title' => $source['document_title'],
                        'source_name'    => $source['source_name'],
                        'source_type'    => $source['source_type'],
                        'attribution'    => $source['attribution'],
                    ];
                }, $message['sources'] ?? []),
                // provider and model are deliberately ABSENT. Which vendor
                // serves this installation is deployment information, not
                // something an API consumer needs or should depend on.
            ];
        }, $messages),
    ], 200, [
        'assistant_role' => 'decision_support_only',
        'assistant_note' => 'The assistant does not diagnose, prescribe, or replace a licensed veterinarian.',
    ]);
}

// ---- list -----------------------------------------------------------------
$v = new DfmsValidator($input);
$limit = $v->integer('limit', ['nullable' => true, 'min' => 1, 'max' => 100]) ?? 30;
if ($v->fails()) {
    http_validation_failed($v->errors());
}

// Tenant- and user-scoped by construction; there is no cross-farm code path.
$conversations = aiVetConversations($conn, $limit);

http_success(
    array_map(static function (array $conversation): array {
        return [
            'id'            => (int) $conversation['conversation_id'],
            'title'         => $conversation['title'],
            'animal_id'     => $conversation['cow_id'] !== null ? (int) $conversation['cow_id'] : null,
            'animal_tag'    => $conversation['animal_tag'],
            'message_count' => (int) $conversation['message_count'],
            'last_message_at' => $conversation['last_message_at'],
            'created_at'    => $conversation['created_at'],
        ];
    }, $conversations),
    200,
    ['count' => count($conversations)]
);
