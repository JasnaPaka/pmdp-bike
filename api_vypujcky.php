<?php
// PMDP Bike – API pro statistiky výpůjček
// Akce: overview | daily | hourly | stations

require_once __DIR__ . '/config.php';

date_default_timezone_set('Europe/Prague');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$action = $_GET['action'] ?? '';
$days   = max(1, (int)($_GET['days'] ?? 7));
$date   = $_GET['date'] ?? '';  // YYYY-MM-DD, pokud je zadáno, omezí dotaz na konkrétní den

try {
    $pdo = getDb();

    if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $since = (new DateTimeImmutable($date))->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $until = (new DateTimeImmutable($date))->modify('+1 day')->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $days  = 1;
    } else {
        $daysBack = $days - 1;
        $since = (new DateTimeImmutable("-{$daysBack} days"))->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $until = null;
    }

    switch ($action) {
        case 'overview': echo json_encode(actionOverview($pdo, $since, $until, $days), JSON_UNESCAPED_UNICODE); break;
        case 'daily':    echo json_encode(actionDaily($pdo, $since, $until),           JSON_UNESCAPED_UNICODE); break;
        case 'hourly':   echo json_encode(actionHourly($pdo, $since, $until),          JSON_UNESCAPED_UNICODE); break;
        case 'stations':  echo json_encode(actionStations($pdo, $since, $until),        JSON_UNESCAPED_UNICODE); break;
        case 'districts': echo json_encode(actionDistricts($pdo, $since, $until),       JSON_UNESCAPED_UNICODE); break;
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

function whereClause(?string $until): string
{
    return $until !== null ? 'occurred_at >= :s AND occurred_at < :u' : 'occurred_at >= :s';
}

function bindParams(PDOStatement $stmt, string $since, ?string $until): void
{
    $stmt->bindValue(':s', $since);
    if ($until !== null) $stmt->bindValue(':u', $until);
}

function actionOverview(PDO $pdo, string $since, ?string $until, int $days): array
{
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(count),0) AS total, COUNT(DISTINCT DATE(occurred_at)) AS days_with_data FROM departures WHERE " . whereClause($until));
    bindParams($stmt, $since, $until);
    $stmt->execute();
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

function actionDaily(PDO $pdo, string $since, ?string $until): array
{
    $stmt = $pdo->prepare("SELECT DATE(occurred_at) AS day, SUM(count) AS trips FROM departures WHERE " . whereClause($until) . " GROUP BY day ORDER BY day");
    bindParams($stmt, $since, $until);
    $stmt->execute();
    return array_map(fn($r) => ['day' => $r['day'], 'trips' => (int)$r['trips']], $stmt->fetchAll());
}

function actionHourly(PDO $pdo, string $since, ?string $until): array
{
    $stmt = $pdo->prepare("SELECT HOUR(occurred_at) AS hour, SUM(count) AS trips FROM departures WHERE " . whereClause($until) . " GROUP BY hour ORDER BY hour");
    bindParams($stmt, $since, $until);
    $stmt->execute();
    $byHour = array_fill(0, 24, 0);
    foreach ($stmt->fetchAll() as $r) $byHour[(int)$r['hour']] = (int)$r['trips'];
    $out = [];
    for ($h = 0; $h < 24; $h++) $out[] = ['hour' => $h, 'trips' => $byHour[$h]];
    return $out;
}

function actionStations(PDO $pdo, string $since, ?string $until): array
{
    $stmt = $pdo->prepare("
        SELECT d.station_id, s.name, s.district, SUM(d.count) AS trips
        FROM departures d
        LEFT JOIN stations s ON s.station_id = d.station_id
        WHERE " . whereClause($until) . "
        GROUP BY d.station_id, s.name, s.district
        ORDER BY trips DESC
    ");
    bindParams($stmt, $since, $until);
    $stmt->execute();
    return array_map(fn($r) => ['station_id' => $r['station_id'], 'name' => $r['name'] ?? $r['station_id'], 'district' => $r['district'], 'trips' => (int)$r['trips']], $stmt->fetchAll());
}

function actionDistricts(PDO $pdo, string $since, ?string $until): array
{
    $stmt = $pdo->prepare("
        SELECT s.district, s.district_part, SUM(d.count) AS trips
        FROM departures d
        JOIN stations s ON s.station_id = d.station_id
        WHERE " . whereClause($until) . " AND s.district IS NOT NULL
        GROUP BY s.district, s.district_part
        ORDER BY trips DESC
    ");
    bindParams($stmt, $since, $until);
    $stmt->execute();
    return array_map(fn($r) => [
        'district'      => $r['district'],
        'district_part' => $r['district_part'],
        'trips'         => (int)$r['trips'],
    ], $stmt->fetchAll());
}
