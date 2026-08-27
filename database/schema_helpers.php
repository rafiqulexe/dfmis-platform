<?php
// ============================================================================
// SCHEMA HELPERS — conditional DDL that works on BOTH MySQL 8 and MariaDB.
//
// Baseline audit D-7: migrations 002–004 used `ADD COLUMN IF NOT EXISTS` and
// `CREATE INDEX IF NOT EXISTS`, which are MariaDB extensions. MySQL 8 rejects
// both as syntax errors, ruling out most managed hosting.
//
// These helpers ask information_schema first — supported identically by both
// engines — so every migration written against them is portable AND
// idempotent without relying on vendor syntax.
// ============================================================================

function schemaDatabaseName(PDO $conn): string {
    static $name = null;
    if ($name === null) {
        $name = (string) $conn->query('SELECT DATABASE()')->fetchColumn();
    }
    return $name;
}

function schemaTableExists(PDO $conn, string $table): bool {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?"
    );
    $stmt->execute([schemaDatabaseName($conn), $table]);
    return ((int) $stmt->fetchColumn()) > 0;
}

function schemaColumnExists(PDO $conn, string $table, string $column): bool {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $stmt->execute([schemaDatabaseName($conn), $table, $column]);
    return ((int) $stmt->fetchColumn()) > 0;
}

function schemaIndexExists(PDO $conn, string $table, string $index): bool {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?"
    );
    $stmt->execute([schemaDatabaseName($conn), $table, $index]);
    return ((int) $stmt->fetchColumn()) > 0;
}

function schemaConstraintExists(PDO $conn, string $table, string $constraint): bool {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?"
    );
    $stmt->execute([schemaDatabaseName($conn), $table, $constraint]);
    return ((int) $stmt->fetchColumn()) > 0;
}

