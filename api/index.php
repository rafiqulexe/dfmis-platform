<?php
// ============================================================================
// API ENTRY POINT — /api/...
//
// FRONT CONTROLLER DECISION (documented in docs/DFMIS_API_ARCHITECTURE.md)
//
// This is a front controller for the API ONLY. Existing HTML pages keep
// working exactly as they do today — dashboard.php, modules/cows/index.php and
// every other page are still reached directly and were not modified.
//
// Routing the whole application through a single index.php would mean
// rewriting every page's entry contract, which is precisely the rewrite this
// project forbids. Confining the front controller to /api/ gives new endpoints
// one consistent lifecycle without touching a single working page.
//
// ROUTING is an explicit whitelist, not a filesystem scan or a pattern
// matcher. A route that is not listed below does not exist. That is deliberate:
// a filesystem-based dispatcher turns "someone dropped a file in api/" into
// "a new public endpoint", which is a security surface nobody reviewed.
//
// SECURITY: every gate here comes from Phase 1. Nothing is re-implemented.
// ============================================================================

require_once __DIR__ . '/../includes/auth.php';        // session bootstrap
require_once __DIR__ . '/../includes/errors.php';      // correlation ids, handlers
require_once __DIR__ . '/../includes/http.php';        // JSON envelope
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/tenant.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/repository.php';
require_once __DIR__ . '/../includes/validation.php';
require_once __DIR__ . '/../includes/settings.php';

// ---------------------------------------------------------------------------
// API-specific error handling.
//
// errors.php installs handlers that render an HTML error page — correct for a
// page, wrong for an API client expecting JSON. These override them for the
// duration of an API request only, and keep the same guarantee: no path, no
// trace, no SQL, no secret ever reaches the client.
//
// dfms_set_response_mode('json') makes every SHARED abort path — including the
// ones inside requireAuth(), requireTenantContext() and tenantFindOr404() —
// emit the JSON error envelope instead of HTML. The authorization decision is
// unchanged and still lives in exactly one place; only its presentation
// follows the caller's content type.
// ---------------------------------------------------------------------------
dfms_set_response_mode('json');

set_exception_handler(function (Throwable $e) {
    dfms_log_throwable($e, 'API');
    http_error('INTERNAL_ERROR', 'An unexpected error occurred.', 500);
});

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    if (headers_sent()) {
        return;
    }
    error_log('[' . dfms_request_id() . '] API FATAL ' . dfms_redact($error['message']));
    http_error('INTERNAL_ERROR', 'An unexpected error occurred.', 500);
});

// ---------------------------------------------------------------------------
// Resolve the requested path.
//
// Works under both the PHP built-in server (where PATH_INFO is usually empty
// and the full URI is in REQUEST_URI) and Apache with a rewrite.
// ---------------------------------------------------------------------------
$requestPath = $_SERVER['PATH_INFO'] ?? '';
if ($requestPath === '') {
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    // Strip everything up to and including "/api", leaving the route.
    $position = strpos($uri, '/api');
    $requestPath = $position === false ? $uri : substr($uri, $position + 4);
    // A direct hit on /api/index.php carries the script name; drop it.
    $requestPath = preg_replace('#^/index\.php#', '', $requestPath) ?? $requestPath;
}
$route = '/' . trim((string) $requestPath, '/');

