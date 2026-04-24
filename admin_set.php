<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

// ── Validate & load the requested set ────────────────────────────────────────
$setName = trim($_GET['set'] ?? '');
if ($setName === '' || !preg_match('/^[\w\-]{1,50}$/', $setName)) {
    header('Location: admin.php');
    exit;
}

$xmlFile = __DIR__ . '/config/' . $setName . '.xml';
if (!file_exists($xmlFile)) {
    header('Location: admin.php');
    exit;
}

$error   = '';
$success = '';

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Load the XML document and return [SimpleXMLElement, array of personnages].
 */
function loadXml(string $file): SimpleXMLElement
{
    $xml = simplexml_load_file($file);
    if ($xml === false) {
        throw new RuntimeException('Impossible de lire le fichier XML.');
    }
    return $xml;
}

/**
 * Save the SimpleXMLElement back to file.
 */
function saveXml(SimpleXMLElement $xml, string $file): void
{
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput       = true;
    $dom->loadXML($xml->asXML());
    if ($dom->save($file) === false) {
        throw new RuntimeException('Impossible de sauvegarder le fichier XML.');
    }
}

/**
 * Handle an uploaded image file. Returns the relative path stored in XML,
 * or throws RuntimeException on error.
 * Accepted MIME: image/jpeg, image/png, image/gif, image/webp
 */
function handleImageUpload(array $file, string $setName, string $charName): string
{
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $ext     = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Erreur lors du téléversement du fichier (code ' . $file['error'] . ').');
    }

    // Detect MIME from actual content, not trusting the browser
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);

    if (!in_array($mime, $allowed, true)) {
        throw new RuntimeException('Type de fichier non accepté. Formats autorisés : JPEG, PNG, GIF, WebP.');
    }

    $imgDir = __DIR__ . '/images/' . $setName;
    if (!is_dir($imgDir)) {
        mkdir($imgDir, 0755, true);
    }

    // Build a safe filename from the character name
    $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $charName);
    $filename = strtolower($safeName) . '.' . $ext[$mime];
    $dest     = $imgDir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new RuntimeException('Impossible de déplacer le fichier téléversé.');
    }

    return $setName . '/' . $filename;
}

