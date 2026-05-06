<?php
defined('MOODLE_INTERNAL') || die();

$capabilities = [
    'local/dreamu_qcm:generate' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],
];
