<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/questionlib.php');

$courseid = required_param('courseid', PARAM_INT);
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_login($course);
require_capability('local/dreamu_qcm:generate', $context);

$PAGE->set_url(new moodle_url('/local/dreamu_qcm/review.php', ['courseid' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_title(get_string('review_title', 'local_dreamu_qcm'));
$PAGE->set_heading($course->fullname . ' - ' . get_string('review_title', 'local_dreamu_qcm'));

// Handle actions.
$action = optional_param('action', '', PARAM_ALPHA);
$qid = optional_param('qid', 0, PARAM_INT);

if ($action === 'approve' && $qid && confirm_sesskey()) {
    $DB->set_field('local_dreamu_qcm', 'status', 'approved', ['id' => $qid, 'courseid' => $courseid]);
}
if ($action === 'reject' && $qid && confirm_sesskey()) {
    $DB->set_field('local_dreamu_qcm', 'status', 'rejected', ['id' => $qid, 'courseid' => $courseid]);
}
if ($action === 'approveall' && confirm_sesskey()) {
    $DB->set_field_select('local_dreamu_qcm', 'status', 'approved',
        "courseid = :courseid AND status = 'pending'", ['courseid' => $courseid]);
}
if ($action === 'importall' && confirm_sesskey()) {
    $DB->set_field_select('local_dreamu_qcm', 'status', 'approved',
        "courseid = :courseid AND status = 'pending'", ['courseid' => $courseid]);
    redirect(
        new moodle_url('/local/dreamu_qcm/create_quiz.php', ['courseid' => $courseid]),
        'Questions approuvées. Créez maintenant le quiz pour les importer dans son contexte.',
        null,
        \core\output\notification::NOTIFY_INFO
    );
}

echo $OUTPUT->header();

// Get all questions.
$questions = $DB->get_records('local_dreamu_qcm', ['courseid' => $courseid], 'timecreated DESC');

$pending = array_filter($questions, fn($q) => $q->status === 'pending');
$approved = array_filter($questions, fn($q) => $q->status === 'approved');
$imported = array_filter($questions, fn($q) => $q->status === 'imported');

echo '<h3>' . get_string('review_title', 'local_dreamu_qcm') . '</h3>';
echo '<p>En attente : <strong>' . count($pending) . '</strong> | ';
echo 'Approuvées : <strong>' . count($approved) . '</strong> | ';
echo 'Importées : <strong>' . count($imported) . '</strong></p>';

// Count by type.
$typecounts = [];
foreach ($questions as $q) {
    if ($q->status === 'rejected' || $q->status === 'imported') continue;
    $t = $q->qtype ?? 'multichoice';
    $typecounts[$t] = ($typecounts[$t] ?? 0) + 1;
}
if (!empty($typecounts)) {
    echo '<p>';
    $typelabels = [
        'multichoice' => 'QCM',
        'truefalse' => 'Vrai/Faux',
        'shortanswer' => 'Réponse courte',
        'matching' => 'Correspondance',
        'numerical' => 'Numérique',
    ];
    $parts = [];
    foreach ($typecounts as $type => $cnt) {
        $label = $typelabels[$type] ?? $type;
        $parts[] = "{$label}: {$cnt}";
    }
    echo implode(' | ', $parts);
    echo '</p>';
}

if (!empty($pending) || !empty($approved)) {
    $approveurl = new moodle_url($PAGE->url, ['action' => 'approveall', 'sesskey' => sesskey()]);
    echo '<a href="' . $approveurl . '" class="btn btn-success mb-3">Approuver tout</a> ';

    $createquizurl = new moodle_url('/local/dreamu_qcm/create_quiz.php', ['courseid' => $courseid]);
    echo '<a href="' . $createquizurl . '" class="btn btn-warning mb-3">Créer un Quiz</a> ';

    $genurl = new moodle_url('/local/dreamu_qcm/generate.php', ['courseid' => $courseid]);
    echo '<a href="' . $genurl . '" class="btn btn-primary mb-3">Générer plus</a>';
}

foreach ($questions as $q) {
    if ($q->status === 'rejected' || $q->status === 'imported') continue;

    $qtype = $q->qtype ?? 'multichoice';

    $statusbadge = [
        'pending' => '<span class="badge badge-warning bg-warning">En attente</span>',
        'approved' => '<span class="badge badge-success bg-success">Approuvée</span>',
    ][$q->status] ?? '';

    $diffbadge = [
        'easy' => '<span class="badge badge-info bg-info">Facile</span>',
        'medium' => '<span class="badge badge-primary bg-primary">Moyen</span>',
        'hard' => '<span class="badge badge-danger bg-danger">Difficile</span>',
    ][$q->difficulty] ?? '';

    $typebadge = [
        'multichoice' => '<span class="badge badge-secondary bg-secondary">QCM</span>',
        'truefalse' => '<span class="badge badge-dark bg-dark text-white">Vrai/Faux</span>',
        'shortanswer' => '<span class="badge badge-light bg-light text-dark border">Réponse courte</span>',
        'matching' => '<span class="badge badge-info bg-info">Correspondance</span>',
        'numerical' => '<span class="badge badge-warning bg-warning">Numérique</span>',
    ][$qtype] ?? '<span class="badge badge-secondary">' . $qtype . '</span>';

    echo '<div class="card mb-3">';
    echo '<div class="card-header d-flex justify-content-between">';
    // Verification badge.
    $verifybadge = '';
    $verified_val = $q->verified ?? null;
    if ($verified_val !== null && $verified_val !== '') {
        if ((int)$verified_val === 1) {
            $verifybadge = ' <span class="badge badge-success bg-success">V&eacute;rifi&eacute; &#10003;</span>';
        } else {
            $verifynote = htmlspecialchars($q->verification_note ?? '', ENT_QUOTES);
            $verifybadge = ' <span class="badge badge-danger bg-danger" title="' . $verifynote . '">Non v&eacute;rifi&eacute; &#10007;</span>';
        }
    } else {
        $verifybadge = ' <span class="badge badge-secondary bg-secondary">Non v&eacute;rifi&eacute;</span>';
    }

    echo '<span><strong>Q' . $q->id . '</strong> ' . $typebadge . ' ' . $diffbadge . ' ' . $statusbadge . $verifybadge . '</span>';
    echo '</div>';
    echo '<div class="card-body">';
    echo '<p class="card-text"><strong>' . format_string($q->question) . '</strong></p>';

    // Render based on type.
    switch ($qtype) {
        case 'multichoice':
            render_multichoice($q);
            break;
        case 'truefalse':
            render_truefalse($q);
            break;
        case 'shortanswer':
            render_shortanswer($q);
            break;
        case 'matching':
            render_matching($q);
            break;
        case 'numerical':
            render_numerical($q);
            break;
    }

    // Show verification warning if question failed verification.
    if (isset($q->verified) && $q->verified !== null && $q->verified !== '' && (int)$q->verified === 0 && !empty($q->verification_note)) {
        echo '<div class="alert alert-danger mt-2 mb-2 py-1 px-2"><small><strong>Probl&egrave;me de v&eacute;rification :</strong> ' . format_string($q->verification_note) . '</small></div>';
    }

    if (!empty($q->explanation)) {
        echo '<p class="text-muted mt-2"><em>' . format_string($q->explanation) . '</em></p>';
    }

    if ($q->status === 'pending') {
        $approveurl = new moodle_url($PAGE->url, ['action' => 'approve', 'qid' => $q->id, 'sesskey' => sesskey()]);
        $rejecturl = new moodle_url($PAGE->url, ['action' => 'reject', 'qid' => $q->id, 'sesskey' => sesskey()]);
        echo '<a href="' . $approveurl . '" class="btn btn-sm btn-success">Approuver</a> ';
        echo '<a href="' . $rejecturl . '" class="btn btn-sm btn-danger">Rejeter</a>';
    }

    echo '</div></div>';
}

if (empty($questions)) {
    echo '<div class="alert alert-info">Aucune question générée pour le moment. ';
    echo '<a href="' . new moodle_url('/local/dreamu_qcm/generate.php', ['courseid' => $courseid]) . '">Générer des questions !</a></div>';
}

echo $OUTPUT->footer();

// --- Render functions ---

function render_multichoice($q) {
    $letters = ['a' => 'A', 'b' => 'B', 'c' => 'C', 'd' => 'D'];
    $options = ['a' => $q->optiona, 'b' => $q->optionb, 'c' => $q->optionc, 'd' => $q->optiond];
    echo '<ul class="list-group mb-2">';
    foreach ($options as $letter => $text) {
        $class = ($letter === $q->correct) ? 'list-group-item list-group-item-success' : 'list-group-item';
        echo '<li class="' . $class . '"><strong>' . $letters[$letter] . ')</strong> ' . format_string($text) . '</li>';
    }
    echo '</ul>';
}

function render_truefalse($q) {
    $istrue = ($q->correct === 'true');
    echo '<div class="mb-2">';
    echo '<span class="badge badge-' . ($istrue ? 'success bg-success' : 'secondary bg-secondary') . ' p-2 mr-2">';
    echo 'VRAI' . ($istrue ? ' (correct)' : '') . '</span>';
    echo '<span class="badge badge-' . (!$istrue ? 'success bg-success' : 'secondary bg-secondary') . ' p-2">';
    echo 'FAUX' . (!$istrue ? ' (correct)' : '') . '</span>';
    echo '</div>';
}

function render_shortanswer($q) {
    echo '<div class="mb-2">';
    echo '<span class="badge badge-success bg-success p-2">Réponse : ' . format_string($q->correct) . '</span>';
    $extra = json_decode($q->extra_data ?? '{}', true);
    $alts = $extra['alternatives'] ?? [];
    if (!empty($alts)) {
        echo '<br><small class="text-muted">Aussi accepté : ' . implode(', ', array_map('format_string', $alts)) . '</small>';
    }
    echo '</div>';
}

function render_matching($q) {
    $extra = json_decode($q->extra_data ?? '{}', true);
    $pairs = $extra['pairs'] ?? [];
    if (!empty($pairs)) {
        echo '<table class="table table-bordered table-sm mb-2">';
        echo '<thead><tr><th>Terme</th><th>Définition</th></tr></thead><tbody>';
        foreach ($pairs as $pair) {
            echo '<tr><td><strong>' . format_string($pair['term'] ?? '') . '</strong></td>';
            echo '<td>' . format_string($pair['definition'] ?? '') . '</td></tr>';
        }
        echo '</tbody></table>';
    }
}

function render_numerical($q) {
    $extra = json_decode($q->extra_data ?? '{}', true);
    $tolerance = $extra['tolerance'] ?? 0;
    $unit = $extra['unit'] ?? '';
    echo '<div class="mb-2">';
    echo '<span class="badge badge-success bg-success p-2">Réponse : ' . format_string($q->correct);
    if (!empty($unit)) echo ' ' . format_string($unit);
    echo '</span>';
    if ($tolerance > 0) {
        echo ' <small class="text-muted">(tolérance : +/- ' . $tolerance . ')</small>';
    }
    echo '</div>';
}
