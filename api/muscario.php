<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

function muscarioRespond($status, $payload)
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function muscarioConnectDatabase($config)
{
    foreach (['host', 'port', 'database', 'username', 'password'] as $key) {
        if (!isset($config[$key]) || $config[$key] === '' || strpos($config[$key], 'YOUR_') === 0) {
            muscarioRespond(500, ['error' => 'Database configuration is incomplete.']);
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
        error_log('Muscario database connection failed: ' . $error->getMessage());
        muscarioRespond(500, ['error' => 'The online arena is unavailable.']);
    }
}

function muscarioRadius($mass)
{
    return 17 + sqrt($mass) * 2.5;
}

function muscarioColor($id)
{
    $hex = substr(str_replace('-', '', $id), 0, 8);
    return hexdec($hex) % 7;
}

$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '';
if ($method !== 'POST') {
    header('Allow: POST');
    muscarioRespond(405, ['error' => 'Method not allowed.']);
}

$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
    muscarioRespond(500, ['error' => 'Create api/config.php from api/config.example.php first.']);
}
$config = require $configPath;
if (!is_array($config)) {
    muscarioRespond(500, ['error' => 'Database configuration is invalid.']);
}
$database = muscarioConnectDatabase($config);

$rawBody = file_get_contents('php://input');
if ($rawBody === false || strlen($rawBody) > 4096) {
    muscarioRespond(413, ['error' => 'Request body is too large.']);
}
$entry = json_decode($rawBody, true);
if (!is_array($entry)) {
    muscarioRespond(400, ['error' => 'Request body must be valid JSON.']);
}

$id = isset($entry['id']) && is_string($entry['id']) ? strtolower($entry['id']) : '';
$action = isset($entry['action']) && is_string($entry['action']) ? $entry['action'] : '';
if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $id)) {
    muscarioRespond(422, ['error' => 'Player ID is invalid.']);
}
if (!in_array($action, ['start', 'heartbeat', 'leave'], true)) {
    muscarioRespond(422, ['error' => 'Player action is invalid.']);
}

$name = isset($entry['name']) && is_string($entry['name']) ? trim($entry['name']) : '';
if (!preg_match('//u', $name)) {
    muscarioRespond(422, ['error' => 'Player name must be valid UTF-8.']);
}
preg_match_all('/./us', $name, $characters);
if (count($characters[0]) > 20) {
    muscarioRespond(422, ['error' => 'Player name must be 20 characters or fewer.']);
}
if ($name === '') {
    $name = 'Anonymous';
}

$x = isset($entry['x']) && is_numeric($entry['x']) ? (float) $entry['x'] : -1;
$y = isset($entry['y']) && is_numeric($entry['y']) ? (float) $entry['y'] : -1;
$mass = isset($entry['mass']) && is_numeric($entry['mass']) ? (float) $entry['mass'] : -1;
if (!is_finite($x) || !is_finite($y) || !is_finite($mass) ||
    $x < 0 || $x > 2800 || $y < 0 || $y > 1900 || $mass < 12) {
    muscarioRespond(422, ['error' => 'Player position or muscle mass is invalid.']);
}

