<?php
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');

$courseid = required_param('courseid', PARAM_INT);
$cmids_str = required_param('cmids', PARAM_TEXT);
require_login();
require_sesskey();

$context = context_course::instance($courseid);
require_capability('local/dreamu_qcm:generate', $context);

$cmids = array_map('intval', explode(',', $cmids_str));
$cmids = array_filter($cmids, function($id) { return $id > 0; });

$content = \local_dreamu_qcm\course_content::extract_content($courseid, $cmids, false);

$chars = strlen($content);
$words = str_word_count($content);
$tokens = (int)($chars / 4);

header('Content-Type: application/json');
echo json_encode([
    'content' => $content,
    'chars' => number_format($chars),
    'words' => number_format($words),
    'tokens' => '~' . number_format($tokens),
]);
