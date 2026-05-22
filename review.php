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
echo '<script>var COURSEID = ' . $courseid . ';</script>';

// Focus styles for accessibility.
echo '<style>button:focus, a:focus, select:focus, input:focus { outline: 2px solid #0d6efd; outline-offset: 2px; }</style>';

// Get all questions.
$questions = $DB->get_records('local_dreamu_qcm', ['courseid' => $courseid], 'timecreated DESC');

$pending = array_filter($questions, fn($q) => $q->status === 'pending');
$approved = array_filter($questions, fn($q) => $q->status === 'approved');
$imported = array_filter($questions, fn($q) => $q->status === 'imported');

echo '<h3>' . get_string('review_title', 'local_dreamu_qcm') . '</h3>';
echo '<div role="status"><p>En attente : <strong>' . count($pending) . '</strong> | ';
echo 'Approuvées : <strong>' . count($approved) . '</strong> | ';
echo 'Importées : <strong>' . count($imported) . '</strong></p></div>';

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
    echo '<a href="' . $approveurl . '" class="btn btn-success mb-3" aria-label="Approuver toutes les questions en attente">Approuver tout</a> ';

    $createquizurl = new moodle_url('/local/dreamu_qcm/create_quiz.php', ['courseid' => $courseid]);
    echo '<a href="' . $createquizurl . '" class="btn btn-warning mb-3">Créer un Quiz</a> ';

    $genurl = new moodle_url('/local/dreamu_qcm/generate.php', ['courseid' => $courseid]);
    echo '<a href="' . $genurl . '" class="btn btn-primary mb-3">Générer plus</a>';
}

// Filter UI.
echo '<div class="mb-3" style="display:flex; gap:16px; flex-wrap:wrap; align-items:center;">';
echo '    <div>';
echo '        <label class="small text-muted d-block" for="filter-type">Type</label>';
echo '        <select class="form-control form-control-sm" id="filter-type" aria-label="Filtrer par type de question" onchange="applyFilters()">';
echo '            <option value="all">Tous</option>';
echo '            <option value="multichoice">QCM</option>';
echo '            <option value="truefalse">Vrai/Faux</option>';
echo '            <option value="shortanswer">R&eacute;ponse courte</option>';
echo '            <option value="matching">Correspondance</option>';
echo '            <option value="numerical">Num&eacute;rique</option>';
echo '        </select>';
echo '    </div>';
echo '    <div>';
echo '        <label class="small text-muted d-block" for="filter-status">Statut</label>';
echo '        <select class="form-control form-control-sm" id="filter-status" aria-label="Filtrer par statut" onchange="applyFilters()">';
echo '            <option value="all">Tous</option>';
echo '            <option value="pending">En attente</option>';
echo '            <option value="approved">Approuv&eacute;e</option>';
echo '            <option value="imported">Import&eacute;e</option>';
echo '        </select>';
echo '    </div>';
echo '    <div>';
echo '        <label class="small text-muted d-block" for="filter-verified">V&eacute;rification</label>';
echo '        <select class="form-control form-control-sm" id="filter-verified" aria-label="Filtrer par statut de v&eacute;rification" onchange="applyFilters()">';
echo '            <option value="all">Tous</option>';
echo '            <option value="verified">V&eacute;rifi&eacute;</option>';
echo '            <option value="unverified">Non v&eacute;rifi&eacute;</option>';
echo '        </select>';
echo '    </div>';
echo '</div>';

// Group questions by generation session (5 min window).
// First, filter out rejected/imported for display grouping.
$displayquestions = array_filter($questions, fn($q) => $q->status !== 'rejected' && $q->status !== 'imported');

$sessions = [];
$current_session = [];
$last_time = 0;
foreach ($displayquestions as $q) {
    if ($last_time > 0 && abs($q->timecreated - $last_time) > 300) {
        if (!empty($current_session)) {
            $sessions[] = $current_session;
        }
        $current_session = [];
    }
    $current_session[] = $q;
    $last_time = $q->timecreated;
}
if (!empty($current_session)) {
    $sessions[] = $current_session;
}

$typelabels_render = [
    'multichoice' => 'QCM',
    'truefalse' => 'Vrai/Faux',
    'shortanswer' => 'Reponse courte',
    'matching' => 'Correspondance',
    'numerical' => 'Numerique',
];

