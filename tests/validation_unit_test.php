<?php
// ============================================================================
// Unit tests for includes/validation.php
//
// Dependency-free by design. The project has no Composer, no autoloader and
// no PHPUnit; introducing them means restructuring a procedural codebase,
// which is out of scope for Phase 1 (see docs/DFMIS_SECURITY_ARCHITECTURE.md
// "Remaining risks"). This harness gives the validation layer real unit
// coverage now, and can be replaced by PHPUnit without changing a single
// assertion's meaning.
//
//   php tests/validation_unit_test.php
// ============================================================================

require_once __DIR__ . '/../includes/validation.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

$PASS = 0;
$FAIL = 0;
$CURRENT_GROUP = '';

function group(string $name) {
    global $CURRENT_GROUP;
    $CURRENT_GROUP = $name;
    echo "\n== $name ==\n";
}

function assertSame__($expected, $actual, string $label) {
    global $PASS, $FAIL;
    if ($expected === $actual) {
        echo "  pass  $label\n";
        $PASS++;
        return;
    }
    printf("  FAIL  %s — expected %s, got %s\n", $label, var_export($expected, true), var_export($actual, true));
    $FAIL++;
}

function assertTrue__($actual, string $label) {
    assertSame__(true, (bool) $actual, $label);
}

// Convenience: run one rule over one input and return [value, failed].
function run(string $method, array $data, array $args = []): array {
    $v = new DfmsValidator($data);
    $value = $v->$method(...$args);
    return [$value, $v->fails()];
}

// ---------------------------------------------------------------------------
group('id() — positive integers only');
// ---------------------------------------------------------------------------
foreach ([
    '5'     => 5,
    '1'     => 1,
    '99999' => 99999,
] as $input => $expected) {
    [$value, $failed] = run('id', ['x' => $input], ['x']);
    assertSame__($expected, $value, "accepts '$input'");
    assertSame__(false, $failed, "'$input' does not error");
}

// Every one of these reached a database query before Phase 1.
foreach (['0', '-1', '1.5', 'abc', '1 OR 1=1', "1; DROP TABLE cows", '', ' ', '1e3', '0x10', '٣'] as $input) {
    [$value, $failed] = run('id', ['x' => $input], ['x']);
    assertSame__(null, $value, "rejects " . var_export($input, true));
    assertTrue__($failed, "flags " . var_export($input, true) . " as an error");
}

[$value, $failed] = run('id', [], ['x', ['nullable' => true]]);
assertSame__(null, $value, 'nullable id absent returns null');
assertSame__(false, $failed, 'nullable id absent does not error');

// ---------------------------------------------------------------------------
group('date() — strict Y-m-d, no impossible calendar dates');
// ---------------------------------------------------------------------------
[$value, $failed] = run('date', ['d' => '2024-02-29'], ['d']);
assertSame__('2024-02-29', $value, 'accepts a real leap day');

foreach ([
    '2024-02-31' => 'Feb 31 does not exist',
    '2023-02-29' => 'Feb 29 in a non-leap year',
    '2024-13-01' => 'month 13',
    '2024-00-10' => 'month 0',
    '2024-1-1'   => 'unpadded components',
    '01/01/2024' => 'US format',
    ''           => 'empty string (used to be written as 0000-00-00)',
    'yesterday'  => 'relative words',
    '1899-01-01' => 'implausibly old',
] as $input => $why) {
    [$value, $failed] = run('date', ['d' => $input], ['d']);
    assertSame__(null, $value, "rejects $why");
    assertTrue__($failed, "flags $why");
}

// Future dates are rejected by default (a birth date cannot be in the future)
// but allowed when a caller opts in.
[$value, $failed] = run('date', ['d' => '2099-01-01'], ['d']);
assertTrue__($failed, 'rejects a future date by default');
[$value, $failed] = run('date', ['d' => '2099-01-01'], ['d', ['allow_future' => true]]);
assertSame__('2099-01-01', $value, 'allows a future date when opted in');

// ---------------------------------------------------------------------------
group('decimal() — finite, bounded, no exponent smuggling');
// ---------------------------------------------------------------------------
assertSame__(12.5, run('decimal', ['n' => '12.5'], ['n'])[0], 'accepts 12.5');
assertSame__(0.0,  run('decimal', ['n' => '0'], ['n'])[0], 'accepts 0');

foreach (['abc', '1e10', 'NaN', 'INF', '-INF', '1,5', '', '0x1A', '12.34567'] as $input) {
    [$value, $failed] = run('decimal', ['n' => $input], ['n']);
    assertSame__(null, $value, "rejects " . var_export($input, true));
    assertTrue__($failed, "flags " . var_export($input, true));
}

