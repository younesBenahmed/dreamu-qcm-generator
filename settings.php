<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_dreamu_qcm', get_string('pluginname', 'local_dreamu_qcm'));

    $settings->add(new admin_setting_heading(
        'local_dreamu_qcm/heading_api',
        'Configuration IA',
        'Par defaut, le QCM Generator utilise la meme configuration que le AI Grader. '
        . 'Laissez vide pour utiliser les parametres du AI Grader, ou remplissez pour une config separee.'
    ));

    $settings->add(new admin_setting_configtext(
        'local_dreamu_qcm/api_endpoint',
        'Endpoint API (optionnel)',
        'Laisser vide pour utiliser la config du AI Grader',
        '',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configtext(
        'local_dreamu_qcm/api_key',
        'Cle API (optionnel)',
        'Laisser vide pour utiliser la config du AI Grader',
        '',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_dreamu_qcm/model_name',
        'Nom du modele (optionnel)',
        'Laisser vide pour utiliser la config du AI Grader',
        '',
        PARAM_TEXT
    ));

    $ADMIN->add('localplugins', $settings);
}
