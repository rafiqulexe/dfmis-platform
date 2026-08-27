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
];

if (!isset($routes[$route])) {
    http_error('NOT_FOUND', 'No such endpoint.', 404);
}

$definition = $routes[$route];

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