[$value, $failed] = run('decimal', ['n' => '-50'], ['n', ['min' => 0]]);
assertTrue__($failed, 'rejects a negative below min');
[$value, $failed] = run('decimal', ['n' => '999999'], ['n', ['max' => 1000]]);
assertTrue__($failed, 'rejects a value above max');

// ---------------------------------------------------------------------------
group('enum() — strict allow-list (C-7: unknown used to mean "subtract")');
// ---------------------------------------------------------------------------
assertSame__('add', run('enum', ['t' => 'add'], ['t', ['set', 'add', 'subtract']])[0], 'accepts a listed value');

foreach (['bogus', 'ADD', '0', 'set;', 'subtract\'--'] as $input) {
    [$value, $failed] = run('enum', ['t' => $input], ['t', ['set', 'add', 'subtract']]);
    assertSame__(null, $value, "rejects " . var_export($input, true));
    assertTrue__($failed, "flags " . var_export($input, true));
}

// Surrounding whitespace is trimmed before the allow-list comparison — every
// rule shares that normalisation, so a value a browser padded still matches.
// Case is NOT normalised: 'ADD' is rejected above, deliberately.
assertSame__('add', run('enum', ['t' => ' add '], ['t', ['set', 'add', 'subtract']])[0],
    'trims surrounding whitespace before matching');

[$value, $failed] = run('enum', [], ['t', ['a', 'b'], ['default' => 'a']]);
assertSame__('a', $value, 'absent value falls back to the declared default');
assertSame__(false, $failed, 'default does not raise an error');

// A default must never let an INVALID value through — only an absent one.
[$value, $failed] = run('enum', ['t' => 'nope'], ['t', ['a', 'b'], ['default' => 'a']]);
assertTrue__($failed, 'an invalid value is still rejected despite a default');

// ---------------------------------------------------------------------------
group('string() — length bounds and control-character rejection');
// ---------------------------------------------------------------------------
assertSame__('BD001', run('string', ['s' => '  BD001  '], ['s', 1, 50])[0], 'trims surrounding whitespace');
assertTrue__(run('string', ['s' => ''], ['s', 1, 50])[1], 'rejects empty when min is 1');
assertTrue__(run('string', ['s' => str_repeat('x', 51)], ['s', 1, 50])[1], 'rejects over max length');
assertTrue__(run('string', ['s' => "bad\x00null"], ['s', 1, 50])[1], 'rejects an embedded NUL byte');
assertTrue__(run('string', ['s' => "bad\x07bell"], ['s', 1, 50])[1], 'rejects a control character');

// Multi-byte length is counted in characters, not bytes — a farm in Bangladesh
// must be able to name an animal in Bangla (Phase 12 depends on this).
assertSame__('নদী', run('string', ['s' => 'নদী'], ['s', 1, 10])[0], 'accepts multi-byte text');
assertSame__(false, run('string', ['s' => str_repeat('নদী', 3)], ['s', 1, 10])[1], 'counts characters, not bytes');

// ---------------------------------------------------------------------------
group('datetime() — normalises HTML datetime-local to SQL');
// ---------------------------------------------------------------------------
assertSame__('2024-01-01 10:00:00', run('datetime', ['t' => '2024-01-01T10:00'], ['t'])[0], 'accepts datetime-local');
assertSame__('2024-01-01 10:30:00', run('datetime', ['t' => '2024-01-01 10:30:00'], ['t'])[0], 'accepts SQL format');
assertTrue__(run('datetime', ['t' => 'not-a-time'], ['t'])[1], 'rejects nonsense');

// ---------------------------------------------------------------------------
group('time() — HH:MM or HH:MM:SS');
// ---------------------------------------------------------------------------
assertSame__('08:00:00', run('time', ['t' => '08:00'], ['t'])[0], 'accepts HH:MM');
assertTrue__(run('time', ['t' => '25:00'], ['t'])[1], 'rejects hour 25');
assertTrue__(run('time', ['t' => 'noon'], ['t'])[1], 'rejects words');

// ---------------------------------------------------------------------------
group('multi-field collection');
// ---------------------------------------------------------------------------
$v = new DfmsValidator(['a' => '', 'b' => 'bad', 'c' => '5']);
$v->string('a', 1, 10);
$v->id('b');
$v->id('c');
assertSame__(2, count($v->errors()), 'collects one error per failing field');
assertTrue__($v->firstError() !== null, 'firstError returns a message');
assertSame__(false, isset($v->errors()['c']), 'a passing field records no error');

// ---------------------------------------------------------------------------
echo "\n================================\n";
echo " passed: $PASS   failed: $FAIL\n";
echo "================================\n";
exit($FAIL === 0 ? 0 : 1);
