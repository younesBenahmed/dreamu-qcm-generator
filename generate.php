<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/questionlib.php');

$courseid = required_param('courseid', PARAM_INT);
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_login($course);
require_capability('local/dreamu_qcm:generate', $context);

$PAGE->set_url(new moodle_url('/local/dreamu_qcm/generate.php', ['courseid' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_title(get_string('generate_title', 'local_dreamu_qcm'));
$PAGE->set_heading($course->fullname . ' - ' . get_string('generate_title', 'local_dreamu_qcm'));

// Handle form submission.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $cmids = optional_param_array('cmids', [], PARAM_INT);
    $difficulty = optional_param('difficulty', 'medium', PARAM_ALPHA);
    $includehidden = optional_param('includehidden', 0, PARAM_BOOL);

    // Get per-type counts.
    $typecounts = [
        'multichoice' => optional_param('count_multichoice', 0, PARAM_INT),
        'truefalse' => optional_param('count_truefalse', 0, PARAM_INT),
        'shortanswer' => optional_param('count_shortanswer', 0, PARAM_INT),
        'matching' => optional_param('count_matching', 0, PARAM_INT),
        'numerical' => optional_param('count_numerical', 0, PARAM_INT),
    ];

    // Filter out zeros.
    $typecounts = array_filter($typecounts, fn($c) => $c > 0);
    $total = array_sum($typecounts);

    if (empty($cmids)) {
        redirect($PAGE->url, get_string('no_content', 'local_dreamu_qcm'), null, \core\output\notification::NOTIFY_ERROR);
    }
    if ($total <= 0) {
        redirect($PAGE->url, 'Veuillez sélectionner au moins un type avec un nombre > 0.', null, \core\output\notification::NOTIFY_ERROR);
    }

    // Extract content.
    $content = \local_dreamu_qcm\course_content::extract_content($courseid, $cmids, $includehidden);

    if (empty(trim($content))) {
        redirect($PAGE->url, get_string('no_content', 'local_dreamu_qcm'), null, \core\output\notification::NOTIFY_ERROR);
    }

    // Generate questions — one type at a time with exact counts.
    try {
        $generator = new \local_dreamu_qcm\qcm_generator();
        $allquestions = [];

        foreach ($typecounts as $qtype => $count) {
            $questions = $generator->generate($content, $count, $difficulty, 'fr', [$qtype]);
            $allquestions = array_merge($allquestions, $questions);
        }

        // Store in DB.
        foreach ($allquestions as $q) {
            $record = new stdClass();
            $record->courseid = $courseid;
            $record->qtype = $q->qtype;
            $record->question = $q->question;
            $record->optiona = $q->optiona;
            $record->optionb = $q->optionb;
            $record->optionc = $q->optionc;
            $record->optiond = $q->optiond;
            $record->correct = $q->correct;
            $record->difficulty = $difficulty;
            $record->explanation = $q->explanation;
            $record->extra_data = $q->extra_data ?? null;
            $record->status = 'pending';
            $record->createdby = $USER->id;
            $record->timecreated = time();
            $DB->insert_record('local_dreamu_qcm', $record);
        }

        redirect(
            new moodle_url('/local/dreamu_qcm/review.php', ['courseid' => $courseid]),
            count($allquestions) . ' questions générées !',
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (\Exception $e) {
        redirect($PAGE->url, 'Erreur : ' . $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

// Display form.
echo $OUTPUT->header();

$resources = \local_dreamu_qcm\course_content::get_course_resources($courseid, true);

echo '<form method="post" action="" id="qcm-form">';
echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';

echo '<h3>' . get_string('select_resources', 'local_dreamu_qcm') . '</h3>';

if (empty($resources)) {
    echo '<div class="alert alert-warning">Aucune ressource trouvée dans ce cours. Ajoutez du contenu d\'abord.</div>';
} else {
    echo '<div class="form-group">';
    $currentsection = -1;
    foreach ($resources as $r) {
        if ($r->section !== $currentsection) {
            if ($currentsection >= 0) echo '</div>';
            echo '<div class="ml-2 mb-3">';
            echo '<h5>Section ' . $r->section . '</h5>';
            $currentsection = $r->section;
        }
        $hidden = $r->visible ? '' : ' <span class="badge badge-secondary">Masqué</span>';
        $icon = ['resource' => '&#128196;', 'page' => '&#128221;', 'label' => '&#127991;', 'book' => '&#128214;', 'folder' => '&#128193;', 'assign' => '&#128203;'][$r->modname] ?? '&#128230;';
        echo '<div class="form-check ml-3">';
        echo '<input class="form-check-input" type="checkbox" name="cmids[]" value="' . $r->cmid . '" id="cm_' . $r->cmid . '" checked>';
        echo '<label class="form-check-label" for="cm_' . $r->cmid . '">' . $icon . ' ' . format_string($r->name) . ' (' . $r->modname . ')' . $hidden . '</label>';
        echo '</div>';
    }
    echo '</div>';
}

echo '<hr>';

// Question types with individual counts.
echo '<div class="card mb-3"><div class="card-body">';
echo '<h4>Types et nombre de questions</h4>';
echo '<p class="text-muted">Définissez le nombre de questions pour chaque type. Mettez 0 pour ne pas en générer.</p>';

$qtypeinfo = [
    'multichoice' => ['label' => 'Choix multiples (A/B/C/D)', 'desc' => 'QCM classique avec 4 options', 'default' => 5],
    'truefalse' => ['label' => 'Vrai / Faux', 'desc' => 'Affirmation vraie ou fausse', 'default' => 3],
    'shortanswer' => ['label' => 'Réponse courte', 'desc' => "L'étudiant tape une réponse texte courte", 'default' => 0],
    'matching' => ['label' => 'Correspondance', 'desc' => 'Associer des termes à leurs définitions', 'default' => 0],
    'numerical' => ['label' => 'Numérique', 'desc' => "L'étudiant entre un nombre (avec tolérance)", 'default' => 0],
];

echo '<table class="table table-bordered" style="max-width:700px;">';
echo '<thead><tr><th>Type</th><th style="width:100px;">Nombre</th></tr></thead><tbody>';

foreach ($qtypeinfo as $type => $info) {
    echo '<tr>';
    echo '<td><strong>' . $info['label'] . '</strong><br><small class="text-muted">' . $info['desc'] . '</small></td>';
    echo '<td><input type="number" name="count_' . $type . '" value="' . $info['default'] . '" min="0" max="20" class="form-control qtype-count" data-type="' . $type . '"></td>';
    echo '</tr>';
}

echo '</tbody>';
echo '<tfoot><tr><td><strong>Total</strong></td><td><strong id="total-count">8</strong></td></tr></tfoot>';
echo '</table>';

echo '</div></div>';

// Difficulty.
echo '<div class="form-group row">';
echo '<label class="col-sm-3 col-form-label">' . get_string('difficulty', 'local_dreamu_qcm') . '</label>';
echo '<div class="col-sm-3"><select name="difficulty" class="form-control">';
echo '<option value="easy">' . get_string('difficulty_easy', 'local_dreamu_qcm') . '</option>';
echo '<option value="medium" selected>' . get_string('difficulty_medium', 'local_dreamu_qcm') . '</option>';
echo '<option value="hard">' . get_string('difficulty_hard', 'local_dreamu_qcm') . '</option>';
echo '</select></div></div>';

// Include hidden.
echo '<div class="form-group row">';
echo '<label class="col-sm-3 col-form-label">' . get_string('include_hidden', 'local_dreamu_qcm') . '</label>';
echo '<div class="col-sm-3"><input type="checkbox" name="includehidden" value="1"></div>';
echo '</div>';

echo '<button type="submit" class="btn btn-primary btn-lg" id="btn-generate">' . get_string('generate', 'local_dreamu_qcm') . '</button>';
echo '</form>';

// Progress overlay (hidden by default).
echo '
<div id="progress-overlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.7); z-index:9999; justify-content:center; align-items:center;">
    <div style="background:white; border-radius:12px; padding:40px; max-width:500px; width:90%; text-align:center; box-shadow:0 4px 20px rgba(0,0,0,0.3);">
        <h3 style="margin-bottom:20px;">Génération en cours...</h3>
        <div style="background:#e9ecef; border-radius:10px; height:30px; overflow:hidden; margin-bottom:15px;">
            <div id="progress-bar" style="background:linear-gradient(90deg, #0d6efd, #0dcaf0); height:100%; width:0%; border-radius:10px; transition:width 1s linear;"></div>
        </div>
        <p id="progress-text" style="font-size:18px; color:#333; margin-bottom:5px;">0%</p>
        <p id="progress-time" style="font-size:14px; color:#666;">Temps estimé : calcul en cours...</p>
        <p id="progress-type" style="font-size:13px; color:#999;"></p>
        <p style="font-size:12px; color:#aaa; margin-top:15px;">Ne fermez pas cette page</p>
    </div>
</div>
';

// JavaScript for total counter + progress bar.
echo '
<script>
document.addEventListener("DOMContentLoaded", function() {
    // Update total count when inputs change.
    var inputs = document.querySelectorAll(".qtype-count");
    var totalEl = document.getElementById("total-count");

    function updateTotal() {
        var total = 0;
        inputs.forEach(function(inp) { total += parseInt(inp.value) || 0; });
        totalEl.textContent = total;
    }
    inputs.forEach(function(inp) { inp.addEventListener("input", updateTotal); });
    updateTotal();

    // Progress bar on form submit.
    var form = document.getElementById("qcm-form");
    var overlay = document.getElementById("progress-overlay");
    var bar = document.getElementById("progress-bar");
    var textEl = document.getElementById("progress-text");
    var timeEl = document.getElementById("progress-time");
    var typeEl = document.getElementById("progress-type");

    form.addEventListener("submit", function() {
        // Count how many types are active (count > 0).
        var activeTypes = [];
        var typeNames = {
            "multichoice": "Choix multiples",
            "truefalse": "Vrai / Faux",
            "shortanswer": "Réponse courte",
            "matching": "Correspondance",
            "numerical": "Numérique"
        };
        inputs.forEach(function(inp) {
            var count = parseInt(inp.value) || 0;
            if (count > 0) {
                activeTypes.push({ type: inp.dataset.type, name: typeNames[inp.dataset.type] || inp.dataset.type, count: count });
            }
        });

        if (activeTypes.length === 0) return;

        // Show overlay.
        overlay.style.display = "flex";

        // Estimate: ~30 seconds per type.
        var secsPerType = 30;
        var totalSecs = activeTypes.length * secsPerType;
        var startTime = Date.now();
        var currentTypeIndex = 0;

        function updateProgress() {
            var elapsed = (Date.now() - startTime) / 1000;
            var pct = Math.min(95, (elapsed / totalSecs) * 100);

            // Which type are we on?
            currentTypeIndex = Math.min(Math.floor(elapsed / secsPerType), activeTypes.length - 1);
            var currentType = activeTypes[currentTypeIndex];

            bar.style.width = pct + "%";
            textEl.textContent = Math.round(pct) + "%";

            var remaining = Math.max(0, Math.round(totalSecs - elapsed));
            var mins = Math.floor(remaining / 60);
            var secs = remaining % 60;
            timeEl.textContent = "Temps restant estimé : " + (mins > 0 ? mins + "min " : "") + secs + "s";
            typeEl.textContent = "Génération : " + currentType.name + " (" + currentType.count + " questions)  [" + (currentTypeIndex + 1) + "/" + activeTypes.length + "]";

            if (pct < 95) {
                requestAnimationFrame(updateProgress);
            }
        }
        requestAnimationFrame(updateProgress);
    });
});
</script>
';

echo $OUTPUT->footer();
