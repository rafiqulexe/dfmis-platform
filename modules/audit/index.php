<?php
// ============================================================================
// AUDIT LOG READER
//
// Baseline audit D-1: `audit.view` was defined and granted to farm managers,
// the table existed with indexes, and there was nothing to view because
// nothing wrote and nothing read. Both halves now exist.
//
// Reads are farm-scoped like any other tenant data.
// ============================================================================

require_once '../../includes/auth.php';
require_once '../../includes/errors.php';
require_once '../../includes/audit.php';
require_once '../../includes/functions.php';
require_once '../../includes/permissions.php';
require_once '../../includes/repository.php';
require_once '../../includes/validation.php';

requireAuth('audit.view');

$conn = getDBConnection();
$error_message = '';
$entries = [];
$totalEntries = 0;
$perPage = 50;

$v = new DfmsValidator($_GET);
$page = $v->integer('page', ['nullable' => true, 'min' => 1, 'max' => 100000]) ?? 1;
$actionFilter = $v->string('action', 0, 100, ['nullable' => true]);

try {
    $params = [];
    $where = '';
    if ($actionFilter) {
        $where = ' AND a.action LIKE ?';
        $params[] = $actionFilter . '%';
    }

    $totalEntries = (int) tenantScalar(
        $conn,
        "SELECT COUNT(*) FROM audit_log a WHERE {scope}" . $where,
        $params,
        0,
        'a'
    );

    $totalPages = max(1, (int) ceil($totalEntries / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    // LIMIT/OFFSET are integers we computed, never client strings — they are
    // interpolated because MySQL will not accept placeholders there under
    // real (non-emulated) prepares.
    $entries = tenantQuery(
        $conn,
        "SELECT a.*, u.username
           FROM audit_log a
           LEFT JOIN admin_users u ON u.admin_id = a.admin_id
          WHERE {scope}" . $where . "
          ORDER BY a.created_at DESC, a.audit_id DESC
          LIMIT " . (int) $perPage . " OFFSET " . (int) $offset,
        $params,
        'a'
    );
} catch (PDOException $e) {
    $error_message = safeError($e, 'Could not load the audit log');
    $totalPages = 1;
}

$page_title = 'Audit Log';
$page_breadcrumb = [['label' => 'Audit Log']];
require_once '../../includes/header.php';
?>

<?php dfms_page_header('Audit Log', 'Every recorded change on this farm, newest first.'); ?>

<?php if ($error_message): ?>
    <?php dfms_alert($error_message, 'error'); ?>
<?php endif; ?>

<section class="dfms-section">
    <div class="dfms-card">
        <?php if (empty($entries)): ?>
            <?php dfms_empty_state(
                'No audit entries yet',
                'Changes to animals, production, stock and alerts will appear here as they happen.'
            ); ?>
        <?php else: ?>
            <?php dfms_table_open([
                ['label' => 'When'],
                ['label' => 'Who'],
                ['label' => 'Action'],
                ['label' => 'Entity'],
                ['label' => 'Details'],
            ]); ?>
            <?php foreach ($entries as $entry): ?>
                <tr>
                    <?php
                    dfms_cell('When', e(date('M j, Y H:i', strtotime($entry['created_at']))));
                    dfms_cell('Who', e($entry['username'] ?? 'system'));
                    dfms_cell('Action', dfms_badge_html($entry['action'], dfms_audit_tone($entry['action'])));
                    dfms_cell('Entity', e($entry['entity_type'] . ($entry['entity_id'] ? ' #' . $entry['entity_id'] : '')));

                    // old_value/new_value are JSON written by audit.php and
                    // already credential-filtered. Escaped again on output.
                    $detail = '';
                    if (!empty($entry['new_value'])) {
                        $detail = '<code style="font-size:.78rem;">' . e(mb_substr($entry['new_value'], 0, 160, 'UTF-8')) . '</code>';
                    } elseif (!empty($entry['old_value'])) {
                        $detail = '<code style="font-size:.78rem;">' . e(mb_substr($entry['old_value'], 0, 160, 'UTF-8')) . '</code>';
                    }
                    dfms_cell('Details', $detail ?: '<span class="dfms-text-muted">—</span>');
                    ?>
                </tr>
            <?php endforeach; ?>
            <?php dfms_table_close(); ?>

            <?php dfms_pagination($page, $totalPages, BASE_URL . 'modules/audit/', $totalEntries); ?>
        <?php endif; ?>
    </div>
</section>

<?php
// Maps an audit action to a badge tone so security events stand out from
// ordinary business changes.
function dfms_audit_tone($action) {
    if (strpos($action, 'auth.login_failed') === 0 || strpos($action, 'authz.') === 0
        || strpos($action, 'tenant.') === 0) {
        return 'danger';
    }
    if (strpos($action, '.delete') !== false || strpos($action, '.archive') !== false) {
        return 'warning';
    }
    if (strpos($action, '.create') !== false) {
        return 'success';
    }
    return 'neutral';
}
?>

<?php require_once '../../includes/footer.php'; ?>
