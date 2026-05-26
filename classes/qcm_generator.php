<?php
namespace local_dreamu_qcm;

defined('MOODLE_INTERNAL') || die();

class qcm_generator {

    private string $endpoint;
    private string $apikey;
    private string $model;

    public function __construct() {
        $this->endpoint = get_config('local_dreamu_qcm', 'api_endpoint')
            ?: get_config('local_dreamu_ai', 'api_endpoint')
            ?: 'http://100.76.166.71:8200/v1/chat/completions';
        $this->apikey = get_config('local_dreamu_qcm', 'api_key')
            ?: get_config('local_dreamu_ai', 'api_key')
            ?: 'dummy';
        $this->model = get_config('local_dreamu_qcm', 'model_name')
            ?: get_config('local_dreamu_ai', 'model_name')
            ?: 'general';
    }

    /**
     * Generate diverse questions from course content.
     *
     * @param string $content Course content text
     * @param int $numquestions Total number of questions
     * @param string $difficulty easy|medium|hard
     * @param string $language fr|en
     * @param array $qtypes List of question types to generate
     * @return array List of question objects with ->qtype
     */
    public function generate(string $content, int $numquestions = 10, string $difficulty = 'medium',
                             string $language = 'fr', array $qtypes = ['multichoice'],
                             string $custom_instructions = ''): array {
        $langname = ($language === 'fr') ? 'French' : 'English';
        $diffname = [
            'easy' => 'easy (basic recall, definitions)',
            'medium' => 'medium (understanding, comparison)',
            'hard' => 'hard (analysis, application, problem-solving)',
        ][$difficulty] ?? 'medium';

        // Smart chunking: split content into sections for balanced coverage.
        $content_chunks = self::smart_chunk($content, 6000);

        // Distribute questions across types.
        $distribution = $this->distribute_questions($numquestions, $qtypes);

        $allquestions = [];

        foreach ($distribution as $qtype => $count) {
            if ($count <= 0) continue;

            $prompt = $this->build_prompt($qtype, $count, $diffname, $langname, $custom_instructions);
            // Use a different chunk for each type to ensure balanced coverage.
            $chunk_idx = array_search($qtype, array_keys($distribution)) % count($content_chunks);
            $chunk = $content_chunks[$chunk_idx];
            $user = "=== COURSE CONTENT (use ONLY this to create questions) ===\n\n{$chunk}\n\n"
                . "=== END OF COURSE CONTENT ===\n\n"
                . "Generate EXACTLY {$count} {$qtype} questions based STRICTLY on the course content above.\n"
                . "Do NOT use any knowledge outside this content. JSON:";

            // Temperature per type: low for factual (truefalse, numerical), higher for creative (multichoice).
            $temp_by_type = [
                'multichoice' => 0.3,
                'truefalse' => 0.2,
                'shortanswer' => 0.2,
                'matching' => 0.3,
                'numerical' => 0.1,
            ];
            $temperature = $temp_by_type[$qtype] ?? 0.7;

            try {
                $response = $this->call_api($prompt, $user, $temperature);
                $parsed = $this->parse_questions($response, $qtype);
                $allquestions = array_merge($allquestions, $parsed);
            } catch (\Exception $e) {
                // If one type fails, continue with others.
                debugging("QCM generation failed for type {$qtype}: " . $e->getMessage(), DEBUG_DEVELOPER);
                error_log("QCM_GEN_ERROR [{$qtype}]: " . $e->getMessage());
            }
        }

        return $allquestions;
    }


