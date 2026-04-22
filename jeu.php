<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

// ── Validate token ────────────────────────────────────────────────────────────
$token = $_GET['token'] ?? '';
if (empty($token)) {
    header('Location: index.php');
    exit;
}

$db = getDB();

$stmt = $db->prepare(
    'SELECT *,
            CASE WHEN token_joueur1 = :token THEN 1 ELSE 2 END AS mon_numero
     FROM parties
     WHERE token_joueur1 = :token OR token_joueur2 = :token'
);
$stmt->execute(['token' => $token]);
$partie = $stmt->fetch();

if (!$partie) {
    ?><!DOCTYPE html>
<html lang="fr"><head><meta charset="UTF-8"><title>Partie introuvable</title>
<link rel="stylesheet" href="css/style.css"></head>
<body><header><h1>🎭 Qui Est-Ce ?</h1></header>
<main><div class="card">
    <div class="alert alert-error">
        <strong>Lien invalide.</strong> Cette partie n'existe pas ou a expiré.
    </div>
    <p><a href="index.php" class="btn btn-primary">← Retour à l'accueil</a></p>
</div></main></body></html><?php
    exit;
}

/** @var int $monNumero    1 or 2 */
$monNumero        = (int) $partie['mon_numero'];
$advNumero        = $monNumero === 1 ? 2 : 1;
$monEmailCol      = 'email_joueur'      . $monNumero;
$advEmailCol      = 'email_joueur'      . $advNumero;
$monPersoCol      = 'personnage_joueur' . $monNumero;
$advPersoCol      = 'personnage_joueur' . $advNumero;
$monEliminCol     = 'elimines_joueur'   . $monNumero;

// ── Handle POST actions (PRG pattern) ─────────────────────────────────────────
$flash = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Re-load fresh party data for each action to avoid races
    $stmt = $db->prepare('SELECT * FROM parties WHERE id = :id');
    $stmt->execute(['id' => $partie['id']]);
    $p = $stmt->fetch();

    $action = $_POST['action'] ?? '';

    switch ($action) {

        // ── Choose secret character ──────────────────────────────────────────
        case 'choisir_personnage':
            if ($p['etat'] === 'choix_personnage' && empty($p[$monPersoCol])) {
                $choix = $_POST['personnage'] ?? '';
                $personnages = loadSet($p['set_personnages']);
                $noms = array_column($personnages, 'nom');
                if (in_array($choix, $noms, true)) {
                    // If the opponent has already chosen → game starts
                    $autreChoix  = $p[$advPersoCol];
                    $nouvelEtat  = !empty($autreChoix) ? 'en_cours' : 'choix_personnage';
                    $stmt = $db->prepare(
                        "UPDATE parties SET {$monPersoCol} = :c, etat = :e, updated_at = NOW() WHERE id = :id"
                    );
                    $stmt->execute(['c' => $choix, 'e' => $nouvelEtat, 'id' => $p['id']]);
                }
            }
            break;

        // ── Toggle character elimination ─────────────────────────────────────
        case 'eliminer':
            if (in_array($p['etat'], ['en_cours', 'choix_personnage'], true)) {
                $nom          = $_POST['personnage'] ?? '';
                $personnages  = loadSet($p['set_personnages']);
                $noms         = array_column($personnages, 'nom');
                if (in_array($nom, $noms, true)) {
                    $elimines = json_decode($p[$monEliminCol], true) ?: [];
                    if (in_array($nom, $elimines, true)) {
                        $elimines = array_values(array_diff($elimines, [$nom]));
                    } else {
                        $elimines[] = $nom;
                    }
                    $stmt = $db->prepare(
                        "UPDATE parties SET {$monEliminCol} = :e, updated_at = NOW() WHERE id = :id"
                    );
                    $stmt->execute(['e' => json_encode($elimines), 'id' => $p['id']]);
                }
            }
            break;

        // ── Ask a question ───────────────────────────────────────────────────
        case 'poser_question':
            if ($p['etat'] === 'en_cours'
                && (int) $p['tour'] === $monNumero
                && empty($p['question_en_cours'])
            ) {
                $question = trim($_POST['question'] ?? '');
                if ($question !== '') {
                    $stmt = $db->prepare(
                        "UPDATE parties
                         SET question_en_cours = :q, question_posee_par = :pp, updated_at = NOW()
                         WHERE id = :id"
                    );
                    $stmt->execute(['q' => $question, 'pp' => $monNumero, 'id' => $p['id']]);

                    $stmt = $db->prepare(
                        'INSERT INTO questions (partie_id, posee_par, question) VALUES (:pid, :pp, :q)'
                    );
                    $stmt->execute(['pid' => $p['id'], 'pp' => $monNumero, 'q' => $question]);
                }
            }
            break;

        // ── Answer yes/no ────────────────────────────────────────────────────
        case 'repondre_question':
            if ($p['etat'] === 'en_cours'
                && !empty($p['question_en_cours'])
                && (int) $p['question_posee_par'] !== $monNumero
            ) {
                $rep = ($_POST['reponse'] ?? '') === 'oui';

                // Record answer on the question log
                $stmt = $db->prepare(
                    "UPDATE questions SET reponse = :r
                     WHERE partie_id = :pid AND reponse IS NULL
                     ORDER BY id DESC LIMIT 1"
                );
                $stmt->bindValue(':r',   $rep, PDO::PARAM_BOOL);
                $stmt->bindValue(':pid', $p['id'], PDO::PARAM_INT);
                $stmt->execute();

                // After answering, it becomes the answerer's (my) turn to ask
                $stmt = $db->prepare(
                    "UPDATE parties
                     SET question_en_cours = NULL, question_posee_par = NULL,
                         tour = :t, updated_at = NOW()
                     WHERE id = :id"
                );
                $stmt->execute(['t' => $monNumero, 'id' => $p['id']]);
            }
            break;

        // ── Guess the opponent's character ───────────────────────────────────
        case 'deviner':
            if ($p['etat'] === 'en_cours'
                && (int) $p['tour'] === $monNumero
                && empty($p['question_en_cours'])
            ) {
                $devinette   = $_POST['personnage'] ?? '';
                $personnages = loadSet($p['set_personnages']);
                $noms        = array_column($personnages, 'nom');
                if (in_array($devinette, $noms, true)) {
                    $correct = ($devinette === $p[$advPersoCol]);
                    $gagnant = $correct ? $monNumero : $advNumero;
                    $stmt    = $db->prepare(
                        "UPDATE parties SET etat = 'terminee', gagnant = :g, updated_at = NOW() WHERE id = :id"
                    );
                    $stmt->execute(['g' => $gagnant, 'id' => $p['id']]);
                }
            }
            break;
    }

    // PRG: redirect back to the game page to avoid form re-submission on F5
    header('Location: jeu.php?token=' . rawurlencode($token));
    exit;
}

