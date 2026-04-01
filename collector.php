<?php
// PMDP Bike - historicky sberac dat

define('DB_PATH',      __DIR__ . '/data/bike.db');
define('INFO_URL',     'https://pmdpbike.admin.freebike.com/api/gbfs/v30/station_information');
define('STATUS_URL',   'https://pmdpbike.admin.freebike.com/api/gbfs/v30/station_status');
define('HTTP_TIMEOUT', 15);

/* ── entry point ── */
try {
    $pdo = getDb();
    $ts  = time();

    $info   = fetchJson(INFO_URL);
    $status = fetchJson(STATUS_URL);

    $stations = mergeStations($info, $status);
    if (empty($stations)) {
        throw new RuntimeException('Žádné stanice z API');
    }

    saveSnapshot($pdo, $stations, $ts);
    aggregateYesterday($pdo);

    echo date('Y-m-d H:i:s', $ts) . ' – OK, ' . count($stations) . " stanic\n";
} catch (Throwable $e) {
    echo date('Y-m-d H:i:s') . ' – CHYBA: ' . $e->getMessage() . "\n";
    exit(1);
}

/* ── database ── */
function getDb(): PDO
{
    $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA synchronous=NORMAL');
    createSchema($pdo);
    return $pdo;
}

function createSchema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS snapshots (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            collected_at     INTEGER NOT NULL,
            total_available  INTEGER NOT NULL,
            total_docks      INTEGER NOT NULL,
            total_capacity   INTEGER NOT NULL,
            station_count    INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_snapshots_collected ON snapshots(collected_at);

        CREATE TABLE IF NOT EXISTS station_snapshots (
            snapshot_id  INTEGER NOT NULL REFERENCES snapshots(id),
            station_id   TEXT    NOT NULL,
            available    INTEGER NOT NULL,
            docks        INTEGER NOT NULL,
            is_renting   INTEGER NOT NULL DEFAULT 1,
            is_installed INTEGER NOT NULL DEFAULT 1
        );
        CREATE INDEX IF NOT EXISTS idx_ss_station ON station_snapshots(station_id, snapshot_id);
        CREATE INDEX IF NOT EXISTS idx_ss_avail   ON station_snapshots(station_id, available, snapshot_id);

        CREATE TABLE IF NOT EXISTS stations (
            station_id TEXT PRIMARY KEY,
            name       TEXT NOT NULL,
            lat        REAL,
            lon        REAL,
            capacity   INTEGER NOT NULL DEFAULT 0,
            first_seen INTEGER NOT NULL,
            last_seen  INTEGER NOT NULL
        );

        CREATE TABLE IF NOT EXISTS daily_aggregates (
            date_bucket    TEXT PRIMARY KEY,
            avg_available  REAL,
            max_available  INTEGER,
            min_available  INTEGER,
            snapshot_count INTEGER
        );
    ");
}

