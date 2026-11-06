<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

function respond($status, $payload)
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function connectDatabase($config)
{
    foreach (['host', 'port', 'database', 'username', 'password'] as $key) {
        if (!isset($config[$key]) || $config[$key] === '' || strpos($config[$key], 'YOUR_') === 0) {
            respond(500, ['error' => 'Database configuration is incomplete.']);
        }
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $config['host'],
        $config['port'],
        $config['database']
    );

    try {
        return new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $error) {
        error_log('Muscle Quest database connection failed: ' . $error->getMessage());
        respond(500, ['error' => 'The leaderboard database is unavailable.']);
    }
}

function uuidV4()
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);

    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' .
        substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
}

$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '';
if ($method !== 'GET' && $method !== 'POST') {
    header('Allow: GET, POST');
    respond(405, ['error' => 'Method not allowed.']);
}

$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
    respond(500, ['error' => 'Create api/config.php from api/config.example.php first.']);
}
$config = require $configPath;
if (!is_array($config)) {
    respond(500, ['error' => 'Database configuration is invalid.']);
}
$database = connectDatabase($config);

if ($method === 'GET') {
    $level = null;
    if (isset($_GET['level'])) {
        $value = (string) $_GET['level'];
        if (!in_array($value, ['1', '2', '3'], true)) {
            respond(400, ['error' => 'Level must be 1, 2, or 3.']);
        }
        $level = (int) $value;
    }

    $sql = 'SELECT id, player, name, level, score, total, pct, ' .
        'CAST(UNIX_TIMESTAMP(created_at) * 1000 AS UNSIGNED) AS ts ' .
        'FROM quiz_scores ';
    if ($level !== null) {
        $sql .= 'WHERE level = :level ';
    }
    $sql .= 'ORDER BY pct DESC, total DESC, created_at ASC LIMIT 50';
    try {
        $statement = $database->prepare($sql);
        if ($level !== null) {
            $statement->bindValue(':level', $level, PDO::PARAM_INT);
        }
        $statement->execute();

        $rows = array_map(function ($row) {
            $row['level'] = (int) $row['level'];
            $row['score'] = (int) $row['score'];
            $row['total'] = (int) $row['total'];
            $row['pct'] = (int) $row['pct'];
            $row['ts'] = (int) $row['ts'];
            return $row;
        }, $statement->fetchAll());

        respond(200, $rows);
    } catch (PDOException $error) {
        error_log('Muscle Quest leaderboard query failed: ' . $error->getMessage());
        respond(500, ['error' => 'The leaderboard could not be loaded.']);
    }
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false || strlen($rawBody) > 4096) {
    respond(413, ['error' => 'Request body is too large.']);
}
$entry = json_decode($rawBody, true);
if (!is_array($entry)) {
    respond(400, ['error' => 'Request body must be valid JSON.']);
}

$player = isset($entry['player']) && is_string($entry['player']) ? $entry['player'] : '';
$name = isset($entry['name']) && is_string($entry['name']) ? trim($entry['name']) : '';
$level = isset($entry['level']) && is_int($entry['level']) ? $entry['level'] : 0;
$score = isset($entry['score']) && is_int($entry['score']) ? $entry['score'] : -1;
$total = isset($entry['total']) && is_int($entry['total']) ? $entry['total'] : 0;

if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $player)) {
    respond(422, ['error' => 'Player ID is invalid.']);
}
if ($name === '') {
    $name = 'Anonymous';
}
if (!preg_match('//u', $name)) {
    respond(422, ['error' => 'Name must be valid UTF-8.']);
}
preg_match_all('/./us', $name, $characters);
if (count($characters[0]) > 20) {
    respond(422, ['error' => 'Name must be 20 characters or fewer.']);
}
if ($level < 1 || $level > 3 || $total < 1 || $total > 500 ||
    $score < 0 || $score > $total ||
    ($level === 1 && $total !== 10) ||
    ($level === 2 && $total !== 30)) {
    respond(422, ['error' => 'Score or quiz level is invalid.']);
}

try {
    $statement = $database->prepare(
        'INSERT INTO quiz_scores (id, player, name, level, score, total) ' .
        'VALUES (:id, :player, :name, :level, :score, :total)'
    );
    $statement->execute([
        ':id' => uuidV4(),
        ':player' => $player,
        ':name' => $name,
        ':level' => $level,
        ':score' => $score,
        ':total' => $total,
    ]);
} catch (PDOException $error) {
    error_log('Muscle Quest score insert failed: ' . $error->getMessage());
    respond(500, ['error' => 'The score could not be saved.']);
}

respond(201, ['ok' => true]);