// ── Handle POST actions ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        $xml = loadXml($xmlFile);

        // ── Add a new character ──────────────────────────────────────────────
        if ($action === 'add') {
            $nom = trim($_POST['nom'] ?? '');
            if ($nom === '') {
                throw new RuntimeException('Le nom du personnage est obligatoire.');
            }
            if (strlen($nom) > 100) {
                throw new RuntimeException('Le nom du personnage ne doit pas dépasser 100 caractères.');
            }

            // Check for duplicate
            foreach ($xml->PERSONNAGE as $p) {
                if (strtolower((string) $p['nom']) === strtolower($nom)) {
                    throw new RuntimeException('Un personnage portant ce nom existe déjà dans ce jeu.');
                }
            }

            // Handle image upload
            if (empty($_FILES['image']) || $_FILES['image']['error'] === UPLOAD_ERR_NO_FILE) {
                throw new RuntimeException('L\'image du personnage est obligatoire.');
            }
            $imagePath = handleImageUpload($_FILES['image'], $setName, $nom);

            $perso = $xml->addChild('PERSONNAGE');
            $perso->addAttribute('nom',   $nom);
            $perso->addAttribute('image', $imagePath);

            saveXml($xml, $xmlFile);
            $success = 'Personnage « ' . h($nom) . ' » ajouté avec succès.';

        // ── Edit an existing character ───────────────────────────────────────
        } elseif ($action === 'edit') {
            $oldNom = trim($_POST['old_nom'] ?? '');
            $newNom = trim($_POST['nom']     ?? '');

            if ($newNom === '') {
                throw new RuntimeException('Le nom du personnage est obligatoire.');
            }
            if (strlen($newNom) > 100) {
                throw new RuntimeException('Le nom du personnage ne doit pas dépasser 100 caractères.');
            }

            $found = false;
            foreach ($xml->PERSONNAGE as $p) {
                if ((string) $p['nom'] === $oldNom) {
                    // Check duplicate name (excluding itself)
                    if (strtolower($newNom) !== strtolower($oldNom)) {
                        foreach ($xml->PERSONNAGE as $other) {
                            if (strtolower((string) $other['nom']) === strtolower($newNom)) {
                                throw new RuntimeException('Un personnage portant ce nom existe déjà dans ce jeu.');
                            }
                        }
                    }

                    // Handle optional image replacement
                    if (!empty($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
                        $oldImage  = (string) $p['image'];
                        $imagePath = handleImageUpload($_FILES['image'], $setName, $newNom);
                        // Remove old image if path changes
                        if ($oldImage !== $imagePath) {
                            $oldFile = __DIR__ . '/images/' . $oldImage;
                            if (file_exists($oldFile)) {
                                unlink($oldFile);
                            }
                        }
                        $p['image'] = $imagePath;
                    } elseif ($newNom !== $oldNom) {
                        // Rename image file to match new name
                        $oldImage = (string) $p['image'];
                        $oldFile  = __DIR__ . '/images/' . $oldImage;
                        if (file_exists($oldFile)) {
                            $ext      = pathinfo($oldFile, PATHINFO_EXTENSION);
                            $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $newNom);
                            $newFile  = __DIR__ . '/images/' . $setName . '/' . strtolower($safeName) . '.' . $ext;
                            rename($oldFile, $newFile);
                            $p['image'] = $setName . '/' . strtolower($safeName) . '.' . $ext;
                        }
                    }

                    $p['nom'] = $newNom;
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                throw new RuntimeException('Personnage introuvable.');
            }

            saveXml($xml, $xmlFile);
            $success = 'Personnage « ' . h($newNom) . ' » modifié avec succès.';

        // ── Delete a character ───────────────────────────────────────────────
        } elseif ($action === 'delete') {
            $nom = trim($_POST['nom'] ?? '');
            $deleted = false;

            for ($i = count($xml->PERSONNAGE) - 1; $i >= 0; $i--) {
                if ((string) $xml->PERSONNAGE[$i]['nom'] === $nom) {
                    $imagePath = (string) $xml->PERSONNAGE[$i]['image'];
                    // Remove image file
                    $imgFile = __DIR__ . '/images/' . $imagePath;
                    if (file_exists($imgFile)) {
                        unlink($imgFile);
                    }
                    unset($xml->PERSONNAGE[$i]);
                    $deleted = true;
                    break;
                }
            }

            if (!$deleted) {
                throw new RuntimeException('Personnage introuvable.');
            }

            saveXml($xml, $xmlFile);
            $success = 'Personnage « ' . h($nom) . ' » supprimé avec succès.';
        }

    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }

    // PRG
    $qs = 'set=' . rawurlencode($setName);
    if ($error) {
        // Pass error/success in GET via session-like approach — keep it simple with re-render below
    } else {
        header('Location: admin_set.php?' . $qs . '&ok=' . rawurlencode($success));
        exit;
    }
}

// Flash from redirect
if (isset($_GET['ok']) && $_GET['ok'] !== '') {
    $success = htmlspecialchars(strip_tags($_GET['ok']), ENT_QUOTES, 'UTF-8');
}

// Load current characters
try {
    $personnages = loadSet($setName);
} catch (RuntimeException $e) {
    $personnages = [];
}

