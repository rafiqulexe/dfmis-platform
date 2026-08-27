<?php

declare(strict_types=1);

namespace DFMIS\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for includes/http.php and the error contract in includes/errors.php.
 *
 * These are PURE: no server, no database, no session. They cover the shape of
 * the API contract, which is the part most likely to drift silently as
 * endpoints are added.
 */
final class HttpFoundationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/errors.php';
        require_once __DIR__ . '/../../includes/http.php';
    }

    protected function setUp(): void
    {
        // Each test starts from a clean request environment.
        $_SERVER['REQUEST_METHOD'] = 'GET';
        unset($_SERVER['HTTP_ACCEPT'], $_SERVER['CONTENT_TYPE']);
        $_GET = [];
    }

    public function testMethodIsUppercased(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'post';
        self::assertSame('POST', http_method());
    }

    public function testMethodDefaultsToGetWhenAbsent(): void
    {
        unset($_SERVER['REQUEST_METHOD']);
        self::assertSame('GET', http_method());
    }

    public function testWantsJsonDetectsAcceptHeader(): void
    {
        $_SERVER['HTTP_ACCEPT'] = 'application/json, text/plain';
        self::assertTrue(http_wants_json());
    }

    public function testWantsJsonDetectsContentType(): void
    {
        $_SERVER['CONTENT_TYPE'] = 'application/json; charset=utf-8';
        self::assertTrue(http_wants_json());
    }

    public function testWantsJsonIsFalseForBrowserAccept(): void
    {
        $_SERVER['HTTP_ACCEPT'] = 'text/html,application/xhtml+xml';
        self::assertFalse(http_wants_json());
    }

    /**
     * A correlation id must be stable within a request — the value returned to
     * the client has to match the one written to the log, or support cannot
     * find anything.
     */
    public function testCorrelationIdIsStableWithinARequest(): void
    {
        self::assertSame(dfms_request_id(), dfms_request_id());
        self::assertMatchesRegularExpression('/^[a-f0-9]{16}$/', dfms_request_id());
    }

    /**
     * Every HTTP status the abort path can produce must map to a stable,
     * machine-readable code. Clients branch on the code, never the message.
     */
    public function testStatusCodesMapToStableErrorCodes(): void
    {
        self::assertSame('BAD_REQUEST', dfms_status_error_code(400));
        self::assertSame('UNAUTHENTICATED', dfms_status_error_code(401));
        self::assertSame('FORBIDDEN', dfms_status_error_code(403));
        self::assertSame('NOT_FOUND', dfms_status_error_code(404));
        self::assertSame('METHOD_NOT_ALLOWED', dfms_status_error_code(405));
        self::assertSame('VALIDATION_ERROR', dfms_status_error_code(422));
    }

    public function testUnmappedStatusFallsBackToInternalError(): void
    {
        self::assertSame('INTERNAL_ERROR', dfms_status_error_code(500));
        self::assertSame('INTERNAL_ERROR', dfms_status_error_code(418));
    }

    /**
     * The redaction filter is the last line of defence before anything reaches
     * a log file. It must catch the credential shapes this application handles.
     */
    public function testRedactionRemovesCredentials(): void
    {
        self::assertStringNotContainsString('hunter2', dfms_redact('password=hunter2'));
        self::assertStringNotContainsString('supersecret', dfms_redact('api_key: supersecret'));
        self::assertStringNotContainsString('abcd1234', dfms_redact('x-api-key=abcd1234'));
        self::assertStringNotContainsString(
            'sk-abcdefghijklmnopqrstuvwxyz',
            dfms_redact('key sk-abcdefghijklmnopqrstuvwxyz here')
        );
    }

    public function testRedactionLeavesOrdinaryTextAlone(): void
    {
        $text = 'Cow BD001 produced 24.5 litres on 2026-01-15.';
        self::assertSame($text, dfms_redact($text));
    }

    /**
     * Response mode drives whether a shared abort renders HTML or JSON. It must
     * default to HTML so an existing page cannot accidentally start emitting
     * JSON, and must only accept the two known values.
     */
    public function testResponseModeDefaultsToHtml(): void
    {
        self::assertSame('html', dfms_response_mode());
    }

    public function testResponseModeAcceptsJsonAndRejectsAnythingElse(): void
    {
        dfms_set_response_mode('json');
        self::assertSame('json', dfms_response_mode());

        // An unrecognised mode must fall back to html, never be stored as-is.
        dfms_set_response_mode('xml');
        self::assertSame('html', dfms_response_mode());
    }

    protected function tearDown(): void
    {
        // Never leak json mode into another test.
        dfms_set_response_mode('html');
    }
}
