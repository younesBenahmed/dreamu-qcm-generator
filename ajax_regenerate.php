<?php
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');

$qid = required_param('qid', PARAM_INT);
$courseid = required_param('courseid', PARAM_INT);

require_login();
require_sesskey();
$context = context_course::instance($courseid);
require_capability('local/dreamu_qcm:generate', $context);

global $DB, $USER;

$old = $DB->get_record('local_dreamu_qcm', ['id' => $qid, 'courseid' => $courseid], '*', MUST_EXIST);

// Only allow regeneration for pending or approved questions.
if (!in_array($old->status, ['pending', 'approved'])) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Seules les questions en attente ou approuvees peuvent etre regenerees']);
    die();
}

// Extract course content.
$cmids = [];
$modinfo = get_fast_modinfo($courseid);
foreach ($modinfo->get_cms() as $cm) {
    $cmids[] = $cm->id;
}
$content = \local_dreamu_qcm\course_content::extract_content($courseid, $cmids, false);

// Generate 1 replacement question of the same type.
$qtype = $old->qtype ?: 'multichoice';
$gen = new \local_dreamu_qcm\qcm_generator();
try {
    $questions = $gen->generate($content, 1, $old->difficulty ?: 'medium', 'fr', [$qtype]);

    if (empty($questions)) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'La generation n\'a produit aucune question']);
        die();
    }

    $q = $questions[0];

    // Update the existing record with new content.
    $old->question = $q->question;
    $old->optiona = $q->optiona;
    $old->optionb = $q->optionb;
    $old->optionc = $q->optionc;
    $old->optiond = $q->optiond;
    $old->correct = $q->correct;
    $old->explanation = $q->explanation;
    $old->extra_data = $q->extra_data ?? null;
    $old->status = 'pending';
    $old->verified = null;
    $old->verification_note = null;
    $old->timecreated = time();
    $DB->update_record('local_dreamu_qcm', $old);

    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok', 'message' => 'Question regeneree']);
} catch (\Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
