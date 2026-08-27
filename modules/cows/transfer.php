<?php
// ============================================================================
// ANIMAL MOVEMENT AND TRANSFER
//
// Two genuinely different operations on one page, because to a farm worker they
// are the same question — "where is this animal going?":
//
//   INTERNAL   move between locations on THIS farm. Routine.
//   INTER-FARM change the animal's farm_id, which is its TENANT KEY. Guarded
//              by membership AND same-organization checks in
//              includes/livestock.php, and restricted to farm managers.
//
// Both are transactional. A half-completed transfer would leave an animal
// visible to neither farm, or to both.
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
require_once '../../includes/livestock.php';

requireAuth('animal.transfer');

$animalId = dfms_route_id('id');
$conn = getDBConnection();
$animal = animalFindOr404($conn, $animalId);

$locations = farmLocations($conn);

// Farms this user may transfer TO: their own farms, in the same organization,
// excluding the current one. Resolved from live membership, never from input.
$destinationFarms = [];
$allowed = array_values(array_diff(allowedFarmIds(), [(int) $animal['farm_id']]));
if ($allowed) {
    $placeholders = implode(',', array_fill(0, count($allowed), '?'));
    $stmt = $conn->prepare(
        "SELECT farm_id, name FROM farms
          WHERE farm_id IN ($placeholders) AND organization_id = ? ORDER BY name"
    );
    $stmt->execute(array_merge($allowed, [currentOrganizationId()]));
    $destinationFarms = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $v = new DfmsValidator($_POST);
    $mode = $v->enum('mode', ['internal', 'inter_farm']);
    $transferredOn = $v->date('transferred_on', ['nullable' => true]) ?? farmToday();
    $reason = $v->string('reason', 0, 150, ['nullable' => true]);
    $notes = $v->text('notes', 2000, ['nullable' => true]);

    if ($v->fails()) {
        $error_message = $v->firstError();
    } else {
        try {
            if ($mode === 'internal') {
                // An empty location means "unassign", which is legitimate.
                $locationId = $v->id('location_id', ['nullable' => true]);
                if ($v->fails()) {
                    throw new InvalidArgumentException($v->firstError() ?? 'Invalid location.');
                }
                $moved = animalMoveWithinFarm($conn, (int) $animal['cow_id'], $locationId, [
                    'transferred_on' => $transferredOn,
                    'reason'         => $reason,
                    'notes'          => $notes,
                ]);
                redirectWith('modules/cows/view.php?id=' . (int) $animal['cow_id'], 'success',
                    $moved ? 'Animal moved.' : 'The animal is already in that location.');
            } else {
                $destinationFarmId = $v->id('destination_farm_id');
                if ($v->fails()) {
                    throw new InvalidArgumentException($v->firstError() ?? 'Select a destination farm.');
                }
                // Membership and same-organization checks happen inside.
                animalTransferToFarm($conn, (int) $animal['cow_id'], $destinationFarmId, [
                    'transferred_on' => $transferredOn,
                    'reason'         => $reason,
                    'notes'          => $notes,
                ]);
                // The animal now belongs to the other farm and is no longer
                // visible from here, so return to the list rather than a
                // profile the user can no longer open.
                redirectWith('modules/cows/', 'success', 'Animal transferred to the destination farm.');
            }
        } catch (InvalidArgumentException $e) {
            $error_message = $e->getMessage();
        } catch (RuntimeException $e) {
            // Authorization failures from the domain layer, already audited.
            $error_message = $e->getMessage();
        } catch (PDOException $e) {
            $error_message = safeError($e, 'The transfer could not be completed');
        }
    }
}

$history = animalTransferHistory($conn, (int) $animal['cow_id']);
$displayName = animalDisplayName($animal);

$page_title = 'Move or transfer';
$page_breadcrumb = [
    ['label' => 'Livestock', 'url' => BASE_URL . 'modules/cows/'],
    ['label' => $displayName, 'url' => BASE_URL . 'modules/cows/view.php?id=' . (int) $animal['cow_id']],
    ['label' => 'Move'],
];
require_once '../../includes/header.php';
?>

<?php dfms_page_header('Move or transfer', $displayName . ' — currently in '
    . ($animal['location_id'] ? 'an assigned location' : 'no assigned location') . '.'); ?>

<?php if ($error_message): ?><?php dfms_alert($error_message, 'error'); ?><?php endif; ?>

