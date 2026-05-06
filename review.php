<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/questionlib.php');

$courseid = required_param('courseid', PARAM_INT);
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_login($course);
require_capability('local/dreamu_qcm:generate', $context);

$PAGE->set_url(new moodle_url('/local/dreamu_qcm/review.php', ['courseid' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_title(get_string('review_title', 'local_dreamu_qcm'));
$PAGE->set_heading($course->fullname . ' - ' . get_string('review_title', 'local_dreamu_qcm'));

// Handle actions.
$action = optional_param('action', '', PARAM_ALPHA);
$qid = optional_param('qid', 0, PARAM_INT);

if ($action === 'approve' && $qid && confirm_sesskey()) {
    $DB->set_field('local_dreamu_qcm', 'status', 'approved', ['id' => $qid, 'courseid' => $courseid]);
}
if ($action === 'reject' && $qid && confirm_sesskey()) {
    $DB->set_field('local_dreamu_qcm', 'status', 'rejected', ['id' => $qid, 'courseid' => $courseid]);
}
if ($action === 'approveall' && confirm_sesskey()) {
    $DB->set_field_select('local_dreamu_qcm', 'status', 'approved',
        "courseid = :courseid AND status = 'pending'", ['courseid' => $courseid]);
}
if ($action === 'importall' && confirm_sesskey()) {
    // First approve all pending.
    $DB->set_field_select('local_dreamu_qcm', 'status', 'approved',
        "courseid = :courseid AND status = 'pending'", ['courseid' => $courseid]);
    // Import approved.
    $approved = $DB->get_records('local_dreamu_qcm', ['courseid' => $courseid, 'status' => 'approved']);
    $ids = array_keys($approved);
    if (!empty($ids)) {
        $count = \local_dreamu_qcm\qcm_generator::import_to_bank($courseid, $ids);
        redirect($PAGE->url, "{$count} questions imported to question bank!", null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

echo $OUTPUT->header();

// Get pending questions.
$questions = $DB->get_records('local_dreamu_qcm', ['courseid' => $courseid], 'timecreated DESC');

$pending = array_filter($questions, fn($q) => $q->status === 'pending');
$approved = array_filter($questions, fn($q) => $q->status === 'approved');
$imported = array_filter($questions, fn($q) => $q->status === 'imported');

echo '<h3>' . get_string('review_title', 'local_dreamu_qcm') . '</h3>';
echo '<p>Pending: <strong>' . count($pending) . '</strong> | Approved: <strong>' . count($approved) . '</strong> | Imported: <strong>' . count($imported) . '</strong></p>';

if (!empty($pending) || !empty($approved)) {
    $importurl = new moodle_url($PAGE->url, ['action' => 'importall', 'sesskey' => sesskey()]);
    echo '<a href="' . $importurl . '" class="btn btn-success mb-3">' . get_string('approve_all', 'local_dreamu_qcm') . '</a> ';

    $genurl = new moodle_url('/local/dreamu_qcm/generate.php', ['courseid' => $courseid]);
    echo '<a href="' . $genurl . '" class="btn btn-primary mb-3">Generate More</a>';
}

$letters = ['a' => 'A', 'b' => 'B', 'c' => 'C', 'd' => 'D'];

foreach ($questions as $q) {
    if ($q->status === 'rejected' || $q->status === 'imported') continue;

    $statusbadge = [
        'pending' => '<span class="badge badge-warning">Pending</span>',
        'approved' => '<span class="badge badge-success">Approved</span>',
    ][$q->status] ?? '';

    $diffbadge = [
        'easy' => '<span class="badge badge-info">Easy</span>',
        'medium' => '<span class="badge badge-primary">Medium</span>',
        'hard' => '<span class="badge badge-danger">Hard</span>',
    ][$q->difficulty] ?? '';

    echo '<div class="card mb-3">';
    echo '<div class="card-header d-flex justify-content-between">';
    echo '<strong>Q' . $q->id . '</strong> ' . $diffbadge . ' ' . $statusbadge;
    echo '</div>';
    echo '<div class="card-body">';
    echo '<p class="card-text"><strong>' . format_string($q->question) . '</strong></p>';

    $options = ['a' => $q->optiona, 'b' => $q->optionb, 'c' => $q->optionc, 'd' => $q->optiond];
    echo '<ul class="list-group mb-2">';
    foreach ($options as $letter => $text) {
        $class = ($letter === $q->correct) ? 'list-group-item list-group-item-success' : 'list-group-item';
        $mark = ($letter === $q->correct) ? ' ✅' : '';
        echo '<li class="' . $class . '"><strong>' . $letters[$letter] . ')</strong> ' . format_string($text) . $mark . '</li>';
    }
    echo '</ul>';

    if (!empty($q->explanation)) {
        echo '<p class="text-muted"><em>💡 ' . format_string($q->explanation) . '</em></p>';
    }

    if ($q->status === 'pending') {
        $approveurl = new moodle_url($PAGE->url, ['action' => 'approve', 'qid' => $q->id, 'sesskey' => sesskey()]);
        $rejecturl = new moodle_url($PAGE->url, ['action' => 'reject', 'qid' => $q->id, 'sesskey' => sesskey()]);
        echo '<a href="' . $approveurl . '" class="btn btn-sm btn-success">✅ Approve</a> ';
        echo '<a href="' . $rejecturl . '" class="btn btn-sm btn-danger">❌ Reject</a>';
    }

    echo '</div></div>';
}

if (empty($questions)) {
    echo '<div class="alert alert-info">No questions generated yet. ';
    echo '<a href="' . new moodle_url('/local/dreamu_qcm/generate.php', ['courseid' => $courseid]) . '">Generate some!</a></div>';
}

echo $OUTPUT->footer();
