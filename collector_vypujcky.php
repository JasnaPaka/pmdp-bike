<?php
// PMDP Bike – Sběrač výpůjček
// Výpůjčka = konkrétní vehicle_id zmizelo ze stanice mezi dvěma snapshoty.
// (vehicle_id při vrácení kola dle GBFS v3 standardu není perzistentní,
//  takže sledujeme pouze odjezdy, nikoli příjezdy.)
// Doporučené spouštění: každých 5 minut přes cron
// */5 * * * * php /cesta/k/collector_vypujcky.php >> /var/log/pmdp_bike_vypujcky.log 2>&1

require_once __DIR__ . '/config.php';

date_default_timezone_set('Europe/Prague');

define('VEHICLE_STATUS_URL', 'https://pmdpbike.admin.freebike.com/api/gbfs/v30/vehicle_status');
define('STATION_INFO_URL',   'https://pmdpbike.admin.freebike.com/api/gbfs/v30/station_information');
define('HTTP_TIMEOUT',       15);

try {
    $pdo = getDb();
    $now = new DateTimeImmutable('now');

    syncStations($pdo, fetchJson(STATION_INFO_URL), $now);
    [$deps, $total] = processSnapshot($pdo, fetchJson(VEHICLE_STATUS_URL), $now);

    echo $now->format('Y-m-d H:i:s') . " – OK, detekováno výpůjček: $total (ze $deps stanic)\n";
} catch (Throwable $e) {
    echo (new DateTimeImmutable())->format('Y-m-d H:i:s') . ' – CHYBA: ' . $e->getMessage() . "\n";
    exit(1);
}

function getDb(): PDO
{
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
    return new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
}

function fetchJson(string $url): array
{
    $ctx = stream_context_create(['http' => ['timeout' => HTTP_TIMEOUT, 'user_agent' => 'PMDPBikeCollector/1.0']]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) throw new RuntimeException("Nelze stáhnout: $url");
    $data = json_decode($raw, true);
    if (!is_array($data)) throw new RuntimeException("Neplatný JSON z: $url");
    return $data;
}

function syncStations(PDO $pdo, array $info, DateTimeImmutable $now): void
{
    $stmt = $pdo->prepare("
        INSERT INTO stations (station_id, name, lat, lon, updated_at)
        VALUES (:sid, :name, :lat, :lon, :now)
        ON DUPLICATE KEY UPDATE name = VALUES(name), lat = VALUES(lat), lon = VALUES(lon), updated_at = VALUES(updated_at)
    ");
    foreach ($info['data']['stations'] ?? [] as $s) {
        $nameArr = $s['name'] ?? [];
        $name = '';
        foreach ($nameArr as $n) {
            if (($n['language'] ?? '') === 'cs') { $name = $n['text']; break; }
        }
        if ($name === '' && isset($nameArr[0]['text'])) $name = $nameArr[0]['text'];
        $stmt->execute([
            ':sid'  => $s['station_id'],
            ':name' => $name ?: "Stanice {$s['station_id']}",
            ':lat'  => isset($s['lat']) ? (float)$s['lat'] : null,
            ':lon'  => isset($s['lon']) ? (float)$s['lon'] : null,
            ':now'  => $now->format('Y-m-d H:i:s'),
        ]);
    }
}

function processSnapshot(PDO $pdo, array $vehicleData, DateTimeImmutable $now): array
{
    $nowStr = $now->format('Y-m-d H:i:s');

    // Aktuální stav: station_id → [vehicle_id, ...]
    // Ignorujeme kola bez stanice (jsou na cestě) a disabled kola.
    $curr     = [];  // station_id → Set(vehicle_id)
    $disabled = [];  // Set(vehicle_id) — právě označená jako poškozená/nedostupná
    foreach ($vehicleData['data']['vehicles'] ?? [] as $v) {
        if ($v['is_disabled'] ?? false) {
            $disabled[$v['vehicle_id']] = true;
            continue;
        }
        $sid = isset($v['station_id']) && $v['station_id'] !== '' ? (string)$v['station_id'] : null;
        if ($sid === null) continue;
        $curr[$sid][$v['vehicle_id']] = true;
    }

    // Předchozí snapshot
    $prevId = $pdo->query("SELECT id FROM snapshots ORDER BY id DESC LIMIT 1")->fetchColumn();

    $prev = [];  // station_id → Set(vehicle_id)
    if ($prevId) {
        $rows = $pdo->prepare("SELECT station_id, vehicle_id FROM station_vehicles WHERE snapshot_id = ?");
        $rows->execute([$prevId]);
        foreach ($rows->fetchAll() as $r) {
            $prev[$r['station_id']][$r['vehicle_id']] = true;
        }
    }

    // Nový snapshot
    $pdo->prepare("INSERT INTO snapshots (collected_at) VALUES (?)")->execute([$nowStr]);
    $snapId = (int)$pdo->lastInsertId();

    $pdo->beginTransaction();
    try {
        $stmtVehicle = $pdo->prepare("INSERT INTO station_vehicles (snapshot_id, station_id, vehicle_id) VALUES (?,?,?)");
        $stmtDep     = $pdo->prepare("INSERT INTO departures (station_id, occurred_at, count) VALUES (?,?,?)");

        // Uložíme aktuální vozidla
        foreach ($curr as $sid => $vehicles) {
            foreach ($vehicles as $vid => $_) {
                $stmtVehicle->execute([$snapId, $sid, $vid]);
            }
        }

        // Detekujeme zmizení vehicle_id ze stanic
        $totalDeps  = 0;
        $stationDeps = 0;
        foreach ($prev as $sid => $prevVehicles) {
            $currVehicles = $curr[$sid] ?? [];
            $gone  = array_diff_key($prevVehicles, $currVehicles);
            $gone  = array_diff_key($gone, $disabled);  // kola nově označená disabled nejsou výpůjčky
            $count = count($gone);
            if ($count > 0) {
                $stmtDep->execute([$sid, $nowStr, $count]);
                $totalDeps += $count;
                $stationDeps++;
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return [$stationDeps, $totalDeps];
}