// ---------------------------------------------------------------------------
// Route table — the complete list of endpoints that exist.
//
// 'auth' => false          public; no session required
// 'auth' => true           requireAuth() with no specific permission
// 'permission' => '...'    requireAuth() with that permission
// ---------------------------------------------------------------------------
$routes = [
    '/v1/health' => [
        'methods'    => ['GET'],
        'handler'    => __DIR__ . '/v1/health.php',
        'auth'       => false,
        'permission' => null,
    ],
    '/v1/me' => [
        'methods'    => ['GET'],
        'handler'    => __DIR__ . '/v1/me.php',
        'auth'       => true,
        'permission' => null,
    ],
    '/v1/settings' => [
        'methods'    => ['GET'],
        'handler'    => __DIR__ . '/v1/settings.php',
        'auth'       => true,
        'permission' => 'settings.view',
    ],
    '/v1/animals' => [
        'methods'    => ['GET'],
        'handler'    => __DIR__ . '/v1/animals.php',
        'auth'       => true,
        'permission' => 'cows.view',
        // Also serves /v1/animals/{id}. See the resolution below: the trailing
        // segment is a single id passed to the handler, NOT a wildcard that
        // could match a deeper path.
        'accepts_id' => true,
    ],

    // ---- Phase 4: Veterinary & Animal Health -------------------------------
    //
    // NOTE THE NAMES. /v1/health is ALREADY the public, unauthenticated
    // liveness probe above. These are deliberately separate routes rather than
    // an overload of it — reusing that path would either expose clinical data
    // on a public endpoint or break every load balancer pointing at it.
    //
    // Both require health.view, the SAME permission the HTML pages check, and
    // both are read-only: Phase 4 adds no write endpoints, exactly as Phase 2B
    // added none for animals.
    '/v1/animal-health' => [
        'methods'    => ['GET'],
        'handler'    => __DIR__ . '/v1/animal-health.php',
        'auth'       => true,
        'permission' => 'health.view',
        // Serves /v1/animal-health/{animal id}. The bare path returns 404 from
        // the handler, because a health summary is always about one animal.
        'accepts_id' => true,
    ],
    '/v1/health-cases' => [
        'methods'    => ['GET'],
        'handler'    => __DIR__ . '/v1/health-cases.php',
        'auth'       => true,
        'permission' => 'health.view',
        // Serves /v1/health-cases and /v1/health-cases/{case id}.
        'accepts_id' => true,
    ],

    // ---- Phase 5D: AI Veterinarian Assistant -------------------------------
    //
    // Both READ-ONLY and both authenticated. Asking a question is a POST to
    // the HTML module; the API has been read-only since Phase 2B and this
    // phase does not change that, so no speculative write surface is created.
    //
    // ai-status is NOT public. /v1/health already serves as the unauthenticated
    // liveness probe; whether an organization has AI configured and how much
    // allowance it has left is tenant business information.
    // ---- Phase 6: Production ----------------------------------------------
    //
    // ONE new route, read-only, and only because production analytics is
    // genuinely useful to a client that is not a browser. Feeding deliberately
    // gets NO endpoint: recording consumption moves stock, and this API has
    // been read-only since Phase 2B — a write surface for it would be a new
    // security decision, not a convenience.
    '/v1/production' => [
        'methods'    => ['GET'],
        'handler'    => __DIR__ . '/v1/production.php',
        'auth'       => true,
        'permission' => 'milk.view',
        // Serves /v1/production and /v1/production/{animal id}.
        'accepts_id' => true,
    ],

    '/v1/ai-status' => [
        'methods'    => ['GET'],
        'handler'    => __DIR__ . '/v1/ai-status.php',
        'auth'       => true,
        'permission' => 'ai.consult',
    ],
    // ---- Phase 7: AI animal image analysis ---------------------------------
    //
    // ONE new route, READ-ONLY. Requesting an analysis is a POST to the HTML
    // module (modules/cows/analyse.php) because it spends the organization
    // metered AI allowance; exposing that as an API write would be a new
    // security decision rather than a convenience, and this API has been
    // read-only since Phase 2B.
    //
    // ai.consult, not cows.view: seeing an animal is not the same decision as
    // seeing what an AI said about it, and it is the permission the HTML tab
    // and /v1/ai-conversations already use for exactly this kind of read.
    '/v1/animal-image-analysis' => [
        'methods'    => ['GET'],
        'handler'    => __DIR__ . '/v1/animal-image-analysis.php',
        'auth'       => true,
        'permission' => 'ai.consult',
        // Serves /v1/animal-image-analysis/{animal id}. The bare path returns
        // 404 from the handler: an analysis history is always about one animal.
        'accepts_id' => true,
    ],
    '/v1/ai-conversations' => [
        'methods'    => ['GET'],
        'handler'    => __DIR__ . '/v1/ai-conversations.php',
        'auth'       => true,
        'permission' => 'ai.consult',
        // Serves /v1/ai-conversations and /v1/ai-conversations/{id}.
        'accepts_id' => true,
    ],
];

// Route resolution: exact match first.
$definition = $routes[$route] ?? null;
$GLOBALS['dfms_route_param'] = null;

// Then, for routes that opted in, a single trailing id segment. This is
// deliberately not a pattern router: the parent path must still be an exact
// entry in the table above, and only ONE extra segment is ever accepted, so
// a deeper path cannot be smuggled through.
if ($definition === null && preg_match('#^(/v\d+/[a-z0-9_\-]+)/([^/]+)$#', $route, $matches)) {
    $candidate = $routes[$matches[1]] ?? null;
    if ($candidate !== null && !empty($candidate['accepts_id'])) {
        $definition = $candidate;
        // Passed as-is and validated by the handler with DfmsValidator, so a
        // non-numeric segment becomes a 404 rather than reaching a query.
        $GLOBALS['dfms_route_param'] = $matches[2];
    }
}

if ($definition === null) {
    http_error('NOT_FOUND', 'No such endpoint.', 404);
}

// 1. Method — before authentication, so an unsupported method on a public
//    endpoint does not depend on session state.
http_require_method($definition['methods']);

// 2/3/4/5. Authentication, tenant context, membership and permission.
//
// requireAuth() is the SAME gate every HTML page uses. It covers authenticated
// user, tenant context present, farm membership, organization membership and
// permission. The API therefore cannot become a tenant-isolation bypass by
// construction — there is no second authorization path to get wrong.
if ($definition['auth']) {
    if (!isLoggedIn()) {
        // requireAuth() would REDIRECT an unauthenticated caller to the login
        // page, which is right for a browser and useless for an API client.
        // This is the one place the API differs, and it differs only in how it
        // reports the failure — never in whether it enforces it.
        http_error('UNAUTHENTICATED', 'Authentication required.', 401);
    }
    // From here the full Phase 1 gate runs unchanged. A missing tenant context
    // or a failed permission check aborts inside requireAuth().
    requireAuth($definition['permission']);
}

// 6. Dispatch. The handler receives an already-authenticated, already-scoped
//    request and does nothing but produce data.
//
//    The marker lets each handler refuse direct execution. Without it, a
//    request straight to /api/v1/me.php would run the handler having skipped
//    every gate above.
define('DFMS_API_DISPATCHED', true);
require $definition['handler'];

// A handler that returns without responding is a bug, not an empty success.
http_error('INTERNAL_ERROR', 'The endpoint produced no response.', 500);
