<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/questionlib.php');

$courseid = required_param('courseid', PARAM_INT);
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_login($course);
require_capability('local/dreamu_qcm:generate', $context);

$PAGE->set_url(new moodle_url('/local/dreamu_qcm/generate.php', ['courseid' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_title(get_string('generate_title', 'local_dreamu_qcm'));
$PAGE->set_heading($course->fullname . ' - ' . get_string('generate_title', 'local_dreamu_qcm'));

// Handle form submission.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $cmids = optional_param_array('cmids', [], PARAM_INT);
    $numquestions = optional_param('numquestions', 10, PARAM_INT);
    $difficulty = optional_param('difficulty', 'medium', PARAM_ALPHA);
    $includehidden = optional_param('includehidden', 0, PARAM_BOOL);

    if (empty($cmids)) {
        redirect($PAGE->url, get_string('no_content', 'local_dreamu_qcm'), null, \core\output\notification::NOTIFY_ERROR);
    }

    // Extract content.
    $content = \local_dreamu_qcm\course_content::extract_content($courseid, $cmids, $includehidden);

    if (empty(trim($content))) {
        redirect($PAGE->url, get_string('no_content', 'local_dreamu_qcm'), null, \core\output\notification::NOTIFY_ERROR);
    }

    // Generate questions.
    try {
        $generator = new \local_dreamu_qcm\qcm_generator();
        $questions = $generator->generate($content, $numquestions, $difficulty, 'fr');

        // Store in DB.
        foreach ($questions as $q) {
            $record = new stdClass();
            $record->courseid = $courseid;
            $record->question = $q->question;
            $record->optiona = $q->optiona;
            $record->optionb = $q->optionb;
            $record->optionc = $q->optionc;
            $record->optiond = $q->optiond;
            $record->correct = $q->correct;
            $record->difficulty = $difficulty;
            $record->explanation = $q->explanation;
            $record->status = 'pending';
            $record->createdby = $USER->id;
            $record->timecreated = time();
            $DB->insert_record('local_dreamu_qcm', $record);
        }

        redirect(
            new moodle_url('/local/dreamu_qcm/review.php', ['courseid' => $courseid]),
            count($questions) . ' questions generated!',
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (\Exception $e) {
        redirect($PAGE->url, 'Error: ' . $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

// Display form.
echo $OUTPUT->header();

$resources = \local_dreamu_qcm\course_content::get_course_resources($courseid, true);

echo '<form method="post" action="">';
echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';

echo '<h3>' . get_string('select_resources', 'local_dreamu_qcm') . '</h3>';

if (empty($resources)) {
    echo '<div class="alert alert-warning">No resources found in this course. Add some content first.</div>';
} else {
    echo '<div class="form-group">';
    $currentsection = -1;
    foreach ($resources as $r) {
        if ($r->section !== $currentsection) {
            if ($currentsection >= 0) echo '</div>';
            echo '<div class="ml-2 mb-3">';
            echo '<h5>Section ' . $r->section . '</h5>';
            $currentsection = $r->section;
        }
        $hidden = $r->visible ? '' : ' <span class="badge badge-secondary">Hidden</span>';
        $icon = ['resource' => '📄', 'page' => '📝', 'label' => '🏷️', 'book' => '📖', 'folder' => '📁', 'assign' => '📋'][$r->modname] ?? '📦';
        echo '<div class="form-check ml-3">';
        echo '<input class="form-check-input" type="checkbox" name="cmids[]" value="' . $r->cmid . '" id="cm_' . $r->cmid . '" checked>';
        echo '<label class="form-check-label" for="cm_' . $r->cmid . '">' . $icon . ' ' . format_string($r->name) . ' (' . $r->modname . ')' . $hidden . '</label>';
        echo '</div>';
    }
    echo '</div>';
}

echo '<hr>';

echo '<div class="form-group row">';
echo '<label class="col-sm-3 col-form-label">' . get_string('num_questions', 'local_dreamu_qcm') . '</label>';
echo '<div class="col-sm-3"><select name="numquestions" class="form-control">';
foreach ([5, 10, 15, 20, 30] as $n) {
    $sel = ($n == 10) ? ' selected' : '';
    echo "<option value=\"{$n}\"{$sel}>{$n}</option>";
}
echo '</select></div></div>';

echo '<div class="form-group row">';
echo '<label class="col-sm-3 col-form-label">' . get_string('difficulty', 'local_dreamu_qcm') . '</label>';
echo '<div class="col-sm-3"><select name="difficulty" class="form-control">';
echo '<option value="easy">' . get_string('difficulty_easy', 'local_dreamu_qcm') . '</option>';
echo '<option value="medium" selected>' . get_string('difficulty_medium', 'local_dreamu_qcm') . '</option>';
echo '<option value="hard">' . get_string('difficulty_hard', 'local_dreamu_qcm') . '</option>';
echo '</select></div></div>';

echo '<div class="form-group row">';
echo '<label class="col-sm-3 col-form-label">' . get_string('include_hidden', 'local_dreamu_qcm') . '</label>';
echo '<div class="col-sm-3"><input type="checkbox" name="includehidden" value="1"></div>';
echo '</div>';

echo '<button type="submit" class="btn btn-primary">' . get_string('generate', 'local_dreamu_qcm') . '</button>';
echo '</form>';

echo $OUTPUT->footer();
