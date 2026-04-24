<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $nom = trim($_POST['nom'] ?? '');

        // Validate: alphanumeric, hyphens, underscores; 2-50 chars
        if ($nom === '') {
            $error = 'Le nom du jeu est obligatoire.';
        } elseif (!preg_match('/^[\w\-]{2,50}$/', $nom)) {
            $error = 'Le nom ne doit contenir que des lettres, chiffres, tirets ou underscores (2–50 caractères).';
        } else {
            $xmlFile = __DIR__ . '/config/' . $nom . '.xml';
            $imgDir  = __DIR__ . '/images/' . $nom;

            if (file_exists($xmlFile)) {
                $error = 'Un jeu portant ce nom existe déjà.';
            } else {
                // Create empty XML file
                $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<PARTIE>\n</PARTIE>\n";
                if (file_put_contents($xmlFile, $xml) === false) {
                    $error = 'Impossible de créer le fichier de configuration.';
                } else {
                    // Create images directory
                    if (!is_dir($imgDir)) {
                        mkdir($imgDir, 0755, true);
                    }
                    $success = 'Le jeu « ' . h($nom) . ' » a été créé avec succès.';
                }
            }
        }
    } elseif ($action === 'delete_set') {
        $nom = trim($_POST['nom'] ?? '');
        if ($nom !== '' && preg_match('/^[\w\-]{1,50}$/', $nom)) {
            $xmlFile = __DIR__ . '/config/' . $nom . '.xml';
            if (file_exists($xmlFile)) {
                unlink($xmlFile);
                $success = 'Le jeu « ' . h($nom) . ' » a été supprimé.';
            }
        }
    }
}

$sets = getAvailableSets();
sort($sets);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Administration – Jeux de personnages</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

<header>
    <div>
        <h1>🎭 Qui Est-Ce ?</h1>
        <span class="subtitle">Administration</span>
    </div>
    <nav class="header-nav">
        <a href="index.php">← Retour à l'accueil</a>
    </nav>
</header>

<main>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= $success ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error"><?= h($error) ?></div>
    <?php endif; ?>

    <div class="card">
        <h2>➕ Créer un nouveau jeu de personnages</h2>
        <form method="post" action="admin.php" class="admin-create-form">
            <input type="hidden" name="action" value="create">
            <div class="form-group">
                <label for="nom">Nom du jeu</label>
                <input type="text" name="nom" id="nom"
                       value="<?= h($_POST['nom'] ?? '') ?>"
                       placeholder="Ex: MonJeu"
                       pattern="[\w\-]{2,50}"
                       title="Lettres, chiffres, tirets ou underscores – 2 à 50 caractères"
                       required maxlength="50">
                <small class="form-hint">Lettres, chiffres, tirets ou underscores (2–50 caractères).</small>
            </div>
            <button type="submit" class="btn btn-primary">Créer le jeu</button>
        </form>
    </div>

    <div class="card">
        <h2>📋 Jeux de personnages existants</h2>

        <?php if (empty($sets)): ?>
            <p class="no-sets">Aucun jeu de personnages n'a encore été créé.</p>
        <?php else: ?>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Nom</th>
                        <th>Personnages</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($sets as $s):
                    try {
                        $chars = loadSet($s);
                        $count = count($chars);
                    } catch (RuntimeException $e) {
                        $count = '?';
                        $chars = [];
                    }
                ?>
                    <tr>
                        <td><strong><?= h($s) ?></strong></td>
                        <td><?= $count ?> personnage<?= $count !== 1 ? 's' : '' ?></td>
                        <td class="actions-cell">
                            <a href="admin_set.php?set=<?= rawurlencode($s) ?>"
                               class="btn btn-primary btn-sm">✏️ Gérer</a>
                            <form method="post" action="admin.php"
                                  onsubmit="return confirm('Supprimer le jeu « <?= h($s) ?> » ? Cette action est irréversible.');"
                                  style="display:inline;">
                                <input type="hidden" name="action" value="delete_set">
                                <input type="hidden" name="nom" value="<?= h($s) ?>">
                                <button type="submit" class="btn btn-danger btn-sm">🗑️ Supprimer</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

</main>

</body>
</html>
