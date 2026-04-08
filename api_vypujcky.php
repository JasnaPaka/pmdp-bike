<?php
// PMDP Bike – API pro statistiky výpůjček
// Akce: overview | daily | hourly | stations

require_once __DIR__ . '/config.php';

date_default_timezone_set('Europe/Prague');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$action = $_GET['action'] ?? '';
$days   = max(1, (int)($_GET['days'] ?? 7));

try {
    $pdo   = getDb();
    $since = (new DateTimeImmutable("-{$days} days"))->format('Y-m-d H:i:s');

    switch ($action) {
        case 'overview': echo json_encode(actionOverview($pdo, $since, $days), JSON_UNESCAPED_UNICODE); break;
        case 'daily':    echo json_encode(actionDaily($pdo, $since),           JSON_UNESCAPED_UNICODE); break;
        case 'hourly':   echo json_encode(actionHourly($pdo, $since),          JSON_UNESCAPED_UNICODE); break;
        case 'stations': echo json_encode(actionStations($pdo, $since),        JSON_UNESCAPED_UNICODE); break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Neznámá akce: overview | daily | hourly | stations']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
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

function actionOverview(PDO $pdo, string $since, int $days): array
{
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(count),0) AS total, COUNT(DISTINCT DATE(occurred_at)) AS days_with_data FROM departures WHERE occurred_at >= :s");
    $stmt->execute([':s' => $since]);
    $row = $stmt->fetch();
    $total        = (int)$row['total'];
    $daysWithData = (int)$row['days_with_data'];
    return [
        'days'          => $days,
        'total'         => $total,
        'days_with_data'=> $daysWithData,
        'avg_per_day'   => $daysWithData > 0 ? round($total / $daysWithData, 1) : null,
    ];
}

function actionDaily(PDO $pdo, string $since): array
{
    $stmt = $pdo->prepare("SELECT DATE(occurred_at) AS day, SUM(count) AS trips FROM departures WHERE occurred_at >= :s GROUP BY day ORDER BY day");
    $stmt->execute([':s' => $since]);
    return array_map(fn($r) => ['day' => $r['day'], 'trips' => (int)$r['trips']], $stmt->fetchAll());
}

function actionHourly(PDO $pdo, string $since): array
{
    $stmt = $pdo->prepare("SELECT HOUR(occurred_at) AS hour, SUM(count) AS trips FROM departures WHERE occurred_at >= :s GROUP BY hour ORDER BY hour");
    $stmt->execute([':s' => $since]);
    $byHour = array_fill(0, 24, 0);
    foreach ($stmt->fetchAll() as $r) $byHour[(int)$r['hour']] = (int)$r['trips'];
    $out = [];
    for ($h = 0; $h < 24; $h++) $out[] = ['hour' => $h, 'trips' => $byHour[$h]];
    return $out;
}

function actionStations(PDO $pdo, string $since): array
{
    $stmt = $pdo->prepare("
        SELECT d.station_id, s.name, SUM(d.count) AS trips
        FROM departures d
        LEFT JOIN stations s ON s.station_id = d.station_id
        WHERE d.occurred_at >= :s
        GROUP BY d.station_id, s.name
        ORDER BY trips DESC
    ");
    $stmt->execute([':s' => $since]);
    return array_map(fn($r) => ['station_id' => $r['station_id'], 'name' => $r['name'] ?? $r['station_id'], 'trips' => (int)$r['trips']], $stmt->fetchAll());
}
