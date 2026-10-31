<?php
/**
 * Registrér eller fortryd et batteriskift.
 * POST fra index.php: maaler (lt:<id> | nexus:<kanal> | vevor), dato (Y-m-d), handling (skift | fortryd)
 * Kræver login (SSO), så kun du kan ændre data, selv om oversigten er offentlig.
 *
 * Bemærk: db.php må IKKE indlæses her. auth_check.php definerer selv DB_HOST,
 * DB_USER m.fl., og de to filer i samme script giver konflikter (HTTP 500).
 * Derfor har siden sin egen lille databaseforbindelse med unikke navne.
 */
require_once '/var/www/html/login/auth_check.php';

date_default_timezone_set('Europe/Copenhagen');

const BATTERI_INI = '/etc/itkur/indeklima-web.ini';
$tilbage = 'index.php#batterier';

function batteri_fejl(int $kode, string $tekst): void
{
    http_response_code($kode);
    exit($tekst);
}

function batteri_db(): PDO
{
    $ini = @parse_ini_file(BATTERI_INI, true, INI_SCANNER_RAW);
    if (!$ini || empty($ini['lt_b112'])) {
        batteri_fejl(500, 'Konfiguration mangler: ' . BATTERI_INI);
    }
    return new PDO(
        'mysql:host=localhost;dbname=environment;charset=utf8mb4',
        $ini['lt_b112']['user'],
        $ini['lt_b112']['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: $tilbage");
    exit;
}

// Simpel CSRF-beskyttelse: formularen skal komme fra samme vært
$kilde = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';
if ($kilde === '' || parse_url($kilde, PHP_URL_HOST) !== ($_SERVER['HTTP_HOST'] ?? '')) {
    batteri_fejl(403, 'Afvist: forespørgslen kom ikke fra indeklima-siden.');
}

$maaler   = trim((string)($_POST['maaler'] ?? ''));
$handling = $_POST['handling'] ?? 'skift';
$dato     = trim((string)($_POST['dato'] ?? date('Y-m-d')));

if (!preg_match('/^(lt:\d{1,6}|nexus:\d{1,2}|vevor)$/', $maaler)) {
    batteri_fejl(400, 'Ugyldig måler.');
}

try {
    $pdo = batteri_db();

    if ($handling === 'fortryd') {
        // Slet det seneste registrerede skift for måleren
        $stmt = $pdo->prepare(
            "DELETE FROM batteriskift WHERE maaler = ? ORDER BY skiftet DESC, id DESC LIMIT 1"
        );
        $stmt->execute([$maaler]);
    } else {
        $d = DateTime::createFromFormat('Y-m-d', $dato);
        if (!$d || $d->format('Y-m-d') !== $dato || $dato > date('Y-m-d') || $dato < '2020-01-01') {
            batteri_fejl(400, 'Ugyldig dato.');
        }
        // Samme dato to gange (dobbeltklik) gemmes kun én gang
        $stmt = $pdo->prepare(
            "INSERT INTO batteriskift (maaler, skiftet)
             SELECT ?, ? FROM DUAL
             WHERE NOT EXISTS (SELECT 1 FROM batteriskift WHERE maaler = ? AND skiftet = ?)"
        );
        $stmt->execute([$maaler, $dato, $maaler, $dato]);
    }
} catch (PDOException $e) {
    error_log('indeklima/batteri.php: ' . $e->getMessage());
    batteri_fejl(500, 'Databasefejl: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

header("Location: $tilbage");
