<?php
// ============================================================================
// INPUT VALIDATION — one definition per field type, used by every handler.
//
// Baseline audit C-6/C-7/C-8: validation rules differed page to page for the
// same field. cows/add.php wrote an empty string into a DATE column;
// milk/add.php accepted any string as a yield while milk/edit.php checked
// is_numeric; health/add.php never checked severity against its ENUM; and an
// unrecognised inventory adjustment type silently SUBTRACTED stock.
//
// USAGE
//   $v = new DfmsValidator($_POST);
//   $tag  = $v->required('unique_id')->string('unique_id', 1, 50);
//   $dob  = $v->date('date_of_birth', ['nullable' => true]);
//   $qty  = $v->decimal('quantity', ['min' => 0, 'max' => 999999.99]);
//   $sev  = $v->enum('severity', ['routine','moderate','urgent']);
//   if ($v->fails()) { $error = $v->firstError(); }
//
// Every accessor returns a normalised value (trimmed, cast) or null. A failed
// rule records an error and returns null, so a handler can collect all the
// problems in one pass rather than stopping at the first.
// ============================================================================

class DfmsValidator
{
    private array $data;
    private array $errors = [];

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    // ---- state ------------------------------------------------------------

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): ?string
    {
        return $this->errors ? reset($this->errors) : null;
    }

    // Records at most one error per field — the first is the most specific.
    private function fail(string $field, string $message)
    {
        if (!isset($this->errors[$field])) {
            $this->errors[$field] = $message;
        }
        return null;
    }

    private function raw(string $field)
    {
        $value = $this->data[$field] ?? null;
        return is_string($value) ? trim($value) : $value;
    }

    private static function label(string $field): string
    {
        return ucfirst(str_replace('_', ' ', $field));
    }

    // Shared "is this absent?" test. An empty string counts as absent, which
    // is what an untouched HTML form field submits.
    private function isBlank($value): bool
    {
        return $value === null || $value === '' || (is_array($value) && $value === []);
    }

    // ---- rules ------------------------------------------------------------

    public function required(string $field, ?string $message = null): self
    {
        if ($this->isBlank($this->raw($field))) {
            $this->fail($field, $message ?? self::label($field) . ' is required.');
        }
        return $this;
    }

    public function string(string $field, int $min = 0, int $max = 255, array $options = []): ?string
    {
        $value = $this->raw($field);
        if ($this->isBlank($value)) {
            return !empty($options['nullable']) ? null
                : $this->fail($field, self::label($field) . ' is required.');
        }
        if (!is_string($value)) {
            return $this->fail($field, self::label($field) . ' must be text.');
        }
        // Reject control characters and embedded NULs outright — never valid
        // in a name, tag or note, and a common smuggling vector.
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
            return $this->fail($field, self::label($field) . ' contains invalid characters.');
        }
        $length = mb_strlen($value, 'UTF-8');
        if ($length < $min) {
            return $this->fail($field, self::label($field) . " must be at least {$min} characters.");
        }
        if ($length > $max) {
            return $this->fail($field, self::label($field) . " must be {$max} characters or fewer.");
        }
        if (!empty($options['pattern']) && !preg_match($options['pattern'], $value)) {
            return $this->fail($field, $options['pattern_message'] ?? self::label($field) . ' is not in a valid format.');
        }
        return $value;
    }

    // Positive integer identifier arriving from a URL or form. Rejects
    // floats, negatives, zero and anything non-numeric before it can reach a
    // query. Tenant ownership is a separate check (see repository.php).
    public function id(string $field, array $options = []): ?int
    {
        $value = $this->raw($field);
        if ($this->isBlank($value)) {
            return !empty($options['nullable']) ? null
                : $this->fail($field, self::label($field) . ' is required.');
        }
        if (!is_scalar($value) || !preg_match('/^\d+$/', (string) $value)) {
            return $this->fail($field, 'Invalid ' . strtolower(self::label($field)) . '.');
        }
        $int = (int) $value;
        if ($int < 1 || $int > PHP_INT_MAX) {
            return $this->fail($field, 'Invalid ' . strtolower(self::label($field)) . '.');
        }
        return $int;
    }

    public function integer(string $field, array $options = []): ?int
    {
        $value = $this->raw($field);
        if ($this->isBlank($value)) {
            return !empty($options['nullable']) ? null
                : $this->fail($field, self::label($field) . ' is required.');
        }
        if (!is_scalar($value) || !preg_match('/^-?\d+$/', (string) $value)) {
            return $this->fail($field, self::label($field) . ' must be a whole number.');
        }
        $int = (int) $value;
        if (isset($options['min']) && $int < $options['min']) {
            return $this->fail($field, self::label($field) . ' must be at least ' . $options['min'] . '.');
        }
        if (isset($options['max']) && $int > $options['max']) {
            return $this->fail($field, self::label($field) . ' must be no more than ' . $options['max'] . '.');
        }
        return $int;
    }

    // Decimal quantity — yields, weights, stock levels, money.
    // Rejects NaN, INF, exponent notation and impossible magnitudes, all of
    // which survive a bare is_numeric() check.
    public function decimal(string $field, array $options = []): ?float
    {
        $value = $this->raw($field);
        if ($this->isBlank($value)) {
            return !empty($options['nullable']) ? null
                : $this->fail($field, self::label($field) . ' is required.');
        }
        if (!is_scalar($value) || !preg_match('/^-?\d{1,12}(\.\d{1,4})?$/', (string) $value)) {
            return $this->fail($field, self::label($field) . ' must be a number.');
        }
        $float = (float) $value;
        if (!is_finite($float)) {
            return $this->fail($field, self::label($field) . ' must be a number.');
        }
        $min = $options['min'] ?? null;
        $max = $options['max'] ?? null;
        if ($min !== null && $float < $min) {
            return $this->fail($field, self::label($field) . ' cannot be less than ' . $min . '.');
        }
        if ($max !== null && $float > $max) {
            return $this->fail($field, self::label($field) . ' cannot be more than ' . $max . '.');
        }
        return $float;
    }

    // Strict Y-m-d. Rejects '' (which used to be written into DATE columns as
    // 0000-00-00), impossible calendar dates like 2024-02-31, and dates
    // outside a sane window for farm records.
    public function date(string $field, array $options = []): ?string
    {
        $value = $this->raw($field);
        if ($this->isBlank($value)) {
            return !empty($options['nullable']) ? null
                : $this->fail($field, self::label($field) . ' is required.');
        }
        if (!is_string($value)) {
            return $this->fail($field, self::label($field) . ' must be a date.');
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        $bad = $errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0);
        if (!$parsed || $bad || $parsed->format('Y-m-d') !== $value) {
            return $this->fail($field, self::label($field) . ' must be a valid date (YYYY-MM-DD).');
        }
        // A farm record dated 1400 or 2400 is a typo, not data.
        $floor = new DateTimeImmutable($options['min'] ?? '1900-01-01');
        if ($parsed < $floor) {
            return $this->fail($field, self::label($field) . ' is too far in the past.');
        }
        if (empty($options['allow_future'])) {
            $ceiling = new DateTimeImmutable($options['max'] ?? 'today');
            if ($parsed > $ceiling) {
                return $this->fail($field, self::label($field) . ' cannot be in the future.');
            }
        }
        return $parsed->format('Y-m-d');
    }

    // Accepts both the HTML datetime-local format (2024-01-01T10:00) and SQL
    // format, normalising to 'Y-m-d H:i:s'. The datetime-local case is why
    // alerts/edit.php could previously write a value MySQL rejected.
    public function datetime(string $field, array $options = []): ?string
    {
        $value = $this->raw($field);
        if ($this->isBlank($value)) {
            return !empty($options['nullable']) ? null
                : $this->fail($field, self::label($field) . ' is required.');
        }
        foreach (['!Y-m-d\TH:i:s', '!Y-m-d\TH:i', '!Y-m-d H:i:s', '!Y-m-d H:i'] as $format) {
            $parsed = DateTimeImmutable::createFromFormat($format, (string) $value);
            if ($parsed) {
                $errors = DateTimeImmutable::getLastErrors();
                if ($errors && $errors['error_count'] > 0) {
                    continue;
                }
                return $parsed->format('Y-m-d H:i:s');
            }
        }
        return $this->fail($field, self::label($field) . ' must be a valid date and time.');
    }

    public function time(string $field, array $options = []): ?string
    {
        $value = $this->raw($field);
        if ($this->isBlank($value)) {
            return !empty($options['nullable']) ? null
                : $this->fail($field, self::label($field) . ' is required.');
        }
        // PHP's H/i format specifiers ROLL OVER rather than reject: '25:00'
        // parses happily as 01:00 the next day, and '10:99' as 11:39. A
        // silently reinterpreted time is worse than a rejected one, so the
        // parsed result is round-tripped back and compared to the input.
        foreach (['!H:i:s' => 'H:i:s', '!H:i' => 'H:i'] as $format => $echo) {
            $parsed = DateTimeImmutable::createFromFormat($format, (string) $value);
            if ($parsed && $parsed->format($echo) === (string) $value) {
                return $parsed->format('H:i:s');
            }
        }
        return $this->fail($field, self::label($field) . ' must be a valid time (HH:MM).');
    }

    // Strict allow-list. This is what stops "any adjustment_type that isn't
    // 'add' silently subtracts stock" (C-7).
    public function enum(string $field, array $allowed, array $options = []): ?string
    {
        $value = $this->raw($field);
        if ($this->isBlank($value)) {
            if (array_key_exists('default', $options)) {
                return $options['default'];
            }
            return !empty($options['nullable']) ? null
                : $this->fail($field, self::label($field) . ' is required.');
        }
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            return $this->fail($field, self::label($field) . ' is not a recognised value.');
        }
        return $value;
    }

    public function text(string $field, int $max = 5000, array $options = []): ?string
    {
        $value = $this->raw($field);
        if ($this->isBlank($value)) {
            return !empty($options['nullable']) ? null : '';
        }
        if (!is_string($value)) {
            return $this->fail($field, self::label($field) . ' must be text.');
        }
        // Allow newlines and tabs here; strip only the genuinely invalid.
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
            return $this->fail($field, self::label($field) . ' contains invalid characters.');
        }
        if (mb_strlen($value, 'UTF-8') > $max) {
            return $this->fail($field, self::label($field) . " must be {$max} characters or fewer.");
        }
        return $value;
    }
}

// ---------------------------------------------------------------------------
// Convenience: validate an id arriving from the URL, aborting on anything
// malformed before it can reach a query.
// ---------------------------------------------------------------------------
function dfms_route_id($key = 'id')
{
    $v = new DfmsValidator($_GET);
    $id = $v->id($key);
    if ($v->fails()) {
        // Indistinguishable from "does not exist" — a malformed id must not
        // be reported differently from a foreign one.
        if (function_exists('dfms_abort')) {
            dfms_abort(404, "Malformed route id for '$key'");
        }
        http_response_code(404);
        exit('Not found.');
    }
    return $id;
}
