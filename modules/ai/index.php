<?php
// ============================================================================
// AI VETERINARIAN ASSISTANT — the consultation surface.
//
// Server-rendered, POST-redirect-GET, no frontend framework — consistent with
// every other DFMIS module. The conversation is a plain URL (?c=), so it is
// linkable, bookmarkable and works without JavaScript.
//
// The ONE piece of JavaScript is a progressive-enhancement loading state on
// submit: an AI call takes seconds, and a form that looks inert invites a
// double submission. Without JavaScript the form still posts normally.
// ============================================================================

define('DFMS_AI_PAGE', true);
require_once __DIR__ . '/_shared.php';

requireAuth('ai.consult');
aiRequireFeature();

$conn = getDBConnection();
$error = '';

$availability = aiVetAvailability($conn);
$animals = aiSelectableAnimals($conn);
$conversations = aiVetConversations($conn, 30);

// A conversation id identifies a RESOURCE BEING VIEWED: malformed means 404,
// never a silent fall-through to the new-conversation view.
$conversationId = aiOptionalResourceId('c');

// cow_id here is only a form PRESELECT, so a malformed one is ignored —
// the same treatment the Phase 4 health forms give it.
$gv = new DfmsValidator($_GET);
$preCow = $gv->id('cow_id', ['nullable' => true]);

// 404s for another user's or another farm's conversation, indistinguishably.
$conversation = null;
$messages = [];
if ($conversationId !== null) {
    $conversation = aiVetFindConversationOr404($conn, $conversationId);
    $messages = aiVetMessages($conn, (int) $conversation['conversation_id']);
}

$submitted = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $submitted = $_POST;

    $v = new DfmsValidator($_POST);
    $question = $v->text('question', 4000, ['nullable' => true]);
    $cowId = $v->id('cow_id', ['nullable' => true]);
    $existingId = $v->id('conversation_id', ['nullable' => true]);

    if ($v->fails()) {
        $error = $v->firstError();
    } elseif ($question === null || trim($question) === '') {
        $error = 'Type a question first.';
    } else {
        try {
            $result = aiVetAsk($conn, [
                'question'        => $question,
                'cow_id'          => $cowId,
                'conversation_id' => $existingId,
            ]);

            // A degraded answer still produces a conversation the user can see,
            // so the redirect target is the same either way. The failure is
            // visible in the thread rather than swallowed into a flash message.
            $target = 'modules/ai/?c=' . (int) $result['conversation_id'];
            if ($result['ok']) {
                redirectTo($target);
            }
            redirectWith($target, 'error', dfms_ai_failure_message($result['reason']));
        } catch (AiVetRuleViolation $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            $error = safeError($e, 'Could not complete the consultation');
        }
    }
}

$page_title = 'AI Assistant';
$page_breadcrumb = [['label' => 'AI Assistant']];
require_once __DIR__ . '/../../includes/header.php';
?>
<?php aiScopedStyles(); ?>
<div class="dfms-ai-scope">

<?php
dfms_page_header(
    'AI Veterinarian Assistant',
    'Decision support over this farm\'s own health records and knowledge base. It does not diagnose or prescribe.',
    hasPermission('ai.knowledge')
        ? dfms_button_html('Knowledge base', BASE_URL . 'modules/ai/knowledge.php', 'secondary')
        : '',
    'Intelligence'
);
?>

<?php dfms_render_flashes(); ?>
<?php if ($error): ?>
    <?php dfms_alert($error, 'error'); ?>
<?php endif; ?>

<?php if (!$availability['available']): ?>
    <!--
        The degraded state. Explicit and honest: no fake answer is ever shown,
        and the reason is a mapped safe message — never an upstream error body.
    -->
    <div class="dfms-card">
        <div class="dfms-card-body">
            <?php dfms_empty_state(
                'The assistant is unavailable',
                $availability['message'] ?? 'The AI service cannot be reached right now.',
                $availability['reason'] === 'not_configured' && hasPermission('settings.farm')
                    ? '<p class="dfms-hint">An administrator needs to configure an AI provider key in the server environment. '
                      . 'No key is stored in the application or in the database.</p>'
                    : '',
                'sparkle'
            ); ?>
        </div>
    </div>
<?php else: ?>

