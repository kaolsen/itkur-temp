<?php
/**
 * rum_temperatur.php – Nexus-rumtemperaturer + udetemperatur fra Vevor
 *
 * Viser aktuelle målinger pr. sensor og en graf, hvor man kan bladre
 * tilbage i tiden (24 timer / 7 dage / 30 dage / 1 år).
 *
 * Datakilder (database vejrdata):
 *   radio_sensor             -> målerne (fast sensor_id, navn, kanal)
 *   radio_id                 -> radio-ID'er over tid; nyt ID = batteriskift.
 *                               sensor_id NULL = ukendt (nyt batteri, støj, nabo)
 *   nexus                    -> målinger, knyttet til radio_id
 *   vevor                    -> udetemperatur som reference
 */
declare(strict_types=1);
date_default_timezone_set('Europe/Copenhagen');

// ── Konfiguration ───────────────────────────────────────────────────────────
// Login hentes fra /etc/itkur/db.ini via /etc/itkur/db.php (aldrig i koden)
require_once '/etc/itkur/db.php';
const DB_NAME = 'vejrdata';

// Vevor-værdier uden for dette interval regnes som fejlaflæsninger
const UDE_MIN = -40;
const UDE_MAX = 60;

// Sensor regnes som tavs efter så mange sekunder uden måling
const TAVS_EFTER = 3600;

// Ukendte ID'er på en kendt kanal: kig så mange timer tilbage, og kræv mindst
// så mange målinger (filtrerer radiostøj fra, som kun giver enkelte pakker)
const UKENDT_VINDUE_TIMER = 2;
const UKENDT_MIN_ANTAL    = 3;

$PERIODER = [
    '24t'  => ['label' => '24 timer', 'sek' => 86400,       'bucket' => 600,   'fmt' => 'H:i'],
    '7d'   => ['label' => '7 dage',   'sek' => 7 * 86400,   'bucket' => 3600,  'fmt' => 'd/m H:i'],
    '30d'  => ['label' => '30 dage',  'sek' => 30 * 86400,  'bucket' => 14400, 'fmt' => 'd/m H:i'],
    '1aar' => ['label' => '1 år',     'sek' => 365 * 86400, 'bucket' => 86400, 'fmt' => 'd/m-y'],
];

$FARVER = [1 => '#b45309', 2 => '#2563eb', 3 => '#dc2626', 'ude' => '#059669'];
$EKSTRA = ['#7c3aed', '#db2777', '#0891b2', '#65a30d'];
// ────────────────────────────────────────────────────────────────────────────

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function alder(string $ts): string
{
    $s = time() - strtotime($ts);
    if ($s < 120)   return 'lige nu';
    if ($s < 3600)  return floor($s / 60) . ' min siden';
    if ($s < 86400) return floor($s / 3600) . ' t siden';
    return floor($s / 86400) . ' dage siden';
}

function tal($v, int $dec = 1): string
{
    return $v === null ? '–' : number_format((float)$v, $dec, ',', '.');
}

// ── Parametre ───────────────────────────────────────────────────────────────
$periode = array_key_exists($_GET['periode'] ?? '', $PERIODER) ? $_GET['periode'] : '24t';
$maal    = (($_GET['maal'] ?? '') === 'fugt') ? 'fugt' : 'temp';
$p       = $PERIODER[$periode];
$b       = $p['bucket'];

$nu  = time();
$til = $nu;
if (!empty($_GET['til'])) {
    $t = strtotime((string)$_GET['til']);
    if ($t !== false) {
        $til = min($t, $nu);
    }
}
$fra  = $til - $p['sek'];
$fraB = intdiv($fra, $b) * $b;        // justér til hel bucket
$erNu = ($til >= $nu - 60);

