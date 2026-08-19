<?php
// Tenant (farm) context. Exactly one farm exists today, but every
// business table is farm_id-scoped so a second farm is a query filter
// away, not a rewrite. auth.php must already be loaded.

function currentFarmId() {
    return $_SESSION['farm_id'] ?? 1;
}
