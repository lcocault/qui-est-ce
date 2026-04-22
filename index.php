<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$error   = '';
$success = false;
$partie  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $set    = trim($_POST['set']    ?? '');
    $email1 = trim($_POST['email1'] ?? '');
    $email2 = trim($_POST['email2'] ?? '');

    // Validation
    if (empty($set) || empty($email1) || empty($email2)) {
        $error = 'Tous les champs sont obligatoires.';
    } elseif (!filter_var($email1, FILTER_VALIDATE_EMAIL)) {
        $error = 'L\'adresse email du joueur 1 est invalide.';
    } elseif (!filter_var($email2, FILTER_VALIDATE_EMAIL)) {
        $error = 'L\'adresse email du joueur 2 est invalide.';
    } else {
        try {
            loadSet($set); // Validate set exists
        } catch (RuntimeException $e) {
            $error = h($e->getMessage());
        }
    }

    if (!$error) {
        try {
            $db = getDB();

            $gameId  = generateUUID();
            $token1  = generateUUID();
            $token2  = generateUUID();

            $stmt = $db->prepare(
                'INSERT INTO parties
                    (identifiant, token_joueur1, token_joueur2,
                     email_joueur1, email_joueur2, set_personnages)
                 VALUES (:gid, :t1, :t2, :e1, :e2, :set)'
            );
            $stmt->execute([
                'gid' => $gameId,
                't1'  => $token1,
                't2'  => $token2,
                'e1'  => $email1,
                'e2'  => $email2,
                'set' => $set,
            ]);

            // Try to send emails (non-blocking: we show the links anyway)
            sendGameEmail($email1, 1, $token1);
            sendGameEmail($email2, 2, $token2);

            $partie  = ['token1' => $token1, 'token2' => $token2, 'email1' => $email1, 'email2' => $email2];
            $success = true;

        } catch (Exception $e) {
            $error = 'Erreur lors de la création de la partie : ' . h($e->getMessage());
        }
    }
}

$sets = getAvailableSets();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Qui Est-Ce ? – Accueil</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

<header>
    <div>
        <h1>🎭 Qui Est-Ce ?</h1>
        <span class="subtitle">Le jeu de déduction en ligne</span>
    </div>
</header>

<main>
<?php if ($success && $partie): ?>

    <div class="card">
        <h2>🎉 Partie créée avec succès !</h2>
        <div class="alert alert-success">
            Un email a été envoyé à chaque joueur avec son lien personnel.
            Si vous ne recevez pas d'email, utilisez les liens ci-dessous directement.
        </div>

        <div class="player-links">
            <div class="player-link-box">
                <strong>Joueur 1 – <?= h($partie['email1']) ?></strong>
                <span class="link-text"><?= h(BASE_URL . '/jeu.php?token=' . $partie['token1']) ?></span>
                <a class="btn btn-primary btn-sm"
                   href="jeu.php?token=<?= rawurlencode($partie['token1']) ?>">
                    Accéder à la partie (Joueur 1)
                </a>
            </div>
            <div class="player-link-box">
                <strong>Joueur 2 – <?= h($partie['email2']) ?></strong>
                <span class="link-text"><?= h(BASE_URL . '/jeu.php?token=' . $partie['token2']) ?></span>
                <a class="btn btn-primary btn-sm"
                   href="jeu.php?token=<?= rawurlencode($partie['token2']) ?>">
                    Accéder à la partie (Joueur 2)
                </a>
            </div>
        </div>

        <p style="margin-top:1.5rem; text-align:center;">
            <a href="index.php" class="btn btn-outline">← Créer une nouvelle partie</a>
        </p>
    </div>

<?php else: ?>

    <div class="home-hero">
        <h2>Bienvenue sur Qui Est-Ce ?</h2>
        <p>Un jeu de déduction à deux joueurs. Devinez le personnage choisi par votre adversaire !</p>
    </div>

    <div class="card home-form">
        <h2>Nouvelle partie</h2>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= $error ?></div>
        <?php endif; ?>

        <form method="post" action="index.php">
            <div class="form-group">
                <label for="set">Ensemble de personnages</label>
                <select name="set" id="set" required>
                    <?php foreach ($sets as $s): ?>
                        <option value="<?= h($s) ?>"
                            <?= (($_POST['set'] ?? '') === $s ? 'selected' : '') ?>>
                            <?= h($s) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="email1">Email du Joueur 1</label>
                <input type="email" name="email1" id="email1"
                       value="<?= h($_POST['email1'] ?? '') ?>"
                       placeholder="joueur1@exemple.fr" required>
            </div>

            <div class="form-group">
                <label for="email2">Email du Joueur 2 (adversaire)</label>
                <input type="email" name="email2" id="email2"
                       value="<?= h($_POST['email2'] ?? '') ?>"
                       placeholder="joueur2@exemple.fr" required>
            </div>

            <button type="submit" class="btn btn-primary btn-block">
                🎲 Créer la partie
            </button>
        </form>
    </div>

<?php endif; ?>
</main>

</body>
</html>
