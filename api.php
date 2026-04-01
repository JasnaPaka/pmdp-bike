<?php
// PMDP Bike – JSON API
// GET ?action=extremes
// GET ?action=station_health&days=30
// GET ?action=timeseries&days=7&granularity=hour

define('DB_PATH', __DIR__ . '/data/bike.db');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$action = $_GET['action'] ?? '';

try {
    $pdo = getDb();

    switch ($action) {
        case 'extremes':
            echo json_encode(actionExtremes($pdo), JSON_UNESCAPED_UNICODE);
            break;
        case 'station_health':
            $days = max(1, (int)($_GET['days'] ?? 30));
            echo json_encode(actionStationHealth($pdo, $days), JSON_UNESCAPED_UNICODE);
            break;
        case 'timeseries':
            $days        = max(1, (int)($_GET['days']        ?? 7));
            $granularity = $_GET['granularity'] ?? 'hour';
            echo json_encode(actionTimeseries($pdo, $days, $granularity), JSON_UNESCAPED_UNICODE);
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Neznámá akce. Použij: extremes, station_health, timeseries']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

/* ── database ── */
function getDb(): PDO
{
    if (!file_exists(DB_PATH)) {
        throw new RuntimeException('Databáze neexistuje. Spusť collector.php.');
    }
    $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA query_only=1');
    return $pdo;
}

/* ── extremes ── */
function actionExtremes(PDO $pdo): array
{
    $maxRow = $pdo->query("
        SELECT collected_at, total_available, total_docks
        FROM snapshots
        ORDER BY total_available DESC
        LIMIT 1
    ")->fetch();

    $minRow = $pdo->query("
        SELECT collected_at, total_available, total_docks
        FROM snapshots
        WHERE station_count >= 10
        ORDER BY total_available ASC
        LIMIT 1
    ")->fetch();

    $formatRow = function($row) {
        if (!$row) return null;
        return [
            'collected_at'    => (int)$row['collected_at'],
            'datetime'        => date('j. n. Y H:i', (int)$row['collected_at']),
            'total_available' => (int)$row['total_available'],
            'total_docks'     => (int)$row['total_docks'],
        ];
    };

    return [
        'max' => $formatRow($maxRow),
        'min' => $formatRow($minRow),
    ];
}

/* ── station health ── */
function actionStationHealth(PDO $pdo, int $days): array
{
    $since = time() - $days * 86400;

    $stmt = $pdo->prepare("
        SELECT
            s.station_id,
            st.name,
            st.capacity,
            COUNT(*) AS total_snaps,
            ROUND(100.0 * SUM(CASE WHEN s.available = 0 THEN 1 ELSE 0 END) / COUNT(*), 1) AS empty_pct,
            ROUND(100.0 * SUM(CASE WHEN s.docks = 0    THEN 1 ELSE 0 END) / COUNT(*), 1) AS full_pct,
            ROUND(AVG(s.available), 1) AS avg_available
        FROM station_snapshots s
        JOIN snapshots sn ON sn.id = s.snapshot_id
        JOIN stations  st ON st.station_id = s.station_id
        WHERE sn.collected_at >= :since
          AND s.is_installed = 1
        GROUP BY s.station_id
        HAVING total_snaps >= 10
        ORDER BY empty_pct DESC
    ");
    $stmt->execute([':since' => $since]);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $row['total_snaps']    = (int)$row['total_snaps'];
        $row['capacity']       = (int)$row['capacity'];
        $row['empty_pct']      = (float)$row['empty_pct'];
        $row['full_pct']       = (float)$row['full_pct'];
        $row['avg_available']  = (float)$row['avg_available'];

        if ($row['empty_pct'] > 50) {
            $row['label'] = 'chronically_empty';
        } elseif ($row['full_pct'] > 50) {
            $row['label'] = 'chronically_full';
        } else {
            $row['label'] = 'balanced';
        }
    }
    unset($row);

    return ['days' => $days, 'stations' => $rows];
}

/* ── timeseries ── */
function actionTimeseries(PDO $pdo, int $days, string $granularity): array
{
    $since       = time() - $days * 86400;
    $validGranul = ['hour', 'day', 'week'];
    if (!in_array($granularity, $validGranul, true)) {
        $granularity = 'hour';
    }

    // For long periods use daily_aggregates + supplemental unavailable query
    if ($days > 30 && $granularity !== 'hour') {
        $stmt = $pdo->prepare("
            SELECT
                date_bucket          AS bucket,
                avg_available,
                max_available,
                min_available,
                snapshot_count
            FROM daily_aggregates
            WHERE date_bucket >= date(:since, 'unixepoch', 'localtime')
            ORDER BY bucket
        ");
        $stmt->execute([':since' => $since]);
        $rows = $stmt->fetchAll();

        // Unavailable per day from raw snapshots
        $uStmt = $pdo->prepare("
            SELECT
                strftime('%Y-%m-%d', collected_at, 'unixepoch', 'localtime') AS bucket,
                ROUND(AVG(total_capacity - total_available - total_docks), 0) AS avg_unavailable,
                MAX(total_capacity - total_available - total_docks)           AS max_unavailable,
                MIN(total_capacity - total_available - total_docks)           AS min_unavailable
            FROM snapshots
            WHERE collected_at >= :since
            GROUP BY bucket
        ");
        $uStmt->execute([':since' => $since]);
        $uMap = [];
        foreach ($uStmt->fetchAll() as $u) {
            $uMap[$u['bucket']] = $u;
        }

        foreach ($rows as &$row) {
            $row['avg_available']   = (float)$row['avg_available'];
            $row['max_available']   = (int)$row['max_available'];
            $row['min_available']   = (int)$row['min_available'];
            $row['snapshot_count']  = (int)$row['snapshot_count'];
            $u = $uMap[$row['bucket']] ?? null;
            $row['avg_unavailable'] = $u ? (int)$u['avg_unavailable'] : null;
            $row['max_unavailable'] = $u ? (int)$u['max_unavailable'] : null;
            $row['min_unavailable'] = $u ? (int)$u['min_unavailable'] : null;
        }
        unset($row);

        return ['days' => $days, 'granularity' => 'day', 'source' => 'daily_aggregates', 'points' => $rows];
    }

    // Raw snapshots
    $bucketExpr = match($granularity) {
        'week' => "strftime('%Y-W%W', collected_at, 'unixepoch', 'localtime')",
        'day'  => "strftime('%Y-%m-%d', collected_at, 'unixepoch', 'localtime')",
        default => "strftime('%Y-%m-%dT%H:00:00', collected_at, 'unixepoch', 'localtime')",
    };

    $stmt = $pdo->prepare("
        SELECT
            $bucketExpr AS bucket,
            ROUND(AVG(total_available), 0)                               AS avg_available,
            MAX(total_available)                                          AS max_available,
            MIN(total_available)                                          AS min_available,
            ROUND(AVG(total_capacity - total_available - total_docks), 0) AS avg_unavailable,
            MAX(total_capacity - total_available - total_docks)           AS max_unavailable,
            MIN(total_capacity - total_available - total_docks)           AS min_unavailable,
            COUNT(*)                                                      AS snapshot_count
        FROM snapshots
        WHERE collected_at >= :since
        GROUP BY bucket
        ORDER BY bucket
    ");
    $stmt->execute([':since' => $since]);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $row['avg_available']   = (float)$row['avg_available'];
        $row['max_available']   = (int)$row['max_available'];
        $row['min_available']   = (int)$row['min_available'];
        $row['avg_unavailable'] = (int)$row['avg_unavailable'];
        $row['max_unavailable'] = (int)$row['max_unavailable'];
        $row['min_unavailable'] = (int)$row['min_unavailable'];
        $row['snapshot_count']  = (int)$row['snapshot_count'];
    }
    unset($row);

    return ['days' => $days, 'granularity' => $granularity, 'source' => 'snapshots', 'points' => $rows];
}