$today = usergetmidnight(time());
$yesterday = $today - 86400;
$last_date_label = '';

foreach ($sessions as $si => $session) {
    $session_time = $session[0]->timecreated;
    $session_day = usergetmidnight($session_time);

    // Date group header.
    if ($session_day >= $today) {
        $date_label = "Aujourd'hui";
    } else if ($session_day >= $yesterday) {
        $date_label = "Hier";
    } else if ($session_day >= $today - 7 * 86400) {
        $date_label = userdate($session_time, '%A %d %B');
    } else {
        $date_label = userdate($session_time, '%d %B %Y');
    }

    if ($date_label !== $last_date_label) {
        echo '<h4 style="margin:28px 0 8px; padding-bottom:6px; border-bottom:2px solid #dee2e6; color:#495057; font-size:1.1em;">' . ucfirst($date_label) . '</h4>';
        $last_date_label = $date_label;
    }

    $date = userdate($session_time, '%H:%M');
    $count = count($session);
    $types = [];
    foreach ($session as $sq) {
        $t = $sq->qtype ?? 'multichoice';
        $types[$t] = ($types[$t] ?? 0) + 1;
    }
    $type_parts = [];
    foreach ($types as $t => $c) {
        $label = $typelabels_render[$t] ?? $t;
        $type_parts[] = "$c $label";
    }
    $type_str = implode(', ', $type_parts);

    echo '<div style="background:#e9ecef; padding:10px 16px; border-radius:8px; margin:12px 0 8px; cursor:pointer;" onclick="this.nextElementSibling.style.display = this.nextElementSibling.style.display === \'none\' ? \'\' : \'none\'">';
    echo '<strong>Session de ' . $date . '</strong> &mdash; ' . $count . ' questions (' . $type_str . ') ';
    echo '<span style="float:right;">&#9660;</span>';
    echo '</div>';
    echo '<div>'; // Session content wrapper.

    $qnum = 0;
    foreach ($session as $q) {
        $qnum++;
        $qtype = $q->qtype ?? 'multichoice';

        // Compute verified data attribute value.
        $verified_val = $q->verified ?? null;
        $verified_data = 'unverified';
        if ($verified_val !== null && $verified_val !== '' && (int)$verified_val === 1) {
            $verified_data = 'verified';
        }

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

        echo '<div class="card mb-3 question-card" data-qtype="' . htmlspecialchars($qtype) . '" data-status="' . htmlspecialchars($q->status) . '" data-verified="' . $verified_data . '">';
        echo '<div class="card-header d-flex justify-content-between">';
        // Verification badge.
        $verifybadge = '';
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

        echo '<span><strong>Q' . $qnum . '</strong> ' . $typebadge . ' ' . $diffbadge . ' ' . $statusbadge . $verifybadge . '</span>';
        echo '</div>';
        echo '<div class="card-body">';
        $editable = in_array($q->status, ['pending', 'approved']);
        if ($editable) {
            echo '<p class="card-text"><strong><span class="editable-field" data-qid="' . $q->id . '" data-field="question" data-value="' . htmlspecialchars($q->question, ENT_QUOTES) . '" style="cursor:pointer; border-bottom:1px dashed #ccc;" title="Cliquer pour modifier">' . format_string($q->question) . '</span></strong></p>';
        } else {
            echo '<p class="card-text"><strong>' . format_string($q->question) . '</strong></p>';
        }

        // Render based on type.
        switch ($qtype) {
            case 'multichoice':
                render_multichoice($q, $editable);
                break;
            case 'truefalse':
                render_truefalse($q, $editable);
                break;
            case 'shortanswer':
                render_shortanswer($q, $editable);
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
            if ($editable) {
                echo '<p class="text-muted mt-2"><em><span class="editable-field" data-qid="' . $q->id . '" data-field="explanation" data-value="' . htmlspecialchars($q->explanation, ENT_QUOTES) . '" style="cursor:pointer; border-bottom:1px dashed #ccc;" title="Cliquer pour modifier">' . format_string($q->explanation) . '</span></em></p>';
            } else {
                echo '<p class="text-muted mt-2"><em>' . format_string($q->explanation) . '</em></p>';
            }
        }

        if ($q->status === 'pending') {
            $approveurl = new moodle_url($PAGE->url, ['action' => 'approve', 'qid' => $q->id, 'sesskey' => sesskey()]);
            $rejecturl = new moodle_url($PAGE->url, ['action' => 'reject', 'qid' => $q->id, 'sesskey' => sesskey()]);
            echo '<a href="' . $approveurl . '" class="btn btn-sm btn-success" aria-label="Approuver la question ' . $q->id . '">Approuver</a> ';
            echo '<a href="' . $rejecturl . '" class="btn btn-sm btn-danger" aria-label="Rejeter la question ' . $q->id . '">Rejeter</a> ';
        }
        if (in_array($q->status, ['pending', 'approved'])) {
            echo '<button class="btn btn-warning btn-sm" onclick="regenerateQuestion(' . $q->id . ', this)" aria-label="Regenerer la question ' . $q->id . '">Regenerer</button>';
        }

        echo '</div></div>';
    }

    echo '</div>'; // End session content wrapper.
}