    /**
     * Verify generated questions against course content using a second model.
     * Returns questions with a 'verified' field (true/false) and 'verification_note'.
     */
    public function verify_questions(array $questions, string $content): array {
        // Use the same model for verification (DeepSeek R1 is good at reasoning/verification).
        $verifier_model = $this->model;
        $verified = [];

        foreach ($questions as $q) {
            $prompt = "Tu es un verificateur de questions d'examen. Tu dois determiner si la question suivante et sa reponse sont FACTUELLEMENT CORRECTES par rapport au contenu du cours fourni.\n\n";
            $prompt .= "=== CONTENU DU COURS ===\n" . substr($content, 0, 10000) . "\n\n";
            $prompt .= "=== QUESTION ===\n" . $q->question . "\n";

            if ($q->qtype === 'multichoice') {
                $prompt .= "Options: A) " . $q->optiona . " B) " . $q->optionb . " C) " . $q->optionc . " D) " . $q->optiond . "\n";
                $prompt .= "Reponse indiquee comme correcte: " . strtoupper($q->correct) . "\n";
            } else if ($q->qtype === 'truefalse') {
                $prompt .= "Reponse indiquee: " . $q->correct . "\n";
            } else {
                $prompt .= "Reponse: " . $q->correct . "\n";
            }

            $prompt .= "\nReponds UNIQUEMENT avec un JSON: {\"valid\": true/false, \"reason\": \"explication courte\"}\n";
            $prompt .= "- valid=true si la question ET la reponse sont correctes par rapport au contenu du cours\n";
            $prompt .= "- valid=false si la question est hors-sujet, si la reponse est fausse, ou si le niveau est inadapte\n";

            try {
                // Save current model, use verifier
                $original_model = $this->model;
                $this->model = $verifier_model;

                $response = $this->call_api($prompt, "Verifie cette question. Reponds UNIQUEMENT avec le JSON.");

                $this->model = $original_model;

                // Clean and parse
                $response = preg_replace('/<think>[\s\S]*?<\/think>/', '', $response);
                $response = preg_replace('/```json\s*/', '', $response);
                $response = preg_replace('/```\s*/', '', $response);
                $response = trim($response);

                if (preg_match('/\{[\s\S]*\}/', $response, $matches)) {
                    $result = json_decode($matches[0], true);
                    $q->verified = !empty($result['valid']);
                    $q->verification_note = $result['reason'] ?? '';
                } else {
                    $q->verified = null; // Could not verify
                    $q->verification_note = 'Verification impossible';
                }
            } catch (\Exception $e) {
                $q->verified = null;
                $q->verification_note = 'Erreur de verification: ' . $e->getMessage();
            }

            // Extra check for numerical questions: verify math if calculation steps are shown.
            if ($q->qtype === 'numerical' && !empty($q->explanation)) {
                $math_check = $this->verify_math($q);
                if ($math_check !== null) {
                    if (!$math_check['valid']) {
                        $q->verified = 0;
                        $q->verification_note = ($q->verification_note ? $q->verification_note . ' | ' : '')
                            . 'Erreur de calcul : ' . $math_check['reason'];
                    }
                }
            }

            $verified[] = $q;
        }

        return $verified;
    }

