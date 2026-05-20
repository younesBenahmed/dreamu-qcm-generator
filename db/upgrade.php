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

    if ($oldversion < 2026050603) {
        $table = new xmldb_table('local_dreamu_qcm');

        $field = new xmldb_field('qtype', XMLDB_TYPE_CHAR, '20', null,
            XMLDB_NOTNULL, null, 'multichoice', 'courseid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('extra_data', XMLDB_TYPE_TEXT, null, null,
            null, null, null, 'explanation');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        foreach (['optiona', 'optionb', 'optionc', 'optiond'] as $fieldname) {
            $field = new xmldb_field($fieldname, XMLDB_TYPE_TEXT, null, null,
                null, null, null);
            if ($dbman->field_exists($table, $field)) {
                $dbman->change_field_notnull($table, $field);
            }
        }

        $field = new xmldb_field('correct', XMLDB_TYPE_CHAR, '255', null,
            XMLDB_NOTNULL, null, 'a');
        if ($dbman->field_exists($table, $field)) {
            $dbman->change_field_precision($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026050603, 'local', 'dreamu_qcm');
    }

    if ($oldversion < 2026050604) {
        upgrade_plugin_savepoint(true, 2026050604, 'local', 'dreamu_qcm');
    }

    if ($oldversion < 2026050605) {
        upgrade_plugin_savepoint(true, 2026050605, 'local', 'dreamu_qcm');
    }

    if ($oldversion < 2026050606) {
        upgrade_plugin_savepoint(true, 2026050606, 'local', 'dreamu_qcm');
    }

    if ($oldversion < 2026052000) {
        $table = new xmldb_table('local_dreamu_qcm');

        // Add verified field (nullable int, 1=valid, 0=invalid, null=not verified).
        $field = new xmldb_field('verified', XMLDB_TYPE_INTEGER, '1', null,
            null, null, null, 'status');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add verification_note field (nullable text).
        $field2 = new xmldb_field('verification_note', XMLDB_TYPE_TEXT, null, null,
            null, null, null, 'verified');
        if (!$dbman->field_exists($table, $field2)) {
            $dbman->add_field($table, $field2);
        }

        upgrade_plugin_savepoint(true, 2026052000, 'local', 'dreamu_qcm');
    }

    return true;
}
