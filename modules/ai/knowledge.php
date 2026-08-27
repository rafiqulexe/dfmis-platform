<?php
// ============================================================================
// KNOWLEDGE BASE — curating what the assistant is allowed to quote.
//
// Restricted to ai.knowledge (farm managers). Whatever is written here is
// retrieved and quoted back to everyone who asks the assistant, so editing it
// shapes the advice the whole farm receives — a narrower decision than merely
// consulting.
//
// NOTHING IS PRE-LOADED. DFMIS ships no veterinary content: a farm's knowledge
// base contains its own protocols and material it has chosen to transcribe.
// Seeding invented clinical guidance would mean this project asserting advice
// it is not qualified to author.
//
// PDF LIMITATION, SURFACED ON THE PAGE ITSELF: stored_files accepts PDFs
// (Phase 4) but there is no text extractor here, so a PDF cannot be retrieved
// from. The page says so plainly rather than letting someone upload a document
// and assume the assistant has read it.
// ============================================================================

define('DFMS_AI_PAGE', true);
require_once __DIR__ . '/_shared.php';

requireAuth('ai.knowledge');
aiRequireFeature();

$conn = getDBConnection();
$error = '';

// Both identify a resource being viewed, so a malformed value is a 404.
$editId = aiOptionalResourceId('edit');
$sourceFilter = aiOptionalResourceId('source');

$editing = null;
if ($editId !== null) {
    $editing = tenantFindOr404($conn, 'ai_knowledge_documents', 'document_id', $editId);
}

$submitted = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $submitted = $_POST;

    $v = new DfmsValidator($_POST);
    $action = $v->enum('action', ['create_source', 'save_document', 'delete_document', 'toggle_source']);

    try {
        if ($v->fails()) {
            $error = $v->firstError();
        } elseif ($action === 'create_source') {
            $name = $v->string('name', 2, 150);
            $type = $v->enum('source_type', array_keys(DFMS_KNOWLEDGE_SOURCE_TYPES));
            $description = $v->text('description', 500, ['nullable' => true]);
            $attribution = $v->string('attribution', 0, 255, ['nullable' => true]);

            if ($v->fails()) {
                $error = $v->firstError();
            } else {
                aiKnowledgeCreateSource($conn, [
                    'name'        => $name,
                    'source_type' => $type,
                    'description' => $description,
                    'attribution' => $attribution,
                    'is_active'   => true,
                ]);
                redirectWith('modules/ai/knowledge.php', 'success', 'Knowledge source added.');
            }
        } elseif ($action === 'toggle_source') {
            $sourceId = $v->id('source_id');
            $active = !empty($_POST['is_active']);
            if ($v->fails()) {
                $error = $v->firstError();
            } else {
                aiKnowledgeSetSourceActive($conn, (int) $sourceId, $active);
                redirectWith('modules/ai/knowledge.php', 'success',
                    $active ? 'Source re-enabled for retrieval.' : 'Source will no longer be retrieved.');
            }
        } elseif ($action === 'delete_document') {
            $documentId = $v->id('document_id');
            if ($v->fails()) {
                $error = $v->firstError();
            } else {
                aiKnowledgeDeleteDocument($conn, (int) $documentId);
                redirectWith('modules/ai/knowledge.php', 'success',
                    'Document deleted. Past answers that cited it no longer show that citation.');
            }
        } else {
            $documentId = $v->id('document_id', ['nullable' => true]);
            $sourceId = $v->id('source_id');
            $title = $v->string('title', 2, 200);
            $body = $v->text('body', 200000, ['nullable' => true]);
            $species = $v->enum('species_scope', array_keys(DFMS_SPECIES), ['nullable' => true]);

            if ($v->fails()) {
                $error = $v->firstError();
            } else {
                aiKnowledgeSaveDocument($conn, [
                    'source_id'     => $sourceId,
                    'title'         => $title,
                    'body'          => $body,
                    'species_scope' => $species,
                    'is_active'     => true,
                ], $documentId);
                redirectWith('modules/ai/knowledge.php', 'success', 'Document saved and indexed for retrieval.');
            }
        }
    } catch (KnowledgeRuleViolation $e) {
        $error = $e->getMessage();
    } catch (PDOException $e) {
        $error = safeError($e, 'Could not save that change');
    }
}

$sources = aiKnowledgeSources($conn);
$documents = aiKnowledgeDocuments($conn, $sourceFilter);
$stats = aiKnowledgeStats($conn);

