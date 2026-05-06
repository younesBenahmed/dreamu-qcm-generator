<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->dirroot . '/mod/quiz/lib.php');
require_once($CFG->dirroot . '/course/lib.php');

$courseid = required_param('courseid', PARAM_INT);
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_login($course);
require_capability('local/dreamu_qcm:generate', $context);

$PAGE->set_url(new moodle_url('/local/dreamu_qcm/create_quiz.php', ['courseid' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_title('Créer un Quiz IA');
$PAGE->set_heading($course->fullname . ' - Créer un Quiz IA');

$confirm = optional_param('confirm', 0, PARAM_INT);
$quizname = optional_param('quizname', '', PARAM_TEXT);

if ($confirm && confirm_sesskey()) {
    // Step 1: Approve all pending and import to question bank.
    $DB->set_field_select('local_dreamu_qcm', 'status', 'approved',
        "courseid = :courseid AND status = 'pending'", ['courseid' => $courseid]);

    $approved = $DB->get_records('local_dreamu_qcm', ['courseid' => $courseid, 'status' => 'approved']);
    $ids = array_keys($approved);

    if (empty($ids)) {
        redirect(
            new moodle_url('/local/dreamu_qcm/review.php', ['courseid' => $courseid]),
            'Aucune question à importer.',
            null,
            \core\output\notification::NOTIFY_WARNING
        );
    }

    $count = \local_dreamu_qcm\qcm_generator::import_to_bank($courseid, $ids);

    // Step 2: Create the quiz activity.
    if (empty($quizname)) {
        $quizname = 'Quiz IA - ' . date('Y-m-d');
    }

    // Get the default category to find the imported questions.
    $category = question_get_default_category($context->id);

    // Create quiz module instance.
    $quiz = new stdClass();
    $quiz->course = $courseid;
    $quiz->name = $quizname;
    $quiz->intro = '<p>Quiz généré automatiquement par l\'IA avec ' . $count . ' questions.</p>';
    $quiz->introformat = FORMAT_HTML;
    $quiz->timeopen = 0;
    $quiz->timeclose = 0;
    $quiz->timelimit = 0;
    $quiz->overduehandling = 'autosubmit';
    $quiz->graceperiod = 0;
    $quiz->preferredbehaviour = 'deferredfeedback';
    $quiz->canredoquestions = 0;
    $quiz->attempts = 0;
    $quiz->attemptonlast = 0;
    $quiz->grademethod = 1; // Highest grade.
    $quiz->decimalpoints = 2;
    $quiz->questiondecimalpoints = -1;
    $quiz->reviewattempt = 69904;
    $quiz->reviewcorrectness = 69904;
    $quiz->reviewmarks = 69904;
    $quiz->reviewspecificfeedback = 69904;
    $quiz->reviewgeneralfeedback = 69904;
    $quiz->reviewrightanswer = 69904;
    $quiz->reviewoverallfeedback = 4368;
    $quiz->questionsperpage = 1;
    $quiz->navmethod = 'free';
    $quiz->shuffleanswers = 1;
    $quiz->sumgrades = 0;
    $quiz->grade = 100;
    $quiz->timecreated = time();
    $quiz->timemodified = time();

    $quiz->id = $DB->insert_record('quiz', $quiz);

    // Step 3: Create course module.
    $module = $DB->get_record('modules', ['name' => 'quiz'], '*', MUST_EXIST);

    $cm = new stdClass();
    $cm->course = $courseid;
    $cm->module = $module->id;
    $cm->instance = $quiz->id;
    $cm->section = 0;
    $cm->visible = 1;
    $cm->visibleoncoursepage = 1;
    $cm->groupmode = 0;
    $cm->groupingid = 0;
    $cm->added = time();

    $cmid = add_course_module($cm);

    // Add to first course section.
    $sectionnum = 0;
    $section = $DB->get_record('course_sections', ['course' => $courseid, 'section' => $sectionnum]);
    if (!$section) {
        $section = course_create_section($courseid, $sectionnum);
    }
    course_add_cm_to_section($courseid, $cmid, $sectionnum);

    // Set the module visibility.
    set_coursemodule_visible($cmid, 1);

    // Step 4: Add questions to the quiz via quiz_slots.
    // Get recently imported questions from the question bank (questions in the default category).
    // We match by name from our local_dreamu_qcm records that were just imported.
    $importedrecords = $DB->get_records('local_dreamu_qcm', ['courseid' => $courseid, 'status' => 'imported']);

    $slot = 0;
    $sumgrades = 0;

    foreach ($importedrecords as $qcmrecord) {
        // Find the matching question in the question bank by name prefix.
        $qname = substr($qcmrecord->question, 0, 80);
        $question = $DB->get_record_select('question',
            "category = :catid AND name = :qname AND qtype != 'random'",
            ['catid' => $category->id, 'qname' => $qname],
            '*', IGNORE_MULTIPLE
        );

        if (!$question) {
            continue;
        }

        // Get the question bank entry and version for Moodle 4.x.
        $version = $DB->get_record_sql(
            "SELECT qv.*, qbe.id AS bankentryid
               FROM {question_versions} qv
               JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
              WHERE qv.questionid = :qid
           ORDER BY qv.version DESC",
            ['qid' => $question->id],
            IGNORE_MULTIPLE
        );

        if (!$version) {
            continue;
        }

        $slot++;
        $sumgrades += $question->defaultmark;

        // Create a quiz slot reference.
        $reference = new stdClass();
        $reference->usingcontextid = context_module::instance($cmid)->id;
        $reference->component = 'mod_quiz';
        $reference->questionarea = 'slot';
        $reference->questionbankentryid = $version->bankentryid;
        $reference->version = null; // Always latest.

        $slotrecord = new stdClass();
        $slotrecord->quizid = $quiz->id;
        $slotrecord->slot = $slot;
        $slotrecord->page = ceil($slot / $quiz->questionsperpage);
        $slotrecord->requireprevious = 0;
        $slotrecord->maxmark = $question->defaultmark;

        $slotrecord->id = $DB->insert_record('quiz_slots', $slotrecord);

        // Set the itemid for the reference.
        $reference->itemid = $slotrecord->id;
        $DB->insert_record('question_references', $reference);
    }

    // Update quiz sum of grades.
    $DB->set_field('quiz', 'sumgrades', $sumgrades, ['id' => $quiz->id]);

    // Rebuild course cache.
    rebuild_course_cache($courseid, true);

    // Redirect to the quiz.
    $quizurl = new moodle_url('/mod/quiz/view.php', ['id' => $cmid]);
    redirect($quizurl,
        "Quiz \"{$quizname}\" créé avec {$slot} questions !",
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// Confirmation page.
echo $OUTPUT->header();

$pendingcount = $DB->count_records_select('local_dreamu_qcm',
    "courseid = :courseid AND status IN ('pending', 'approved')",
    ['courseid' => $courseid]);

echo '<h3>Créer un Quiz automatiquement</h3>';

echo '<div class="alert alert-info">';
echo '<strong>' . $pendingcount . '</strong> questions seront approuvées, importées dans la banque de questions, ';
echo 'puis ajoutées à un nouveau quiz dans le cours.';
echo '</div>';

if ($pendingcount == 0) {
    echo '<div class="alert alert-warning">Aucune question en attente ou approuvée. ';
    echo '<a href="' . new moodle_url('/local/dreamu_qcm/generate.php', ['courseid' => $courseid]) . '">Générer des questions d\'abord.</a></div>';
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

echo '<div class="mt-3">';
echo '<button type="submit" class="btn btn-warning">Créer le Quiz</button> ';
echo '<a href="' . new moodle_url('/local/dreamu_qcm/review.php', ['courseid' => $courseid]) . '" class="btn btn-secondary">Annuler</a>';
echo '</div>';

echo '</form>';

echo $OUTPUT->footer();
