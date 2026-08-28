<?php
// ============================================================================
// STRUCTURED AI OUTPUT — extraction and strict validation.
//
// THE POSITION THIS FILE TAKES
// A language model is an UNTRUSTED EXTERNAL SYSTEM. Its output is input.
// Phase 5D only ever needed prose, so "the model returned text" was the whole
// contract. The moment any future feature wants a FIELD out of a model — a
// risk band, a score, a list of factors — that text becomes data, and data
// from an untrusted source has to be parsed and validated exactly as strictly
// as a form post is by DfmsValidator.
//
// The failure this file exists to prevent is narrow and specific: invalid AI
// output silently becoming valid application data. A model that returns
// {"severity": "catastrophic"} for an enum of three values, or a 40MB string,
// or JSON nested 900 levels deep, must produce a REFUSAL — never a partial
// record, never a coerced value, never a default quietly substituted.
//
// ---------------------------------------------------------------------------
// WHAT THIS FILE DELIBERATELY DOES NOT DO
//
//   - It does not touch the database. Nothing here writes anything. A caller
//     that wants to persist a validated structure must decide that for itself,
//     which is the point: a future phase chooses what validated AI output
//     MEANS, and that decision must be visible in that phase's code.
//   - It does not call a provider. It is pure, so it is testable without a
//     network, a key or a tenant.
//   - It does not add a dependency. The project has no JSON-Schema library and
//     pulling one in for six types would be a supply-chain decision made for
//     convenience. The schema format below is deliberately small and reads
//     like the DfmsValidator rules everyone here already knows.
//
// ---------------------------------------------------------------------------
// SCHEMA FORMAT
//
//   [
//     'type'   => 'object',
//     'fields' => [
//       'summary'    => ['type' => 'string',  'required' => true, 'max' => 2000],
//       'severity'   => ['type' => 'enum',    'required' => true, 'values' => ['low','medium','high']],
//       'score'      => ['type' => 'integer', 'min' => 0, 'max' => 100],
//       'weight_kg'  => ['type' => 'decimal', 'min' => 0],
//       'urgent'     => ['type' => 'boolean'],
//       'factors'    => ['type' => 'array', 'max_items' => 10, 'items' => [
//                          'type' => 'object', 'fields' => [
//                            'label' => ['type' => 'string', 'required' => true],
//                          ],
//                       ]],
//     ],
//   ]
//
// Every field is OPTIONAL unless 'required' => true. A field may also be
// 'nullable' => true, which permits an explicit JSON null.
//
// UNKNOWN KEYS ARE REJECTED by default. That is the "unexpected dangerous
// structure" rule: a model that invents a field is a model that misunderstood
// the contract, and accepting the rest of its answer as if it had not is how a
// half-understood response gets treated as a good one. A schema may opt out
// with 'allow_unknown' => true where a caller genuinely wants to ignore extras.
//
// NO COERCION. "7" is not 7 and "true" is not true. A model that cannot follow
// the type contract has not followed the contract, and silently repairing it
// hides exactly the signal a caller needs in order to distrust the answer.
// ============================================================================

/**
 * Hard ceilings applied regardless of what a schema asks for.
 *
 * These are resource limits, not business rules. A schema may be STRICTER than
 * these; it can never be looser. They exist because the size of a provider
 * response is not under our control, and "validate it first" is no defence if
 * validating it is itself what exhausts memory.
 */
const DFMS_AI_SCHEMA_LIMITS = [
    'max_response_bytes' => 65536,   // raw text accepted for parsing at all
    'max_depth'          => 8,       // nesting levels in the decoded structure
    'max_string_length'  => 8000,    // characters in any one string field
    'max_array_items'    => 200,     // elements in any one array
    'max_object_fields'  => 64,      // keys in any one object
];

/**
 * Machine tokens for a structured-output failure. A closed set, so the UI can
 * map each to safe text and an unrecognised value is never shown verbatim.
 */
const DFMS_AI_SCHEMA_REASONS = [
    'oversized_response' => 'The AI response was too large to process.',
    'invalid_json'       => 'The AI response was not valid structured data.',
    'schema_violation'   => 'The AI response did not match the expected structure.',
];

/** Safe, user-facing text for a structured-output failure token. */
function aiSchemaFailureMessage(?string $reason): string {
    return DFMS_AI_SCHEMA_REASONS[$reason ?? '']
        ?? 'The AI response could not be used.';
}

/**
 * A schema that is itself malformed. This is a PROGRAMMING error — the schema
 * is written by us, not by the model — so it throws rather than degrading. A
 * silent pass on an unreadable schema would mean validating nothing.
 */
