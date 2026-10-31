<?php
// Fælles databaseforbindelse for indeklima-siden
// Adgangsoplysninger ligger i /etc/itkur/indeklima-web.ini (uden for webroden og git)

// PHP skal regne i dansk tid, ligesom MariaDB's NOW() gør på Pi'en
date_default_timezone_set('Europe/Copenhagen');

$_ini = @parse_ini_file('/etc/itkur/indeklima-web.ini', true, INI_SCANNER_RAW);
if ($_ini === false) {
    http_response_code(500);
    exit('Konfiguration mangler: /etc/itkur/indeklima-web.ini');
}

const DB_HOST = 'localhost';
const DB_NAME = 'environment';
define('DB_USER', $_ini['lt_b112']['user']);
define('DB_PASS', $_ini['lt_b112']['password']);

// Hvor gammel battery_at må være, før en måler regnes som "mistet forbindelse"
const KONTAKT_MINUTTER = 20;
// Under denne procent vises "skift batteri snart"
const BATTERI_LAV = 20;

// ── Vejrstationen (database vejrdata) ──────────────────────────────────────
// Læsebruger til vejrdatabasen (samme som /temperatur/rum_temperatur.php)
const VEJR_DB_NAME = 'vejrdata';
define('VEJR_DB_USER', $_ini['web_laes']['user']);
define('VEJR_DB_PASS', $_ini['web_laes']['password']);

// Nexus/Vevor sender hvert par minutter, så tavshed betyder noget her
const VEJR_TAVS_MINUTTER = 60;
// Vevor-værdier uden for intervallet regnes som fejlaflæsninger
const UDE_MIN = -40;
const UDE_MAX = 60;
// Ukendt ID på kendt kanal: så mange timer tilbage og mindst så mange målinger
const UKENDT_VINDUE_TIMER = 2;
const UKENDT_MIN_ANTAL    = 3;

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = ny_pdo(DB_NAME, DB_USER, DB_PASS);
    }
    return $pdo;
}

function vejr_db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = ny_pdo(VEJR_DB_NAME, VEJR_DB_USER, VEJR_DB_PASS);
    }
    return $pdo;
}

function ny_pdo(string $navn, string $bruger, string $pass): PDO
{
    return new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . $navn . ';charset=utf8mb4',
        $bruger,
        $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
}

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function siden(?int $min): string
{
    if ($min === null) return '–';
    if ($min < 1)      return 'lige nu';
    if ($min < 60)     return $min . ' min siden';
    if ($min < 1440)   return floor($min / 60) . ' t ' . ($min % 60) . ' min siden';
    return floor($min / 1440) . ' dage siden';
}

function tal($v, int $dec = 1): string
{
    return $v === null ? '–' : number_format((float)$v, $dec, ',', '');
}

/**
 * Alle aktive målere med seneste måling, batteri og beregnet status.
 */
function hent_maalere(): array
{
    $sql = "
        SELECT s.id, s.name, s.location, s.gateway, s.bluetooth_name,
               s.battery, s.battery_at,
               TIMESTAMPDIFF(MINUTE, s.battery_at, NOW()) AS min_siden_kontakt,
               m.temperature, m.humidity, m.measured_at,
               TIMESTAMPDIFF(MINUTE, m.measured_at, NOW()) AS min_siden_maaling
        FROM sensors s
        LEFT JOIN measurements m
               ON m.id = (SELECT id FROM measurements
                          WHERE sensor_id = s.id
                          ORDER BY measured_at DESC LIMIT 1)
        WHERE s.active = 1
        ORDER BY s.sortering, s.name";

    $rows = db()->query($sql)->fetchAll();

    foreach ($rows as &$r) {
        if ($r['battery_at'] === null) {
            $r['status'] = 'ukendt';
        } elseif ((int)$r['min_siden_kontakt'] > KONTAKT_MINUTTER) {
            $r['status'] = 'mistet';
        } elseif ($r['battery'] !== null && (int)$r['battery'] < BATTERI_LAV) {
            $r['status'] = 'batteri';
        } else {
            $r['status'] = 'ok';
        }
    }
    return $rows;
}

