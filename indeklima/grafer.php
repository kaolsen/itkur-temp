<?php
require __DIR__ . '/db.php';

$valg = [6 => '6 timer', 24 => 'Døgn', 168 => 'Uge', 720 => 'Måned'];

$timer  = (int)($_GET['timer'] ?? 24);
$sensor = (int)($_GET['sensor'] ?? 0);
if (!isset($valg[$timer])) {
    $timer = 24;
}

try {
    $rum = db()->query(
        "SELECT id, name, location FROM sensors WHERE active = 1 ORDER BY sortering, name"
    )->fetchAll();
    $fejl = null;
} catch (Throwable $e) {
    $rum = [];
    $fejl = $e->getMessage();
}
?>
<!doctype html>
<html lang="da">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Indeklima – grafer</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<main>
  <header>
    <h1>Grafer</h1>
    <nav class="topnav">
      <a href="index.php">Oversigt</a>
      <a href="grafer.php" class="aktiv">Grafer</a>
    </nav>
  </header>

  <?php if ($fejl): ?>
    <p class="fejl">Databasefejl: <?= h($fejl) ?></p>
  <?php endif; ?>

  <div class="vaelger">
    <select id="rum" aria-label="Vælg rum">
      <option value="0">Alle rum</option>
      <?php foreach ($rum as $r): ?>
        <option value="<?= (int)$r['id'] ?>" <?= (int)$r['id'] === $sensor ? 'selected' : '' ?>>
          <?= h($r['name']) ?><?= $r['location'] && $r['location'] !== $r['name'] ? ' – ' . h($r['location']) : '' ?>
        </option>
      <?php endforeach; ?>
    </select>
    <div class="valg" id="periode">
      <?php foreach ($valg as $t => $tekst): ?>
        <button type="button" data-timer="<?= $t ?>" class="<?= $t === $timer ? 'aktiv' : '' ?>"><?= h($tekst) ?></button>
      <?php endforeach; ?>
    </div>
  </div>

  <section class="noegletal" id="noegletal" hidden>
    <div class="panel"><div class="etiket">Temperatur nu</div><div class="vaerdi" id="n-nu">–</div></div>
    <div class="panel"><div class="etiket">Laveste i perioden</div><div class="vaerdi" id="n-min">–</div></div>
    <div class="panel"><div class="etiket">Højeste i perioden</div><div class="vaerdi" id="n-max">–</div></div>
    <div class="panel"><div class="etiket">Gennemsnit (tidsvægtet)</div><div class="vaerdi" id="n-snit">–</div></div>
    <div class="panel"><div class="etiket">Luftfugtighed nu</div><div class="vaerdi" id="n-fugt">–</div></div>
  </section>

  <div class="panel">
    <div class="graf"><canvas id="graf-temp"></canvas></div>
    <div class="graf"><canvas id="graf-fugt"></canvas></div>
    <div class="tom" id="tom" hidden>Ingen målinger i perioden.</div>
  </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3.0.0/dist/chartjs-adapter-date-fns.bundle.min.js"></script>