class AiSchemaDefinitionError extends InvalidArgumentException {}

// ===========================================================================
// EXTRACTION
// ===========================================================================

/**
 * Recover a JSON value from raw model text.
 *
 * Models wrap JSON in prose or a markdown fence even when told not to. Three
 * BOUNDED strategies are tried, in order of how much they assume:
 *
 *   1. the whole response, decoded as-is
 *   2. the contents of the first fenced code block
 *   3. the span from the first opening brace/bracket to the last matching one
 *
 * Strategy 3 is lenient about what SURROUNDS the JSON and not at all lenient
 * about the JSON itself — every candidate still has to decode cleanly, and
 * whatever comes out still faces the schema. Being permissive here and strict
 * afterwards is the right split: a chatty preamble is a formatting quirk,
 * whereas a wrong field is a wrong answer.
 *
 * @return array{ok: bool, value: mixed, reason: ?string}
 */
function aiJsonExtract(string $raw): array {
    if (strlen($raw) > DFMS_AI_SCHEMA_LIMITS['max_response_bytes']) {
        return ['ok' => false, 'value' => null, 'reason' => 'oversized_response'];
    }

    $text = trim($raw);
    if ($text === '') {
        return ['ok' => false, 'value' => null, 'reason' => 'invalid_json'];
    }

    $candidates = [$text];

    if (preg_match('/```(?:json|JSON)?\s*(.+?)```/s', $text, $matches) === 1) {
        $candidates[] = trim($matches[1]);
    }

    foreach ([['{', '}'], ['[', ']']] as [$open, $close]) {
        $start = strpos($text, $open);
        $end = strrpos($text, $close);
        if ($start !== false && $end !== false && $end > $start) {
            $candidates[] = substr($text, $start, $end - $start + 1);
        }
    }

    foreach ($candidates as $candidate) {
        if ($candidate === '') {
            continue;
        }
        // The depth argument is a REAL guard, not a formality: json_decode
        // refuses rather than building a structure deeper than we will ever
        // walk, so an adversarial response cannot exhaust the stack here.
        $decoded = json_decode($candidate, true, DFMS_AI_SCHEMA_LIMITS['max_depth'] + 2);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return ['ok' => true, 'value' => $decoded, 'reason' => null];
        }
    }

    return ['ok' => false, 'value' => null, 'reason' => 'invalid_json'];
}

// ===========================================================================
// VALIDATION
// ===========================================================================

/**
 * Validate a decoded value against a schema.
 *
 * Collects EVERY violation rather than stopping at the first, for the same
 * reason DfmsValidator does: one report of what is wrong is more useful than
 * five round trips discovering it a field at a time.
 *
 * @param mixed $value
 * @return array{ok: bool, value: mixed, errors: list<string>}
 */
function aiSchemaValidate($value, array $schema): array {
    $errors = [];
    $clean = aiSchemaValidateNode($value, $schema, '$', 0, $errors);
    return [
        'ok'     => $errors === [],
        'value'  => $errors === [] ? $clean : null,
        'errors' => $errors,
    ];
}

/**
 * Validate one node. Returns the normalised value; violations are appended to
 * $errors, which is what the caller checks — a returned value is only
 * meaningful when no error was recorded.
 *
 * @param mixed $value
 * @param list<string> $errors
 * @return mixed
 */
function aiSchemaValidateNode($value, array $schema, string $path, int $depth, array &$errors) {
    if ($depth > DFMS_AI_SCHEMA_LIMITS['max_depth']) {
        $errors[] = "$path is nested too deeply.";
        return null;
    }

    $type = $schema['type'] ?? null;
    if (!is_string($type)) {
        throw new AiSchemaDefinitionError("Schema at $path has no type.");
    }

    // An explicit null is acceptable only where the schema says so. A model
    // omitting a value it could not determine is honest; a model nulling a
    // required field is not, and the two must not look the same.
    if ($value === null) {
        if (!empty($schema['nullable'])) {
            return null;
        }
        $errors[] = "$path must not be null.";
        return null;
    }

    switch ($type) {
        case 'string':
            return aiSchemaValidateString($value, $schema, $path, $errors);
        case 'integer':
            return aiSchemaValidateInteger($value, $schema, $path, $errors);
        case 'decimal':
            return aiSchemaValidateDecimal($value, $schema, $path, $errors);
        case 'boolean':
            if (!is_bool($value)) {
                $errors[] = "$path must be true or false.";
                return null;
            }
            return $value;
        case 'enum':
            return aiSchemaValidateEnum($value, $schema, $path, $errors);
        case 'array':
            return aiSchemaValidateArray($value, $schema, $path, $depth, $errors);
        case 'object':
            return aiSchemaValidateObject($value, $schema, $path, $depth, $errors);
        default:
            throw new AiSchemaDefinitionError("Schema at $path has unknown type '$type'.");
    }
}