/**
 * Status for en vejrstationsmåler – samme navne som LT-målerne,
 * så kortene får de samme farvede kanter.
 */
function vejr_status(?int $min, $batteri_ok, bool $ukendt_id): string
{
    if ($min === null)                 return 'ukendt';
    if ($min > VEJR_TAVS_MINUTTER)     return 'mistet';
    if ($ukendt_id)                    return 'batteri';   // batteriskift / døende batteri
    if ($batteri_ok !== null && (int)$batteri_ok === 0) return 'batteri';
    return 'ok';
}

/**
 * Nexus-målerne (radio_sensor) og udedelen fra Vevor.
 * En måler kendes på sit faste sensor_id; radio-ID'et skifter ved batteriskift
 * (radio_id-tabellen). Ukendte = radio-ID'er på en målers kanal, som endnu ikke
 * er knyttet til en sensor (nyt batteri, støj eller en nabo).
 * Returnerer ['rum' => [...], 'ude' => [...] | null]
 */
function hent_vejr_maalere(): array
{
    $pdo = vejr_db();

    $sensorer = $pdo->query(
        "SELECT sensor_id, navn, kanal FROM radio_sensor
         WHERE type = 'nexus' AND aktiv = 1
         ORDER BY sortering, kanal"
    )->fetchAll();

    // Ukendte radio-ID'er på målernes kanaler med nok målinger til ikke at være støj
    $stmt = $pdo->prepare(
        "SELECT r.kanal, r.enhed_id, COUNT(*) AS antal
         FROM radio_id r
         JOIN nexus n ON n.radio_id = r.radio_id
         WHERE r.type = 'nexus' AND r.sensor_id IS NULL
           AND n.tidspunkt >= NOW() - INTERVAL " . UKENDT_VINDUE_TIMER . " HOUR
           AND r.kanal IN (SELECT kanal FROM radio_sensor WHERE type = 'nexus' AND aktiv = 1)
         GROUP BY r.radio_id, r.kanal, r.enhed_id
         HAVING COUNT(*) >= ?"
    );
    $stmt->execute([UKENDT_MIN_ANTAL]);
    $ukendte = [];
    foreach ($stmt as $u) {
        $ukendte[(int)$u['kanal']][] = (int)$u['enhed_id'];
    }

    // Målerens to seneste radio-ID'er: nuværende (batteriet) og forrige
    $radioer = $pdo->prepare(
        "SELECT radio_id, enhed_id, gyldig_fra FROM radio_id
         WHERE sensor_id = ? ORDER BY gyldig_fra DESC LIMIT 2"
    );
    $seneste = $pdo->prepare(
        "SELECT tidspunkt, temperatur, luftfugtighed, batteri_ok,
                TIMESTAMPDIFF(MINUTE, tidspunkt, NOW()) AS min_siden
         FROM nexus WHERE radio_id = ?
         ORDER BY tidspunkt DESC LIMIT 1"
    );

    $rum = [];
    foreach ($sensorer as $s) {
        $kanal = (int)$s['kanal'];
        $radioer->execute([(int)$s['sensor_id']]);
        $rr  = $radioer->fetchAll();
        $nu  = $rr[0] ?? null;
        $a   = [];
        if ($nu) {
            $seneste->execute([(int)$nu['radio_id']]);
            $a = $seneste->fetch() ?: [];
        }

        $r = [
            'sensor_id'     => (int)$s['sensor_id'],
            'kanal'         => $kanal,
            'placering'     => $s['navn'],
            'temperatur'    => $a['temperatur'] ?? null,
            'luftfugtighed' => $a['luftfugtighed'] ?? null,
            'batteri_ok'    => $a['batteri_ok'] ?? null,
            'enhed_id'      => $nu['enhed_id'] ?? null,
            'min_siden'     => isset($a['min_siden']) ? (int)$a['min_siden'] : null,
            'ukendte'       => $ukendte[$kanal] ?? [],
            'batteri_fra'   => $nu['gyldig_fra'] ?? null,        // nuværende radio-ID startede = batteriskift
            'forrige_fra'   => $rr[1]['gyldig_fra'] ?? null,
        ];
        $r['status'] = vejr_status($r['min_siden'], $r['batteri_ok'], (bool)$r['ukendte']);
        $rum[] = $r;
    }

    $ude = $pdo->query(
        "SELECT temperatur, luftfugtighed, batteri_ok,
                TIMESTAMPDIFF(MINUTE, tidspunkt, NOW()) AS min_siden
         FROM vevor
         WHERE temperatur BETWEEN " . UDE_MIN . " AND " . UDE_MAX . "
         ORDER BY tidspunkt DESC
         LIMIT 1"
    )->fetch() ?: null;

    if ($ude) {
        $ude['min_siden'] = (int)$ude['min_siden'];
        $ude['status'] = vejr_status($ude['min_siden'], $ude['batteri_ok'], false);
    }

    return ['rum' => $rum, 'ude' => $ude];
}

