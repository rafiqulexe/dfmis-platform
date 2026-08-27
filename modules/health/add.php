<?php
require_once '../../includes/auth.php';
require_once '../../includes/errors.php';
require_once '../../includes/csrf.php';
require_once '../../includes/audit.php';
require_once '../../includes/functions.php';
require_once '../../includes/permissions.php';
require_once '../../includes/repository.php';
require_once '../../includes/validation.php';

requireAuth('health.create');

$error = '';
$success = '';

// Values the health_events ENUM columns actually accept. Anything else is
// rejected rather than handed to the database (baseline audit C-6).
const HEALTH_SEVERITIES = ['routine', 'moderate', 'urgent'];

// Dropdown lists only this farm's cows.
try {
    $conn = getDBConnection();
    $cows = tenantQuery($conn, "SELECT cow_id, unique_id, name FROM cows WHERE {scope} AND deleted_at IS NULL ORDER BY unique_id");
} catch (PDOException $e) {
    $cows = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $v = new DfmsValidator($_POST);
    $cow_id = $v->id('cow_id');
    $event_date = $v->date('event_date');
    $event_type = $v->string('event_type', 1, 50);
    $severity = $v->enum('severity', HEALTH_SEVERITIES, ['default' => 'routine']);
    $description = $v->text('description', 5000, ['nullable' => true]);

    if ($v->fails()) {
        $error = $v->firstError();
    } else {
        // Never trust the submitted cow_id — a POST can name any id, so
        // confirm it belongs to this farm before writing a row against it.
        $targetCow = tenantFind($conn, 'cows', 'cow_id', $cow_id, 'deleted_at IS NULL');
        if ($targetCow === null) {
            $error = 'Please select a valid cow.';
        } else {
            try {
                $eventId = tenantInsert($conn, 'health_events', [
                    'cow_id'      => $targetCow['cow_id'],
                    'event_date'  => $event_date,
                    'event_type'  => $event_type,
                    'severity'    => $severity,
                    'description' => $description,
                    'recorded_by' => currentUserId(),
                ]);
                auditCreate('health_event', $eventId, [
                    'cow_id'     => (int) $targetCow['cow_id'],
                    'event_type' => $event_type,
                    'severity'   => $severity,
                    'event_date' => $event_date,
                ]);
                $success = 'Health event added successfully';
            } catch (PDOException $e) {
                $error = safeError($e, 'Error adding health event');
            }
        }
    }
}

$page_title = 'Log Health Event';
$page_breadcrumb = [['label' => 'Health', 'url' => 'index.php'], ['label' => 'Log Event']];
require_once '../../includes/header.php';
?>

<div class="py-6">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="sm:flex sm:items-center">
            <div class="sm:flex-auto">
                <h1 class="text-2xl font-semibold text-gray-900">Add Health Event</h1>
                <p class="mt-2 text-sm text-gray-700">Record a new health event for a cow</p>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="mt-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="mt-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="mt-8 space-y-6">
            <?php csrf_field(); ?>
            <div class="bg-white shadow px-4 py-5 sm:rounded-lg sm:p-6">
                <div class="grid grid-cols-1 gap-6">
                    <div>
                        <label for="cow_id" class="block text-sm font-medium text-gray-700">Cow</label>
                        <select name="cow_id" id="cow_id" required
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                            <option value="">Select a cow</option>
                            <?php foreach ($cows as $cow): ?>
                                <option value="<?php echo $cow['cow_id']; ?>">
                                    <?php echo htmlspecialchars($cow['unique_id']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="event_date" class="block text-sm font-medium text-gray-700">Event Date</label>
                        <input type="date" name="event_date" id="event_date" required
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                    </div>

                    <div>
                        <label for="event_type" class="block text-sm font-medium text-gray-700">Event Type</label>
                        <select name="event_type" id="event_type" required
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                            <option value="">Select event type</option>
                            <option value="Vaccination">Vaccination</option>
                            <option value="Check-up">Check-up</option>
                            <option value="Treatment">Treatment</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>

                    <div>
                        <label for="severity" class="block text-sm font-medium text-gray-700">Severity</label>
                        <select name="severity" id="severity" required
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                            <option value="routine">Routine</option>
                            <option value="moderate">Moderate</option>
                            <option value="urgent">Urgent</option>
                        </select>
                    </div>

                    <div>
                        <label for="description" class="block text-sm font-medium text-gray-700">Description</label>
                        <textarea name="description" id="description" rows="3"
                                  class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"></textarea>
                    </div>
                </div>
            </div>

            <div class="flex justify-end">
                <a href="index.php" class="bg-white py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Cancel
                </a>
                <button type="submit" class="ml-3 inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                    Save
                </button>
            </div>
        </form>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?> 