/**
 * @param mixed $value
 * @param list<string> $errors
 */
function aiSchemaValidateString($value, array $schema, string $path, array &$errors): ?string {
    if (!is_string($value)) {
        $errors[] = "$path must be text.";
        return null;
    }
    // The same control-character rule DfmsValidator applies to form input. A
    // NUL or an escape sequence is never legitimate content and is a common
    // way to smuggle something past a downstream consumer.
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
        $errors[] = "$path contains invalid characters.";
        return null;
    }
    $max = min(
        (int) ($schema['max'] ?? DFMS_AI_SCHEMA_LIMITS['max_string_length']),
        DFMS_AI_SCHEMA_LIMITS['max_string_length']
    );
    $length = mb_strlen($value, 'UTF-8');
    if ($length > $max) {
        $errors[] = "$path is longer than $max characters.";
        return null;
    }
    if (isset($schema['min']) && $length < (int) $schema['min']) {
        $errors[] = "$path is shorter than " . (int) $schema['min'] . ' characters.';
        return null;
    }
    if (isset($schema['pattern']) && preg_match((string) $schema['pattern'], $value) !== 1) {
        $errors[] = "$path is not in the expected format.";
        return null;
    }
    return $value;
}

/**
 * Strictly an integer. A JSON number with a fractional part is NOT an integer
 * and neither is the string "7" — accepting either would mean the schema said
 * one thing and the data meant another.
 *
 * @param mixed $value
 * @param list<string> $errors
 */
function aiSchemaValidateInteger($value, array $schema, string $path, array &$errors): ?int {
    if (!is_int($value)) {
        $errors[] = "$path must be a whole number.";
        return null;
    }
    if (isset($schema['min']) && $value < (int) $schema['min']) {
        $errors[] = "$path is below the allowed minimum.";
        return null;
    }
    if (isset($schema['max']) && $value > (int) $schema['max']) {
        $errors[] = "$path is above the allowed maximum.";
        return null;
    }
    return $value;
}

/**
 * A decimal accepts a JSON integer or float and normalises to float. NAN and
 * INF are rejected: json_decode cannot produce them, but a caller may pass an
 * already-decoded structure, and a non-finite number that reaches a numeric
 * column corrupts it silently.
 *
 * @param mixed $value
 * @param list<string> $errors
 */
function aiSchemaValidateDecimal($value, array $schema, string $path, array &$errors): ?float {
    if (is_bool($value) || (!is_int($value) && !is_float($value))) {
        $errors[] = "$path must be a number.";
        return null;
    }
    $number = (float) $value;
    if (!is_finite($number)) {
        $errors[] = "$path must be a finite number.";
        return null;
    }
    if (isset($schema['min']) && $number < (float) $schema['min']) {
        $errors[] = "$path is below the allowed minimum.";
        return null;
    }
    if (isset($schema['max']) && $number > (float) $schema['max']) {
        $errors[] = "$path is above the allowed maximum.";
        return null;
    }
    return $number;
}

/**
 * @param mixed $value
 * @param list<string> $errors
 */
function aiSchemaValidateEnum($value, array $schema, string $path, array &$errors): ?string {
    $allowed = $schema['values'] ?? null;
    if (!is_array($allowed) || $allowed === []) {
        throw new AiSchemaDefinitionError("Enum schema at $path has no values.");
    }
    if (!is_string($value)) {
        $errors[] = "$path must be one of the allowed values.";
        return null;
    }
    if (!in_array($value, $allowed, true)) {
        // The allowed set is OURS, not the model's, so naming it is safe and
        // makes a failure diagnosable. The rejected value is not echoed.
        $errors[] = "$path must be one of: " . implode(', ', array_map('strval', $allowed)) . '.';
        return null;
    }
    return $value;
}

/**
 * @param mixed $value
 * @param list<string> $errors
 * @return list<mixed>|null
 */
function aiSchemaValidateArray($value, array $schema, string $path, int $depth, array &$errors): ?array {
    if (!is_array($value) || !array_is_list($value)) {
        $errors[] = "$path must be a list.";
        return null;
    }
    $items = $schema['items'] ?? null;
    if (!is_array($items)) {
        throw new AiSchemaDefinitionError("Array schema at $path has no item schema.");
    }
    $max = min(
        (int) ($schema['max_items'] ?? DFMS_AI_SCHEMA_LIMITS['max_array_items']),
        DFMS_AI_SCHEMA_LIMITS['max_array_items']
    );
    if (count($value) > $max) {
        $errors[] = "$path has more than $max items.";
        return null;
    }
    if (isset($schema['min_items']) && count($value) < (int) $schema['min_items']) {
        $errors[] = "$path has fewer than " . (int) $schema['min_items'] . ' items.';
        return null;
    }

    $clean = [];
    foreach ($value as $index => $item) {
        $clean[] = aiSchemaValidateNode($item, $items, $path . '[' . $index . ']', $depth + 1, $errors);
    }
    return $clean;
}

