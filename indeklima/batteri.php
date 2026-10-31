<?php
/**
 * Registrér eller fortryd et batteriskift.
 * POST fra index.php: maaler (lt:<id> | nexus:<kanal> | vevor), dato (Y-m-d), handling (skift | fortryd)
 * Kræver login (SSO), så kun du kan ændre data, selv om oversigten er offentlig.
 */
require_once '/var/www/html/login/auth_check.php';
require __DIR__ . '/db.php';

$tilbage = 'index.php#batterier';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: $tilbage");
    exit;
}

// Simpel CSRF-beskyttelse: formularen skal komme fra samme vært
$kilde = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';
if ($kilde === '' || parse_url($kilde, PHP_URL_HOST) !== ($_SERVER['HTTP_HOST'] ?? '')) {
    http_response_code(403);
    exit('Afvist: forespørgslen kom ikke fra indeklima-siden.');
}

$maaler   = trim((string)($_POST['maaler'] ?? ''));
$handling = $_POST['handling'] ?? 'skift';
$dato     = trim((string)($_POST['dato'] ?? date('Y-m-d')));

if (!gyldig_maaler($maaler)) {
    http_response_code(400);
    exit('Ugyldig måler.');
}

if ($handling === 'fortryd') {
    // Slet det seneste registrerede skift for måleren
    $stmt = db()->prepare(
        "DELETE FROM batteriskift WHERE maaler = ? ORDER BY skiftet DESC, id DESC LIMIT 1"
    );
    $stmt->execute([$maaler]);
} else {
    $d = DateTime::createFromFormat('Y-m-d', $dato);
    if (!$d || $d->format('Y-m-d') !== $dato || $dato > date('Y-m-d') || $dato < '2020-01-01') {
        http_response_code(400);
        exit('Ugyldig dato.');
    }
    // Samme dato to gange (dobbeltklik) gemmes kun én gang
    $stmt = db()->prepare(
        "INSERT INTO batteriskift (maaler, skiftet)
         SELECT ?, ? FROM DUAL
         WHERE NOT EXISTS (SELECT 1 FROM batteriskift WHERE maaler = ? AND skiftet = ?)"
    );
    $stmt->execute([$maaler, $dato, $maaler, $dato]);
}

header("Location: $tilbage");
