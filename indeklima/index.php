<?php
require __DIR__ . '/db.php';

try {
    $maalere = hent_maalere();
    $fejl = null;
} catch (Throwable $e) {
    $maalere = [];
    $fejl = $e->getMessage();
}

// Vejrstationen hentes for sig, så LT-delen virker, selv om vejrdata fejler
try {
    $vejr = hent_vejr_maalere();
    $vejr_fejl = null;
} catch (Throwable $e) {
    $vejr = ['rum' => [], 'ude' => null];
    $vejr_fejl = $e->getMessage();
}
$ude = $vejr['ude'];

// Batteriskift (tabellen findes først efter sql/opgradering_batteriskift.sql)
try {
    $skift = hent_batteriskift();
    $skift_fejl = null;
} catch (Throwable $e) {
    $skift = [];
    $skift_fejl = $e->getMessage();
}
$idag = date('Y-m-d');

// Første måling fra hver måler (fallback for batteriets alder, når intet skift er registreret)
try {
    $foerste = hent_foerste_maaling($maalere, $vejr);
} catch (Throwable $e) {
    $foerste = [];
}

// Alle målere med batteri, i samme rækkefølge som kortene
$batterier = [];
foreach ($maalere as $m) {
    $batterier[] = ['noegle' => 'lt:' . (int)$m['id'], 'navn' => $m['name'], 'type' => $m['bluetooth_name'] ?: 'LT'];
}
foreach ($vejr['rum'] as $r) {
    $batterier[] = ['noegle' => 'nexus:' . (int)$r['kanal'], 'navn' => $r['placering'], 'type' => 'Nexus kanal ' . (int)$r['kanal']];
}
if ($ude) {
    $batterier[] = ['noegle' => 'vevor', 'navn' => 'Ude', 'type' => 'Vevor udedel'];
}

/** Kort tekst til kortene: "Batteri 12.10 (19 dage)" */
function batteri_tekst(array $skift, string $noegle, string $idag): string
{
    if (empty($skift[$noegle])) return '';
    $s = $skift[$noegle]['seneste'];
    return 'Batteri skiftet ' . date('d.m.y', strtotime($s)) . ' (' . dage_mellem($s, $idag) . ' dage)';
}

$statustekst = [
    'ok'      => 'Forbundet',
    'batteri' => 'Skift batteri snart',
    'mistet'  => 'Mistet forbindelse',
    'ukendt'  => 'Ingen kontakt endnu',
];

$alle_vejr = $ude ? array_merge($vejr['rum'], [$ude]) : $vejr['rum'];
$antal_problemer =
      count(array_filter($maalere, fn($m) => $m['status'] !== 'ok'))
    + count(array_filter($alle_vejr, fn($v) => $v['status'] !== 'ok'));

// Statustabellen sorteres på gateway og derefter navn (kortene følger sensors.sortering)
$status_liste = $maalere;
usort($status_liste, fn($a, $b) =>
    [$a['gateway'], $a['name']] <=> [$b['gateway'], $b['name']]);

// LT-målere slået op på navn, så et Nexus-kort kan vise LT-måleren i samme rum
$noegle = fn($s) => function_exists('mb_strtolower') ? mb_strtolower(trim((string)$s)) : strtolower(trim((string)$s));
$lt_efter_navn = [];
foreach ($maalere as $m) {
    $lt_efter_navn[$noegle($m['name'])] = $m;
}