/**
 * @param mixed $value
 * @param list<string> $errors
 * @return array<string, mixed>|null
 */
function aiSchemaValidateObject($value, array $schema, string $path, int $depth, array &$errors): ?array {
    if (!is_array($value) || ($value !== [] && array_is_list($value))) {
        $errors[] = "$path must be an object.";
        return null;
    }
    $fields = $schema['fields'] ?? null;
    if (!is_array($fields)) {
        throw new AiSchemaDefinitionError("Object schema at $path has no fields.");
    }
    if (count($value) > DFMS_AI_SCHEMA_LIMITS['max_object_fields']) {
        $errors[] = "$path has too many fields.";
        return null;
    }

    // An unexpected key is a REFUSAL by default. See the header: a model that
    // invents a field misunderstood the contract, and the rest of its answer
    // does not get the benefit of the doubt.
    if (empty($schema['allow_unknown'])) {
        foreach (array_keys($value) as $key) {
            if (!array_key_exists((string) $key, $fields)) {
                $errors[] = "$path contains an unexpected field '" . (string) $key . "'.";
            }
        }
    }

    $clean = [];
    foreach ($fields as $name => $fieldSchema) {
        if (!is_array($fieldSchema)) {
            throw new AiSchemaDefinitionError("Field schema at $path.$name is not an array.");
        }
        $name = (string) $name;
        if (!array_key_exists($name, $value)) {
            if (!empty($fieldSchema['required'])) {
                $errors[] = "$path is missing required field '$name'.";
            }
            continue;
        }
        $clean[$name] = aiSchemaValidateNode(
            $value[$name],
            $fieldSchema,
            $path . '.' . $name,
            $depth + 1,
            $errors
        );
    }
    return $clean;
}

// ===========================================================================
// THE ONE CALL MOST CALLERS WANT
// ===========================================================================

/**
 * Extract and validate in one step.
 *
 * @return array{ok: bool, data: mixed, reason: ?string, errors: list<string>}
 *         `reason` is a MACHINE TOKEN from DFMS_AI_SCHEMA_REASONS, never
 *         upstream prose and never the offending text.
 */
function aiStructuredParse(string $raw, array $schema): array {
    $extracted = aiJsonExtract($raw);
    if (!$extracted['ok']) {
        return [
            'ok' => false, 'data' => null,
            'reason' => $extracted['reason'], 'errors' => [],
        ];
    }

    $validated = aiSchemaValidate($extracted['value'], $schema);
    if (!$validated['ok']) {
        return [
            'ok' => false, 'data' => null,
            'reason' => 'schema_violation', 'errors' => $validated['errors'],
        ];
    }

    return ['ok' => true, 'data' => $validated['value'], 'reason' => null, 'errors' => []];
}

/**
 * A human-readable description of a schema, appended to a system prompt so the
 * model is told the contract it will be held to.
 *
 * Generated FROM the schema rather than written alongside it, because a
 * hand-written description drifts from the rules that are actually enforced,
 * and the version the model sees would then be the one nobody checks.
 */
function aiSchemaDescribe(array $schema): string {
    return json_encode(aiSchemaDescribeNode($schema), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        ?: '{}';
}

/** @return mixed */
function aiSchemaDescribeNode(array $schema) {
    $type = (string) ($schema['type'] ?? 'string');

    switch ($type) {
        case 'object':
            $described = [];
            foreach (($schema['fields'] ?? []) as $name => $field) {
                if (!is_array($field)) {
                    continue;
                }
                $label = (string) $name . (empty($field['required']) ? ' (optional)' : ' (required)');
                $described[$label] = aiSchemaDescribeNode($field);
            }
            return $described;
        case 'array':
            $items = $schema['items'] ?? ['type' => 'string'];
            return [is_array($items) ? aiSchemaDescribeNode($items) : 'string'];
        case 'enum':
            return 'one of: ' . implode(' | ', array_map('strval', $schema['values'] ?? []));
        case 'integer':
            return 'whole number';
        case 'decimal':
            return 'number';
        case 'boolean':
            return 'true or false';
        default:
            return 'text';
    }
}