function schemaColumnDefault(PDO $conn, string $table, string $column) {
    $stmt = $conn->prepare(
        "SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $stmt->execute([schemaDatabaseName($conn), $table, $column]);
    $value = $stmt->fetchColumn();
    return $value === false ? null : $value;
}

// ---- guarded operations ----------------------------------------------------
// Each returns a short human-readable line describing what it did (or skipped),
// which the runner prints so an operator can see exactly what changed.

function schemaAddColumn(PDO $conn, string $table, string $column, string $definition): string {
    if (!schemaTableExists($conn, $table)) {
        return "  skip  $table.$column — table does not exist";
    }
    if (schemaColumnExists($conn, $table, $column)) {
        return "  skip  $table.$column — already present";
    }
    $conn->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    return "  ADD   $table.$column";
}

function schemaDropDefault(PDO $conn, string $table, string $column): string {
    if (!schemaColumnExists($conn, $table, $column)) {
        return "  skip  $table.$column — column does not exist";
    }
    if (schemaColumnDefault($conn, $table, $column) === null) {
        return "  skip  $table.$column — no default set";
    }
    $conn->exec("ALTER TABLE `$table` ALTER COLUMN `$column` DROP DEFAULT");
    return "  DROP  default on $table.$column";
}

function schemaAddIndex(PDO $conn, string $table, string $index, string $columns): string {
    if (!schemaTableExists($conn, $table)) {
        return "  skip  $index — table $table does not exist";
    }
    if (schemaIndexExists($conn, $table, $index)) {
        return "  skip  $index — already present";
    }
    $conn->exec("CREATE INDEX `$index` ON `$table` ($columns)");
    return "  ADD   index $index on $table($columns)";
}

function schemaAddUnique(PDO $conn, string $table, string $index, string $columns): string {
    if (schemaIndexExists($conn, $table, $index)) {
        return "  skip  $index — already present";
    }
    $conn->exec("ALTER TABLE `$table` ADD CONSTRAINT `$index` UNIQUE ($columns)");
    return "  ADD   unique $index on $table($columns)";
}

function schemaDropIndex(PDO $conn, string $table, string $index): string {
    if (!schemaIndexExists($conn, $table, $index)) {
        return "  skip  drop $index — not present";
    }
    $conn->exec("ALTER TABLE `$table` DROP INDEX `$index`");
    return "  DROP  index $index on $table";
}

function schemaAddForeignKey(PDO $conn, string $table, string $name, string $column, string $refTable, string $refColumn, string $onDelete = 'RESTRICT'): string {
    if (!schemaTableExists($conn, $table)) {
        return "  skip  $name — table $table does not exist";
    }
    if (schemaConstraintExists($conn, $table, $name)) {
        return "  skip  $name — already present";
    }
    // A foreign key needs an index on the referencing column; add one first so
    // the engine does not silently create an auto-named one we cannot track.
    $autoIndex = 'idx_' . $table . '_' . $column;
    if (!schemaIndexExists($conn, $table, $autoIndex) && !schemaIndexExists($conn, $table, $column)) {
        $conn->exec("CREATE INDEX `$autoIndex` ON `$table` (`$column`)");
    }
    $conn->exec(
        "ALTER TABLE `$table` ADD CONSTRAINT `$name`
         FOREIGN KEY (`$column`) REFERENCES `$refTable` (`$refColumn`)
         ON DELETE $onDelete ON UPDATE CASCADE"
    );
    return "  ADD   fk $name: $table.$column -> $refTable.$refColumn (ON DELETE $onDelete)";
}

function schemaCreateTable(PDO $conn, string $table, string $definition): string {
    if (schemaTableExists($conn, $table)) {
        return "  skip  table $table — already present";
    }
    $conn->exec("CREATE TABLE `$table` ($definition) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    return "  ADD   table $table";
}

/**
 * Widen an existing ENUM column so it also accepts $newValues.
 *
 * Phase 4 needs this for `cows.health_status`, which already carries real data
 * under four values and must gain two more WITHOUT reinterpreting a single
 * existing row.
 *
 * The current definition is read from information_schema and the new values are
 * APPENDED to it — the existing values, their order and the column's default
 * are all preserved, so no stored value changes meaning. When every requested
 * value is already present the column is left untouched, which is what makes
 * re-running the migration a no-op.
 *
 * MySQL stores an ENUM's members by ordinal, so appending is safe while
 * reordering or removing would silently remap existing rows. This helper can
 * therefore only ever widen.
 */
function schemaEnsureEnumValues(PDO $conn, string $table, string $column, array $newValues, string $suffix = ''): string {
    if (!schemaColumnExists($conn, $table, $column)) {
        return "  skip  $table.$column — column does not exist";
    }

    $stmt = $conn->prepare(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $stmt->execute([schemaDatabaseName($conn), $table, $column]);
    $type = (string) $stmt->fetchColumn();

    if (stripos($type, 'enum(') !== 0) {
        return "  skip  $table.$column — not an ENUM (found '$type')";
    }

    // Parse the members out of enum('a','b',...). Values are single-quoted and
    // any embedded quote is doubled, which is why this unescapes rather than
    // splitting naively on commas.
    preg_match_all("/'((?:[^']|'')*)'/", $type, $matches);
    $existing = array_map(static function (string $value): string {
        return str_replace("''", "'", $value);
    }, $matches[1]);

    $missing = array_values(array_diff($newValues, $existing));
    if ($missing === []) {
        return "  skip  $table.$column — already accepts " . implode(', ', $newValues);
    }

    $members = array_merge($existing, $missing);
    $quoted = implode(', ', array_map(static function (string $value): string {
        return "'" . str_replace("'", "''", $value) . "'";
    }, $members));

    $conn->exec("ALTER TABLE `$table` MODIFY COLUMN `$column` ENUM($quoted) $suffix");
    return "  WIDEN $table.$column += " . implode(', ', $missing) . ' (existing values untouched)';
}
