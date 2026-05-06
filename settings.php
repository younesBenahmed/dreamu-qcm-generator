<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_dreamu_qcm', get_string('pluginname', 'local_dreamu_qcm'));

    $settings->add(new admin_setting_configtext(
        'local_dreamu_qcm/api_endpoint',
        get_string('api_endpoint', 'local_dreamu_qcm'),
        get_string('api_endpoint_desc', 'local_dreamu_qcm'),
        'http://100.76.166.71:8200/v1/chat/completions'
    ));

    $settings->add(new admin_setting_configtext(
        'local_dreamu_qcm/api_key',
        get_string('api_key', 'local_dreamu_qcm'),
        '',
        'sk-dummy'
    ));

    $settings->add(new admin_setting_configtext(
        'local_dreamu_qcm/model_name',
        get_string('model_name', 'local_dreamu_qcm'),
        '',
        'general'
    ));

    $ADMIN->add('localplugins', $settings);
}
