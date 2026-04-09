<?php
// PMDP Bike – Doplnění městského obvodu a části obce ke stanicím
// Volá službu https://tools.jasnapaka.com/mestske-obvody-plzen/
// Spusť jednou (nebo opakovaně pro nové stanice bez obvodu):
//   php enrich_stations.php

require_once __DIR__ . '/config.php';

date_default_timezone_set('Europe/Prague');

define('DISTRICT_API', 'https://tools.jasnapaka.com/mestske-obvody-plzen/service.php');
define('HTTP_TIMEOUT', 10);

$pdo      = getDb();
$stations = $pdo->query("SELECT station_id, name, lat, lon FROM stations WHERE lat IS NOT NULL AND lon IS NOT NULL AND district IS NULL")->fetchAll();

if (!$stations) {
    echo "Žádné stanice ke zpracování.\n";
    exit(0);
}

echo "Zpracovávám " . count($stations) . " stanic…\n";

$stmt = $pdo->prepare("UPDATE stations SET district = ?, district_part = ? WHERE station_id = ?");
$ok   = 0;
$err  = 0;

foreach ($stations as $s) {
    $url = DISTRICT_API . '?' . http_build_query(['lat' => $s['lat'], 'long' => $s['lon'], 'format' => 'json']);
    $ctx = stream_context_create(['http' => ['timeout' => HTTP_TIMEOUT, 'user_agent' => 'PMDPBikeEnrich/1.0']]);
    $raw = @file_get_contents($url, false, $ctx);

    if ($raw === false) {
        echo "CHYBA [{$s['station_id']}] {$s['name']}: nelze stáhnout\n";
        $err++;
        continue;
    }

    $data = json_decode($raw, true);

    if (empty($data['umo'])) {
        echo "MIMO  [{$s['station_id']}] {$s['name']}: mimo obvody Plzně\n";
        $err++;
        continue;
    }

    $stmt->execute([$data['umo'], $data['part'] ?? null, $s['station_id']]);
    echo "OK    [{$s['station_id']}] {$s['name']}: {$data['umo']}, {$data['part']}\n";
    $ok++;

    usleep(100_000); // 100 ms pauza – slušnost vůči API
}

echo "\nHotovo: $ok OK, $err chyb.\n";

function getDb(): PDO
{
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
    return new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
}
