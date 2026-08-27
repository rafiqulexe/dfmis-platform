<?php
// ============================================================================
// NOTIFICATION PREFERENCES
//
// Records how THIS user wants to be told about events on THIS farm. Nothing
// delivers yet — see includes/notifications.php for the alert/notification
// distinction and the honest state of each channel.
//
// AUTHORIZATION: requireAuth('notifications.manage'). The user id and farm id
// are taken from the tenant context inside dfms_set_notification_preference(),
// so a user can only ever change their own preferences on their active farm.
// ============================================================================

require_once '../../includes/auth.php';
require_once '../../includes/errors.php';
require_once '../../includes/csrf.php';
require_once '../../includes/audit.php';
require_once '../../includes/functions.php';
require_once '../../includes/permissions.php';
require_once '../../includes/repository.php';
require_once '../../includes/validation.php';
require_once '../../includes/settings.php';
require_once '../../includes/notifications.php';

requireAuth('notifications.manage');

$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    try {
        // The form posts the full matrix; an unchecked box simply does not
        // appear in $_POST, which is how "off" is expressed.
        $submitted = $_POST['pref'] ?? [];
        if (!is_array($submitted)) {
            $submitted = [];
        }

        $changes = 0;
        foreach (dfms_notification_categories() as $category => $_) {
            foreach (dfms_notification_channels() as $channel => $meta) {
                // An unavailable channel is not writable — the checkbox is
                // disabled in the UI, and the server refuses it too rather
                // than trusting that.
                if (!$meta['available']) {
                    continue;
                }
                $enabled = isset($submitted[$category][$channel]);
                dfms_set_notification_preference($category, $channel, $enabled);
                $changes++;
            }
        }

        redirectWith('modules/settings/notifications.php', 'success',
            $changes > 0 ? t('state.saved') : t('state.no_changes'));
    } catch (InvalidArgumentException $e) {
        $error_message = 'That notification category or channel is not recognised.';
    } catch (Throwable $e) {
        $error_message = safeError($e, 'Could not save notification preferences');
    }
}

$preferences = dfms_notification_preferences();

$page_title = 'Notification Preferences';
$page_breadcrumb = [['label' => 'Settings', 'url' => 'index.php'], ['label' => 'Notifications']];
require_once '../../includes/header.php';
?>

<?php dfms_page_header(
    'Notification preferences',
    'How you want to hear about events on ' . e(dfms_farm_row()['name'] ?? 'this farm') . '.'
); ?>

<?php if ($error_message): ?><?php dfms_alert($error_message, 'error'); ?><?php endif; ?>

<?php dfms_alert(
    'Only in-app delivery works today — the alerts page and the bell in the top bar. '
  . 'Email, SMS and push are modelled here so preferences survive, but no message is '
  . 'sent through them yet. Alerts themselves are recorded regardless of these settings.',
    'info'
); ?>

<section class="dfms-section">
    <div class="dfms-card">
        <form method="POST" action="">
            <?php csrf_field(); ?>
            <?php
            $columns = [['label' => 'Event category']];
            foreach (dfms_notification_channels() as $channel => $meta) {
                $columns[] = ['label' => $meta['label'] . ($meta['available'] ? '' : ' (soon)')];
            }
            dfms_table_open($columns);
            ?>
            <?php foreach (dfms_notification_categories() as $category => $meta): ?>
                <tr>
                    <?php dfms_cell('Event category',
                        '<strong>' . e($meta['label']) . '</strong><br>'
                        . '<span class="dfms-text-muted" style="font-size:.85rem;">' . e($meta['description']) . '</span>'
                    ); ?>
                    <?php foreach (dfms_notification_channels() as $channel => $channelMeta): ?>
                        <?php
                        $checked = !empty($preferences[$category][$channel]);
                        $id = "pref-{$category}-{$channel}";
                        $cell = '<input type="checkbox" id="' . e($id) . '"'
                              . ' name="pref[' . e($category) . '][' . e($channel) . ']" value="1"'
                              . ($checked ? ' checked' : '')
                              . ($channelMeta['available'] ? '' : ' disabled')
                              . '>'
                              . '<label class="dfms-visually-hidden" for="' . e($id) . '">'
                              . e($channelMeta['label'] . ' for ' . $meta['label']) . '</label>';
                        dfms_cell($channelMeta['label'], $cell);
                        ?>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            <?php dfms_table_close(); ?>

            <div style="display:flex;gap:.75rem;margin-top:1.5rem;">
                <button type="submit" class="dfms-btn dfms-btn-primary"><?php echo e(t('action.save')); ?></button>
                <a href="<?php echo BASE_URL; ?>modules/settings/" class="dfms-btn dfms-btn-secondary"><?php echo e(t('action.cancel')); ?></a>
            </div>
        </form>
    </div>
</section>

<?php require_once '../../includes/footer.php'; ?>
