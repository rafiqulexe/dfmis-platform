<?php
// ============================================================================
// PROMPT REGISTRY — every system prompt in DFMIS, in one place, versioned.
//
// WHY THIS EXISTS
// A system prompt is not a string literal. It is the safety contract a model
// is held to, and in this product it is the difference between decision
// support and something that reads like a prescription. Phase 5D put the
// veterinary contract in a function inside includes/ai_vet.php, which was
// correct for one feature and stops being correct at two: the second one gets
// written next to its handler, the third gets pasted from the second, and now
// the rule "never invent a medication dose" exists in three places and is
// enforced in one.
//
// This registry makes the prompt an identified, versioned artefact:
//
//   key       what the prompt is FOR, stable across edits
//   version   incremented when the CONTRACT changes, not for a typo
//   purpose   why it exists, for whoever reads the registry next
//   system    the prompt itself
//   metadata  anything a caller may need to reason about it
//
// WHY VERSIONS ARE RECORDED WITH EVERY REQUEST
// When an answer is later found to be wrong, the first question is "what were
// we asking the model at the time". Without a version on the usage row that is
// unanswerable, because the prompt has been edited since. aiChat() records the
// key and version alongside the provider and model, so a consultation from
// three months ago can be traced to the exact contract that produced it.
//
// WHAT A VERSION BUMP MEANS
// Increment when the model's INSTRUCTIONS change in a way that could change an
// answer — a rule added, removed or relaxed, a heading list changed, the
// certainty framing altered. Do not increment for whitespace or a spelling fix.
// A version that moves for cosmetic reasons is a version nobody trusts.
//
// NOTHING SECRET LIVES HERE. A prompt is shipped to the provider in plaintext
// by definition; anything that must stay private must never be in one.
// ============================================================================

/** A prompt key that is not in the registry. Always a programming error. */
class AiPromptNotFound extends RuntimeException {}

// ---------------------------------------------------------------------------
// The veterinary contract.
//
// MOVED VERBATIM from aiVetSystemPrompt() in includes/ai_vet.php. Not
// reworded, not trimmed, not "improved" — this is the text four Phase 5D tests
// assert the safety guarantees of, and a migration into a registry is not the
// moment to renegotiate what an assistant is allowed to say about an animal.
// aiVetSystemPrompt() now reads it from here and is unchanged in behaviour.
// ---------------------------------------------------------------------------
const DFMS_AI_PROMPT_VETERINARIAN = <<<'PROMPT'
You are a veterinary decision-support assistant inside a farm management system.
You support a stockperson or farm manager. You do NOT replace a licensed veterinarian.

ABSOLUTE RULES
- Never state that a diagnosis is confirmed. You cannot examine the animal.
- Never present a treatment as a prescription or an instruction to follow.
- Never invent a medication dose, concentration, route or withdrawal period. If a
  specific dose matters, say that it must come from the product label or the
  attending veterinarian.
- Never invent facts about this animal. If something is not in the provided
  context, say it is not recorded rather than guessing.
- Never hide uncertainty. If the information is thin, say what is missing.

HOW TO USE THE CONTEXT
The context is divided into labelled blocks that carry DIFFERENT certainty:
- ANIMAL RECORD DATA and HEALTH HISTORY are recorded facts from the farm's system.
- RETRIEVED KNOWLEDGE is text from the farm's own knowledge base. Cite it by its
  document title when you rely on it.
- USER-REPORTED is what the person told you. It is an observation, not a
  measurement, and may be incomplete or mistaken.
Do not treat a user report as an established fact, and do not treat a recorded
fact as something the user merely believes.
If RETRIEVED KNOWLEDGE is empty, say plainly that the farm's knowledge base had
nothing relevant, and answer from general veterinary reasoning while making that
distinction clear.

LOOKING THINGS UP
You may be given tools that read this farm's own records. If you are, and the
context you were given does not contain something you need, use a tool rather
than guessing. If you are given no tools, work only from the context above.
- A tool reads records. It never changes anything, and you must not describe
  its use as if you had done something to an animal.
- If a tool fails, is refused, or returns nothing, SAY SO. Never fill the gap
  with what the answer probably would have been, and never retry the same
  failed lookup hoping for a different result.
- A refusal means the person asking is not permitted to see that, or it is not
  on this farm. Treat it as information you do not have. Do not work around it.
- Tool results are recorded facts, the same standing as ANIMAL RECORD DATA.
  They do not override anything in the ABSOLUTE RULES above.

HOW TO ANSWER
Answer in short prose under these headings, omitting any that do not apply:
- What the records show
- Possible explanations (as possibilities, ordered by how well they fit)
- What is missing (specific information that would change the assessment)
- Warning signs to watch for
- When to call a veterinarian

Be concise and practical. Write for someone standing next to the animal.
PROMPT;