<script>
(() => {
  const css = getComputedStyle(document.documentElement);
  const farve = v => css.getPropertyValue(v).trim();
  const palet = ['#2b6cb0', '#d0573a', '#2f8f5b', '#9b59b6', '#c98a12', '#17a2b8', '#e05297', '#6b8e23'];

  const vaelgRum = document.getElementById('rum');
  const knapper  = document.querySelectorAll('#periode button');
  let timer  = <?= $timer ?>;
  let sensor = <?= $sensor ?>;
  let grafer = [];

  const tal = (v, dec = 1) => v.toFixed(dec).replace('.', ',');

  // Tidsvægtet gennemsnit: hver værdi gælder indtil næste måling (trappekurve)
  function tidsvaegtet(punkter, felt) {
    let sum = 0, tid = 0;
    for (let i = 0; i < punkter.length - 1; i++) {
      const dt = new Date(punkter[i + 1][0].replace(' ', 'T')) - new Date(punkter[i][0].replace(' ', 'T'));
      sum += punkter[i][felt] * dt;
      tid += dt;
    }
    return tid > 0 ? sum / tid : punkter[0][felt];
  }

  function visNoegletal(maaler) {
    const boks = document.getElementById('noegletal');
    if (!maaler || !maaler.punkter.length) { boks.hidden = true; return; }
    const p = maaler.punkter;
    const temps = p.map(x => x[1]);
    const sidste = p[p.length - 1];
    document.getElementById('n-nu').innerHTML   = tal(sidste[1]) + '<small> °C</small>';
    document.getElementById('n-min').innerHTML  = tal(Math.min(...temps)) + '<small> °C</small>';
    document.getElementById('n-max').innerHTML  = tal(Math.max(...temps)) + '<small> °C</small>';
    document.getElementById('n-snit').innerHTML = tal(tidsvaegtet(p, 1)) + '<small> °C</small>';
    document.getElementById('n-fugt').innerHTML = tal(sidste[2], 0) + '<small> %</small>';
    boks.hidden = false;
  }

  function lavGraf(id, data, felt, titel, suffix, enkeltFarve) {
    const enhed = timer <= 24 ? 'hour' : 'day';
    const datasets = data.maalere
      .filter(m => m.punkter.length)
      .map((m, i) => {
        const f = enkeltFarve || palet[i % palet.length];
        return {
          label: m.navn,
          data: m.punkter.map(p => ({ x: p[0].replace(' ', 'T'), y: p[felt] })),
          borderColor: f,
          backgroundColor: enkeltFarve ? f + '22' : f,
          fill: !!enkeltFarve,
          borderWidth: 2,
          pointRadius: 0,
          pointHitRadius: 6,
          stepped: 'before',   // målerne sender kun ved ændring
        };
      });

    return new Chart(document.getElementById(id), {
      type: 'line',
      data: { datasets },
      options: {
        maintainAspectRatio: false,
        animation: false,
        interaction: { mode: 'nearest', axis: 'x', intersect: false },
        plugins: {
          title: { display: true, text: titel, color: farve('--tekst'), align: 'start', font: { size: 14, weight: '600' } },
          legend: { display: !enkeltFarve, labels: { color: farve('--svag'), boxWidth: 12, boxHeight: 12 } },
          tooltip: { callbacks: { label: c => `${c.dataset.label}: ${tal(c.parsed.y)}${suffix}` } },
        },
        scales: {
          x: {
            type: 'time',
            min: data.fra.replace(' ', 'T'),
            max: data.til.replace(' ', 'T'),
            time: { unit: enhed, displayFormats: { hour: 'HH:mm', day: 'd/M' }, tooltipFormat: 'd/M HH:mm' },
            ticks: { color: farve('--svag'), maxRotation: 0 },
            grid: { color: farve('--linje') },
          },
          y: {
            ticks: { color: farve('--svag'), callback: v => v + suffix },
            grid: { color: farve('--linje') },
          },
        },
      },
    });
  }

  async function opdater() {
    // Hold URL'en i trit, så et bogmærke eller genindlæsning viser samme visning
    history.replaceState(null, '', `?sensor=${sensor}&timer=${timer}`);

    const svar = await fetch(`api.php?timer=${timer}&sensor=${sensor}`, { cache: 'no-store' });
    const data = await svar.json();
    if (data.fejl) { console.error(data.fejl); return; }

    grafer.forEach(g => g.destroy());
    grafer = [];

    const harData = data.maalere.some(m => m.punkter.length);
    document.getElementById('tom').hidden = harData;
    document.querySelectorAll('.graf').forEach(g => g.hidden = !harData);
    visNoegletal(sensor > 0 ? data.maalere[0] : null);
    if (!harData) return;

    const enkelt = sensor > 0;
    grafer.push(lavGraf('graf-temp', data, 1, 'Temperatur', ' °C', enkelt ? farve('--temp') : null));
    grafer.push(lavGraf('graf-fugt', data, 2, 'Luftfugtighed', ' %', enkelt ? farve('--fugt') : null));
  }

  vaelgRum.addEventListener('change', () => { sensor = +vaelgRum.value; opdater(); });
  knapper.forEach(k => k.addEventListener('click', () => {
    timer = +k.dataset.timer;
    knapper.forEach(x => x.classList.toggle('aktiv', x === k));
    opdater();
  }));

  opdater();
  setInterval(opdater, 120000);   // frisk data hvert 2. minut uden at genindlæse siden
})();
</script>
</body>
</html>