$RUMSIDE = '/temperatur/rum_temperatur.php';
?>
<!doctype html>
<html lang="da">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="refresh" content="120">
<title>Indeklima</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<main>
  <header>
    <h1>Indeklima</h1>
    <?php if ($ude && $ude['status'] !== 'mistet'): ?>
      <span class="ude-nu">Ude <?= tal($ude['temperatur']) ?> °C</span>
    <?php endif; ?>
    <span class="opdateret">Opdateret <?= date('d.m.Y H:i') ?></span>
    <?php if ($maalere || $alle_vejr): ?>
      <span class="alarm <?= $antal_problemer ? '' : 'ok' ?>">
        <?= $antal_problemer ? $antal_problemer . ' måler(e) kræver opmærksomhed' : 'Alle målere OK' ?>
      </span>
    <?php endif; ?>
    <nav class="topnav">
      <a href="index.php" class="aktiv">Oversigt</a>
      <a href="grafer.php">Grafer</a>
    </nav>
  </header>

  <?php if ($fejl): ?>
    <p class="fejl">Databasefejl: <?= h($fejl) ?></p>
  <?php endif; ?>

  <section class="kort-grid">
    <?php foreach ($maalere as $m): ?>
      <a class="kort <?= h($m['status']) ?>" href="grafer.php?sensor=<?= (int)$m['id'] ?>" title="Se grafer for <?= h($m['name']) ?>">
        <div class="navn"><?= h($m['name']) ?></div>
        <div class="sted"><?= h($m['location']) ?></div>
        <div class="vaerdier">
          <span class="temp"><?= $m['temperature'] !== null ? number_format((float)$m['temperature'], 1, ',', '') : '–' ?><small> °C</small></span>
          <span class="fugt"><?= $m['humidity'] !== null ? number_format((float)$m['humidity'], 0, ',', '') . ' %' : '' ?></span>
        </div>
        <div class="meta">
          <span>Ændret <?= siden($m['min_siden_maaling'] !== null ? (int)$m['min_siden_maaling'] : null) ?></span>
          <?php if ($m['battery'] !== null): ?>
            <span class="bat" title="Batteri"><span class="bat-bar"><span class="bat-fyld" style="width:<?= (int)$m['battery'] ?>%"></span></span><?= (int)$m['battery'] ?> %</span>
          <?php endif; ?>
        </div>
        <?php if ($t = batteri_tekst($skift, 'lt:' . (int)$m['id'], $idag)): ?><div class="bat-alder"><?= h($t) ?></div><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </section>

  <h2>Vejrstationen <span class="opdateret">Nexus-målere og udedel · <a href="<?= h($RUMSIDE) ?>">grafer og detaljer</a></span></h2>

  <?php if ($vejr_fejl): ?>
    <p class="fejl">Databasefejl (vejrdata): <?= h($vejr_fejl) ?></p>
  <?php endif; ?>

  <section class="kort-grid">
    <?php foreach ($vejr['rum'] as $r):
        $lt = $lt_efter_navn[$noegle($r['placering'])] ?? null;
    ?>
      <a class="kort <?= h($r['status']) ?>" href="<?= h($RUMSIDE) ?>" title="Se <?= h($r['placering']) ?> på rumsiden">
        <div class="navn"><?= h($r['placering']) ?></div>
        <div class="sted">Nexus · kanal <?= (int)$r['kanal'] ?></div>
        <div class="vaerdier">
          <span class="temp"><?= tal($r['temperatur']) ?><small> °C</small></span>
          <span class="fugt"><?= $r['luftfugtighed'] !== null ? tal($r['luftfugtighed'], 0) . ' %' : '' ?></span>
        </div>
        <div class="meta">
          <span>Målt <?= siden($r['min_siden']) ?></span>
          <?php if ($r['enhed_id'] !== null): ?><span>id <?= (int)$r['enhed_id'] ?></span><?php endif; ?>
        </div>
        <?php if ($t = batteri_tekst($skift, 'nexus:' . (int)$r['kanal'], $idag)): ?><div class="bat-alder"><?= h($t) ?></div><?php endif; ?>

        <?php if ($r['status'] === 'ukendt'): ?>
          <div class="advarsel">Ingen målinger fra kendte ID'er</div>
        <?php elseif ($r['status'] === 'mistet'): ?>
          <div class="advarsel">⚠ Tavs i <?= siden($r['min_siden']) ?> – tjek batteri</div>
        <?php elseif ($r['batteri_ok'] !== null && (int)$r['batteri_ok'] === 0): ?>
          <div class="advarsel">🔋 Lavt batteri</div>
        <?php endif; ?>
        <?php foreach ($r['ukendte'] as $id): ?>
          <div class="advarsel">⚠ Ukendt ID <?= (int)$id ?> – registrér det på rumsiden</div>
        <?php endforeach; ?>

        <?php if ($lt && $lt['temperature'] !== null && $r['temperatur'] !== null):
            $forskel = (float)$r['temperatur'] - (float)$lt['temperature'];
        ?>
          <div class="sammenlign">
            LT-måler <?= tal($lt['temperature']) ?> °C ·
            forskel <?= ($forskel > 0 ? '+' : '') . tal($forskel) ?> °C
          </div>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>

    <?php if ($ude): ?>
      <a class="kort <?= h($ude['status']) ?>" href="<?= h($RUMSIDE) ?>" title="Se udetemperaturen på rumsiden">
        <div class="navn">Ude</div>
        <div class="sted">Vevor</div>
        <div class="vaerdier">
          <span class="temp"><?= tal($ude['temperatur']) ?><small> °C</small></span>
          <span class="fugt"><?= $ude['luftfugtighed'] !== null ? tal($ude['luftfugtighed'], 0) . ' %' : '' ?></span>
        </div>
        <div class="meta">
          <span>Målt <?= siden($ude['min_siden']) ?></span>
        </div>
        <?php if ($t = batteri_tekst($skift, 'vevor', $idag)): ?><div class="bat-alder"><?= h($t) ?></div><?php endif; ?>
        <?php if ($ude['status'] === 'mistet'): ?>
          <div class="advarsel">⚠ Tavs i <?= siden($ude['min_siden']) ?></div>
        <?php elseif ($ude['batteri_ok'] !== null && (int)$ude['batteri_ok'] === 0): ?>
          <div class="advarsel">🔋 Lavt batteri i udedelen</div>
        <?php endif; ?>
      </a>
    <?php endif; ?>
  </section>

  <h2>Status</h2>
  <div class="panel tabel-wrap">
    <table>
      <thead>
        <tr><th>Måler</th><th>Gateway</th><th>Status</th><th>Batteri</th><th>Seneste kontakt</th><th>Seneste ændring</th></tr>
      </thead>
      <tbody>
        <?php foreach ($status_liste as $m): ?>
          <tr>
            <td><?= h($m['name']) ?> <span class="opdateret"><?= h($m['bluetooth_name']) ?></span></td>
            <td><?= h($m['gateway']) ?></td>
            <td><span class="prik <?= h($m['status']) ?>"></span><?= h($statustekst[$m['status']]) ?></td>
            <td class="tal"><?= $m['battery'] !== null ? (int)$m['battery'] . ' %' : '–' ?></td>
            <td><?= siden($m['min_siden_kontakt'] !== null ? (int)$m['min_siden_kontakt'] : null) ?></td>
            <td><?= siden($m['min_siden_maaling'] !== null ? (int)$m['min_siden_maaling'] : null) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <h2 id="batterier">Batterier <span class="opdateret">registrér et skift, når du skifter batteri (kræver login)</span></h2>
  <?php if ($skift_fejl): ?>
    <p class="fejl">Batteriskift kan ikke vises: <?= h($skift_fejl) ?> – er sql/opgradering_batteriskift.sql kørt?</p>
  <?php endif; ?>
  <div class="panel tabel-wrap">
    <table class="batteri-tabel">
      <thead>
        <tr><th>Måler</th><th>Type</th><th title="Første måling fra sensoren. Nexus/Vevor skifter ID ved batteriskift, så for dem er det reelt hvornår batteriet kom i">Første måling</th><th>Skiftet</th><th>Batteriets alder</th><th>Forrige holdt</th><th>Registrér skift</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($batterier as $b):
            $k = $skift[$b['noegle']] ?? null;
            $holdt = $k ? dage_mellem($k['forrige'], $k['seneste']) : null;
            $f = $foerste[$b['noegle']] ?? null;
            $f_dato = $f ? substr($f, 0, 10) : null;
            // Batteriet er fra det seneste registrerede skift – ellers mindst fra første måling
            $fra = $k['seneste'] ?? $f_dato;
        ?>
          <tr>
            <td><?= h($b['navn']) ?></td>
            <td class="opdateret"><?= h($b['type']) ?></td>
            <td><?= $f_dato ? date('d.m.Y', strtotime($f_dato)) : '–' ?></td>
            <td><?= $k ? date('d.m.Y', strtotime($k['seneste'])) : '–' ?></td>
            <td class="tal">
              <?php if ($k): ?>
                <?= dage_mellem($fra, $idag) ?> dage
              <?php elseif ($fra): ?>
                <span class="opdateret" title="Intet skift registreret – regnet fra første måling, så batteriet kan være ældre">≥ <?= dage_mellem($fra, $idag) ?> dage</span>
              <?php else: ?>–<?php endif; ?>
            </td>
            <td class="tal"><?= $holdt !== null ? $holdt . ' dage' : '–' ?></td>
            <td>
              <form method="post" action="batteri.php" class="skift-form">
                <input type="hidden" name="maaler" value="<?= h($b['noegle']) ?>">
                <input type="hidden" name="handling" value="skift">
                <input type="date" name="dato" value="<?= $idag ?>" max="<?= $idag ?>" required>
                <button type="submit">Skiftet</button>
              </form>
            </td>
            <td>
              <?php if ($k): ?>
                <form method="post" action="batteri.php" class="skift-form"
                      onsubmit="return confirm(<?= h(json_encode('Slet skiftet ' . date('d.m.Y', strtotime($k['seneste'])) . ' for ' . $b['navn'] . '?', JSON_UNESCAPED_UNICODE)) ?>)">
                  <input type="hidden" name="maaler" value="<?= h($b['noegle']) ?>">
                  <input type="hidden" name="handling" value="fortryd">
                  <button type="submit" class="fortryd" title="Slet seneste registrerede skift">Fortryd</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</main>
</body>
</html>