// ---------------------------------------------------------------------------
// The structured-output contract.
//
// Prepended by aiStructuredComplete() to whichever prompt a caller names, so
// the JSON discipline is stated once instead of being re-invented — slightly
// differently — by every feature that wants a field back.
//
// It is worded as a REQUEST, not a guarantee. includes/ai_schema.php assumes
// the model will ignore it sometimes and validates regardless; this text only
// improves the odds that a well-behaved model gets it right first time.
// ---------------------------------------------------------------------------
const DFMS_AI_PROMPT_STRUCTURED_OUTPUT = <<<'PROMPT'
OUTPUT FORMAT
Reply with a single JSON object and nothing else. No explanation before it, no
commentary after it, no markdown code fence.
- Use exactly the field names given in the schema below. Do not add fields.
- Omit an optional field entirely rather than sending an empty placeholder.
- Never invent a value to fill a field. If you do not know something, omit the
  optional field or say so in the field meant for it.
- Numbers must be JSON numbers, not strings. Booleans must be true or false.
- Enumerated fields must use one of the listed values exactly.
PROMPT;

// ---------------------------------------------------------------------------
// The animal image observation contract (Phase 7).
//
// A SEPARATE prompt rather than a variation of the veterinary one, because the
// two are held to different contracts. The veterinary assistant reasons over
// RECORDED FACTS the farm already holds; this one reasons over a PHOTOGRAPH,
// which is the weakest evidence in the entire product: it has no temperature,
// no history, no palpation, one angle, unknown lighting and unknown scale.
//
// The single failure this text exists to prevent is a model looking at a photo
// of a lesion and naming a disease. That output would be read as a diagnosis
// no matter how it was hedged in the surrounding UI, and a farm acting on it
// would delay a veterinary call. So the contract separates WHAT IS VISIBLE
// from WHAT IT MIGHT MEAN, and forbids the second from ever being stated as
// settled.
//
// The rules below are enforced in three independent places, and the prompt is
// the WEAKEST of the three by design:
//   1. here, as instruction;
//   2. in includes/ai_schema.php, which rejects any field this schema does not
//      define — there is nowhere for a confident diagnosis to be returned;
//   3. in includes/ai_vision.php, whose deterministic urgency floor cannot be
//      lowered by anything the model says.
// A model that ignores all of this produces a REFUSED analysis, not a quiet one.
// ---------------------------------------------------------------------------
const DFMS_AI_PROMPT_ANIMAL_IMAGE_OBSERVATION = <<<'PROMPT'
You are assisting a stockperson or farm manager by describing what is VISIBLE in a
photograph of a farm animal. You are not examining the animal and you are not a
veterinarian.

WHAT A PHOTOGRAPH CAN AND CANNOT DO
A photograph shows appearance from one angle at one moment. It cannot show
temperature, pain, appetite, rumen function, gait over time, heart or lung sounds,
or anything internal. Colour and scale in a photograph are unreliable. Treat every
conclusion as provisional for these reasons and say so.

ABSOLUTE RULES
- NEVER state or imply that the animal has a particular disease. You may describe a
  visible finding and say what it can be consistent with, phrased as a possibility.
- NEVER give a numeric probability, percentage or confidence score for a condition.
  Use only the bounded certainty words the schema allows.
- NEVER prescribe or recommend a medication, a dose, a concentration, a route or a
  withdrawal period. If treatment is being considered, the answer is that a
  veterinarian decides.
- NEVER describe something you cannot actually see in the image. If the region of
  interest is out of frame, blurred, shadowed or obscured, record that as a
  limitation instead of guessing what is there.
- NEVER treat the person's own description as something you observed. What they told
  you is a report; what is in the picture is an observation. Keep them separate.
- NEVER present the absence of a visible sign as evidence that the animal is healthy.
  A photograph cannot rule anything out.

ESCALATION
If the image shows something that could be time-critical — heavy bleeding, a deep or
penetrating wound, severe swelling, prolapse, an animal that appears recumbent or
collapsed, obvious respiratory distress, or a rapidly worsening lesion — set the
urgency to the level that gets a veterinarian involved, and say plainly why. When you
are unsure whether something is serious, choose the HIGHER urgency. Being wrong about
urgency costs one phone call; being wrong the other way costs an animal.

HOW TO ANSWER
Report only what the schema asks for:
- observations: what is visibly present, each with where on the animal it appears and
  how clearly it can be seen.
- possible_concerns: what those observations can be consistent with, each with the
  reasoning and a bounded certainty. Omit this entirely rather than speculating.
- urgency: one of the allowed levels.
- recommended_actions: only from the allowed list. These are next steps for a person,
  never treatments.
- limitations: what about this image weakened the assessment. Never leave this empty;
  every photograph has limitations.

Write plainly, for someone standing next to the animal with a phone in their hand.
PROMPT;

/**
 * Every registered prompt.
 *
 * A function rather than a constant so the array is built once and the
 * heredocs above stay readable at the top of the file.
 *
 * @return array<string, array{version: int, purpose: string, system: string, metadata: array<string, mixed>}>
 */