// ── Load fresh game state ─────────────────────────────────────────────────────
$stmt = $db->prepare('SELECT * FROM parties WHERE id = :id');
$stmt->execute(['id' => $partie['id']]);
$partie = $stmt->fetch();

$monPersonnage  = $partie[$monPersoCol];
$advPersonnage  = $partie[$advPersoCol];
$monElimines    = json_decode($partie[$monEliminCol], true) ?: [];
$etat           = $partie['etat'];
$tour           = (int) $partie['tour'];
$questionCours  = $partie['question_en_cours'];
$questionPosePar = (int) ($partie['question_posee_par'] ?? 0);
$gagnant        = (int) ($partie['gagnant'] ?? 0);

$personnages = loadSet($partie['set_personnages']);

// Question log
$stmt = $db->prepare(
    'SELECT * FROM questions WHERE partie_id = :pid ORDER BY id ASC'
);
$stmt->execute(['pid' => $partie['id']]);
$questions = $stmt->fetchAll();

// ── Determine current UI state ────────────────────────────────────────────────
$isGameOver = $etat === 'terminee';
$estGagnant = $isGameOver && $gagnant === $monNumero;

$monTour         = false;
$attenteTour     = false;
$doitRepondre    = false;
$attendReponse   = false;
$doitChoisir     = false;
$attChoisir      = false;

if ($etat === 'choix_personnage') {
    if (empty($monPersonnage)) {
        $doitChoisir = true;
    } else {
        $attChoisir = true;
    }
} elseif ($etat === 'en_cours') {
    if (!empty($questionCours)) {
        if ($questionPosePar === $monNumero) {
            $attendReponse = true;
        } else {
            $doitRepondre = true;
        }
    } else {
        if ($tour === $monNumero) {
            $monTour = true;
        } else {
            $attenteTour = true;
        }
    }
}

