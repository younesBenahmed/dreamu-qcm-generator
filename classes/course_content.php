<?php
namespace local_dreamu_qcm;

defined('MOODLE_INTERNAL') || die();

/**
 * Extracts text content from course resources.
 */
class course_content {

    /**
     * Get all available resources in a course with their content.
     *
     * @param int $courseid
     * @param bool $includehidden Include hidden resources
     * @return array List of resources with id, name, type, content
     */
    public static function get_course_resources(int $courseid, bool $includehidden = false): array {
        global $DB;

        $modinfo = get_fast_modinfo($courseid);
        $resources = [];

        foreach ($modinfo->get_cms() as $cm) {
            // Skip if hidden and not requested.
            if (!$cm->visible && !$includehidden) {
                continue;
            }

            // Skip if not a content module.
            $supported = ['resource', 'page', 'label', 'book', 'folder', 'url', 'assign'];
            if (!in_array($cm->modname, $supported)) {
                continue;
            }

            $resources[] = (object)[
                'cmid' => $cm->id,
                'name' => $cm->name,
                'modname' => $cm->modname,
                'section' => $cm->sectionnum,
                'visible' => $cm->visible,
            ];
        }

        return $resources;
    }

    /**
     * Extract text content from selected course modules.
     *
     * @param int $courseid
     * @param array $cmids List of course module IDs to extract
     * @param bool $includehidden
     * @return string Combined text content
     */
    public static function extract_content(int $courseid, array $cmids, bool $includehidden = false): string {
        global $DB;

        $modinfo = get_fast_modinfo($courseid);
        $text = '';

        foreach ($cmids as $cmid) {
            try {
                $cm = $modinfo->get_cm($cmid);
            } catch (\Exception $e) {
                continue;
            }

            if (!$cm->visible && !$includehidden) {
                continue;
            }

            $content = self::extract_module_content($cm);
            if (!empty(trim($content))) {
                $text .= "=== {$cm->name} ({$cm->modname}) ===\n{$content}\n\n";
            }
        }

        return trim($text);
    }

    /**
     * Extract text from a single course module.
     */
    private static function extract_module_content(\cm_info $cm): string {
        global $DB;

        $text = '';

        switch ($cm->modname) {
            case 'page':
                $page = $DB->get_record('page', ['id' => $cm->instance]);
                if ($page) {
                    $text = html_to_text($page->content, 0, false);
                }
                break;

            case 'label':
                $label = $DB->get_record('label', ['id' => $cm->instance]);
                if ($label) {
                    $text = html_to_text($label->intro, 0, false);
                }
                break;

            case 'book':
                $chapters = $DB->get_records('book_chapters', ['bookid' => $cm->instance], 'pagenum');
                foreach ($chapters as $ch) {
                    $text .= "--- Chapter: {$ch->title} ---\n";
                    $text .= html_to_text($ch->content, 0, false) . "\n\n";
                }
                break;

            case 'resource':
            case 'folder':
                $text = self::extract_files($cm);
                break;

            case 'assign':
                $assign = $DB->get_record('assign', ['id' => $cm->instance]);
                if ($assign) {
                    $text = html_to_text($assign->intro, 0, false);
                }
                break;
        }

        return $text;
    }

    /**
     * Extract text from files attached to a module.
     */
    private static function extract_files(\cm_info $cm): string {
        $fs = get_file_storage();
        $context = \context_module::instance($cm->id);
        $text = '';

        $areas = ['content', 'intro'];
        foreach ($areas as $area) {
            $files = $fs->get_area_files($context->id, 'mod_' . $cm->modname, $area, false, 'filename', false);
            foreach ($files as $file) {
                $filename = $file->get_filename();
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

                $textexts = ['txt', 'md', 'html', 'htm', 'csv', 'json', 'xml', 'py', 'java', 'c', 'cpp', 'h', 'php', 'js'];

                if (in_array($ext, $textexts)) {
                    $content = $file->get_content();
                    if (strlen($content) > 10000) {
                        $content = substr($content, 0, 10000) . "\n[... truncated ...]";
                    }
                    $text .= "--- File: {$filename} ---\n{$content}\n\n";
                } elseif ($ext === 'pdf') {
                    $text .= "--- File: {$filename} (PDF document) ---\n";
                    // Try to extract text with pdftotext.
                    $tmpfile = tempnam(sys_get_temp_dir(), 'qcm_pdf_');
                    file_put_contents($tmpfile, $file->get_content());
                    $extracted = @shell_exec('pdftotext ' . escapeshellarg($tmpfile) . ' - 2>/dev/null');
                    @unlink($tmpfile);
                    if (!empty(trim($extracted))) {
                        if (strlen($extracted) > 15000) {
                            $extracted = substr($extracted, 0, 15000) . "\n[... PDF tronque ...]";
                        }
                        $text .= $extracted . "\n\n";
                    } else {
                        $text .= "[Impossible d'extraire le contenu du PDF]\n\n";
                    }
                } else {
                    $text .= "--- File: {$filename} ({$ext}) ---\n\n";
                }
            }
        }

        return $text;
    }
}