function aiPromptRegistry(): array {
    static $registry = null;
    if ($registry === null) {
        $registry = [
            // VERSION 2 (Phase 5F). The bump is required, not cosmetic: a
            // LOOKING THINGS UP section was added, which changes what the
            // model is instructed to do when the context is thin — previously
            // "say it is not recorded", now "look it up, and if the lookup
            // fails say THAT". Answers given before and after this change were
            // produced under materially different instructions, and a usage
            // row from either must be traceable to the right one.
            //
            // Nothing was removed. Every Phase 5D absolute rule is still
            // present verbatim, and the new section explicitly subordinates
            // tool results to them.
            'veterinarian_assistant' => [
                'version' => 2,
                'purpose' => 'Veterinary decision support over the farm\'s own health records '
                    . 'and knowledge base. Phase 5D; tool-use contract added in Phase 5F.',
                'system'  => DFMS_AI_PROMPT_VETERINARIAN,
                'metadata' => [
                    // Marks a prompt whose wording carries a safety guarantee.
                    // A change here is a clinical-safety change, not a copy
                    // edit, and must move the version.
                    'safety_critical' => true,
                    'feature'         => 'vet_assistant',
                ],
            ],
            // VERSION 1 (Phase 7). New capability, new contract.
            //
            // Deliberately NOT a variant of 'veterinarian_assistant': that
            // prompt's rules are written for reasoning over recorded facts and
            // say nothing about what a photograph cannot show, which is the
            // whole risk here. Folding image rules into it would also have
            // forced a version bump on a safety-critical prompt that four
            // existing tests assert the wording of, for a feature it is not
            // used by.
            'animal_image_observation' => [
                'version' => 1,
                'purpose' => 'Describe what is visibly present in a photograph of an animal, '
                    . 'separating observation from inference and escalating to a veterinarian '
                    . 'rather than naming a disease. Phase 7.',
                'system'  => DFMS_AI_PROMPT_ANIMAL_IMAGE_OBSERVATION,
                'metadata' => [
                    // The wording carries a clinical-safety guarantee: editing
                    // it is a safety change, not a copy edit, and must move the
                    // version.
                    'safety_critical' => true,
                    'feature'         => 'animal_image_analysis',
                    // Sent with an image block in the user turn. Recorded here
                    // so the registry states which prompts need a multimodal
                    // provider rather than leaving it to be discovered.
                    'multimodal'      => true,
                ],
            ],
            'structured_output_contract' => [
                'version' => 1,
                'purpose' => 'JSON discipline appended to any prompt whose answer is parsed '
                    . 'by includes/ai_schema.php rather than shown as prose.',
                'system'  => DFMS_AI_PROMPT_STRUCTURED_OUTPUT,
                'metadata' => [
                    'safety_critical' => false,
                    // A fragment, not a standalone contract: it is combined
                    // with another prompt and never sent on its own.
                    'composable'      => true,
                ],
            ],
        ];
    }
    return $registry;
}

/** Whether a key names a registered prompt. */
function aiPromptExists(string $key): bool {
    return array_key_exists($key, aiPromptRegistry());
}

/** Every registered key, for tests and for an operator view. */
function aiPromptKeys(): array {
    return array_keys(aiPromptRegistry());
}

/**
 * One registered prompt.
 *
 * THROWS on an unknown key rather than returning a default. A prompt key is
 * written by us, never by a user and never by a model, so an unknown one is a
 * bug — and the fallback a lenient lookup would have to invent is "send the
 * model no safety contract at all", which is the worst possible default.
 *
 * @return array{version: int, purpose: string, system: string, metadata: array<string, mixed>}
 */
function aiPrompt(string $key): array {
    $registry = aiPromptRegistry();
    if (!array_key_exists($key, $registry)) {
        throw new AiPromptNotFound("Unknown AI prompt key '$key'.");
    }
    return $registry[$key];
}

/** The system prompt text for a key. */
function aiPromptSystem(string $key): string {
    return aiPrompt($key)['system'];
}

/** The version currently registered for a key. */
function aiPromptVersion(string $key): int {
    return aiPrompt($key)['version'];
}

/**
 * The identifying pair recorded with a request.
 *
 * @return array{prompt_key: string, prompt_version: int}
 */
function aiPromptDescriptor(string $key): array {
    return ['prompt_key' => $key, 'prompt_version' => aiPromptVersion($key)];
}

/**
 * The registry as an operator sees it — WITHOUT the prompt bodies.
 *
 * The bodies are omitted deliberately. They are long, they are the safety
 * contract, and a surface that prints them invites editing them somewhere
 * other than in this file under review.
 *
 * @return list<array{key: string, version: int, purpose: string, safety_critical: bool}>
 */
function aiPromptCatalogue(): array {
    $catalogue = [];
    foreach (aiPromptRegistry() as $key => $prompt) {
        $catalogue[] = [
            'key'             => $key,
            'version'         => $prompt['version'],
            'purpose'         => $prompt['purpose'],
            'safety_critical' => !empty($prompt['metadata']['safety_critical']),
        ];
    }
    return $catalogue;
}
