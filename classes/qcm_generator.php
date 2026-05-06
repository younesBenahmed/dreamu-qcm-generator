<?php
namespace local_dreamu_qcm;

defined('MOODLE_INTERNAL') || die();

/**
 * Generates QCM questions using AI.
 */
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
            ?: 'sk-dummy';
        $this->model = get_config('local_dreamu_qcm', 'model_name')
            ?: get_config('local_dreamu_ai', 'model_name')
            ?: 'general';
    }

    /**
     * Generate QCM questions from course content.
     *
     * @param string $content The course content text
     * @param int $numquestions Number of questions to generate
     * @param string $difficulty easy|medium|hard
     * @param string $language fr|en
     * @return array List of question objects
     */
    public function generate(string $content, int $numquestions = 10, string $difficulty = 'medium', string $language = 'fr'): array {
        $langname = ($language === 'fr') ? 'French' : 'English';
        $diffname = ['easy' => 'easy (basic recall)', 'medium' => 'medium (understanding)', 'hard' => 'hard (analysis/application)'][$difficulty] ?? 'medium';

        // Truncate content if too long
        if (strlen($content) > 25000) {
            $content = substr($content, 0, 25000) . "\n[... content truncated ...]";
        }

        $system = "You are a university professor creating exam questions. "
            . "Based on the course content provided, generate EXACTLY {$numquestions} multiple choice questions. "
            . "Difficulty: {$diffname}. Language: {$langname}.\n\n"
            . "Respond ONLY in JSON array format:\n"
            . "[{\"question\": \"...\", \"a\": \"...\", \"b\": \"...\", \"c\": \"...\", \"d\": \"...\", \"correct\": \"a\", \"explanation\": \"...\"}]\n\n"
            . "Rules:\n"
            . "- Each question has EXACTLY 4 options (a, b, c, d)\n"
            . "- 'correct' is the letter of the right answer (a, b, c, or d)\n"
            . "- 'explanation' explains WHY the answer is correct\n"
            . "- Questions must be DIRECTLY based on the course content provided\n"
            . "- Vary question types: definitions, comparisons, applications, true/false reworded as MCQ\n"
            . "- Wrong answers must be plausible (not obviously wrong)\n"
            . "- All text in {$langname}";

        $user = "Course content:\n\n{$content}\n\nGenerate {$numquestions} QCM questions in JSON:";

        $response = $this->call_api($system, $user);

        return $this->parse_questions($response);
    }

    /**
     * Call the AI API.
     */
    private function call_api(string $system, string $user): string {
        $payload = json_encode([
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            'temperature' => 0.7,
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
     * Parse AI response into question objects.
     */
    private function parse_questions(string $response): array {
        // Try to extract JSON array from response
        $json = $response;
        if (preg_match('/```(?:json)?\s*(\[.+\])\s*```/s', $response, $matches)) {
            $json = $matches[1];
        } elseif (preg_match('/(\[[\s\S]*"question"[\s\S]*\])/s', $response, $matches)) {
            $json = $matches[1];
        }

        $questions = json_decode($json, true);
        if (!is_array($questions)) {
            throw new \moodle_exception('parse_error', 'local_dreamu_qcm', '', null,
                "Could not parse questions: " . substr($response, 0, 500));
        }

        $parsed = [];
        foreach ($questions as $q) {
            if (!isset($q['question'], $q['a'], $q['b'], $q['c'], $q['d'], $q['correct'])) {
                continue;
            }
            $obj = new \stdClass();
            $obj->question = $q['question'];
            $obj->optiona = $q['a'];
            $obj->optionb = $q['b'];
            $obj->optionc = $q['c'];
            $obj->optiond = $q['d'];
            $obj->correct = strtolower(substr($q['correct'], 0, 1));
            $obj->explanation = $q['explanation'] ?? '';
            $parsed[] = $obj;
        }

        return $parsed;
    }

    /**
     * Import approved questions into Moodle question bank.
     *
     * @param int $courseid
     * @param array $questionids IDs from local_dreamu_qcm table
     * @return int Number imported
     */
    public static function import_to_bank(int $courseid, array $questionids): int {
        global $DB, $USER;

        $context = \context_course::instance($courseid);
        $category = question_get_default_category($context->id);
        if (!$category) {
            $category = question_make_default_categories([$context]);
            $category = question_get_default_category($context->id);
        }

        $imported = 0;
        foreach ($questionids as $qid) {
            $qcm = $DB->get_record('local_dreamu_qcm', ['id' => $qid]);
            if (!$qcm || $qcm->status !== 'approved') {
                continue;
            }

            // Create Moodle multichoice question.
            $question = new \stdClass();
            $question->category = $category->id;
            $question->name = substr($qcm->question, 0, 80);
            $question->questiontext = $qcm->question;
            $question->questiontextformat = FORMAT_HTML;
            $question->generalfeedback = $qcm->explanation ?: '';
            $question->generalfeedbackformat = FORMAT_HTML;
            $question->qtype = 'multichoice';
            $question->defaultmark = 1;
            $question->penalty = 0.3333333;
            $question->length = 1;
            $question->hidden = 0;
            $question->createdby = $USER->id;
            $question->modifiedby = $USER->id;
            $question->timecreated = time();
            $question->timemodified = time();

            $question->id = $DB->insert_record('question', $question);

            // Create question version and bank entry (Moodle 4.x).
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

            // Create multichoice options.
            $mc = new \stdClass();
            $mc->questionid = $question->id;
            $mc->layout = 0;
            $mc->single = 1;
            $mc->shuffleanswers = 1;
            $mc->correctfeedback = 'Correct!';
            $mc->correctfeedbackformat = FORMAT_HTML;
            $mc->partiallycorrectfeedback = '';
            $mc->partiallycorrectfeedbackformat = FORMAT_HTML;
            $mc->incorrectfeedback = 'Incorrect.';
            $mc->incorrectfeedbackformat = FORMAT_HTML;
            $mc->answernumbering = 'abc';
            $mc->showstandardinstruction = 0;
            $DB->insert_record('qtype_multichoice_options', $mc);

            // Create answer options.
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
                $answer->feedback = ($letter === $qcm->correct) ? ($qcm->explanation ?: 'Bonne réponse!') : '';
                $answer->feedbackformat = FORMAT_HTML;
                $DB->insert_record('question_answers', $answer);
            }

            // Mark as imported.
            $DB->set_field('local_dreamu_qcm', 'status', 'imported', ['id' => $qid]);
            $imported++;
        }

        return $imported;
    }
}