if (empty($questions)) {
    echo '<div class="alert alert-info">Aucune question générée pour le moment. ';
    echo '<a href="' . new moodle_url('/local/dreamu_qcm/generate.php', ['courseid' => $courseid]) . '">Générer des questions !</a></div>';
}

?>
<script>
function regenerateQuestion(qid, btn) {
    if (!confirm('Regenerer cette question ? L\'ancienne sera remplacee.')) return;
    btn.disabled = true;
    btn.textContent = 'Regeneration...';

    fetch(M.cfg.wwwroot + '/local/dreamu_qcm/ajax_regenerate.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'qid=' + qid + '&courseid=' + COURSEID + '&sesskey=' + M.cfg.sesskey
    }).then(function(r) { return r.json(); }).then(function(data) {
        if (data.status === 'ok') {
            window.location.reload();
        } else {
            alert('Erreur: ' + data.message);
            btn.disabled = false;
            btn.textContent = 'Regenerer';
        }
    }).catch(function() {
        alert('Erreur reseau');
        btn.disabled = false;
        btn.textContent = 'Regenerer';
    });
}

function applyFilters() {
    var typeFilter = document.getElementById('filter-type').value;
    var statusFilter = document.getElementById('filter-status').value;
    var verifiedFilter = document.getElementById('filter-verified').value;
    document.querySelectorAll('.question-card').forEach(function(card) {
        var show = true;
        if (typeFilter !== 'all' && card.dataset.qtype !== typeFilter) show = false;
        if (statusFilter !== 'all' && card.dataset.status !== statusFilter) show = false;
        if (verifiedFilter !== 'all' && card.dataset.verified !== verifiedFilter) show = false;
        card.style.display = show ? '' : 'none';
    });
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.editable-field').forEach(function(el) {
        el.addEventListener('click', function() {
            if (this.querySelector('input, textarea')) return;

            var currentText = this.dataset.value || this.textContent.trim();
            var field = this.dataset.field;
            var qid = this.dataset.qid;
            var isLong = field === 'question' || field === 'explanation';

            var input;
            if (isLong) {
                input = document.createElement('textarea');
                input.rows = 3;
                input.className = 'form-control';
            } else {
                input = document.createElement('input');
                input.type = 'text';
                input.className = 'form-control';
            }
            input.value = currentText;
            this.textContent = '';
            this.appendChild(input);
            input.focus();

            var saved = false;
            var save = function() {
                if (saved) return;
                saved = true;
                var newValue = input.value.trim();
                fetch(M.cfg.wwwroot + '/local/dreamu_qcm/ajax_edit.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'qid=' + qid + '&field=' + field + '&value=' + encodeURIComponent(newValue) + '&courseid=' + COURSEID + '&sesskey=' + M.cfg.sesskey
                }).then(function(r) { return r.json(); }).then(function(data) {
                    if (data.status === 'ok') {
                        el.textContent = newValue;
                        el.dataset.value = newValue;
                        el.style.background = '#d4edda';
                        setTimeout(function() { el.style.background = ''; }, 1500);
                    } else {
                        el.textContent = currentText;
                        el.style.background = '#f8d7da';
                        setTimeout(function() { el.style.background = ''; }, 1500);
                    }
                }).catch(function() {
                    el.textContent = currentText;
                    el.style.background = '#f8d7da';
                    setTimeout(function() { el.style.background = ''; }, 1500);
                });
            };

            input.addEventListener('blur', save);
            input.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && !isLong) {
                    e.preventDefault();
                    save();
                }
                if (e.key === 'Escape') {
                    el.textContent = currentText;
                }
            });
        });
    });
});
</script>
<?php
echo $OUTPUT->footer();

