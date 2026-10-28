<?php
// JSON-data til graferne: api.php?timer=24&sensor=3  (sensor=0 eller udeladt = alle)
// Returnerer pr. måler en serie af [tid, temperatur, fugt].
// Målerne sender kun ved ændring, så vi tager også den sidste måling
// FØR perioden med – ellers starter trappekurven midt i grafen.

require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$timer = (int)($_GET['timer'] ?? 24);
$timer = max(1, min($timer, 24 * 31));   // 1 time – 31 dage
$sensor = (int)($_GET['sensor'] ?? 0);   // 0 = alle målere

try {
    if ($sensor > 0) {
        $stmt = db()->prepare("SELECT id, name FROM sensors WHERE active = 1 AND id = :id");
        $stmt->execute([':id' => $sensor]);
        $sensors = $stmt->fetchAll();
    } else {
        $sensors = db()->query(
            "SELECT id, name FROM sensors WHERE active = 1 ORDER BY name"
        )->fetchAll();
    }

    $forrige = db()->prepare(
        "SELECT measured_at, temperature, humidity
         FROM measurements
         WHERE sensor_id = :id AND measured_at < NOW() - INTERVAL :t HOUR
         ORDER BY measured_at DESC LIMIT 1"
    );
    $periode = db()->prepare(
        "SELECT measured_at, temperature, humidity
         FROM measurements
         WHERE sensor_id = :id AND measured_at >= NOW() - INTERVAL :t HOUR
         ORDER BY measured_at"
    );

    $fra = date('Y-m-d H:i:s', time() - $timer * 3600);
    $nu  = date('Y-m-d H:i:s');
    $ud  = ['fra' => $fra, 'til' => $nu, 'maalere' => []];

    foreach ($sensors as $s) {
        $punkter = [];

        $forrige->execute([':id' => $s['id'], ':t' => $timer]);
        if ($f = $forrige->fetch()) {
            $punkter[] = [$fra, (float)$f['temperature'], (float)$f['humidity']];
        }

        $periode->execute([':id' => $s['id'], ':t' => $timer]);
        foreach ($periode as $r) {
            $punkter[] = [$r['measured_at'], (float)$r['temperature'], (float)$r['humidity']];
        }

        // Forlæng sidste kendte værdi til "nu", så trappen når højre kant
        if ($punkter) {
            $sidste = end($punkter);
            $punkter[] = [$nu, $sidste[1], $sidste[2]];
        }

        $ud['maalere'][] = ['id' => (int)$s['id'], 'navn' => $s['name'], 'punkter' => $punkter];
    }

    echo json_encode($ud, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['fejl' => $e->getMessage()]);
}
