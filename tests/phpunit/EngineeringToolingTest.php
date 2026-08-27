<?php

declare(strict_types=1);

namespace DFMIS\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The engineering configuration itself — composer.json, phpstan.neon, the CI
 * workflow, .gitignore and .env.example.
 *
 * These guard against silent regressions in the tooling: a CI file that stops
 * running a suite, a gitignore that stops covering .env, an analysis level
 * quietly lowered so a build goes green.
 */
final class EngineeringToolingTest extends TestCase
{
    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private function ci(): string
    {
        $path = $this->root() . '/.github/workflows/ci.yml';
        self::assertFileExists($path, 'CI workflow must exist');
        return (string) file_get_contents($path);
    }

    // ---- composer -----------------------------------------------------------

    public function testComposerJsonIsValid(): void
    {
        $raw = file_get_contents($this->root() . '/composer.json');
        self::assertIsString($raw);

        $json = json_decode($raw, true);
        self::assertIsArray($json, 'composer.json must be valid JSON');
        self::assertArrayHasKey('require-dev', $json);
    }

    /**
     * Dependency policy: dev tooling only. A production `require` beyond the
     * PHP version is a decision that needs review, not something that should
     * arrive unnoticed in a lockfile diff.
     */
    public function testProductionDependenciesRemainMinimal(): void
    {
        $json = json_decode((string) file_get_contents($this->root() . '/composer.json'), true);
        self::assertSame(
            ['php'],
            array_keys($json['require'] ?? []),
            'DFMIS must not gain runtime dependencies without review'
        );
    }

    public function testDevDependenciesAreTheExpectedTools(): void
    {
        $json = json_decode((string) file_get_contents($this->root() . '/composer.json'), true);
        $dev = array_keys($json['require-dev'] ?? []);
        sort($dev);
        self::assertSame(['phpstan/phpstan', 'phpunit/phpunit'], $dev);
    }

    // ---- static analysis ----------------------------------------------------

    public function testPhpstanConfigDeclaresAMeaningfulLevel(): void
    {
        $config = (string) file_get_contents($this->root() . '/phpstan.neon');
        self::assertMatchesRegularExpression('/^\s*level:\s*(\d+)/m', $config);

        preg_match('/^\s*level:\s*(\d+)/m', $config, $m);
        // Guards against lowering the level to make a build pass.
        self::assertGreaterThanOrEqual(
            5,
            (int) $m[1],
            'Static analysis level must not be lowered below 5'
        );
    }

    public function testPhpstanBaselineIsEmpty(): void
    {
        $baseline = (string) file_get_contents($this->root() . '/phpstan-baseline.neon');
        self::assertStringContainsString(
            'ignoreErrors: []',
            $baseline,
            'The baseline is empty by design — findings are fixed, not suppressed'
        );
    }

    // ---- CI -----------------------------------------------------------------

    /**
     * A STRUCTURAL check, not a full YAML parse — no YAML extension is
     * installed and adding a library purely for this would be dependency
     * bloat. It catches the mistakes that actually occur: tab characters
     * (illegal in YAML), a missing top-level key, or a suite dropped from the
     * pipeline. It does NOT prove the workflow is semantically valid to
     * GitHub.
     */
    public function testCiWorkflowIsStructurallySound(): void
    {
        $ci = $this->ci();

        self::assertStringNotContainsString("\t", $ci, 'YAML must not contain tab characters');
        self::assertStringContainsString('name:', $ci);
        self::assertStringContainsString('jobs:', $ci);
        self::assertMatchesRegularExpression('/^on:/m', $ci, 'Workflow must declare triggers');
    }

    /**
     * Every quality gate must be present in CI. If a suite is added locally and
     * CI is forgotten — or a suite is removed from CI — this names it.
     */
    public function testCiRunsEveryQualityGate(): void
    {
        $ci = $this->ci();

        $required = [
            'tools/lint.php'       => 'lint',
            'phpstan'              => 'static analysis',
            'phpunit'              => 'PHPUnit',
            'tests/run_all.sh'     => 'the legacy suite runner',
            'database/migrate.php' => 'migrations',
        ];

        foreach ($required as $needle => $label) {
            self::assertStringContainsString($needle, $ci, "CI must run $label");
        }
    }

    /**
     * This project fails rather than skips when no server is running. CI must
     * therefore start one explicitly, instead of letting the HTTP suites
     * quietly not run and the build go green anyway.
     */
    public function testCiStartsAWebServerForIntegrationSuites(): void
    {
        $ci = $this->ci();
        self::assertStringContainsString('php -S', $ci, 'CI must start a web server for the HTTP suites');
    }

    // ---- secrets ------------------------------------------------------------

    public function testGitignoreCoversSecretsAndArtifacts(): void
    {
        $gitignore = (string) file_get_contents($this->root() . '/.gitignore');

        foreach (['.env', 'vendor/'] as $entry) {
            self::assertMatchesRegularExpression(
                '/^' . preg_quote($entry, '/') . '$/m',
                $gitignore,
                "$entry must be gitignored"
            );
        }
    }

    /** The example env file is a template of NAMES; it must carry no real values. */
    public function testEnvExampleContainsNoRealSecrets(): void
    {
        $example = (string) file_get_contents($this->root() . '/.env.example');

        self::assertStringNotContainsString('sk-', $example, '.env.example must not contain an API key');

        preg_match_all('/^(APP_|DB_|ANTHROPIC_)[A-Z_]+=(.*)$/m', $example, $matches, PREG_SET_ORDER);
        self::assertNotEmpty($matches, '.env.example must document the required variables');

        foreach ($matches as $match) {
            $value = trim($match[2]);
            if ($value === '') {
                continue;
            }
            self::assertLessThan(
                60,
                strlen($value),
                'Suspiciously long value in .env.example: ' . $match[0]
            );
        }
    }
}
