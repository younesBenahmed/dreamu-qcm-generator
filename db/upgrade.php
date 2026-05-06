<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_local_dreamu_qcm_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026050601) {
        $table = new xmldb_table('local_dreamu_qcm');

        // Add qtype field.
        $field = new xmldb_field('qtype', XMLDB_TYPE_CHAR, '20', null,
            XMLDB_NOTNULL, null, 'multichoice', 'courseid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add extra_data field.
        $field2 = new xmldb_field('extra_data', XMLDB_TYPE_TEXT, null, null,
            null, null, null, 'explanation');
        if (!$dbman->field_exists($table, $field2)) {
            $dbman->add_field($table, $field2);
        }

        // Make option fields nullable (truefalse/shortanswer don't need 4 options).
        // Change correct field to char(255) for longer answers.
        $correctfield = new xmldb_field('correct', XMLDB_TYPE_CHAR, '255', null,
            XMLDB_NOTNULL, null, 'a');
        $dbman->change_field_precision($table, $correctfield);

        upgrade_plugin_savepoint(true, 2026050601, 'local', 'dreamu_qcm');
    }

    return true;
}
