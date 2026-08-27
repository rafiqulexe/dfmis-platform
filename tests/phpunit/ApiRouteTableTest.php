<?php

declare(strict_types=1);

namespace DFMIS\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Invariants of the API route table in api/index.php.
 *
 * The route table is a SECURITY surface: every entry declares whether the
 * endpoint is public and which permission it needs. A route added without
 * those fields, or with auth accidentally set to false, is a hole. These tests
 * read the route table out of the source and assert its shape, so a malformed
 * entry fails the build rather than shipping.
 *
 * Parsing the source rather than executing api/index.php is deliberate: that
 * file dispatches a request on include, which is not something a unit test
 * should trigger.
 */
final class ApiRouteTableTest extends TestCase
{
    /** @return array<string, array{methods: list<string>, auth: bool, permission: ?string, handler: string}> */
    private function routeTable(): array
    {
        $source = file_get_contents(__DIR__ . '/../../api/index.php');
        self::assertIsString($source, 'api/index.php must be readable');

        // Extract each route block: '/v1/x' => [ … ],
        //
        // The closing bracket is anchored to its four-space indentation. A
        // plain non-greedy match to the first "]," would stop inside
        // 'methods' => ['GET'], and silently yield a route with no auth or
        // handler — which would make the security assertions below vacuous.
        $routes = [];
        if (preg_match_all(
            "/'(\/v\d+\/[a-z0-9_\-\/]+)'\s*=>\s*\[\s*(.*?)\n    \],/s",
            $source,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $match) {
                [$whole, $path, $body] = $match;

                preg_match("/'methods'\s*=>\s*\[([^\]]*)\]/", $body, $m);
                $methods = [];
                if (isset($m[1]) && trim($m[1]) !== '') {
                    foreach (explode(',', $m[1]) as $method) {
                        $method = trim($method, " \t\n'\"");
                        if ($method !== '') {
                            $methods[] = $method;
                        }
                    }
                }

                preg_match("/'auth'\s*=>\s*(true|false)/", $body, $a);
                preg_match("/'permission'\s*=>\s*(null|'[^']*')/", $body, $p);
                preg_match("/'handler'\s*=>\s*__DIR__\s*\.\s*'([^']+)'/", $body, $h);

                $routes[$path] = [
                    'methods'    => $methods,
                    'auth'       => ($a[1] ?? '') === 'true',
                    'permission' => (($p[1] ?? 'null') === 'null') ? null : trim($p[1], "'"),
                    'handler'    => $h[1] ?? '',
                ];
            }
        }

        self::assertNotEmpty($routes, 'The route table must be parseable and non-empty');
        return $routes;
    }

    public function testEveryRouteDeclaresItsContract(): void
    {
        foreach ($this->routeTable() as $path => $route) {
            self::assertNotEmpty($route['methods'], "$path must declare allowed methods");
            self::assertNotEmpty($route['handler'], "$path must declare a handler");
            foreach ($route['methods'] as $method) {
                self::assertContains(
                    $method,
                    ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
                    "$path declares an unexpected method"
                );
            }
        }
    }

    /**
     * The whole point of the table. Only endpoints on an explicit allow-list may
     * be public; anything else must require authentication. If a future route is
     * added with auth => false, this test names it.
     */
    public function testOnlyExplicitlyApprovedRoutesArePublic(): void
    {
        $approvedPublic = ['/v1/health'];

        foreach ($this->routeTable() as $path => $route) {
            if (!$route['auth']) {
                self::assertContains(
                    $path,
                    $approvedPublic,
                    "$path is public but is not on the approved public list. "
                    . 'Either require authentication or justify it by adding it here.'
                );
            }
        }
    }

    public function testHealthIsTheOnlyPublicRoute(): void
    {
        $public = array_keys(array_filter($this->routeTable(), fn($r) => !$r['auth']));
        self::assertSame(['/v1/health'], $public);
    }

    /** Every handler named in the table must actually exist. */
    public function testEveryHandlerFileExists(): void
    {
        foreach ($this->routeTable() as $path => $route) {
            $file = __DIR__ . '/../../api' . $route['handler'];
            self::assertFileExists($file, "Handler for $path is missing");
        }
    }

    /**
     * A handler must refuse to run when reached directly, bypassing the router's
     * method and authorization gates.
     */
    public function testEveryHandlerRefusesDirectExecution(): void
    {
        foreach ($this->routeTable() as $path => $route) {
            $source = file_get_contents(__DIR__ . '/../../api' . $route['handler']);
            self::assertStringContainsString(
                'DFMS_API_DISPATCHED',
                (string) $source,
                "Handler for $path must guard against direct execution"
            );
        }
    }

    /** Any permission a route declares must exist in the RBAC matrix. */
    public function testDeclaredPermissionsExist(): void
    {
        require_once __DIR__ . '/../../includes/permissions.php';

        foreach ($this->routeTable() as $path => $route) {
            if ($route['permission'] === null) {
                continue;
            }
            self::assertArrayHasKey(
                $route['permission'],
                DFMS_PERMISSIONS,
                "$path declares permission '{$route['permission']}' which is not in DFMS_PERMISSIONS"
            );
        }
    }

    /** Versioned namespace — every route lives under /v<N>/. */
    public function testAllRoutesAreVersioned(): void
    {
        foreach (array_keys($this->routeTable()) as $path) {
            self::assertMatchesRegularExpression('#^/v\d+/#', $path, "$path is not versioned");
        }
    }
}
