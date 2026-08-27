<?php
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

requireAuth('cows.create');

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $v = new DfmsValidator($_POST);
    // A tag is the animal's only human-readable identifier; it must be
    // present and printable. Uniqueness is now enforced PER FARM by the
    // database (migration 005), so the duplicate below is a same-farm clash.
    $unique_id = $v->string('unique_id', 1, 50);
    $name = $v->string('name', 0, 100, ['nullable' => true]);
    $species = $v->enum('species', array_keys(DFMS_SPECIES), ['default' => 'cattle']);
    $sex = $v->enum('sex', ['female', 'male'], ['default' => 'female']);
    $breed = $v->string('breed', 0, 100, ['nullable' => true]);
    $date_of_birth = $v->date('date_of_birth', ['nullable' => true]);
    $birth_type = $v->enum('birth_type', array_keys(DFMS_BIRTH_TYPES), ['nullable' => true]);
    $acquisition_type = $v->enum('acquisition_type', array_keys(DFMS_ACQUISITION_TYPES), ['nullable' => true]);
    $acquisition_date = $v->date('acquisition_date', ['nullable' => true]);
    $acquisition_source = $v->string('acquisition_source', 0, 150, ['nullable' => true]);
    $notes = $v->text('notes', 5000, ['nullable' => true]);
    $damId = $v->id('dam_id', ['nullable' => true]);
    $sireId = $v->id('sire_id', ['nullable' => true]);
    $damExternal = $v->string('dam_external', 0, 150, ['nullable' => true]);
    $sireExternal = $v->string('sire_external', 0, 150, ['nullable' => true]);

    if ($v->fails()) {
        $error = $v->firstError();
    } else {
        try {
            $conn = getDBConnection();

            // Parentage: each parent is resolved through tenantFind() inside
            // animalValidateParent(), so a crafted id cannot reference another
            // tenant's animal, and sex is checked against the role. The animal
            // does not exist yet, so there is no self-parent case to test here.
            $damCheck = animalValidateParent($conn, null, $damId, 'dam');
            $sireCheck = animalValidateParent($conn, null, $sireId, 'sire');

            if (!$damCheck['ok']) {
                throw new InvalidArgumentException($damCheck['error']);
            }
            if (!$sireCheck['ok']) {
                throw new InvalidArgumentException($sireCheck['error']);
            }

            // farm_id is applied by tenantInsert() from the tenant context and
            // can never be supplied by the client.
            $cowId = tenantInsert($conn, 'cows', [
                'unique_id'          => $unique_id,
                'name'               => $name,
                'species'            => $species,
                'sex'                => $sex,
                'breed'              => $breed,
                'date_of_birth'      => $date_of_birth,
                'birth_type'         => $birth_type,
                'acquisition_type'   => $acquisition_type,
                'acquisition_date'   => $acquisition_date,
                'acquisition_source' => $acquisition_source,
                'dam_id'             => $damId,
                'sire_id'            => $sireId,
                'dam_external'       => $damExternal,
                'sire_external'      => $sireExternal,
                'notes'              => $notes,
                'lifecycle_status'   => 'active',
                'created_at'         => dfms_utc_now(),
            ]);
            auditCreate('animal', $cowId, [
                'unique_id'        => $unique_id,
                'species'          => $species,
                'sex'              => $sex,
                'breed'            => $breed,
                'date_of_birth'    => $date_of_birth,
                'acquisition_type' => $acquisition_type,
            ]);
            $success = 'Animal added successfully';
        } catch (InvalidArgumentException $e) {
            // Domain rule messages are written for the user.
            $error = $e->getMessage();
        } catch (PDOException $e) {
            // 23000 = integrity constraint. On this table that means the tag
            // is already used ON THIS FARM — a user error, not a system fault.
            if ($e->getCode() === '23000') {
                $error = 'An animal with that tag already exists on this farm.';
            } else {
                $error = safeError($e, 'Error adding cow');
            }
        }
    }
}

$page_title = 'Add Animal';
$page_breadcrumb = [['label' => 'Livestock', 'url' => BASE_URL . 'modules/cows/'], ['label' => 'Add Animal']];
require_once '../../includes/header.php';