// Detect which character is being edited
$editNom = trim($_GET['edit'] ?? '');
$editChar = null;
if ($editNom !== '') {
    foreach ($personnages as $p) {
        if ($p['nom'] === $editNom) {
            $editChar = $p;
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gérer le jeu « <?= h($setName) ?> »</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

<header>
    <div>
        <h1>🎭 Qui Est-Ce ?</h1>
        <span class="subtitle">Jeu : <?= h($setName) ?></span>
    </div>
    <nav class="header-nav">
        <a href="admin.php">← Retour à l'administration</a>
    </nav>
</header>

<main>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= $success ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error"><?= h($error) ?></div>
    <?php endif; ?>

    <?php if ($editChar): ?>
    <!-- ── Edit form ── -->
    <div class="card">
        <h2>✏️ Modifier le personnage « <?= h($editChar['nom']) ?> »</h2>
        <form method="post" action="admin_set.php?set=<?= rawurlencode($setName) ?>"
              enctype="multipart/form-data">
            <input type="hidden" name="action"  value="edit">
            <input type="hidden" name="old_nom" value="<?= h($editChar['nom']) ?>">

            <div class="form-group">
                <label for="edit-nom">Nom du personnage</label>
                <input type="text" name="nom" id="edit-nom"
                       value="<?= h($editChar['nom']) ?>"
                       required maxlength="100">
            </div>

            <div class="form-group">
                <label>Image actuelle</label>
                <?php $imgPath = 'images/' . h($editChar['image']); ?>
                <?php if (file_exists(__DIR__ . '/images/' . $editChar['image'])): ?>
                    <img src="<?= $imgPath ?>" alt="<?= h($editChar['nom']) ?>"
                         class="admin-thumb">
                <?php else: ?>
                    <span class="no-image">Aucune image</span>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="edit-image">Remplacer l'image (optionnel)</label>
                <input type="file" name="image" id="edit-image"
                       accept="image/jpeg,image/png,image/gif,image/webp">
                <small class="form-hint">Formats acceptés : JPEG, PNG, GIF, WebP.</small>
            </div>

            <div class="admin-form-actions">
                <button type="submit" class="btn btn-primary">💾 Enregistrer</button>
                <a href="admin_set.php?set=<?= rawurlencode($setName) ?>"
                   class="btn btn-outline">Annuler</a>
            </div>
        </form>
    </div>
    <?php else: ?>
    <!-- ── Add form ── -->
    <div class="card">
        <h2>➕ Ajouter un personnage</h2>
        <form method="post" action="admin_set.php?set=<?= rawurlencode($setName) ?>"
              enctype="multipart/form-data">
            <input type="hidden" name="action" value="add">

            <div class="form-group">
                <label for="nom">Nom du personnage</label>
                <input type="text" name="nom" id="nom"
                       value="<?= h($_POST['nom'] ?? '') ?>"
                       placeholder="Ex: Alice"
                       required maxlength="100">
            </div>

            <div class="form-group">
                <label for="image">Image du personnage</label>
                <input type="file" name="image" id="image"
                       accept="image/jpeg,image/png,image/gif,image/webp"
                       required>
                <small class="form-hint">Formats acceptés : JPEG, PNG, GIF, WebP.</small>
            </div>

            <button type="submit" class="btn btn-primary">➕ Ajouter</button>
        </form>
    </div>
    <?php endif; ?>

    <!-- ── Character list ── -->
    <div class="card">
        <h2>📋 Personnages du jeu (<?= count($personnages) ?>)</h2>

        <?php if (empty($personnages)): ?>
            <p class="no-sets">Ce jeu ne contient encore aucun personnage.</p>
        <?php else: ?>
            <div class="admin-chars-grid">
                <?php foreach ($personnages as $p):
                    $imgPath = __DIR__ . '/images/' . $p['image'];
                ?>
                <div class="admin-char-card">
                    <?php if (file_exists($imgPath)): ?>
                        <img src="images/<?= h($p['image']) ?>" alt="<?= h($p['nom']) ?>">
                    <?php else: ?>
                        <div class="char-placeholder">🎭</div>
                    <?php endif; ?>
                    <div class="admin-char-name"><?= h($p['nom']) ?></div>
                    <div class="admin-char-actions">
                        <a href="admin_set.php?set=<?= rawurlencode($setName) ?>&edit=<?= rawurlencode($p['nom']) ?>"
                           class="btn btn-warning btn-sm">✏️</a>
                        <form method="post"
                              action="admin_set.php?set=<?= rawurlencode($setName) ?>"
                              onsubmit="return confirm('Supprimer « <?= h($p['nom']) ?> » ?');"
                              style="display:inline;">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="nom"    value="<?= h($p['nom']) ?>">
                            <button type="submit" class="btn btn-danger btn-sm">🗑️</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

</main>

</body>
</html>
