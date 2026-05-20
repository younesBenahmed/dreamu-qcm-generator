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
            ?: 'http://100.76.166.71:11434/v1/chat/completions';
        $this->apikey = get_config('local_dreamu_qcm', 'api_key')
            ?: get_config('local_dreamu_ai', 'api_key')
            ?: 'ollama';
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

        if (strlen($content) > 25000) {
            $content = substr($content, 0, 25000) . "\n[... content truncated ...]";
        }

        // Distribute questions across types.
        $distribution = $this->distribute_questions($numquestions, $qtypes);

        $allquestions = [];

        foreach ($distribution as $qtype => $count) {
            if ($count <= 0) continue;

            $prompt = $this->build_prompt($qtype, $count, $diffname, $langname, $custom_instructions);
            $user = "Course content:\n\n{$content}\n\nGenerate {$count} questions in JSON:";

            // Temperature per type: low for factual (truefalse, numerical), higher for creative (multichoice).
            $temp_by_type = [
                'multichoice' => 0.7,
                'truefalse' => 0.3,
                'shortanswer' => 0.4,
                'matching' => 0.5,
                'numerical' => 0.2,
            ];
            $temperature = $temp_by_type[$qtype] ?? 0.7;

            try {
                $response = $this->call_api($prompt, $user, $temperature);
                $parsed = $this->parse_questions($response, $qtype);
                $allquestions = array_merge($allquestions, $parsed);
            } catch (\Exception $e) {
                // If one type fails, continue with others.
                debugging("QCM generation failed for type {$qtype}: " . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        return $allquestions;
    }


    /**
     * Verify generated questions against course content using a second model.
     * Returns questions with a 'verified' field (true/false) and 'verification_note'.
     */
    public function verify_questions(array $questions, string $content): array {
        $verifier_model = 'llama3.1:70b';
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

            $verified[] = $q;
        }

        return $verified;
    }

    /**
     * Distribute questions across types.
     */
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
        $base = "You are a university professor creating exam questions. "
            . "Difficulty: {$diffname}. Language: {$langname}. "
            . "Respond ONLY in valid JSON array format. ";

        $prompt = '';

        switch ($qtype) {
            case 'multichoice':
                $prompt = $base . "Generate EXACTLY {$count} multiple choice questions.\n"
                    . "Format: [{\"type\": \"multichoice\", \"question\": \"...\", \"a\": \"...\", \"b\": \"...\", \"c\": \"...\", \"d\": \"...\", \"correct\": \"a\", \"explanation\": \"...\"}]\n"
                    . "Rules:\n"
                    . "- 4 options (a, b, c, d), 'correct' = letter of right answer\n"
                    . "- Wrong answers must be plausible\n"
                    . "- All text in {$langname}\n\n"
                    . "Example of a good question:\n"
                    . "[{\"type\": \"multichoice\", \"question\": \"Quelle est la moyenne d'un échantillon {2, 4, 6} ?\", \"a\": \"3\", \"b\": \"4\", \"c\": \"5\", \"d\": \"6\", \"correct\": \"b\", \"explanation\": \"La moyenne est (2+4+6)/3 = 12/3 = 4\"}]\n\n"
                    . "Another example:\n"
                    . "[{\"type\": \"multichoice\", \"question\": \"Quel indicateur mesure la dispersion des données autour de la moyenne ?\", \"a\": \"La médiane\", \"b\": \"Le mode\", \"c\": \"L'écart-type\", \"d\": \"Le quartile\", \"correct\": \"c\", \"explanation\": \"L'écart-type mesure la dispersion des valeurs autour de la moyenne arithmétique.\"}]";
                break;

            case 'truefalse':
                $prompt = $base . "Generate EXACTLY {$count} true/false questions.\n"
                    . "Format: [{\"type\": \"truefalse\", \"question\": \"...\", \"correct\": true, \"explanation\": \"...\"}]\n"
                    . "Rules:\n"
                    . "- 'correct' is true or false (boolean)\n"
                    . "- Questions should be clear statements that are definitively true or false\n"
                    . "- Mix true and false answers (not all the same)\n"
                    . "- All text in {$langname}\n\n"
                    . "Example of a good question:\n"
                    . "[{\"type\": \"truefalse\", \"question\": \"La médiane d'un échantillon est toujours égale à la moyenne.\", \"correct\": false, \"explanation\": \"La médiane et la moyenne ne sont égales que dans une distribution parfaitement symétrique.\"}]\n\n"
                    . "Another example:\n"
                    . "[{\"type\": \"truefalse\", \"question\": \"La variance est le carré de l'écart-type.\", \"correct\": true, \"explanation\": \"Par définition, la variance est sigma² et l'écart-type est sigma, donc la variance est bien le carré de l'écart-type.\"}]";
                break;

            case 'shortanswer':
                $prompt = $base . "Generate EXACTLY {$count} short answer questions.\n"
                    . "Format: [{\"type\": \"shortanswer\", \"question\": \"...\", \"correct\": \"the answer\", \"alternatives\": [\"alt1\", \"alt2\"], \"explanation\": \"...\"}]\n"
                    . "Rules:\n"
                    . "- 'correct' is the main expected answer (1-3 words)\n"
                    . "- 'alternatives' are other acceptable answers (synonyms, abbreviations)\n"
                    . "- Questions should have a clear, unambiguous short answer\n"
                    . "- Good for: definitions, names, specific terms, formulas\n"
                    . "- All text in {$langname}\n\n"
                    . "Example of a good question:\n"
                    . "[{\"type\": \"shortanswer\", \"question\": \"Comment appelle-t-on la valeur qui apparaît le plus fréquemment dans un échantillon ?\", \"correct\": \"le mode\", \"alternatives\": [\"mode\", \"Mode\"], \"explanation\": \"Le mode est la valeur la plus fréquente dans une série statistique.\"}]\n\n"
                    . "Another example:\n"
                    . "[{\"type\": \"shortanswer\", \"question\": \"Quelle mesure de tendance centrale divise un échantillon ordonné en deux parties égales ?\", \"correct\": \"la médiane\", \"alternatives\": [\"médiane\", \"Médiane\"], \"explanation\": \"La médiane est la valeur qui sépare la moitié inférieure de la moitié supérieure d'un échantillon ordonné.\"}]";
                break;

            case 'matching':
                $prompt = $base . "Generate EXACTLY {$count} matching questions.\n"
                    . "Format: [{\"type\": \"matching\", \"question\": \"Match the following:\", \"pairs\": [{\"term\": \"...\", \"definition\": \"...\"}], \"explanation\": \"...\"}]\n"
                    . "Rules:\n"
                    . "- Each question has 4-6 pairs of term/definition\n"
                    . "- Terms on the left, definitions on the right\n"
                    . "- Good for: vocabulary, concept-definition, cause-effect\n"
                    . "- All text in {$langname}\n\n"
                    . "Example of a good question:\n"
                    . "[{\"type\": \"matching\", \"question\": \"Associez chaque mesure statistique à sa définition :\", \"pairs\": [{\"term\": \"Moyenne\", \"definition\": \"Somme des valeurs divisée par le nombre de valeurs\"}, {\"term\": \"Médiane\", \"definition\": \"Valeur centrale d'un échantillon ordonné\"}, {\"term\": \"Mode\", \"definition\": \"Valeur la plus fréquente\"}, {\"term\": \"Étendue\", \"definition\": \"Différence entre la valeur maximale et minimale\"}], \"explanation\": \"Ces quatre mesures sont les indicateurs fondamentaux de la statistique descriptive.\"}]";
                break;

            case 'numerical':
                $prompt = $base . "Generate EXACTLY {$count} numerical answer questions.\n"
                    . "Format: [{\"type\": \"numerical\", \"question\": \"...\", \"correct\": 42.5, \"tolerance\": 0.1, \"unit\": \"kg\", \"explanation\": \"...\"}]\n"
                    . "Rules:\n"
                    . "- 'correct' is the numeric answer\n"
                    . "- 'tolerance' is the acceptable margin of error\n"
                    . "- 'unit' is the expected unit (optional)\n"
                    . "- Good for: calculations, measurements, quantities\n"
                    . "- All text in {$langname}\n\n"
                    . "Example of a good question:\n"
                    . "[{\"type\": \"numerical\", \"question\": \"Calculez la variance de l'échantillon {2, 4, 6}. La moyenne est 4.\", \"correct\": 2.67, \"tolerance\": 0.01, \"unit\": \"\", \"explanation\": \"Variance = [(2-4)² + (4-4)² + (6-4)²] / 3 = [4 + 0 + 4] / 3 = 8/3 = 2.67\"}]\n\n"
                    . "Another example:\n"
                    . "[{\"type\": \"numerical\", \"question\": \"Quel est l'écart-type de la série {10, 20, 30} ?\", \"correct\": 8.16, \"tolerance\": 0.01, \"unit\": \"\", \"explanation\": \"Moyenne = 20, Variance = [(10-20)² + (20-20)² + (30-20)²]/3 = 200/3 = 66.67, Écart-type = sqrt(66.67) = 8.16\"}]";
                break;

            default:
                $prompt = $base . "Generate EXACTLY {$count} multiple choice questions.\n"
                    . "Format: [{\"type\": \"multichoice\", \"question\": \"...\", \"a\": \"...\", \"b\": \"...\", \"c\": \"...\", \"d\": \"...\", \"correct\": \"a\", \"explanation\": \"...\"}]";
                break;
        }

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
            'max_tokens' => 4000,
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
            CURLOPT_TIMEOUT => 300,
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