    /**
     * Verify arithmetic in numerical questions by extracting and evaluating math expressions.
     * Returns null if no verifiable math found, or ['valid' => bool, 'reason' => string].
     */
    private function verify_math(object $q): ?array {
        $answer = floatval($q->correct);
        $text = $q->question . ' ' . ($q->explanation ?? '');

        // Try to find patterns like "= NUMBER" at the end of a calculation chain.
        // Pattern: (A + B) / C = RESULT  or  A / B = RESULT
        $patterns = [
            // (X + Y) / Z = R
            '/\((\d+(?:\.\d+)?)\s*\+\s*(\d+(?:\.\d+)?)\)\s*\/\s*(\d+(?:\.\d+)?)\s*=\s*(\d+(?:\.\d+)?)/',
            // (X - Y) / Z = R
            '/\((\d+(?:\.\d+)?)\s*-\s*(\d+(?:\.\d+)?)\)\s*\/\s*(\d+(?:\.\d+)?)\s*=\s*(\d+(?:\.\d+)?)/',
            // X + Y = R
            '/(\d+(?:\.\d+)?)\s*\+\s*(\d+(?:\.\d+)?)\s*=\s*(\d+(?:\.\d+)?)/',
            // X / Y = R
            '/(\d+(?:\.\d+)?)\s*\/\s*(\d+(?:\.\d+)?)\s*=\s*(\d+(?:\.\d+)?)/',
            // X * Y = R
            '/(\d+(?:\.\d+)?)\s*\*\s*(\d+(?:\.\d+)?)\s*=\s*(\d+(?:\.\d+)?)/',
        ];

        foreach ($patterns as $i => $pat) {
            if (preg_match_all($pat, $text, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $expected = null;
                    $stated = null;

                    if ($i <= 1 && count($m) >= 5) {
                        // (A op B) / C = R
                        $a = floatval($m[1]);
                        $b = floatval($m[2]);
                        $c = floatval($m[3]);
                        $stated = floatval($m[4]);
                        if ($c != 0) {
                            $inner = ($i === 0) ? ($a + $b) : ($a - $b);
                            $expected = $inner / $c;
                        }
                    } elseif (count($m) >= 4) {
                        // A op B = R
                        $a = floatval($m[1]);
                        $b = floatval($m[2]);
                        $stated = floatval($m[3]);
                        if ($i === 2) $expected = $a + $b;
                        elseif ($i === 3 && $b != 0) $expected = $a / $b;
                        elseif ($i === 4) $expected = $a * $b;
                    }

                    if ($expected !== null && $stated !== null) {
                        if (abs($expected - $stated) > 0.01) {
                            return [
                                'valid' => false,
                                'reason' => "Le calcul indique {$m[0]} mais le resultat correct est " . round($expected, 4),
                            ];
                        }
                    }
                }
            }
        }

        // Also verify the final answer matches any "= ANSWER" in the explanation.
        if (preg_match('/=\s*(\d+(?:\.\d+)?)\s*$/', trim($q->explanation ?? ''), $finalm)) {
            $final = floatval($finalm[1]);
            if (abs($final - $answer) > ($q->tolerance ?? 0.01)) {
                return [
                    'valid' => false,
                    'reason' => "L'explication conclut a {$final} mais la reponse indiquee est {$answer}",
                ];
            }
        }

        return null;
    }

    /**
     * Distribute questions across types.
     */
    /**
     * Split content into balanced chunks by section separators.
     */
    private static function smart_chunk(string $content, int $max_chunk_size = 15000): array {
        // Split by section markers (=== ... ===)
        $sections = preg_split('/(?=^===\s)/m', $content);
        $sections = array_filter($sections, function($s) { return strlen(trim($s)) > 20; });
        $sections = array_values($sections);

        if (empty($sections)) {
            return [substr($content, 0, $max_chunk_size)];
        }

        // If total content fits in one chunk, return as-is.
        if (strlen($content) <= $max_chunk_size) {
            return [$content];
        }

        // Group sections into chunks that fit within max_chunk_size.
        $chunks = [];
        $current_chunk = '';
        foreach ($sections as $section) {
            if (strlen($current_chunk) + strlen($section) > $max_chunk_size && !empty($current_chunk)) {
                $chunks[] = $current_chunk;
                $current_chunk = $section;
            } else {
                $current_chunk .= $section;
            }
        }
        if (!empty($current_chunk)) {
            $chunks[] = $current_chunk;
        }

        return !empty($chunks) ? $chunks : [substr($content, 0, $max_chunk_size)];
    }

    private function distribute_questions(int $total, array $qtypes): array {
        $count = count($qtypes);
        if ($count === 0) return ['multichoice' => $total];

        $base = intdiv($total, $count);
        $remainder = $total % $count;

        $distribution = [];
        foreach ($qtypes as $i => $type) {
            $distribution[$type] = $base + ($i < $remainder ? 1 : 0);
        }

        return $distribution;
    }

