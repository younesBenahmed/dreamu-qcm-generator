<?php
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');

$qid = required_param('qid', PARAM_INT);
$field = required_param('field', PARAM_ALPHANUMEXT);
$value = required_param('value', PARAM_RAW);
$courseid = required_param('courseid', PARAM_INT);

require_login();
require_sesskey();
$context = context_course::instance($courseid);
require_capability('local/dreamu_qcm:generate', $context);

global $DB;
$allowed_fields = ['question', 'optiona', 'optionb', 'optionc', 'optiond', 'correct', 'explanation'];
if (!in_array($field, $allowed_fields)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid field']);
    die();
}

$record = $DB->get_record('local_dreamu_qcm', ['id' => $qid, 'courseid' => $courseid], '*', MUST_EXIST);
$DB->set_field('local_dreamu_qcm', $field, $value, ['id' => $qid]);

header('Content-Type: application/json');
echo json_encode(['status' => 'ok', 'field' => $field, 'value' => $value]);