// --- Render functions ---

function render_multichoice($q, $editable = false) {
    $letters = ['a' => 'A', 'b' => 'B', 'c' => 'C', 'd' => 'D'];
    $options = ['a' => $q->optiona, 'b' => $q->optionb, 'c' => $q->optionc, 'd' => $q->optiond];
    echo '<ul class="list-group mb-2">';
    foreach ($options as $letter => $text) {
        $class = ($letter === $q->correct) ? 'list-group-item list-group-item-success' : 'list-group-item';
        $fieldname = 'option' . $letter;
        if ($editable) {
            echo '<li class="' . $class . '"><strong>' . $letters[$letter] . ')</strong> <span class="editable-field" data-qid="' . $q->id . '" data-field="' . $fieldname . '" data-value="' . htmlspecialchars($text, ENT_QUOTES) . '" style="cursor:pointer; border-bottom:1px dashed #ccc;" title="Cliquer pour modifier">' . format_string($text) . '</span></li>';
        } else {
            echo '<li class="' . $class . '"><strong>' . $letters[$letter] . ')</strong> ' . format_string($text) . '</li>';
        }
    }
    echo '</ul>';
    if ($editable) {
        echo '<div class="mb-2"><small class="text-muted">Bonne r&eacute;ponse :</small> ';
        echo '<span class="editable-field" data-qid="' . $q->id . '" data-field="correct" data-value="' . htmlspecialchars($q->correct, ENT_QUOTES) . '" style="cursor:pointer; border-bottom:1px dashed #ccc;" title="Cliquer pour modifier (a, b, c ou d)">' . strtoupper($q->correct) . '</span>';
        echo '</div>';
    }
}

function render_truefalse($q, $editable = false) {
    $istrue = ($q->correct === 'true');
    echo '<div class="mb-2">';
    echo '<span class="badge badge-' . ($istrue ? 'success bg-success' : 'secondary bg-secondary') . ' p-2 mr-2">';
    echo 'VRAI' . ($istrue ? ' (correct)' : '') . '</span>';
    echo '<span class="badge badge-' . (!$istrue ? 'success bg-success' : 'secondary bg-secondary') . ' p-2">';
    echo 'FAUX' . (!$istrue ? ' (correct)' : '') . '</span>';
    if ($editable) {
        echo '<br><small class="text-muted mt-1">Bonne r&eacute;ponse :</small> ';
        echo '<span class="editable-field" data-qid="' . $q->id . '" data-field="correct" data-value="' . htmlspecialchars($q->correct, ENT_QUOTES) . '" style="cursor:pointer; border-bottom:1px dashed #ccc;" title="Cliquer pour modifier (true ou false)">' . ($istrue ? 'true' : 'false') . '</span>';
    }
    echo '</div>';
}

function render_shortanswer($q, $editable = false) {
    echo '<div class="mb-2">';
    if ($editable) {
        echo '<span class="badge badge-success bg-success p-2">R&eacute;ponse : <span class="editable-field" data-qid="' . $q->id . '" data-field="correct" data-value="' . htmlspecialchars($q->correct, ENT_QUOTES) . '" style="cursor:pointer; border-bottom:1px dashed #fff;" title="Cliquer pour modifier">' . format_string($q->correct) . '</span></span>';
    } else {
        echo '<span class="badge badge-success bg-success p-2">R&eacute;ponse : ' . format_string($q->correct) . '</span>';
    }
    $extra = json_decode($q->extra_data ?? '{}', true);
    $alts = $extra['alternatives'] ?? [];
    if (!empty($alts)) {
        echo '<br><small class="text-muted">Aussi accept&eacute; : ' . implode(', ', array_map('format_string', $alts)) . '</small>';
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