// Auto-refresh delay (seconds) when waiting for opponent
$autoRefresh = ($attenteTour || $attendReponse || $attChoisir) ? 5 : 0;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Qui Est-Ce ? – Partie</title>
    <link rel="stylesheet" href="css/style.css">
    <?php if ($autoRefresh > 0): ?>
        <meta http-equiv="refresh" content="<?= $autoRefresh ?>">
    <?php endif; ?>
</head>
<body>

<header>
    <div>
        <h1>🎭 Qui Est-Ce ?</h1>
        <span class="subtitle">
            Joueur <?= $monNumero ?> · <?= h($partie[$monEmailCol]) ?>
            &nbsp;|&nbsp; Ensemble : <?= h($partie['set_personnages']) ?>
        </span>
    </div>
</header>

<main>

<?php /* ── GAME OVER ──────────────────────────────────────────────────────── */ ?>
<?php if ($isGameOver): ?>

    <div class="card">
        <div class="status-bar gameover">
            <span class="dot"></span>
            <?php if ($estGagnant): ?>
                🏆 Vous avez gagné ! Vous avez deviné correctement.
            <?php else: ?>
                😞 Vous avez perdu. Votre adversaire a deviné votre personnage.
            <?php endif; ?>
        </div>

        <?php if (!empty($advPersonnage)): ?>
            <p style="margin:.5rem 0 1rem;">
                Le personnage de votre adversaire était :
                <strong><?= h($advPersonnage) ?></strong>
            </p>
        <?php endif; ?>

        <p>
            <a href="index.php" class="btn btn-primary">← Nouvelle partie</a>
        </p>
    </div>

<?php /* ── CHOOSE CHARACTER ───────────────────────────────────────────────── */ ?>
<?php elseif ($doitChoisir): ?>

    <div class="card">
        <h2>Choisissez votre personnage secret</h2>
        <div class="alert alert-info">
            Choisissez le personnage que votre adversaire devra deviner.
            Votre choix restera secret jusqu'à la fin de la partie.
        </div>

        <form method="post" action="jeu.php?token=<?= rawurlencode($token) ?>" id="form-choisir">
            <input type="hidden" name="action"     value="choisir_personnage">
            <input type="hidden" name="personnage" value="" id="input-choix">
        </form>

        <p class="grid-title">Cliquez sur un personnage pour le choisir</p>
        <div class="characters-grid">
            <?php foreach ($personnages as $p): ?>
                <?php $imgPath = 'images/' . h($p['image']); ?>
                <div class="character-card" onclick="choisir(<?= json_encode($p['nom']) ?>)" title="<?= h($p['nom']) ?>">
                    <img src="<?= $imgPath ?>" alt="<?= h($p['nom']) ?>"
                         onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                    <div class="char-placeholder" style="display:none">🎭</div>
                    <div class="char-name"><?= h($p['nom']) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <script>
    function choisir(nom) {
        if (confirm('Choisir « ' + nom + ' » comme votre personnage secret ?')) {
            document.getElementById('input-choix').value = nom;
            document.getElementById('form-choisir').submit();
        }
    }
    </script>

<?php /* ── WAITING FOR OPPONENT TO CHOOSE ───────────────────────────────── */ ?>
<?php elseif ($attChoisir): ?>

    <div class="card">
        <div class="status-bar waiting">
            <span class="dot"></span>
            En attente que votre adversaire choisisse son personnage…
            <small style="margin-left:auto">(actualisation auto dans <?= $autoRefresh ?>s)</small>
        </div>
        <p>Vous avez choisi : <span class="secret-badge">🤫 <?= h($monPersonnage) ?></span></p>
    </div>