/**
 * Batteriskift for alle målere: [maaler => ['seneste' => 'Y-m-d', 'forrige' => 'Y-m-d'|null, 'antal' => n]]
 * maaler-nøgler: lt:<sensors.id>, vevor  (Nexus registreres automatisk via radio_id)
 */
function hent_batteriskift(): array
{
    $rows = db()->query(
        "SELECT maaler, skiftet FROM batteriskift ORDER BY maaler, skiftet DESC"
    )->fetchAll();
    $ud = [];
    foreach ($rows as $r) {
        $m = $r['maaler'];
        if (!isset($ud[$m])) {
            $ud[$m] = ['seneste' => $r['skiftet'], 'forrige' => null, 'antal' => 0];
        } elseif ($ud[$m]['forrige'] === null) {
            $ud[$m]['forrige'] = $r['skiftet'];
        }
        $ud[$m]['antal']++;
    }
    return $ud;
}

/** Dage mellem to datoer (Y-m-d); null hvis en mangler. */
function dage_mellem(?string $fra, ?string $til): ?int
{
    if (!$fra || !$til) return null;
    return (int)(new DateTime($fra))->diff(new DateTime($til))->days;
}

/** Gyldig målernøgle? */
function gyldig_maaler(string $m): bool
{
    return (bool)preg_match('/^(lt:\d{1,6}|vevor)$/', $m);
}

/**
 * Første måling fra hver måler – hvor længe den har sendt data.
 * Nexus: gyldig_fra for målerens nuværende radio-ID (= batteriskiftet).
 * Vevor: første måling med det nuværende ID (skifter også ID ved batteriskift).
 * LT_-målerne har fast MAC, så for dem er det blot første måling i databasen.
 * Returnerer [maaler-nøgle => 'Y-m-d H:i:s']
 */
function hent_foerste_maaling(array $maalere, array $vejr): array
{
    $ud = [];
    $lt = db()->query("SELECT sensor_id, MIN(measured_at) AS f FROM measurements GROUP BY sensor_id");
    foreach ($lt as $r) {
        $ud['lt:' . (int)$r['sensor_id']] = $r['f'];
    }

    foreach ($vejr['rum'] as $r) {
        if (!empty($r['batteri_fra'])) $ud['radio:' . (int)$r['sensor_id']] = $r['batteri_fra'];
    }

    $v = vejr_db();
    if (!empty($vejr['ude'])) {
        $f = $v->query(
            "SELECT MIN(tidspunkt) FROM vevor
             WHERE enhed_id = (SELECT enhed_id FROM vevor ORDER BY tidspunkt DESC LIMIT 1)"
        )->fetchColumn();
        if ($f) $ud['vevor'] = $f;
    }
    return $ud;
}