try {
    $database->beginTransaction();

    if ($action === 'start') {
        $database->exec(
            'DELETE FROM muscario_players WHERE last_seen < UTC_TIMESTAMP(3) - INTERVAL 1 DAY'
        );
        $statement = $database->prepare(
            'INSERT INTO muscario_players (player_id, name, x, y, mass, alive, active, last_seen) ' .
            'VALUES (:id, :name, :x, :y, 12, 1, 1, UTC_TIMESTAMP(3)) ' .
            'ON DUPLICATE KEY UPDATE name = VALUES(name), x = VALUES(x), y = VALUES(y), ' .
            'mass = 12, alive = 1, active = 1, last_seen = UTC_TIMESTAMP(3)'
        );
        $statement->execute([':id' => $id, ':name' => $name, ':x' => $x, ':y' => $y]);
    } elseif ($action === 'leave') {
        $statement = $database->prepare(
            'UPDATE muscario_players SET active = 0, last_seen = UTC_TIMESTAMP(3) WHERE player_id = :id'
        );
        $statement->execute([':id' => $id]);
        $database->commit();
        muscarioRespond(200, ['alive' => false, 'players' => []]);
    }

    $statement = $database->prepare(
        'SELECT player_id, name, x, y, mass, alive, active FROM muscario_players ' .
        'WHERE active = 1 AND last_seen >= UTC_TIMESTAMP(3) - INTERVAL 20 SECOND ' .
        'ORDER BY player_id FOR UPDATE'
    );
    $statement->execute();
    $players = $statement->fetchAll();
    $ownIndex = null;
    foreach ($players as $index => $onlinePlayer) {
        if ($onlinePlayer['player_id'] === $id) {
            $ownIndex = $index;
            break;
        }
    }

    if ($ownIndex === null || (int) $players[$ownIndex]['alive'] !== 1) {
        $database->commit();
        muscarioRespond(200, ['alive' => false, 'players' => []]);
    }

    $currentMass = max((float) $players[$ownIndex]['mass'], $mass);
    $players[$ownIndex]['name'] = $name;
    $players[$ownIndex]['x'] = $x;
    $players[$ownIndex]['y'] = $y;
    $players[$ownIndex]['mass'] = $currentMass;

    foreach ($players as $index => $other) {
        if ($index === $ownIndex || (int) $other['alive'] !== 1) {
            continue;
        }
        $otherMass = (float) $other['mass'];
        $distance = hypot($x - (float) $other['x'], $y - (float) $other['y']);
        $ownRadius = muscarioRadius($currentMass);
        $otherRadius = muscarioRadius($otherMass);
        if ($distance >= $ownRadius + $otherRadius - min($ownRadius, $otherRadius) * 0.45) {
            continue;
        }

        if ($ownRadius > $otherRadius * 1.12) {
            $currentMass += $otherMass * 0.8;
            $players[$ownIndex]['mass'] = $currentMass;
            $players[$index]['alive'] = 0;
            $statement = $database->prepare(
                'UPDATE muscario_players SET alive = 0 WHERE player_id = :id AND alive = 1'
            );
            $statement->execute([':id' => $other['player_id']]);
        } elseif ($otherRadius > $ownRadius * 1.12) {
            $currentMass = 0;
            $players[$ownIndex]['alive'] = 0;
            $players[$index]['mass'] = $otherMass + (float) $players[$ownIndex]['mass'] * 0.8;
            $statement = $database->prepare(
                'UPDATE muscario_players SET alive = 0 WHERE player_id = :id AND alive = 1'
            );
            $statement->execute([':id' => $id]);
            $statement = $database->prepare(
                'UPDATE muscario_players SET mass = :mass WHERE player_id = :id AND alive = 1'
            );
            $statement->execute([':mass' => $players[$index]['mass'], ':id' => $other['player_id']]);
            break;
        }
    }

    if ($currentMass > 0) {
        $statement = $database->prepare(
            'UPDATE muscario_players SET name = :name, x = :x, y = :y, mass = :mass, last_seen = UTC_TIMESTAMP(3) ' .
            'WHERE player_id = :id AND alive = 1'
        );
        $statement->execute([':name' => $name, ':x' => $x, ':y' => $y, ':mass' => $currentMass, ':id' => $id]);
    } else {
        $statement = $database->prepare(
            'UPDATE muscario_players SET last_seen = UTC_TIMESTAMP(3) WHERE player_id = :id'
        );
        $statement->execute([':id' => $id]);
    }

    $result = [];
    foreach ($players as $onlinePlayer) {
        if ((int) $onlinePlayer['alive'] !== 1) {
            continue;
        }
        if ($onlinePlayer['player_id'] === $id && $currentMass > 0) {
            $onlinePlayer['mass'] = $currentMass;
        }
        $result[] = [
            'id' => $onlinePlayer['player_id'],
            'name' => $onlinePlayer['name'],
            'x' => (float) $onlinePlayer['x'],
            'y' => (float) $onlinePlayer['y'],
            'mass' => (float) $onlinePlayer['mass'],
            'color' => muscarioColor($onlinePlayer['player_id']),
        ];
    }

    $database->commit();
    muscarioRespond(200, ['alive' => $currentMass > 0, 'players' => $result]);
} catch (PDOException $error) {
    if ($database->inTransaction()) {
        $database->rollBack();
    }
    error_log('Muscario arena update failed: ' . $error->getMessage());
    muscarioRespond(500, ['error' => 'The online arena could not be updated.']);
}
