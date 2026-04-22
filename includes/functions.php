<?php
/**
 * Helper functions for the Qui Est-Ce? application.
 */

/**
 * Generate a version-4 UUID.
 */
function generateUUID(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * Load the character list from an XML set file.
 * Returns an array of ['nom' => '...', 'image' => '...'].
 *
 * @throws RuntimeException if the set file does not exist.
 */
function loadSet(string $setName): array
{
    $file = __DIR__ . '/../config/' . basename($setName) . '.xml';
    if (!file_exists($file)) {
        throw new RuntimeException("Set « {$setName} » introuvable.");
    }
    $xml = simplexml_load_file($file);
    $personnages = [];
    foreach ($xml->PERSONNAGE as $p) {
        $personnages[] = [
            'nom'   => (string) $p['nom'],
            'image' => (string) $p['image'],
        ];
    }
    return $personnages;
}

/**
 * Return the names of all available character sets (one per XML file in config/).
 */
function getAvailableSets(): array
{
    $sets = [];
    foreach (glob(__DIR__ . '/../config/*.xml') as $file) {
        $sets[] = basename($file, '.xml');
    }
    return $sets;
}

/**
 * Send an invitation email to a player.
 * Falls back gracefully if mail() is unavailable.
 */
function sendGameEmail(string $to, int $playerNum, string $token): bool
{
    $url     = BASE_URL . '/jeu.php?token=' . rawurlencode($token);
    $subject = '=?UTF-8?B?' . base64_encode('Qui Est-Ce ? – Votre lien de partie') . '?=';
    $body    = "Bonjour,\n\n"
        . "Vous avez été invité(e) à jouer à Qui Est-Ce !\n\n"
        . "Cliquez sur le lien ci-dessous pour accéder à votre partie :\n"
        . $url . "\n\n"
        . "Conservez ce lien personnel : ne le partagez pas avec votre adversaire.\n\n"
        . "Bonne partie !\n";

    $headers = 'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM . ">\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n";

    $sent = mail($to, $subject, $body, $headers);
    if (!$sent) {
        error_log(sprintf('Qui Est-Ce: mail() failed for recipient "%s"', $to));
    }
    return $sent;
}

/**
 * Escape a string for safe HTML output.
 */
function h(string $str): string
{
    return htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