$page_title = 'AI Knowledge Base';
$page_breadcrumb = [
    ['label' => 'AI Assistant', 'url' => BASE_URL . 'modules/ai/'],
    ['label' => 'Knowledge Base'],
];
require_once __DIR__ . '/../../includes/header.php';
?>
<?php aiScopedStyles(); ?>
<div class="dfms-ai-scope">

<style>
    .kb-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: var(--space-4); margin-bottom: var(--space-6); }
    .kb-add > summary { cursor: pointer; font-weight: 600; font-size: var(--text-sm); color: var(--primary); padding: var(--space-2) 0; }
    .kb-add > summary:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; border-radius: var(--radius-sm); }
    .kb-add[open] > summary { margin-bottom: var(--space-4); }
</style>

<?php dfms_page_header(
    'AI Knowledge Base',
    'What the assistant is allowed to quote back to your team. Nothing here is supplied by DFMIS.',
    dfms_button_html('Back to assistant', BASE_URL . 'modules/ai/', 'secondary'),
    'Intelligence'
); ?>

<?php dfms_render_flashes(); ?>
<?php if ($error): ?>
    <?php dfms_alert($error, 'error'); ?>
<?php endif; ?>

<div class="ai-notice">
    <strong>This knowledge base is yours alone.</strong>
    DFMIS ships no veterinary content and never will — inventing clinical guidance is not something this
    software is qualified to do. Add your own protocols, your veterinarian's written instructions, or
    material you have chosen to transcribe. Text you add here is retrieved and quoted to anyone on this
    farm who asks the assistant, and it is never visible to another organization.
</div>

<div class="kb-stats">
    <?php
    echo dfms_metric_html('Sources', (string) $stats['sources']);
    echo dfms_metric_html('Documents', (string) $stats['documents']);
    echo dfms_metric_html('Passages', (string) $stats['chunks'], '', 'indexed for retrieval');
    echo dfms_metric_html('Retrievable now', (string) $stats['retrievable'], '',
        $stats['retrievable'] < $stats['chunks']
            ? dfms_badge_html('some sources disabled', 'warning')
            : dfms_badge_html('all active', 'success'));
    ?>
</div>

