<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Add QCM generator link to course navigation.
 */
function local_dreamu_qcm_extend_navigation_course($navigation, $course, $context) {
    if (has_capability('local/dreamu_qcm:generate', $context)) {
        $url = new moodle_url('/local/dreamu_qcm/generate.php', ['courseid' => $course->id]);
        $navigation->add(
            get_string('generate', 'local_dreamu_qcm'),
            $url,
            navigation_node::TYPE_CUSTOM,
            null,
            'dreamu_qcm_generate',
            new pix_icon('i/questions', '')
        );
    }
}