    /**
     * Build the system prompt for each question type.
     */
    private function build_prompt(string $qtype, int $count, string $diffname, string $langname, string $custom_instructions = ''): string {
        $base = "You are a university professor creating exam questions STRICTLY from the course content provided.\n\n"
            . "ABSOLUTE RULES:\n"
            . "- ONLY create questions about topics, concepts, formulas, and examples that appear in the provided course content.\n"
            . "- NEVER invent or add topics not covered in the course. If the course is about descriptive statistics, do NOT ask about probability, hypothesis testing, or regression.\n"
            . "- Every question MUST be answerable using ONLY the information in the course content.\n"
            . "- Use the SAME vocabulary, notation, and terminology as the course.\n"
            . "- Match the level of the course. If the course uses simple examples, do NOT create advanced questions.\n"
            . "- If the course content is about drawing diagrams, ask about diagrams. If it is about calculations, ask about calculations.\n\n"
            . "Difficulty: {$diffname}. Language: {$langname}. "
            . "Respond ONLY in valid JSON array format.\n\n";

        $formats = [
            'multichoice' => "Format: [{\"type\": \"multichoice\", \"question\": \"...\", \"a\": \"...\", \"b\": \"...\", \"c\": \"...\", \"d\": \"...\", \"correct\": \"a\", \"explanation\": \"...\"}]\n"
                . "Rules: 4 options (a,b,c,d), 'correct' = letter. Wrong answers must be plausible but clearly wrong based on the course content.",
            'truefalse' => "Format: [{\"type\": \"truefalse\", \"question\": \"...\", \"correct\": true, \"explanation\": \"...\"}]\n"
                . "Rules: 'correct' is boolean. Mix true and false answers. Statements must be verifiable from the course content.",
            'shortanswer' => "Format: [{\"type\": \"shortanswer\", \"question\": \"...\", \"correct\": \"answer\", \"alternatives\": [\"alt1\"], \"explanation\": \"...\"}]\n"
                . "Rules: 'correct' is 1-3 words. 'alternatives' are synonyms. Answer must appear in the course content.",
            'matching' => "Format: [{\"type\": \"matching\", \"question\": \"...\", \"pairs\": [{\"term\": \"...\", \"definition\": \"...\"}], \"explanation\": \"...\"}]\n"
                . "Rules: 4-6 pairs. All terms and definitions must come from the course content.",
            'numerical' => "Format: [{\"type\": \"numerical\", \"question\": \"...\", \"correct\": NUMBER, \"tolerance\": 0.01, \"unit\": \"\", \"explanation\": \"...\"}]\n"
                . "Rules: Use calculations and values from the course content. Show the method in the explanation.",
        ];

        $format = $formats[$qtype] ?? $formats['multichoice'];
        $prompt = $base . "Generate EXACTLY {$count} {$qtype} questions.\n{$format}\nAll text in {$langname}.\nRESPOND ONLY WITH THE JSON ARRAY.";

        if (!empty($custom_instructions)) {
            $prompt .= "\n\nINSTRUCTIONS SPECIFIQUES DU PROFESSEUR:\n" . $custom_instructions . "\n";
        }

        return $prompt;
    }