<div class="ai-layout">
    <!-- ===================== CONVERSATIONS ===================== -->
    <aside class="dfms-card" aria-label="Your conversations">
        <div class="dfms-card-header">
            <h2 class="dfms-card-title">Your conversations</h2>
            <p class="dfms-card-subtitle">Only yours, on this farm.</p>
        </div>
        <?php if (empty($conversations)): ?>
            <div class="dfms-card-body">
                <p class="dfms-muted" style="margin:0;">No conversations yet.</p>
            </div>
        <?php else: ?>
            <ul class="ai-conv-list">
                <?php foreach ($conversations as $item): ?>
                    <?php $isCurrent = $conversation !== null
                        && (int) $item['conversation_id'] === (int) $conversation['conversation_id']; ?>
                    <li>
                        <a href="<?php echo e(BASE_URL . 'modules/ai/?c=' . (int) $item['conversation_id']); ?>"
                           <?php echo $isCurrent ? 'aria-current="true"' : ''; ?>>
                            <?php echo e($item['title']); ?>
                            <span class="ai-conv-meta">
                                <?php if ($item['animal_tag']): ?>
                                    <?php echo e($item['animal_tag']); ?> ·
                                <?php endif; ?>
                                <?php echo e(fmt_instant($item['last_message_at'] ?: $item['created_at'], 'Y-m-d H:i')); ?>
                            </span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <div class="dfms-card-footer">
            <a class="dfms-btn dfms-btn-secondary dfms-btn-sm dfms-btn-block"
               href="<?php echo e(BASE_URL); ?>modules/ai/">Start a new conversation</a>
        </div>
    </aside>

    <!-- ===================== THREAD ===================== -->
    <div>
        <div class="ai-notice">
            This assistant supports your judgement; it does not replace a veterinarian. It cannot examine
            an animal, it will not confirm a diagnosis, and it will not give you a medication dose —
            take doses and withdrawal periods from the product label or your vet.
        </div>

        <?php if ($conversation !== null && !empty($messages)): ?>
            <section class="ai-thread" aria-label="Conversation">
                <?php foreach ($messages as $message): ?>
                    <?php aiRenderMessage($message); ?>
                <?php endforeach; ?>
            </section>
        <?php else: ?>
            <div class="dfms-card">
                <div class="dfms-card-body">
                    <?php dfms_empty_state(
                        'Ask a veterinary question',
                        'Describe what you are seeing. Select an animal to include its recorded health history, '
                        . 'or leave it blank for a general question.',
                        '',
                        'sparkle'
                    ); ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- ===================== ASK ===================== -->
        <section class="dfms-section" style="margin-top:var(--space-5);">
            <div class="dfms-card">
                <div class="dfms-card-body">
                    <form method="POST" novalidate id="ai-ask-form">
                        <?php csrf_field(); ?>
                        <?php if ($conversation !== null): ?>
                            <input type="hidden" name="conversation_id"
                                   value="<?php echo (int) $conversation['conversation_id']; ?>">
                        <?php endif; ?>

                        <div class="dfms-field">
                            <label class="dfms-label" for="question">
                                Your question<span class="dfms-required" aria-hidden="true">*</span>
                            </label>
                            <textarea class="dfms-textarea" id="question" name="question" rows="4"
                                      maxlength="4000" required aria-describedby="question-hint"
                                      placeholder="e.g. One of my cows has a swollen quarter and the milk looks watery. What should I be checking…"><?php
                                echo e($submitted['question'] ?? ''); ?></textarea>
                            <p class="dfms-hint" id="question-hint">
                                Describe what you actually observed. What you report is recorded as an observation,
                                not as a confirmed finding.
                            </p>
                        </div>

                        <?php if ($conversation === null): ?>
                            <div class="dfms-field">
                                <label class="dfms-label" for="cow_id">Animal (optional)</label>
                                <select class="dfms-select" id="cow_id" name="cow_id" aria-describedby="cow_id-hint">
                                    <option value="">General question — no specific animal</option>
                                    <?php foreach ($animals as $animal): ?>
                                        <option value="<?php echo (int) $animal['cow_id']; ?>"
                                            <?php echo (string) ($submitted['cow_id'] ?? $preCow) === (string) $animal['cow_id'] ? 'selected' : ''; ?>>
                                            <?php echo e($animal['unique_id'] . ($animal['name'] ? ' — ' . $animal['name'] : '')); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="dfms-hint" id="cow_id-hint">
                                    Selecting an animal includes its recorded health history — cases, treatments,
                                    medication, vaccinations and active withdrawal restrictions.
                                </p>
                            </div>
                        <?php else: ?>
                            <p class="dfms-hint">
                                <?php if ($conversation['cow_id']): ?>
                                    This conversation is about
                                    <?php echo e($conversation['animal_tag'] ?? ('animal #' . (int) $conversation['cow_id'])); ?>.
                                <?php else: ?>
                                    This is a general conversation with no animal attached.
                                <?php endif; ?>
                            </p>
                        <?php endif; ?>

                        <div class="dfms-form-actions">
                            <button type="submit" class="dfms-btn dfms-btn-primary" id="ai-ask-submit">Ask the assistant</button>
                        </div>
                        <p class="dfms-hint" id="ai-ask-status" role="status" aria-live="polite"></p>
                    </form>
                </div>
            </div>
        </section>
    </div>
</div>

<script>
// PROGRESSIVE ENHANCEMENT ONLY. Without this the form posts normally and the
// browser shows its own loading state; this just makes the wait legible and
// stops a double submission during a call that genuinely takes seconds.
(function () {
    var form = document.getElementById('ai-ask-form');
    var button = document.getElementById('ai-ask-submit');
    var status = document.getElementById('ai-ask-status');
    if (!form || !button || !status) { return; }

    form.addEventListener('submit', function () {
        // The button is disabled only AFTER the browser has begun submitting,
        // so its value is still sent and the request is never suppressed.
        window.setTimeout(function () {
            button.disabled = true;
            button.textContent = 'Thinking…';
        }, 0);
        status.textContent = 'Sending your question to the assistant. This can take a few seconds…';
    });
})();
</script>

<?php endif; ?>

</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