// Candidate parents on THIS farm only. animalSearch() is tenant-scoped, so the
// dropdown can never offer another farm's animal.
$conn = $conn ?? getDBConnection();
$damCandidates = animalSearch($conn, ['sex' => 'female'], 1, 100)['items'];
$sireCandidates = animalSearch($conn, ['sex' => 'male'], 1, 100)['items'];
?>

<?php dfms_page_header('Add animal', 'Register a new animal in the herd.'); ?>

<?php if ($error): ?><?php dfms_alert($error, 'error'); ?><?php endif; ?>
<?php if ($success): ?><?php dfms_alert($success, 'success'); ?><?php endif; ?>

<form method="POST" action="">
    <?php csrf_field(); ?>

    <section class="dfms-section">
        <div class="dfms-card">
            <h2 style="font-size:1.05rem;margin-bottom:1rem;">Identity</h2>
            <div class="dfms-two-col">
                <div class="dfms-field">
                    <label for="unique_id">Tag / management number <span aria-hidden="true">*</span></label>
                    <input id="unique_id" name="unique_id" type="text" autocomplete="off" spellcheck="false" maxlength="50" required
                           value="<?php echo e($_POST['unique_id'] ?? ''); ?>" aria-describedby="tag-help">
                    <small id="tag-help" class="dfms-text-muted">
                        Must be unique on this farm. Other farms may use the same tag.
                    </small>
                </div>

                <div class="dfms-field">
                    <label for="name">Name <span class="dfms-text-muted">(optional)</span></label>
                    <input id="name" name="name" type="text" autocomplete="off" maxlength="100"
                           value="<?php echo e($_POST['name'] ?? ''); ?>">
                </div>

                <div class="dfms-field">
                    <label for="species">Species</label>
                    <select id="species" name="species" required>
                        <?php foreach (DFMS_SPECIES as $speciesKey => $speciesMeta): ?>
                            <option value="<?php echo e($speciesKey); ?>"
                                <?php echo ($_POST['species'] ?? 'cattle') === $speciesKey ? 'selected' : ''; ?>>
                                <?php echo e($speciesMeta['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="dfms-field">
                    <label for="sex">Sex</label>
                    <select id="sex" name="sex" required>
                        <option value="female" <?php echo ($_POST['sex'] ?? 'female') === 'female' ? 'selected' : ''; ?>>Female</option>
                        <option value="male" <?php echo ($_POST['sex'] ?? '') === 'male' ? 'selected' : ''; ?>>Male</option>
                    </select>
                </div>

                <div class="dfms-field">
                    <label for="breed">Breed <span class="dfms-text-muted">(optional)</span></label>
                    <input id="breed" name="breed" type="text" autocomplete="off" maxlength="100" list="breed-options"
                           value="<?php echo e($_POST['breed'] ?? ''); ?>" aria-describedby="breed-help">
                    <datalist id="breed-options">
                        <?php
                        // Suggestions only. Free text is preserved deliberately —
                        // local breeds vary endlessly, and a closed list would
                        // force users to mislabel their animals.
                        foreach (DFMS_BREED_SUGGESTIONS as $suggestionList) {
                            foreach ($suggestionList as $breedName) {
                                echo '<option value="' . e($breedName) . '">';
                            }
                        }
                        ?>
                    </datalist>
                    <small id="breed-help" class="dfms-text-muted">Suggestions are offered; any breed name is accepted.</small>
                </div>
            </div>
        </div>
    </section>

    <section class="dfms-section">
        <div class="dfms-card">
            <h2 style="font-size:1.05rem;margin-bottom:1rem;">Birth and acquisition</h2>
            <div class="dfms-two-col">
                <div class="dfms-field">
                    <label for="date_of_birth">Date of birth <span class="dfms-text-muted">(optional)</span></label>
                    <input id="date_of_birth" name="date_of_birth" type="date"
                           max="<?php echo e(farmToday()); ?>"
                           value="<?php echo e($_POST['date_of_birth'] ?? ''); ?>">
                </div>

                <div class="dfms-field">
                    <label for="birth_type">Birth type <span class="dfms-text-muted">(optional)</span></label>
                    <select id="birth_type" name="birth_type">
                        <option value="">Not recorded</option>
                        <?php foreach (DFMS_BIRTH_TYPES as $birthKey => $birthLabel): ?>
                            <option value="<?php echo e($birthKey); ?>" <?php echo ($_POST['birth_type'] ?? '') === $birthKey ? 'selected' : ''; ?>>
                                <?php echo e($birthLabel); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="dfms-field">
                    <label for="acquisition_type">How was it acquired? <span class="dfms-text-muted">(optional)</span></label>
                    <select id="acquisition_type" name="acquisition_type">
                        <option value="">Not recorded</option>
                        <?php foreach (DFMS_ACQUISITION_TYPES as $acqKey => $acqLabel): ?>
                            <option value="<?php echo e($acqKey); ?>" <?php echo ($_POST['acquisition_type'] ?? '') === $acqKey ? 'selected' : ''; ?>>
                                <?php echo e($acqLabel); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="dfms-field">
                    <label for="acquisition_date">Acquired on <span class="dfms-text-muted">(optional)</span></label>
                    <input id="acquisition_date" name="acquisition_date" type="date"
                           max="<?php echo e(farmToday()); ?>"
                           value="<?php echo e($_POST['acquisition_date'] ?? ''); ?>">
                </div>

                <div class="dfms-field">
                    <label for="acquisition_source">Source <span class="dfms-text-muted">(optional)</span></label>
                    <input id="acquisition_source" name="acquisition_source" type="text" autocomplete="off" maxlength="150"
                           placeholder="Seller, market or originating farm…"
                           value="<?php echo e($_POST['acquisition_source'] ?? ''); ?>">
                </div>
            </div>
        </div>
    </section>

    <section class="dfms-section">
        <div class="dfms-card">
            <h2 style="font-size:1.05rem;margin-bottom:.4rem;">Parentage</h2>
            <p class="dfms-text-muted" style="font-size:.88rem;margin-bottom:1rem;">
                A parent may be an animal on this farm, or an external animal recorded by name.
                An external parent is <strong>not</strong> created as an animal record.
            </p>
            <div class="dfms-two-col">
                <div class="dfms-field">
                    <label for="dam_id">Mother (dam) on this farm</label>
                    <select id="dam_id" name="dam_id">
                        <option value="">— None or external —</option>
                        <?php foreach ($damCandidates as $candidate): ?>
                            <option value="<?php echo (int) $candidate['cow_id']; ?>"
                                <?php echo (string) ($_POST['dam_id'] ?? '') === (string) $candidate['cow_id'] ? 'selected' : ''; ?>>
                                <?php echo e(animalDisplayName($candidate)); ?> (<?php echo e($candidate['unique_id']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="dfms-field">
                    <label for="dam_external">or external mother</label>
                    <input id="dam_external" name="dam_external" type="text" autocomplete="off" maxlength="150"
                           value="<?php echo e($_POST['dam_external'] ?? ''); ?>">
                </div>

                <div class="dfms-field">
                    <label for="sire_id">Father (sire) on this farm</label>
                    <select id="sire_id" name="sire_id">
                        <option value="">— None or external —</option>
                        <?php foreach ($sireCandidates as $candidate): ?>
                            <option value="<?php echo (int) $candidate['cow_id']; ?>"
                                <?php echo (string) ($_POST['sire_id'] ?? '') === (string) $candidate['cow_id'] ? 'selected' : ''; ?>>
                                <?php echo e(animalDisplayName($candidate)); ?> (<?php echo e($candidate['unique_id']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($sireCandidates)): ?>
                        <small class="dfms-text-muted">No male animals are recorded on this farm.</small>
                    <?php endif; ?>
                </div>

                <div class="dfms-field">
                    <label for="sire_external">or external father</label>
                    <input id="sire_external" name="sire_external" type="text" autocomplete="off" maxlength="150"
                           value="<?php echo e($_POST['sire_external'] ?? ''); ?>">
                </div>
            </div>
        </div>
    </section>

    <section class="dfms-section">
        <div class="dfms-card">
            <div class="dfms-field">
                <label for="notes">Notes <span class="dfms-text-muted">(optional)</span></label>
                <textarea id="notes" name="notes" rows="3" maxlength="5000"><?php echo e($_POST['notes'] ?? ''); ?></textarea>
            </div>

            <div style="display:flex;gap:.75rem;margin-top:1rem;">
                <button type="submit" class="dfms-btn dfms-btn-primary">Add animal</button>
                <a href="<?php echo BASE_URL; ?>modules/cows/" class="dfms-btn dfms-btn-secondary">Cancel</a>
            </div>
        </div>
    </section>
</form>

<?php require_once '../../includes/footer.php'; ?>