    /**
     * Parse AI response for a specific question type.
     */
    private function parse_questions(string $response, string $qtype): array {
        // Clean common model artifacts.
        $cleaned = $response;
        $cleaned = preg_replace('/<think>[\s\S]*?<\/think>/', '', $cleaned);
        $cleaned = preg_replace('/```json\s*/', '', $cleaned);
        $cleaned = preg_replace('/```\s*/', '', $cleaned);
        $cleaned = trim($cleaned);

        $json = $cleaned;
        if (preg_match('/(\[[\s\S]*\])/s', $cleaned, $matches)) {
            $json = $matches[1];
        }

        $questions = json_decode($json, true);
        if (!is_array($questions)) {
            throw new \moodle_exception('parse_error', 'local_dreamu_qcm', '', null,
                "Could not parse questions: " . substr($response, 0, 500));
        }

        $parsed = [];
        foreach ($questions as $q) {
            if (!isset($q['question'])) continue;

            $obj = new \stdClass();
            $obj->qtype = $qtype;
            $obj->question = $q['question'];
            $obj->explanation = $q['explanation'] ?? '';

            switch ($qtype) {
                case 'multichoice':
                    if (!isset($q['a'], $q['b'], $q['c'], $q['d'], $q['correct'])) continue 2;
                    $obj->optiona = $q['a'];
                    $obj->optionb = $q['b'];
                    $obj->optionc = $q['c'];
                    $obj->optiond = $q['d'];
                    $obj->correct = strtolower(substr($q['correct'], 0, 1));
                    $obj->extra_data = null;
                    break;

                case 'truefalse':
                    $obj->optiona = '';
                    $obj->optionb = '';
                    $obj->optionc = '';
                    $obj->optiond = '';
                    $obj->correct = (!empty($q['correct']) && $q['correct'] !== 'false') ? 'true' : 'false';
                    $obj->extra_data = null;
                    break;

                case 'shortanswer':
                    $obj->optiona = '';
                    $obj->optionb = '';
                    $obj->optionc = '';
                    $obj->optiond = '';
                    $obj->correct = $q['correct'] ?? '';
                    $obj->extra_data = json_encode([
                        'alternatives' => $q['alternatives'] ?? [],
                    ]);
                    break;

                case 'matching':
                    $obj->optiona = '';
                    $obj->optionb = '';
                    $obj->optionc = '';
                    $obj->optiond = '';
                    $obj->correct = '';
                    $obj->extra_data = json_encode([
                        'pairs' => $q['pairs'] ?? [],
                    ]);
                    break;

                case 'numerical':
                    $obj->optiona = '';
                    $obj->optionb = '';
                    $obj->optionc = '';
                    $obj->optiond = '';
                    $obj->correct = strval($q['correct'] ?? 0);
                    $obj->extra_data = json_encode([
                        'tolerance' => $q['tolerance'] ?? 0.01,
                        'unit' => $q['unit'] ?? '',
                    ]);
                    break;
            }

            $parsed[] = $obj;
        }

        return $parsed;
    }