<!-- ===================== SOURCES ===================== -->
<section class="dfms-section">
    <div class="dfms-section-header">
        <div>
            <h2>Sources</h2>
            <p>Where the material came from. A citation names the source, so provenance matters.</p>
        </div>
    </div>
    <div class="dfms-card">
        <div class="dfms-card-body">
            <?php if (empty($sources)): ?>
                <p class="dfms-muted">No sources yet. Add one before you can store documents.</p>
            <?php else: ?>
                <?php dfms_table_open([
                    ['label' => 'Source'], ['label' => 'Type'], ['label' => 'Attribution'],
                    ['label' => 'Documents'], ['label' => 'Retrieval'], ['label' => ''],
                ]); ?>
                <?php foreach ($sources as $source): ?>
                    <tr>
                        <?php
                        dfms_cell('Source', '<span class="dfms-cell-strong">' . e($source['name']) . '</span>'
                            . ($source['description'] ? '<br><span class="dfms-text-sm">' . e($source['description']) . '</span>' : ''));
                        dfms_cell('Type', e(DFMS_KNOWLEDGE_SOURCE_TYPES[$source['source_type']] ?? $source['source_type']));
                        dfms_cell('Attribution', e($source['attribution'] ?: '—'));
                        dfms_cell('Documents', '<span class="dfms-num">' . (int) $source['document_count'] . '</span>');
                        dfms_cell('Retrieval', (int) $source['is_active'] === 1
                            ? dfms_badge_html('Active', 'success', true)
                            : dfms_badge_html('Disabled', 'neutral', true));
                        dfms_cell('', '<form method="POST">'
                            . '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">'
                            . '<input type="hidden" name="action" value="toggle_source">'
                            . '<input type="hidden" name="source_id" value="' . (int) $source['source_id'] . '">'
                            . ((int) $source['is_active'] === 1
                                ? ''
                                : '<input type="hidden" name="is_active" value="1">')
                            . '<button type="submit" class="dfms-btn dfms-btn-ghost dfms-btn-sm">'
                            . ((int) $source['is_active'] === 1 ? 'Disable' : 'Enable')
                            . '<span class="dfms-visually-hidden"> ' . e($source['name']) . '</span></button>'
                            . '</form>', 'dfms-cell-actions');
                        ?>
                    </tr>
                <?php endforeach; ?>
                <?php dfms_table_close(); ?>
            <?php endif; ?>

            <details class="kb-add">
                <summary>Add a source</summary>
                <form method="POST" novalidate>
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="create_source">
                    <div class="dfms-form-grid">
                        <div class="dfms-field">
                            <label class="dfms-label" for="src-name">Name<span class="dfms-required" aria-hidden="true">*</span></label>
                            <input class="dfms-input" type="text" id="src-name" name="name" maxlength="150" required
                                   placeholder="e.g. Our milking-herd protocols…">
                        </div>
                        <div class="dfms-field">
                            <label class="dfms-label" for="src-type">Type<span class="dfms-required" aria-hidden="true">*</span></label>
                            <select class="dfms-select" id="src-type" name="source_type" required>
                                <?php foreach (DFMS_KNOWLEDGE_SOURCE_TYPES as $key => $label): ?>
                                    <option value="<?php echo e($key); ?>"><?php echo e($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="dfms-field">
                            <label class="dfms-label" for="src-attr">Attribution</label>
                            <!-- A person or practice name: spellcheck squiggles under it are
                                 noise, and on mobile autocorrect actively mangles them. -->
                            <input class="dfms-input" type="text" id="src-attr" name="attribution" maxlength="255"
                                   autocomplete="off" spellcheck="false" aria-describedby="src-attr-hint"
                                   placeholder="e.g. Dr A. Rahman, Riverside Veterinary…">
                            <p class="dfms-hint" id="src-attr-hint">Who wrote or published the material. Shown with every citation.</p>
                        </div>
                    </div>
                    <div class="dfms-field">
                        <label class="dfms-label" for="src-desc">Description</label>
                        <textarea class="dfms-textarea" id="src-desc" name="description" rows="2" maxlength="500"></textarea>
                    </div>
                    <div class="dfms-form-actions">
                        <button type="submit" class="dfms-btn dfms-btn-primary dfms-btn-sm">Add source</button>
                    </div>
                </form>
            </details>
        </div>
    </div>
</section>

<!-- ===================== DOCUMENTS ===================== -->
<section class="dfms-section">
    <div class="dfms-section-header">
        <div>
            <h2>Documents</h2>
            <p>Each document is split into passages. The assistant retrieves passages, not whole documents.</p>
        </div>
    </div>
    <div class="dfms-card">
        <div class="dfms-card-body">
            <?php if (empty($documents)): ?>
                <p class="dfms-muted">No documents yet. The assistant will say it found nothing relevant until you add some.</p>
            <?php else: ?>
                <?php dfms_table_open([
                    ['label' => 'Title'], ['label' => 'Source'], ['label' => 'Species'],
                    ['label' => 'Passages'], ['label' => 'Updated'], ['label' => ''],
                ]); ?>
                <?php foreach ($documents as $document): ?>
                    <tr>
                        <?php
                        dfms_cell('Title', '<span class="dfms-cell-strong">' . e($document['title']) . '</span>');
                        dfms_cell('Source', e($document['source_name'])
                            . ((int) $document['source_active'] === 1 ? '' : ' ' . dfms_badge_html('source disabled', 'warning')));
                        dfms_cell('Species', $document['species_scope']
                            ? e(dfms_species_label($document['species_scope']))
                            : '<span class="dfms-muted">All species</span>');
                        dfms_cell('Passages', '<span class="dfms-num">' . (int) $document['chunk_count'] . '</span>');
                        dfms_cell('Updated', e(fmt_instant($document['updated_at'] ?: $document['created_at'], 'Y-m-d')));
                        dfms_cell('',
                            '<a class="dfms-btn dfms-btn-ghost dfms-btn-sm" href="'
                            . e(BASE_URL . 'modules/ai/knowledge.php?edit=' . (int) $document['document_id'])
                            . '">Edit<span class="dfms-visually-hidden"> ' . e($document['title']) . '</span></a>',
                            'dfms-cell-actions');
                        ?>
                    </tr>
                <?php endforeach; ?>
                <?php dfms_table_close(); ?>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- ===================== ADD / EDIT DOCUMENT ===================== -->
<section class="dfms-section">
    <div class="dfms-card">
        <div class="dfms-card-header">
            <h2 class="dfms-card-title"><?php echo $editing !== null ? 'Edit document' : 'Add a document'; ?></h2>
            <p class="dfms-card-subtitle">
                Paste or type the text. Saving re-indexes the whole document, so passages can never
                quote text the document no longer contains.
            </p>
        </div>
        <div class="dfms-card-body">
            <?php if (empty($sources)): ?>
                <p class="dfms-muted" style="margin:0;">Add a source first.</p>
            <?php else: ?>
            <p class="dfms-hint" style="margin-bottom:var(--space-4);">
                <strong>Text only.</strong> PDFs can be stored against animals and laboratory tests elsewhere in
                DFMIS, but there is no PDF text extractor here — a PDF cannot be searched or quoted by the
                assistant. Paste the relevant text if you want it retrievable.
            </p>
            <form method="POST" novalidate>
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="save_document">
                <?php if ($editing !== null): ?>
                    <input type="hidden" name="document_id" value="<?php echo (int) $editing['document_id']; ?>">
                <?php endif; ?>

                <div class="dfms-form-grid">
                    <div class="dfms-field">
                        <label class="dfms-label" for="doc-title">Title<span class="dfms-required" aria-hidden="true">*</span></label>
                        <input class="dfms-input" type="text" id="doc-title" name="title" maxlength="200" required
                               aria-describedby="doc-title-hint"
                               value="<?php echo e($submitted['title'] ?? ($editing['title'] ?? '')); ?>"
                               placeholder="e.g. Mastitis response protocol…">
                        <p class="dfms-hint" id="doc-title-hint">Shown as the citation under an answer, so make it recognisable.</p>
                    </div>
                    <div class="dfms-field">
                        <label class="dfms-label" for="doc-source">Source<span class="dfms-required" aria-hidden="true">*</span></label>
                        <select class="dfms-select" id="doc-source" name="source_id" required>
                            <option value="">Select a source</option>
                            <?php foreach ($sources as $source): ?>
                                <option value="<?php echo (int) $source['source_id']; ?>"
                                    <?php echo (string) ($submitted['source_id'] ?? ($editing['source_id'] ?? '')) === (string) $source['source_id'] ? 'selected' : ''; ?>>
                                    <?php echo e($source['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="dfms-field">
                        <label class="dfms-label" for="doc-species">Applies to</label>
                        <select class="dfms-select" id="doc-species" name="species_scope" aria-describedby="doc-species-hint">
                            <option value="">All species</option>
                            <?php foreach (DFMS_SPECIES as $key => $meta): ?>
                                <option value="<?php echo e($key); ?>"
                                    <?php echo (string) ($submitted['species_scope'] ?? ($editing['species_scope'] ?? '')) === (string) $key ? 'selected' : ''; ?>>
                                    <?php echo e(dfms_species_label($key)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="dfms-hint" id="doc-species-hint">A species-scoped document is not retrieved for another species.</p>
                    </div>
                </div>

                <div class="dfms-field">
                    <label class="dfms-label" for="doc-body">Text<span class="dfms-required" aria-hidden="true">*</span></label>
                    <textarea class="dfms-textarea" id="doc-body" name="body" rows="14" maxlength="200000"
                              required aria-describedby="doc-body-hint" spellcheck="true"><?php
                        echo e($submitted['body'] ?? ($editing['body'] ?? '')); ?></textarea>
                    <p class="dfms-hint" id="doc-body-hint">
                        Separate topics with a blank line — passages are split on paragraph boundaries, so
                        well-separated text retrieves far better than one long block.
                    </p>
                </div>

                <div class="dfms-form-actions">
                    <?php if ($editing !== null): ?>
                        <a class="dfms-btn dfms-btn-secondary" href="<?php echo e(BASE_URL); ?>modules/ai/knowledge.php">Cancel</a>
                    <?php endif; ?>
                    <button type="submit" class="dfms-btn dfms-btn-primary">
                        <?php echo $editing !== null ? 'Save and re-index' : 'Add document'; ?>
                    </button>
                </div>
            </form>

            <?php if ($editing !== null): ?>
                <!--
                    Deletion is a separate form with its own confirmation, never a
                    link, so it cannot be triggered by a prefetch or a stray GET.
                -->
                <form method="POST" style="margin-top:var(--space-5);border-top:1px solid var(--border);padding-top:var(--space-4);"
                      onsubmit="return confirm('Delete this document? Past answers that cited it will stop showing that citation.');">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="delete_document">
                    <input type="hidden" name="document_id" value="<?php echo (int) $editing['document_id']; ?>">
                    <button type="submit" class="dfms-btn dfms-btn-danger dfms-btn-sm">Delete this document</button>
                    <p class="dfms-hint">
                        Its passages and any citations pointing at them are removed. The answers themselves are kept.
                    </p>
                </form>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</section>

</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