<section class="dfms-section">
    <div class="dfms-section-header">
        <div>
            <h2>Move within this farm</h2>
            <p>Change the animal's barn, pen or pasture. Its farm does not change.</p>
        </div>
    </div>
    <div class="dfms-card">
        <form method="POST" action="">
            <?php csrf_field(); ?>
            <input type="hidden" name="mode" value="internal">

            <div class="dfms-field">
                <label for="location_id">Destination location</label>
                <select id="location_id" name="location_id">
                    <option value="">— Unassigned —</option>
                    <?php foreach ($locations as $location): ?>
                        <option value="<?php echo (int) $location['location_id']; ?>"
                            <?php echo (int) $location['location_id'] === (int) $animal['location_id'] ? 'selected' : ''; ?>>
                            <?php echo e($location['name']); ?> (<?php echo e($location['location_type']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (empty($locations)): ?>
                    <small class="dfms-text-muted">No locations are defined for this farm yet.</small>
                <?php endif; ?>
            </div>

            <div class="dfms-field">
                <label for="transferred_on">Date</label>
                <input id="transferred_on" name="transferred_on" type="date"
                       value="<?php echo e(farmToday()); ?>" max="<?php echo e(farmToday()); ?>">
            </div>

            <div class="dfms-field">
                <label for="reason">Reason <span class="dfms-text-muted">(optional)</span></label>
                <input id="reason" name="reason" type="text" autocomplete="off" maxlength="150">
            </div>

            <button type="submit" class="dfms-btn dfms-btn-primary">Move animal</button>
        </form>
    </div>
</section>

<section class="dfms-section">
    <div class="dfms-section-header">
        <div>
            <h2>Transfer to another farm</h2>
            <p>Moves the animal and its history to a different farm in the same organization.</p>
        </div>
    </div>
    <div class="dfms-card">
        <?php if (empty($destinationFarms)): ?>
            <?php dfms_alert(
                'There is no other farm you can transfer to. A transfer requires a second farm '
                . 'in the same organization that you are also a member of.',
                'info'
            ); ?>
        <?php else: ?>
            <?php dfms_alert(
                'A transfer changes which farm owns this animal. Its full history moves with it, '
                . 'and it will no longer appear on this farm.',
                'warning'
            ); ?>
            <form method="POST" action="" onsubmit="return confirm('Transfer this animal to another farm? It will no longer appear on this farm.');">
                <?php csrf_field(); ?>
                <input type="hidden" name="mode" value="inter_farm">

                <div class="dfms-field">
                    <label for="destination_farm_id">Destination farm</label>
                    <select id="destination_farm_id" name="destination_farm_id" required>
                        <?php foreach ($destinationFarms as $farm): ?>
                            <option value="<?php echo (int) $farm['farm_id']; ?>"><?php echo e($farm['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="dfms-field">
                    <label for="transfer_date">Date</label>
                    <input id="transfer_date" name="transferred_on" type="date"
                           value="<?php echo e(farmToday()); ?>" max="<?php echo e(farmToday()); ?>">
                </div>

                <div class="dfms-field">
                    <label for="transfer_reason">Reason <span class="dfms-text-muted">(optional)</span></label>
                    <input id="transfer_reason" name="reason" type="text" autocomplete="off" maxlength="150">
                </div>

                <button type="submit" class="dfms-btn dfms-btn-primary">Transfer animal</button>
            </form>
        <?php endif; ?>
    </div>
</section>

<section class="dfms-section">
    <div class="dfms-section-header">
        <div><h2>Movement history</h2></div>
    </div>
    <div class="dfms-card">
        <?php if (empty($history)): ?>
            <?php dfms_empty_state('No movements recorded', 'This animal has not been moved yet.'); ?>
        <?php else: ?>
            <?php dfms_table_open([['label' => 'Date'], ['label' => 'Type'], ['label' => 'From'],
                                   ['label' => 'To'], ['label' => 'Recorded by']]); ?>
            <?php foreach ($history as $transfer): ?>
                <?php $isInternal = $transfer['transfer_type'] === 'internal'; ?>
                <tr>
                    <?php
                    dfms_cell('Date', e(fmt_date($transfer['transferred_on'])));
                    dfms_cell('Type', dfms_badge_html($isInternal ? 'Internal' : 'Inter-farm', $isInternal ? 'neutral' : 'info'));
                    dfms_cell('From', e($isInternal ? ($transfer['from_location_name'] ?? 'Unassigned') : ($transfer['from_farm_name'] ?? '—')));
                    dfms_cell('To', e($isInternal ? ($transfer['to_location_name'] ?? 'Unassigned') : ($transfer['to_farm_name'] ?? '—')));
                    dfms_cell('Recorded by', e($transfer['username'] ?? 'system'));
                    ?>
                </tr>
            <?php endforeach; ?>
            <?php dfms_table_close(); ?>
        <?php endif; ?>
    </div>
</section>

<?php require_once '../../includes/footer.php'; ?>