    /**
     * Call the AI API.
     */
    private function call_api(string $system, string $user, float $temperature = 0.7): string {
        $payload = json_encode([
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            'temperature' => $temperature,
            'max_tokens' => 2000,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        $ch = curl_init($this->endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apikey,
            ],
            CURLOPT_TIMEOUT => 600,
            CURLOPT_CONNECTTIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \moodle_exception('api_error', 'local_dreamu_qcm', '', null, "cURL error: {$error}");
        }
        if ($httpcode !== 200) {
            throw new \moodle_exception('api_error', 'local_dreamu_qcm', '', null, "HTTP {$httpcode}: {$response}");
        }

        $decoded = json_decode($response, true);
        if (!$decoded || !isset($decoded['choices'][0]['message']['content'])) {
            throw new \moodle_exception('api_error', 'local_dreamu_qcm', '', null, "Invalid response");
        }

        return $decoded['choices'][0]['message']['content'];
    }

    /**
     * Import approved questions into Moodle question bank.
     * Handles all question types.
     */
    public static function import_to_bank(int $courseid, array $questionids, ?int $contextid = null, bool $include_feedback = true): int {
        global $DB, $USER;

        $context = $contextid ? \context::instance_by_id($contextid) : \context_course::instance($courseid);

        // Get or create the default question category for this context.
        $category = $DB->get_record('question_categories', [
            'contextid' => $context->id,
            'parent' => 0,
        ]);
        if (!$category) {
            // Create top category first.
            $top = new \stdClass();
            $top->name = 'top';
            $top->info = '';
            $top->contextid = $context->id;
            $top->parent = 0;
            $top->sortorder = 0;
            $top->stamp = make_unique_id_code();
            $top->id = $DB->insert_record('question_categories', $top);

            // Create default category under top.
            $cat = new \stdClass();
            $cat->name = get_string('defaultfor', 'question', $context->get_context_name(false));
            $cat->info = get_string('defaultinfofor', 'question', $context->get_context_name(false));
            $cat->contextid = $context->id;
            $cat->parent = $top->id;
            $cat->sortorder = 999;
            $cat->stamp = make_unique_id_code();
            $cat->id = $DB->insert_record('question_categories', $cat);
            $category = $cat;
        } else {
            // Top exists, find or create default sub-category.
            $sub = $DB->get_record('question_categories', [
                'contextid' => $context->id,
                'parent' => $category->id,
            ]);
            if ($sub) {
                $category = $sub;
            }
        }

        $imported = 0;
        foreach ($questionids as $qid) {
            $qcm = $DB->get_record('local_dreamu_qcm', ['id' => $qid]);
            if (!$qcm || $qcm->status !== 'approved') continue;

            $qtype = $qcm->qtype ?? 'multichoice';

            switch ($qtype) {
                case 'multichoice':
                    self::import_multichoice($DB, $category, $qcm, $include_feedback);
                    break;
                case 'truefalse':
                    self::import_truefalse($DB, $category, $qcm, $include_feedback);
                    break;
                case 'shortanswer':
                    self::import_shortanswer($DB, $category, $qcm, $include_feedback);
                    break;
                case 'matching':
                    self::import_matching($DB, $category, $qcm, $include_feedback);
                    break;
                case 'numerical':
                    self::import_numerical($DB, $category, $qcm, $include_feedback);
                    break;
                default:
                    self::import_multichoice($DB, $category, $qcm, $include_feedback);
                    break;
            }

            $DB->set_field('local_dreamu_qcm', 'status', 'imported', ['id' => $qid]);
            $imported++;
        }

        return $imported;
    }

    private static function create_question_base($DB, $category, $qcm, string $qtype, bool $include_feedback = true): \stdClass {
        global $USER;

        $question = new \stdClass();
        $question->category = $category->id;
        $question->name = substr($qcm->question, 0, 80);
        $question->questiontext = $qcm->question;
        $question->questiontextformat = FORMAT_HTML;
        $question->generalfeedback = $include_feedback ? ($qcm->explanation ?: '') : '';
        $question->generalfeedbackformat = FORMAT_HTML;
        $question->qtype = $qtype;
        $question->defaultmark = 1;
        $question->penalty = 0.3333333;
        $question->length = 1;
        $question->hidden = 0;
        $question->createdby = $USER->id;
        $question->modifiedby = $USER->id;
        $question->timecreated = time();
        $question->timemodified = time();

        $question->id = $DB->insert_record('question', $question);

        // Create bank entry + version (Moodle 4.x).
        $be = new \stdClass();
        $be->questioncategoryid = $category->id;
        $be->idnumber = null;
        $be->ownerid = $USER->id;
        $be->id = $DB->insert_record('question_bank_entries', $be);

        $ver = new \stdClass();
        $ver->questionbankentryid = $be->id;
        $ver->version = 1;
        $ver->questionid = $question->id;
        $ver->status = 'ready';
        $DB->insert_record('question_versions', $ver);

        return $question;
    }

    private static function import_multichoice($DB, $category, $qcm, bool $include_feedback = true): void {
        $question = self::create_question_base($DB, $category, $qcm, 'multichoice', $include_feedback);

        $mc = new \stdClass();
        $mc->questionid = $question->id;
        $mc->layout = 0;
        $mc->single = 1;
        $mc->shuffleanswers = 1;
        $mc->correctfeedback = $include_feedback ? ('Bonne réponse ! ' . ($qcm->explanation ?: '')) : 'Bonne réponse !';
        $mc->correctfeedbackformat = FORMAT_HTML;
        $mc->partiallycorrectfeedback = '';
        $mc->partiallycorrectfeedbackformat = FORMAT_HTML;
        $mc->incorrectfeedback = $include_feedback ? ('Incorrect. ' . ($qcm->explanation ?: '')) : 'Incorrect.';
        $mc->incorrectfeedbackformat = FORMAT_HTML;
        $mc->answernumbering = 'abc';
        $mc->showstandardinstruction = 0;
        $DB->insert_record('qtype_multichoice_options', $mc);

        $options = [
            'a' => $qcm->optiona,
            'b' => $qcm->optionb,
            'c' => $qcm->optionc,
            'd' => $qcm->optiond,
        ];

        foreach ($options as $letter => $answertext) {
            $answer = new \stdClass();
            $answer->question = $question->id;
            $answer->answer = $answertext;
            $answer->answerformat = FORMAT_HTML;
            $answer->fraction = ($letter === $qcm->correct) ? 1.0 : 0.0;
            if (!$include_feedback) {
                $answer->feedback = '';
            } else if ($letter === $qcm->correct) {
                $answer->feedback = 'Correct ! ' . ($qcm->explanation ?: '');
            } else {
                $answer->feedback = 'Incorrect. La bonne réponse était : ' . ($options[$qcm->correct] ?? '') . '. ' . ($qcm->explanation ?: '');
            }
            $answer->feedbackformat = FORMAT_HTML;
            $DB->insert_record('question_answers', $answer);
        }
    }

    private static function import_truefalse($DB, $category, $qcm, bool $include_feedback = true): void {
        $question = self::create_question_base($DB, $category, $qcm, 'truefalse', $include_feedback);

        $istrue = ($qcm->correct === 'true');

        // True answer.
        $trueanswer = new \stdClass();
        $trueanswer->question = $question->id;
        $trueanswer->answer = 'True';
        $trueanswer->answerformat = FORMAT_MOODLE;
        $trueanswer->fraction = $istrue ? 1.0 : 0.0;
        if (!$include_feedback) {
            $trueanswer->feedback = '';
        } else {
            $trueanswer->feedback = $istrue
                ? ('Correct ! ' . ($qcm->explanation ?: ''))
                : ('Incorrect. La bonne réponse était : Faux. ' . ($qcm->explanation ?: ''));
        }
        $trueanswer->feedbackformat = FORMAT_HTML;
        $trueid = $DB->insert_record('question_answers', $trueanswer);

        // False answer.
        $falseanswer = new \stdClass();
        $falseanswer->question = $question->id;
        $falseanswer->answer = 'False';
        $falseanswer->answerformat = FORMAT_MOODLE;
        $falseanswer->fraction = $istrue ? 0.0 : 1.0;
        if (!$include_feedback) {
            $falseanswer->feedback = '';
        } else {
            $falseanswer->feedback = !$istrue
                ? ('Correct ! ' . ($qcm->explanation ?: ''))
                : ('Incorrect. La bonne réponse était : Vrai. ' . ($qcm->explanation ?: ''));
        }
        $falseanswer->feedbackformat = FORMAT_HTML;
        $falseid = $DB->insert_record('question_answers', $falseanswer);

        // Truefalse options.
        $tf = new \stdClass();
        $tf->question = $question->id;
        $tf->trueanswer = $trueid;
        $tf->falseanswer = $falseid;
        $DB->insert_record('question_truefalse', $tf);
    }

    private static function import_shortanswer($DB, $category, $qcm, bool $include_feedback = true): void {
        $question = self::create_question_base($DB, $category, $qcm, 'shortanswer', $include_feedback);

        // Shortanswer options.
        $sa = new \stdClass();
        $sa->questionid = $question->id;
        $sa->usecase = 0; // Case insensitive.
        $DB->insert_record('qtype_shortanswer_options', $sa);

        // Main answer.
        $answer = new \stdClass();
        $answer->question = $question->id;
        $answer->answer = $qcm->correct;
        $answer->answerformat = FORMAT_MOODLE;
        $answer->fraction = 1.0;
        $answer->feedback = $include_feedback ? ($qcm->explanation ?: '') : '';
        $answer->feedbackformat = FORMAT_HTML;
        $DB->insert_record('question_answers', $answer);

        // Alternative answers.
        $extra = json_decode($qcm->extra_data ?? '{}', true);
        $alternatives = $extra['alternatives'] ?? [];
        foreach ($alternatives as $alt) {
            $altanswer = new \stdClass();
            $altanswer->question = $question->id;
            $altanswer->answer = $alt;
            $altanswer->answerformat = FORMAT_MOODLE;
            $altanswer->fraction = 1.0;
            $altanswer->feedback = '';
            $altanswer->feedbackformat = FORMAT_HTML;
            $DB->insert_record('question_answers', $altanswer);
        }

        // Wildcard catch-all (0 points).
        $wildcard = new \stdClass();
        $wildcard->question = $question->id;
        $wildcard->answer = '*';
        $wildcard->answerformat = FORMAT_MOODLE;
        $wildcard->fraction = 0.0;
        $wildcard->feedback = $include_feedback ? ('Incorrect. ' . ($qcm->explanation ?: '')) : '';
        $wildcard->feedbackformat = FORMAT_HTML;
        $DB->insert_record('question_answers', $wildcard);
    }

    private static function import_matching($DB, $category, $qcm, bool $include_feedback = true): void {
        $question = self::create_question_base($DB, $category, $qcm, 'match', $include_feedback);

        // Match options.
        $mo = new \stdClass();
        $mo->questionid = $question->id;
        $mo->shuffleanswers = 1;
        $mo->correctfeedback = $include_feedback ? ('Bonne réponse ! ' . ($qcm->explanation ?: '')) : 'Bonne réponse !';
        $mo->correctfeedbackformat = FORMAT_HTML;
        $mo->partiallycorrectfeedback = $include_feedback ? ('Partiellement correct. ' . ($qcm->explanation ?: '')) : 'Partiellement correct.';
        $mo->partiallycorrectfeedbackformat = FORMAT_HTML;
        $mo->incorrectfeedback = $include_feedback ? ('Incorrect. ' . ($qcm->explanation ?: '')) : 'Incorrect.';
        $mo->incorrectfeedbackformat = FORMAT_HTML;
        $DB->insert_record('qtype_match_options', $mo);

        // Add pairs as subquestions.
        $extra = json_decode($qcm->extra_data ?? '{}', true);
        $pairs = $extra['pairs'] ?? [];

        foreach ($pairs as $pair) {
            $sub = new \stdClass();
            $sub->questionid = $question->id;
            $sub->questiontext = $pair['term'] ?? '';
            $sub->questiontextformat = FORMAT_HTML;
            $sub->answertext = $pair['definition'] ?? '';
            $DB->insert_record('qtype_match_subquestions', $sub);
        }
    }

    private static function import_numerical($DB, $category, $qcm, bool $include_feedback = true): void {
        $question = self::create_question_base($DB, $category, $qcm, 'numerical', $include_feedback);

        $extra = json_decode($qcm->extra_data ?? '{}', true);
        $tolerance = $extra['tolerance'] ?? 0.01;

        // Answer.
        $answer = new \stdClass();
        $answer->question = $question->id;
        $answer->answer = $qcm->correct;
        $answer->answerformat = FORMAT_MOODLE;
        $answer->fraction = 1.0;
        $answer->feedback = $include_feedback ? ($qcm->explanation ?: '') : '';
        $answer->feedbackformat = FORMAT_HTML;
        $answerid = $DB->insert_record('question_answers', $answer);

        // Numerical options.
        $num = new \stdClass();
        $num->question = $question->id;
        $num->answer = $answerid;
        $num->tolerance = $tolerance;
        $DB->insert_record('question_numerical', $num);

        // Unit if specified.
        $unit = $extra['unit'] ?? '';
        if (!empty($unit)) {
            $u = new \stdClass();
            $u->question = $question->id;
            $u->multiplier = 1.0;
            $u->unit = $unit;
            $DB->insert_record('question_numerical_units', $u);
        }
    }
}
