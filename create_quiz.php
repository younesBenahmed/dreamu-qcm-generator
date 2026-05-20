<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->dirroot . '/mod/quiz/lib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/course/lib.php');

$courseid = required_param('courseid', PARAM_INT);
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_login($course);
require_capability('local/dreamu_qcm:generate', $context);

$PAGE->set_url(new moodle_url('/local/dreamu_qcm/create_quiz.php', ['courseid' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_title('Creer un Quiz IA');
$PAGE->set_heading($course->fullname . ' - Creer un Quiz IA');

$confirm = optional_param('confirm', 0, PARAM_INT);
$quizname = optional_param('quizname', '', PARAM_TEXT);
$include_feedback = optional_param('include_feedback', 0, PARAM_INT);

if ($confirm && confirm_sesskey()) {
    // Step 1: Approve all pending and import to question bank.
    $DB->set_field_select('local_dreamu_qcm', 'status', 'approved',
        "courseid = :courseid AND status = 'pending'", ['courseid' => $courseid]);

    $approved = $DB->get_records('local_dreamu_qcm', ['courseid' => $courseid, 'status' => 'approved']);
    $ids = array_keys($approved);

    if (empty($ids)) {
        redirect(
            new moodle_url('/local/dreamu_qcm/review.php', ['courseid' => $courseid]),
            'Aucune question a importer.',
            null,
            \core\output\notification::NOTIFY_WARNING
        );
    }

    // Step 2: Create the quiz activity using Moodle API.
    if (empty($quizname)) {
        $quizname = 'Quiz IA - ' . date('Y-m-d');
    }

    // Build the quiz module data as Moodle expects it.
    $quizdata = new stdClass();
    $quizdata->course = $courseid;
    $quizdata->name = $quizname;
    $quizdata->intro = '<p>Quiz genere automatiquement par l\'IA avec ' . count($ids) . ' questions.</p>';
    $quizdata->introformat = FORMAT_HTML;
    $quizdata->timeopen = 0;
    $quizdata->timeclose = 0;
    $quizdata->timelimit = 0;
    $quizdata->overduehandling = 'autosubmit';
    $quizdata->graceperiod = 0;
    $quizdata->preferredbehaviour = 'deferredfeedback';
    $quizdata->attempts = 0;
    $quizdata->grademethod = QUIZ_GRADEHIGHEST;
    $quizdata->decimalpoints = 2;
    $quizdata->questionsperpage = 1;
    $quizdata->shuffleanswers = 1;
    $quizdata->grade = 100;
    $quizdata->sumgrades = 0;
    $quizdata->reviewattempt = 69904;
    $quizdata->reviewcorrectness = 69904;
    $quizdata->reviewmarks = 69904;
    $quizdata->reviewspecificfeedback = 69904;
    $quizdata->reviewgeneralfeedback = 69904;
    $quizdata->reviewrightanswer = 69904;
    $quizdata->reviewoverallfeedback = 4368;
    $quizdata->timecreated = time();
    $quizdata->timemodified = time();

    // Insert quiz record.
    $quizdata->id = $DB->insert_record('quiz', $quizdata);

    // Create course module via API.
    $module = $DB->get_record('modules', ['name' => 'quiz'], '*', MUST_EXIST);

    $cm = new stdClass();
    $cm->course = $courseid;
    $cm->module = $module->id;
    $cm->instance = $quizdata->id;
    $cm->section = 0;
    $cm->visible = 1;
    $cm->visibleoncoursepage = 1;
    $cm->added = time();

    $cmid = add_course_module($cm);
    course_add_cm_to_section($courseid, $cmid, 0);
    set_coursemodule_visible($cmid, 1);

    // Reload quiz with full object for API calls.
    $quizobj = $DB->get_record('quiz', ['id' => $quizdata->id], '*', MUST_EXIST);
    $quizobj->cmid = $cmid;

    // Step 3: Import questions into the quiz module question bank context.
    $quizcontext = context_module::instance($cmid);
    $count = \local_dreamu_qcm\qcm_generator::import_to_bank($courseid, $ids, $quizcontext->id, (bool)$include_feedback);

    // Step 4: Add questions using quiz_add_quiz_question() API.
    $importedrecords = $DB->get_records_list('local_dreamu_qcm', 'id', $ids);
    $added = 0;

    foreach ($importedrecords as $qcmrecord) {
        // Find the matching question in the question bank by name.
        $qname = substr($qcmrecord->question, 0, 80);

        // Get question by name, most recent first.
        $question = $DB->get_record_sql(
            "SELECT q.id, q.defaultmark
               FROM {question} q
               JOIN {question_versions} qv ON qv.questionid = q.id
               JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
              WHERE q.name = :qname AND q.qtype != 'random'
           ORDER BY q.timecreated DESC",
            ['qname' => $qname],
            IGNORE_MULTIPLE
        );

        if (!$question) {
            continue;
        }

        // Use the proper Moodle API to add question to quiz.
        quiz_add_quiz_question($question->id, $quizobj, 0, $question->defaultmark);
        $added++;
    }

    // Recalculate quiz sumgrades with the current Moodle quiz API.
    \mod_quiz\quiz_settings::create($quizobj->id)->get_grade_calculator()->recompute_quiz_sumgrades();

    // Delete any preview attempts.
    quiz_delete_previews($quizobj);

    // Rebuild course cache.
    rebuild_course_cache($courseid, true);

    // Redirect to the quiz.
    $quizurl = new moodle_url('/mod/quiz/view.php', ['id' => $cmid]);
    redirect($quizurl,
        "Quiz \"{$quizname}\" cree avec {$added} questions !",
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// Confirmation page.
echo $OUTPUT->header();

$pendingcount = $DB->count_records_select('local_dreamu_qcm',
    "courseid = :courseid AND status IN ('pending', 'approved')",
    ['courseid' => $courseid]);

echo '<h3>Creer un Quiz automatiquement</h3>';

echo '<div class="alert alert-info">';
echo '<strong>' . $pendingcount . '</strong> questions seront approuvees, importees dans la banque de questions, ';
echo 'puis ajoutees a un nouveau quiz dans le cours.';
echo '</div>';

if ($pendingcount == 0) {
    echo '<div class="alert alert-warning">Aucune question en attente ou approuvee. ';
    echo '<a href="' . new moodle_url('/local/dreamu_qcm/generate.php', ['courseid' => $courseid]) . '">Generer des questions d\'abord.</a></div>';
    echo $OUTPUT->footer();
    exit;
}

$defaultname = 'Quiz IA - ' . date('Y-m-d');

echo '<form method="post" action="' . $PAGE->url . '">';
echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
echo '<input type="hidden" name="confirm" value="1">';
echo '<input type="hidden" name="courseid" value="' . $courseid . '">';

echo '<div class="form-group">';
echo '<label for="quizname"><strong>Nom du quiz :</strong></label>';
echo '<input type="text" id="quizname" name="quizname" class="form-control" value="' . s($defaultname) . '">';
echo '</div>';

echo '<div class="form-group mt-3">';
echo '<div class="form-check">';
echo '<input class="form-check-input" type="checkbox" name="include_feedback" id="include_feedback" value="1" checked>';
echo '<label class="form-check-label" for="include_feedback"><strong>Inclure le feedback IA</strong></label>';
echo '<small class="form-text text-muted d-block">Quand l\'étudiant se trompe, il verra l\'explication de l\'IA avec la bonne réponse. Décochez pour un quiz sans feedback.</small>';
echo '</div>';
echo '</div>';

echo '<div class="mt-3">';
echo '<button type="submit" class="btn btn-warning">Creer le Quiz</button> ';
echo '<a href="' . new moodle_url('/local/dreamu_qcm/review.php', ['courseid' => $courseid]) . '" class="btn btn-secondary">Annuler</a>';
echo '</div>';

echo '</form>';

echo $OUTPUT->footer();