<?php /* ── GAME IN PROGRESS ───────────────────────────────────────────────── */ ?>
<?php else: ?>

    <div class="game-layout">

        <!-- Left column: game board + action -->
        <div>

            <?php /* Status bar */ ?>
            <?php if ($monTour): ?>
                <div class="status-bar my-turn">
                    <span class="dot"></span>
                    C'est votre tour ! Posez une question ou tentez de deviner.
                </div>
            <?php elseif ($attenteTour): ?>
                <div class="status-bar opp-turn">
                    <span class="dot"></span>
                    En attente de la question de votre adversaire…
                    <small style="margin-left:auto">(actualisation dans <?= $autoRefresh ?>s)</small>
                </div>
            <?php elseif ($attendReponse): ?>
                <div class="status-bar waiting">
                    <span class="dot"></span>
                    En attente de la réponse de votre adversaire…
                    <small style="margin-left:auto">(actualisation dans <?= $autoRefresh ?>s)</small>
                </div>
            <?php elseif ($doitRepondre): ?>
                <div class="status-bar answer">
                    <span class="dot"></span>
                    Votre adversaire vous pose une question !
                </div>
            <?php endif; ?>

            <?php /* Answer YES/NO */ ?>
            <?php if ($doitRepondre && !empty($questionCours)): ?>
                <div class="card">
                    <h2>❓ Question de votre adversaire</h2>
                    <p style="font-size:1.05rem; font-weight:600; margin-bottom:1rem;">
                        « <?= h($questionCours) ?> »
                    </p>
                    <form method="post" action="jeu.php?token=<?= rawurlencode($token) ?>"
                          style="display:flex;gap:.75rem;flex-wrap:wrap;">
                        <input type="hidden" name="action" value="repondre_question">
                        <button type="submit" name="reponse" value="oui" class="btn btn-success">
                            ✅ OUI
                        </button>
                        <button type="submit" name="reponse" value="non" class="btn btn-danger">
                            ❌ NON
                        </button>
                    </form>
                </div>
            <?php endif; ?>

            <?php /* Ask a question or guess */ ?>
            <?php if ($monTour): ?>
                <div class="card">
                    <h2>Votre tour</h2>
                    <form method="post" action="jeu.php?token=<?= rawurlencode($token) ?>"
                          style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:.75rem;">
                        <input type="hidden" name="action" value="poser_question">
                        <input type="text" name="question" placeholder="Posez votre question…"
                               style="flex:1;min-width:200px;" required maxlength="250">
                        <button type="submit" class="btn btn-primary">Poser la question</button>
                    </form>

                    <p style="font-size:.85rem;color:#666;margin-bottom:.5rem;">— ou bien —</p>

                    <form method="post" action="jeu.php?token=<?= rawurlencode($token) ?>" id="form-deviner">
                        <input type="hidden" name="action"     value="deviner">
                        <input type="hidden" name="personnage" value="" id="input-devinette">
                        <p style="font-size:.85rem;font-weight:600;margin-bottom:.4rem;">
                            Cliquez sur un personnage ci-dessous pour tenter de le deviner 👇
                        </p>
                    </form>
                </div>
            <?php endif; ?>

            <!-- Character grid (the board) -->
            <div class="card">
                <h2>Plateau de jeu</h2>
                <p class="grid-title">
                    Cliquez sur un personnage pour l'éliminer / le réhabiliter
                </p>
                <form method="post" action="jeu.php?token=<?= rawurlencode($token) ?>" id="form-eliminer">
                    <input type="hidden" name="action"     value="eliminer">
                    <input type="hidden" name="personnage" value="" id="input-eliminer">
                </form>

                <div class="characters-grid">
                    <?php foreach ($personnages as $perso): ?>
                        <?php
                        $nom      = $perso['nom'];
                        $imgPath  = 'images/' . $perso['image'];
                        $elimine  = in_array($nom, $monElimines, true);
                        $classes  = 'character-card';
                        if ($elimine) $classes .= ' eliminated';
                        if ($monTour) $classes .= ' clickable';

                        // Guess mode: clicking triggers a guess
                        $onclick = '';
                        if ($monTour) {
                            $onclick = 'handleCardClick(' . json_encode($nom) . ')';
                        } elseif ($etat === 'en_cours') {
                            $onclick = 'eliminerPersonnage(' . json_encode($nom) . ')';
                        }
                        ?>
                        <div class="<?= $classes ?>"
                             onclick="<?= $onclick ?>"
                             title="<?= h($nom) ?>">
                            <img src="<?= h($imgPath) ?>" alt="<?= h($nom) ?>"
                                 onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                            <div class="char-placeholder" style="display:none">🎭</div>
                            <div class="char-name"><?= h($nom) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if ($autoRefresh > 0): ?>
                    <p class="refresh-notice">Actualisation automatique dans <?= $autoRefresh ?> secondes…</p>
                <?php endif; ?>
            </div>

        </div><!-- /left -->

        <!-- Right column: sidebar -->
        <div>

            <!-- My secret character -->
            <div class="card">
                <h2>Mon personnage secret</h2>
                <?php if (!empty($monPersonnage)): ?>
                    <p style="margin-bottom:.5rem;">
                        <span class="secret-badge">🤫 <?= h($monPersonnage) ?></span>
                    </p>
                    <?php
                    // Find the image for this character
                    $myImg = '';
                    foreach ($personnages as $p) {
                        if ($p['nom'] === $monPersonnage) { $myImg = 'images/' . $p['image']; break; }
                    }
                    ?>
                    <?php if ($myImg): ?>
                        <img src="<?= h($myImg) ?>" alt="<?= h($monPersonnage) ?>"
                             style="width:80px;border-radius:6px;margin-top:.4rem;"
                             onerror="this.style.display='none'">
                    <?php endif; ?>
                    <p style="font-size:.75rem;color:#888;margin-top:.5rem;">
                        Ne révélez pas ce personnage à votre adversaire !
                    </p>
                <?php else: ?>
                    <p style="color:#888;font-size:.85rem;">Non choisi.</p>
                <?php endif; ?>
            </div>

            <!-- Scores / turn indicator -->
            <div class="card">
                <h2>État de la partie</h2>
                <p style="font-size:.85rem;margin-bottom:.3rem;">
                    <strong>Tour :</strong>
                    <?= $tour === $monNumero ? '➤ Vous' : '➤ Adversaire' ?>
                </p>
                <p style="font-size:.85rem;">
                    <strong>Personnages restants :</strong>
                    <?= count($personnages) - count($monElimines) ?>
                    / <?= count($personnages) ?>
                </p>
            </div>

            <!-- Question history -->
            <div class="card">
                <h2>Historique des questions</h2>
                <?php if (empty($questions)): ?>
                    <p style="font-size:.85rem;color:#888;">Aucune question pour l'instant.</p>
                <?php else: ?>
                    <div class="question-log">
                        <?php foreach (array_reverse($questions) as $q): ?>
                            <?php
                            $byMe = (int) $q['posee_par'] === $monNumero;
                            // PostgreSQL PDO returns booleans as 't'/'f' strings
                            $repBool = $q['reponse'] === null ? null : ($q['reponse'] === true || $q['reponse'] === 't');
                            ?>
                            <div class="q-entry <?= $byMe ? 'by-me' : 'by-opp' ?>">
                                <div class="q-text">
                                    <?= $byMe ? 'Vous' : 'Adversaire' ?> :
                                    « <?= h($q['question']) ?> »
                                </div>
                                <div class="q-rep <?= $repBool === null ? 'pending' : ($repBool ? 'oui' : 'non') ?>">
                                    <?php if ($repBool === null): ?>
                                        En attente de réponse…
                                    <?php elseif ($repBool): ?>
                                        ✅ OUI
                                    <?php else: ?>
                                        ❌ NON
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        </div><!-- /right -->

    </div><!-- /game-layout -->

<?php endif; ?>

</main>

<script>
// Character card click handler during a player's turn:
// - Left-click without holding Ctrl → try to GUESS
// - Ctrl+click → eliminate/un-eliminate
function handleCardClick(nom) {
    // Ask the player: guess or eliminate?
    // Simple approach: show a small confirmation
    if (confirm('Deviner que le personnage de votre adversaire est « ' + nom + ' » ?\n\n(Annulez et maintenez Ctrl + clic pour éliminer à la place)')) {
        document.getElementById('input-devinette').value = nom;
        document.getElementById('form-deviner').submit();
    }
}

// Always-available: Ctrl+click on any card → eliminate
document.addEventListener('click', function(e) {
    if (!e.ctrlKey) return;
    var card = e.target.closest('.character-card');
    if (!card) return;
    var name = card.querySelector('.char-name');
    if (!name) return;
    e.preventDefault();
    e.stopPropagation();
    eliminerPersonnage(name.textContent.trim());
});

function eliminerPersonnage(nom) {
    document.getElementById('input-eliminer').value = nom;
    document.getElementById('form-eliminer').submit();
}
</script>

</body>
</html>