/* ── HTTP fetch ── */
function fetchJson(string $url): array
{
    $ctx = stream_context_create(['http' => [
        'timeout'        => HTTP_TIMEOUT,
        'ignore_errors'  => false,
        'user_agent'     => 'PMDPBikeCollector/1.0',
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        throw new RuntimeException("Nelze stáhnout: $url");
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException("Neplatný JSON z: $url");
    }
    return $data;
}

/* ── merge station_information + station_status ── */
function mergeStations(array $info, array $status): array
{
    $infoMap = [];
    foreach ($info['data']['stations'] ?? [] as $s) {
        $infoMap[$s['station_id']] = $s;
    }

    $stations = [];
    foreach ($status['data']['stations'] ?? [] as $st) {
        $sid = $st['station_id'];
        $inf = $infoMap[$sid] ?? [];

        $nameArr = $inf['name'] ?? [];
        $name    = '';
        foreach ($nameArr as $n) {
            if (($n['language'] ?? '') === 'cs') { $name = $n['text']; break; }
        }
        if ($name === '' && isset($nameArr[0]['text'])) {
            $name = $nameArr[0]['text'];
        }
        if ($name === '') {
            $name = "Stanice $sid";
        }

        $available = (int)($st['num_vehicles_available'] ?? 0);
        $docks     = (int)($st['num_docks_available']    ?? 0);
        $capacity  = (int)($inf['capacity'] ?? ($available + $docks));

        $stations[] = [
            'station_id'   => $sid,
            'name'         => $name,
            'lat'          => isset($inf['lat'])  ? (float)$inf['lat']  : null,
            'lon'          => isset($inf['lon'])  ? (float)$inf['lon']  : null,
            'capacity'     => $capacity,
            'available'    => $available,
            'docks'        => $docks,
            'is_renting'   => (int)(bool)($st['is_renting']   ?? 1),
            'is_installed' => (int)(bool)($st['is_installed'] ?? 1),
        ];
    }
    return $stations;
}

/* ── persist one snapshot ── */
function saveSnapshot(PDO $pdo, array $stations, int $ts): void
{
    $totalAvailable = array_sum(array_column($stations, 'available'));
    $totalDocks     = array_sum(array_column($stations, 'docks'));
    $totalCapacity  = array_sum(array_column($stations, 'capacity'));
    $stationCount   = count($stations);

    $pdo->beginTransaction();

    // UPSERT stations metadata
    $upsertStation = $pdo->prepare("
        INSERT INTO stations (station_id, name, lat, lon, capacity, first_seen, last_seen)
        VALUES (:station_id, :name, :lat, :lon, :capacity, :ts, :ts)
        ON CONFLICT(station_id) DO UPDATE SET
            name      = excluded.name,
            lat       = excluded.lat,
            lon       = excluded.lon,
            capacity  = excluded.capacity,
            last_seen = excluded.last_seen
    ");

    foreach ($stations as $s) {
        $upsertStation->execute([
            ':station_id' => $s['station_id'],
            ':name'       => $s['name'],
            ':lat'        => $s['lat'],
            ':lon'        => $s['lon'],
            ':capacity'   => $s['capacity'],
            ':ts'         => $ts,
        ]);
    }

    // INSERT global snapshot
    $pdo->prepare("
        INSERT INTO snapshots (collected_at, total_available, total_docks, total_capacity, station_count)
        VALUES (:ca, :ta, :td, :tc, :sc)
    ")->execute([
        ':ca' => $ts,
        ':ta' => $totalAvailable,
        ':td' => $totalDocks,
        ':tc' => $totalCapacity,
        ':sc' => $stationCount,
    ]);
    $snapshotId = (int)$pdo->lastInsertId();

    // Batch INSERT station_snapshots
    $insertSS = $pdo->prepare("
        INSERT INTO station_snapshots (snapshot_id, station_id, available, docks, is_renting, is_installed)
        VALUES (:snap_id, :sid, :avail, :docks, :renting, :installed)
    ");
    foreach ($stations as $s) {
        $insertSS->execute([
            ':snap_id'   => $snapshotId,
            ':sid'       => $s['station_id'],
            ':avail'     => $s['available'],
            ':docks'     => $s['docks'],
            ':renting'   => $s['is_renting'],
            ':installed' => $s['is_installed'],
        ]);
    }

    $pdo->commit();
}

/* ── aggregate yesterday once per day ── */
function aggregateYesterday(PDO $pdo): void
{
    $yesterday = date('Y-m-d', strtotime('-1 day'));

    $exists = $pdo->prepare("SELECT 1 FROM daily_aggregates WHERE date_bucket = :d");
    $exists->execute([':d' => $yesterday]);
    if ($exists->fetchColumn()) {
        return; // already done
    }

    $row = $pdo->prepare("
        SELECT
            ROUND(AVG(total_available), 2) AS avg_available,
            MAX(total_available)            AS max_available,
            MIN(total_available)            AS min_available,
            COUNT(*)                        AS snapshot_count
        FROM snapshots
        WHERE date(collected_at, 'unixepoch', 'localtime') = :d
    ");
    $row->execute([':d' => $yesterday]);
    $agg = $row->fetch();

    if (!$agg || $agg['snapshot_count'] == 0) {
        return; // no data for yesterday yet
    }

    $pdo->prepare("
        INSERT OR IGNORE INTO daily_aggregates (date_bucket, avg_available, max_available, min_available, snapshot_count)
        VALUES (:d, :avg, :max, :min, :cnt)
    ")->execute([
        ':d'   => $yesterday,
        ':avg' => $agg['avg_available'],
        ':max' => $agg['max_available'],
        ':min' => $agg['min_available'],
        ':cnt' => $agg['snapshot_count'],
    ]);
}