// ── Database ────────────────────────────────────────────────────────────────
try {
    $_db = itkur_db_cfg('vejradmin');
    $pdo = new PDO(
        'mysql:host=' . $_db['host'] . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        $_db['user'],
        $_db['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    http_response_code(500);
    exit('Databasefejl: ' . h($e->getMessage()));
}

// Målerne (fast sensor_id); kanal bruges til farve og som nøgle i grafen
$sensorer = $pdo->query(
    "SELECT sensor_id, kanal, navn AS placering
     FROM radio_sensor
     WHERE type = 'nexus' AND aktiv = 1
     ORDER BY sortering, kanal"
)->fetchAll();

// Seneste måling pr. måler – fra målerens nuværende radio-ID (= nuværende batteri)
$stmt = $pdo->prepare(
    "SELECT n.tidspunkt, n.temperatur, n.luftfugtighed, n.batteri_ok,
            r.enhed_id, r.gyldig_fra AS batteri_fra
     FROM radio_id r
     JOIN nexus n ON n.radio_id = r.radio_id
     WHERE r.radio_id = (SELECT r2.radio_id FROM radio_id r2
                         WHERE r2.sensor_id = ? ORDER BY r2.gyldig_fra DESC LIMIT 1)
     ORDER BY n.tidspunkt DESC
     LIMIT 1"
);
$aktuel = [];
foreach ($sensorer as $s) {
    $stmt->execute([(int)$s['sensor_id']]);
    $aktuel[$s['kanal']] = $stmt->fetch() ?: null;
}

// Ukendte radio-ID'er på målernes kanaler – nyt batteri, der venter på tilknytning
$stmt = $pdo->prepare(
    "SELECT r.radio_id, r.kanal, r.enhed_id,
            COUNT(*)          AS antal,
            MIN(n.tidspunkt)  AS foerste,
            MAX(n.tidspunkt)  AS seneste,
            (SELECT n2.temperatur FROM nexus n2
              WHERE n2.radio_id = r.radio_id
              ORDER BY n2.tidspunkt DESC LIMIT 1) AS temperatur
     FROM radio_id r
     JOIN nexus n ON n.radio_id = r.radio_id
     WHERE r.type = 'nexus' AND r.sensor_id IS NULL
       AND n.tidspunkt >= NOW() - INTERVAL " . UKENDT_VINDUE_TIMER . " HOUR
       AND r.kanal IN (SELECT kanal FROM radio_sensor WHERE type = 'nexus' AND aktiv = 1)
     GROUP BY r.radio_id, r.kanal, r.enhed_id
     HAVING COUNT(*) >= ?
     ORDER BY seneste DESC"
);
$stmt->execute([UKENDT_MIN_ANTAL]);
$ukendte = [];
foreach ($stmt as $r) {
    $ukendte[(int)$r['kanal']][] = $r;
}

$ude = $pdo->query(
    "SELECT tidspunkt, temperatur, luftfugtighed, batteri_ok
     FROM vevor
     WHERE temperatur BETWEEN " . UDE_MIN . " AND " . UDE_MAX . "
     ORDER BY tidspunkt DESC
     LIMIT 1"
)->fetch() ?: null;

// Grafdata – gennemsnit pr. tidsinterval (bucket)
$stmt = $pdo->prepare(
    "SELECT s.kanal AS noegle,
            FLOOR(UNIX_TIMESTAMP(n.tidspunkt) / ?) AS bk,
            AVG(n.temperatur)    AS t,
            AVG(n.luftfugtighed) AS f
     FROM nexus n
     JOIN radio_id r     ON r.radio_id = n.radio_id
     JOIN radio_sensor s ON s.sensor_id = r.sensor_id AND s.aktiv = 1
     WHERE n.tidspunkt >= FROM_UNIXTIME(?) AND n.tidspunkt < FROM_UNIXTIME(?)
     GROUP BY s.kanal, bk
     UNION ALL
     SELECT 'ude',
            FLOOR(UNIX_TIMESTAMP(tidspunkt) / ?) AS bk,
            AVG(temperatur),
            AVG(luftfugtighed)
     FROM vevor
     WHERE tidspunkt >= FROM_UNIXTIME(?) AND tidspunkt < FROM_UNIXTIME(?)
       AND temperatur BETWEEN " . UDE_MIN . " AND " . UDE_MAX . "
     GROUP BY bk"
);
$stmt->execute([$b, $fraB, $til, $b, $fraB, $til]);

$serier = [];
foreach ($stmt as $r) {
    $serier[(string)$r['noegle']][(int)$r['bk']] = ['t' => $r['t'], 'f' => $r['f']];
}

// Min/max/gns for perioden (på rådata, ikke gennemsnit)
$stmt = $pdo->prepare(
    "SELECT s.kanal AS noegle,
            MIN(n.temperatur) AS tmin, MAX(n.temperatur) AS tmax, AVG(n.temperatur) AS tgns,
            MIN(n.luftfugtighed) AS fmin, MAX(n.luftfugtighed) AS fmax, AVG(n.luftfugtighed) AS fgns,
            COUNT(*) AS antal
     FROM nexus n
     JOIN radio_id r     ON r.radio_id = n.radio_id
     JOIN radio_sensor s ON s.sensor_id = r.sensor_id AND s.aktiv = 1
     WHERE n.tidspunkt >= FROM_UNIXTIME(?) AND n.tidspunkt < FROM_UNIXTIME(?)
     GROUP BY s.kanal
     UNION ALL
     SELECT 'ude',
            MIN(temperatur), MAX(temperatur), AVG(temperatur),
            MIN(luftfugtighed), MAX(luftfugtighed), AVG(luftfugtighed),
            COUNT(*)
     FROM vevor
     WHERE tidspunkt >= FROM_UNIXTIME(?) AND tidspunkt < FROM_UNIXTIME(?)
       AND temperatur BETWEEN " . UDE_MIN . " AND " . UDE_MAX
);
$stmt->execute([$fra, $til, $fra, $til]);
$stats = [];
foreach ($stmt as $r) {
    if ((int)$r['antal'] > 0) {
        $stats[(string)$r['noegle']] = $r;
    }
}

// ── Byg x-akse og datasæt ───────────────────────────────────────────────────
$labels = [];
$bkListe = [];
for ($ts = $fraB; $ts < $til; $ts += $b) {
    $labels[]  = date($p['fmt'], $ts);
    $bkListe[] = intdiv($ts, $b);
}

$navne = [];
foreach ($sensorer as $s) {
    $navne[(string)$s['kanal']] = $s['placering'];
}
$navne['ude'] = 'Ude (Vevor)';

$felt = $maal === 'temp' ? 't' : 'f';
$datasets = [];
$i = 0;
foreach ($navne as $noegle => $navn) {
    $data = [];
    foreach ($bkListe as $bk) {
        $v = $serier[$noegle][$bk][$felt] ?? null;
        $data[] = $v === null ? null : round((float)$v, 1);
    }
    $datasets[] = [
        'noegle' => $noegle,
        'navn'   => $navn,
        'farve'  => $FARVER[$noegle] ?? $FARVER[(int)$noegle] ?? $EKSTRA[$i++ % count($EKSTRA)],
        'data'   => $data,
    ];
}

$enhed = $maal === 'temp' ? '°C' : '%';

// ── Navigation ──────────────────────────────────────────────────────────────
$url = function (array $over) use ($periode, $maal, $til, $erNu): string {
    $base = ['periode' => $periode, 'maal' => $maal];
    if (!$erNu) {
        $base['til'] = date('Y-m-d\TH:i', $til);
    }
    $q = array_filter(array_merge($base, $over), fn($v) => $v !== null && $v !== '');
    return '?' . http_build_query($q);
};

$tilbageUrl = $url(['til' => date('Y-m-d\TH:i', $til - $p['sek'])]);
$fremTil    = $til + $p['sek'];
$fremUrl    = $fremTil >= $nu ? $url(['til' => null]) : $url(['til' => date('Y-m-d\TH:i', $fremTil)]);
$nuUrl      = $url(['til' => null]);
?>
<!doctype html>
<html lang="da">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Rumtemperaturer</title>
<style>
:root {
    --bg: #f4f5f7; --kort: #fff; --tekst: #1f2937; --svag: #6b7280;
    --kant: #e5e7eb; --accent: #2563eb; --advarsel: #b91c1c; --advarsel-bg: #fef2f2;
}
@media (prefers-color-scheme: dark) {
    :root {
        --bg: #111827; --kort: #1f2937; --tekst: #f3f4f6; --svag: #9ca3af;
        --kant: #374151; --accent: #60a5fa; --advarsel: #fca5a5; --advarsel-bg: #3b1d1d;
    }
}
* { box-sizing: border-box; }
body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background: var(--bg); color: var(--tekst); }
main { max-width: 1100px; margin: 0 auto; padding: 16px; }
h1 { font-size: 1.4rem; margin: 8px 0 16px; }
.kort-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin-bottom: 20px; }
.kort { background: var(--kort); border: 1px solid var(--kant); border-left: 5px solid var(--farve); border-radius: 10px; padding: 14px 16px; }
.kort .navn { font-size: .85rem; color: var(--svag); text-transform: uppercase; letter-spacing: .04em; }
.kort .temp { font-size: 2.2rem; font-weight: 600; line-height: 1.1; margin: 4px 0; }
.kort .fugt { font-size: 1rem; color: var(--svag); }
.kort .tid { font-size: .8rem; color: var(--svag); margin-top: 6px; }
.kort.tavs { background: var(--advarsel-bg); }
.kort .advarsel { color: var(--advarsel); font-size: .8rem; font-weight: 600; margin-top: 4px; }
.kort .ukendt { margin-top: 8px; padding-top: 8px; border-top: 1px dashed var(--kant); }
.kort details { margin-top: 4px; font-size: .8rem; color: var(--svag); }
.kort summary { cursor: pointer; }
.kort code { display: block; margin-top: 4px; padding: 6px 8px; background: var(--bg); border-radius: 4px; font-size: .75rem; word-break: break-all; user-select: all; }
.panel { background: var(--kort); border: 1px solid var(--kant); border-radius: 10px; padding: 14px 16px; margin-bottom: 20px; }
.styring { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 12px; }
.knapper { display: flex; flex-wrap: wrap; gap: 4px; }
.knap { display: inline-block; padding: 6px 12px; border: 1px solid var(--kant); border-radius: 6px; text-decoration: none; color: var(--tekst); background: var(--kort); font-size: .9rem; cursor: pointer; }
.knap:hover { border-color: var(--accent); }
.knap.aktiv { background: var(--accent); border-color: var(--accent); color: #fff; }
.knap.deaktiv { opacity: .4; pointer-events: none; }
.adskil { width: 1px; height: 24px; background: var(--kant); margin: 0 4px; }
.interval { font-size: .9rem; color: var(--svag); margin-bottom: 8px; }
form.hop { display: flex; gap: 4px; align-items: center; }
form.hop input { padding: 5px 8px; border: 1px solid var(--kant); border-radius: 6px; background: var(--kort); color: var(--tekst); font: inherit; font-size: .9rem; }
.graf { position: relative; height: 380px; }
table { width: 100%; border-collapse: collapse; font-size: .9rem; }
th, td { text-align: right; padding: 6px 8px; border-bottom: 1px solid var(--kant); }
th:first-child, td:first-child { text-align: left; }
th { color: var(--svag); font-weight: 500; }
.prik { display: inline-block; width: 10px; height: 10px; border-radius: 50%; margin-right: 6px; }
@media (max-width: 600px) { .graf { height: 280px; } .kort .temp { font-size: 1.8rem; } }
</style>
</head>
<body>
<main>

<h1>🌡️ Rumtemperaturer</h1>

<!-- Aktuelle målinger -->
<div class="kort-grid">
<?php foreach ($sensorer as $s):
    $a = $aktuel[$s['kanal']];
    $tavs = !$a || (time() - strtotime($a['tidspunkt']) > TAVS_EFTER);
    $harUkendt = !empty($ukendte[(int)$s['kanal']]);
    $farve = $FARVER[(int)$s['kanal']] ?? '#888';
?>
    <div class="kort<?= ($tavs || $harUkendt) ? ' tavs' : '' ?>" style="--farve: <?= h($farve) ?>">
        <div class="navn"><?= h($s['placering']) ?> · kanal <?= (int)$s['kanal'] ?></div>
        <?php if ($a): ?>
            <div class="temp"><?= tal($a['temperatur']) ?> °C</div>
            <div class="fugt">💧 <?= tal($a['luftfugtighed'], 0) ?> %</div>
            <div class="tid"><?= h(alder($a['tidspunkt'])) ?> · id <?= (int)$a['enhed_id'] ?> · batteri fra <?= h(date('d/m-y', strtotime($a['batteri_fra']))) ?></div>
            <?php if ($tavs): ?>
                <div class="advarsel">⚠ Tavs siden <?= h(date('d/m H:i', strtotime($a['tidspunkt']))) ?> – tjek batteri</div>
            <?php elseif ((int)$a['batteri_ok'] === 0): ?>
                <div class="advarsel">🔋 Lavt batteri</div>
            <?php endif ?>
        <?php else: ?>
            <div class="temp">–</div>
            <div class="advarsel">Ingen målinger fra kendte ID'er</div>
        <?php endif ?>

        <?php foreach ($ukendte[(int)$s['kanal']] ?? [] as $u): ?>
            <div class="ukendt">
                <div class="advarsel">
                    ⚠ Ukendt ID <?= (int)$u['enhed_id'] ?> på kanal <?= (int)$s['kanal'] ?>
                    – nyt batteri? Tilknyttes automatisk, når det gamle ID er tavst
                </div>
                <div class="tid">
                    <?= (int)$u['antal'] ?> målinger siden <?= h(date('H:i', strtotime($u['foerste']))) ?>,
                    seneste <?= tal($u['temperatur']) ?> °C <?= h(alder($u['seneste'])) ?>
                </div>
                <details>
                    <summary>Tilknyt manuelt</summary>
                    <code>UPDATE radio_id SET sensor_id = <?= (int)$s['sensor_id'] ?>, tilknyttet = NOW(), note = 'manuelt tilknyttet' WHERE radio_id = <?= (int)$u['radio_id'] ?>;</code>
                </details>
            </div>
        <?php endforeach ?>
    </div>
<?php endforeach ?>

<?php $udeTavs = !$ude || (time() - strtotime($ude['tidspunkt']) > TAVS_EFTER); ?>
    <div class="kort<?= $udeTavs ? ' tavs' : '' ?>" style="--farve: <?= h($FARVER['ude']) ?>">
        <div class="navn">Ude · Vevor</div>
        <?php if ($ude): ?>
            <div class="temp"><?= tal($ude['temperatur']) ?> °C</div>
            <div class="fugt">💧 <?= tal($ude['luftfugtighed'], 0) ?> %</div>
            <div class="tid"><?= h(alder($ude['tidspunkt'])) ?></div>
            <?php if ($udeTavs): ?>
                <div class="advarsel">⚠ Tavs siden <?= h(date('d/m H:i', strtotime($ude['tidspunkt']))) ?></div>
            <?php elseif ($ude['batteri_ok'] !== null && (int)$ude['batteri_ok'] === 0): ?>
                <div class="advarsel">🔋 Lavt batteri i udedelen</div>
            <?php endif ?>
        <?php else: ?>
            <div class="temp">–</div>
        <?php endif ?>
    </div>
</div>

<!-- Graf -->
<div class="panel">
    <div class="styring">
        <div class="knapper">
            <?php foreach ($PERIODER as $k => $pp): ?>
                <a class="knap<?= $k === $periode ? ' aktiv' : '' ?>" href="<?= h($url(['periode' => $k])) ?>"><?= h($pp['label']) ?></a>
            <?php endforeach ?>
        </div>
        <span class="adskil"></span>
        <div class="knapper">
            <a class="knap<?= $maal === 'temp' ? ' aktiv' : '' ?>" href="<?= h($url(['maal' => 'temp'])) ?>">Temperatur</a>
            <a class="knap<?= $maal === 'fugt' ? ' aktiv' : '' ?>" href="<?= h($url(['maal' => 'fugt'])) ?>">Fugt</a>
        </div>
        <span class="adskil"></span>
        <div class="knapper">
            <a class="knap" href="<?= h($tilbageUrl) ?>">◀ Tilbage</a>
            <a class="knap<?= $erNu ? ' deaktiv' : '' ?>" href="<?= h($fremUrl) ?>">Frem ▶</a>
            <a class="knap<?= $erNu ? ' deaktiv' : '' ?>" href="<?= h($nuUrl) ?>">Nu</a>
        </div>
        <form class="hop" method="get">
            <input type="hidden" name="periode" value="<?= h($periode) ?>">
            <input type="hidden" name="maal" value="<?= h($maal) ?>">
            <input type="datetime-local" name="til" value="<?= h(date('Y-m-d\TH:i', $til)) ?>" max="<?= h(date('Y-m-d\TH:i', $nu)) ?>">
            <button class="knap" type="submit">Gå til</button>
        </form>
    </div>

    <div class="interval">
        <?= h(date('d/m/Y H:i', $fra)) ?> – <?= h(date('d/m/Y H:i', $til)) ?>
        · gennemsnit pr. <?= $b >= 86400 ? 'døgn' : ($b >= 3600 ? ($b / 3600) . ' time' . ($b > 3600 ? 'r' : '') : ($b / 60) . ' min') ?>
        · klik på et navn i forklaringen for at skjule/vise
    </div>

    <div class="graf"><canvas id="graf"></canvas></div>
</div>

<!-- Statistik for perioden -->
<div class="panel">
    <table>
        <thead>
            <tr>
                <th>Sensor</th>
                <th>Min <?= h($enhed) ?></th>
                <th>Max <?= h($enhed) ?></th>
                <th>Gns <?= h($enhed) ?></th>
                <th>Målinger</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($datasets as $ds):
            $st = $stats[$ds['noegle']] ?? null;
            $pf = $maal === 'temp' ? 't' : 'f';
            $dec = $maal === 'temp' ? 1 : 0;
        ?>
            <tr>
                <td><span class="prik" style="background: <?= h($ds['farve']) ?>"></span><?= h($ds['navn']) ?></td>
                <td><?= $st ? tal($st[$pf . 'min'], $dec) : '–' ?></td>
                <td><?= $st ? tal($st[$pf . 'max'], $dec) : '–' ?></td>
                <td><?= $st ? tal($st[$pf . 'gns'], 1) : '–' ?></td>
                <td><?= $st ? number_format((int)$st['antal'], 0, ',', '.') : '0' ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
</div>

</main>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
const labels   = <?= json_encode($labels, JSON_UNESCAPED_UNICODE) ?>;
const datasets = <?= json_encode($datasets, JSON_UNESCAPED_UNICODE) ?>;
const enhed    = <?= json_encode($enhed, JSON_UNESCAPED_UNICODE) ?>;

const moerk = window.matchMedia('(prefers-color-scheme: dark)').matches;
const gitter = moerk ? 'rgba(255,255,255,.08)' : 'rgba(0,0,0,.06)';
const tekst  = moerk ? '#9ca3af' : '#6b7280';

new Chart(document.getElementById('graf'), {
    type: 'line',
    data: {
        labels,
        datasets: datasets.map(d => ({
            label: d.navn,
            data: d.data,
            borderColor: d.farve,
            backgroundColor: d.farve,
            borderWidth: 2,
            borderDash: d.noegle === 'ude' ? [6, 4] : [],
            pointRadius: 0,
            pointHoverRadius: 4,
            tension: 0.25,
            spanGaps: false      // huller vises – fx når et batteri er dødt
        }))
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        scales: {
            x: { ticks: { color: tekst, maxTicksLimit: 12, autoSkip: true, maxRotation: 0 }, grid: { color: gitter } },
            y: { ticks: { color: tekst, callback: v => v + ' ' + enhed }, grid: { color: gitter } }
        },
        plugins: {
            legend: { labels: { color: tekst, usePointStyle: true, pointStyle: 'line' } },
            tooltip: {
                callbacks: {
                    label: c => `${c.dataset.label}: ${c.parsed.y === null ? '–' : c.parsed.y.toLocaleString('da-DK') + ' ' + enhed}`
                }
            }
        }
    }
});
</script>
</body>
</html>

