<?php
// ============================================================================
// GET /api/v1/health
//
// Liveness/readiness probe. PUBLIC — the only unauthenticated endpoint.
//
// DELIBERATELY DISCLOSES ALMOST NOTHING. A public health endpoint is reachable
// by anyone who can reach the host, so it must not become a reconnaissance
// tool. It reports that the app responds and whether its database is
// reachable — and nothing about versions, schema, hostnames, counts or
// configuration.
// ============================================================================

// Direct execution guard: handlers are dispatched by api/index.php, which has
// already run the method and authorization gates. Reaching one directly would
// bypass them.
if (!defined('DFMS_API_DISPATCHED')) {
    http_response_code(404);
    exit;
}

$databaseOk = false;
try {
    // A trivial round trip. No schema is inspected and no row is read.
    getDBConnection()->query('SELECT 1')->fetchColumn();
    $databaseOk = true;
} catch (Throwable $e) {
    // The reason is logged, never returned — "connection refused to host X"
    // tells an attacker where the database lives.
    error_log('[' . dfms_request_id() . '] health: database unreachable: ' . dfms_redact($e->getMessage()));
}

// 503 when a dependency is down, so a load balancer can act on the status code
// alone without parsing the body.
http_json([
    'data' => [
        'status'   => $databaseOk ? 'ok' : 'degraded',
        'database' => $databaseOk ? 'ok' : 'unavailable',
    ],
], $databaseOk ? 200 : 503);
