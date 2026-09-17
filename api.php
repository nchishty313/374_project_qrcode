<?php

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$config = require __DIR__ . '/config.php';
$pdo = new PDO(
    "mysql:host={$config->db->host};port={$config->db->port};dbname={$config->db->name};charset={$config->db->charset}",
    $config->db->user,
    $config->db->pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$action = $_GET['action'] ?? $_POST['action'] ?? '';
if ($action === 'check_login') {
    $session = $_GET['session'] ?? '';
    if (!$session) {
        echo json_encode(['ok'=>false,'msg'=>'missing session']);
        exit;
    }

    // Lookup session in DB
    $stmt = $pdo->prepare("SELECT status, token FROM pending_logins WHERE session_id = ? LIMIT 1");
    $stmt->execute([$session]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['ok'=>false,'msg'=>'unknown session']);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'status' => $row['status'], // pending / approved / denied
        'token' => $row['token']
    ]);
    exit;
}

function jsonResp($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

switch ($action) {
    case 'generate_session':
        // create pending session and return session_id + token (hex)
        $session_id = bin2hex(random_bytes(12)); // 24 chars
        $token = random_bytes(16); // 128-bit token
        $tokenHex = bin2hex($token);
        $expires_at = (new DateTime())->modify("+{$GLOBALS['config']->token_ttl} seconds")->format('Y-m-d H:i:s');

        $stmt = $pdo->prepare("INSERT INTO pending_logins (session_id, token, created_at, expires_at, status) VALUES (:session_id, :token, NOW(), :expires_at, 'pending')");
        $stmt->execute([
            ':session_id' => $session_id,
            ':token' => $token,
            ':expires_at' => $expires_at
        ]);
        jsonResp(['ok' => true, 'session_id' => $session_id, 'token_hex' => bin2hex($token), 'expires_at' => $expires_at ]);
        break;

    case 'check_status':
        $session_id = $_GET['session_id'] ?? '';
        if (!$session_id) jsonResp(['ok'=>false,'msg'=>'missing session_id'],400);

        $stmt = $pdo->prepare("SELECT id, status, expires_at FROM pending_logins WHERE session_id = :sid");
        $stmt->execute([':sid'=>$session_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) jsonResp(['ok'=>false,'msg'=>'session not found'],404);

        // expire if expired
        if (new DateTime($row['expires_at']) < new DateTime()) {
            $pdo->prepare("UPDATE pending_logins SET status='expired' WHERE id=:id")->execute([':id'=>$row['id']]);
            jsonResp(['ok'=>true, 'status'=>'expired', 'id'=>$row['id']]);
        }

        jsonResp(['ok'=>true, 'status'=>$row['status'], 'id'=>$row['id']]);
        break;

    case 'approve_login':
    // Phone posts token + integrity JSON
    $raw = json_decode(file_get_contents('php://input'), true);

    // Ensure JSON was parsed
    if (!is_array($raw)) {
        jsonResp(['ok' => false, 'msg' => 'bad json'], 400);
    }

    // Validate token and integrity presence
    $tokenHex = $raw['token'] ?? '';
    $integrity = $raw['integrity'] ?? null;

    if (empty($tokenHex) || empty($integrity)) {
        jsonResp(['ok' => false, 'msg' => 'missing fields'], 400);
    }

    // Validate token format
    if (!ctype_xdigit($tokenHex) || strlen($tokenHex) !== 32) { // 16 bytes = 32 hex chars
        jsonResp(['ok' => false, 'msg' => 'invalid token format'], 400);
    }

    // Convert hex to binary safely
    $token = @hex2bin($tokenHex);
    if ($token === false) {
        jsonResp(['ok' => false, 'msg' => 'token conversion failed'], 400);
    }

    // Lookup pending login by token
    try {
        $stmt = $pdo->prepare("SELECT id, session_id, status, expires_at FROM pending_logins WHERE token = :token LIMIT 1");
        $stmt->execute([':token' => $token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        jsonResp(['ok' => false, 'msg' => 'database error', 'error' => $e->getMessage()], 500);
    }

    if (!$row) {
        jsonResp(['ok' => false, 'msg' => 'invalid token'], 404);
    }

    if ($row['status'] !== 'pending') {
        jsonResp(['ok' => false, 'msg' => 'token not pending', 'status' => $row['status']], 409);
    }

    if (new DateTime($row['expires_at']) < new DateTime()) {
        $pdo->prepare("UPDATE pending_logins SET status='expired' WHERE id=:id")->execute([':id' => $row['id']]);
        jsonResp(['ok' => false, 'msg' => 'token expired'], 410);
    }

    // Calculate risk
    $risk = calculate_risk_score($integrity);
    $summary = generate_summary($integrity, $risk);

    // Save device integrity
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO device_integrity (pending_login_id, payload, risk_score, summary, created_at) 
             VALUES (:pid, :payload, :risk, :summary, NOW())"
        );
        $stmt->execute([
            ':pid' => $row['id'],
            ':payload' => json_encode($integrity, JSON_UNESCAPED_UNICODE),
            ':risk' => $risk,
            ':summary' => $summary
        ]);
        $integrity_id = $pdo->lastInsertId();

        // Update pending login status
        $status = ($risk >= $config->risk_threshold) ? 'denied' : 'approved';
        $stmt = $pdo->prepare(
            "UPDATE pending_logins SET status=:status, integrity_id=:iid, ip=:ip, user_agent=:ua WHERE id=:id"
        );
        $stmt->execute([
            ':status' => $status,
            ':iid' => $integrity_id,
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            ':id' => $row['id']
        ]);

        jsonResp(['ok' => true, 'status' => $status, 'risk' => $risk, 'summary' => $summary]);

    } catch (PDOException $e) {
        jsonResp(['ok' => false, 'msg' => 'database error', 'error' => $e->getMessage()], 500);
    }

    break;


    case 'get_integrity':
        $pending_id = (int)($_GET['pending_id'] ?? 0);
        if (!$pending_id) jsonResp(['ok'=>false,'msg'=>'missing id'],400);
        $stmt = $pdo->prepare("SELECT payload, risk_score, summary, created_at FROM device_integrity WHERE pending_login_id = :pid");
        $stmt->execute([':pid'=>$pending_id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$r) jsonResp(['ok'=>false,'msg'=>'not found'],404);
        jsonResp(['ok'=>true, 'data'=>$r]);
        break;

    default:
        jsonResp(['ok'=>false, 'msg'=>'unknown action'],400);
}


function calculate_risk_score(array $integrity): int {
    $score = 0;
    // Age of browser
    $ua = $integrity['userAgent'] ?? '';
    if (preg_match('/(Chrome|Firefox|Safari|Edge)\/(\d+)/i', $ua, $m)) {
        $browser = strtolower($m[1]);
        $ver = (int)$m[2];
        if ($browser === 'chrome' && $ver < 90) $score += 15;
        if ($browser === 'firefox' && $ver < 90) $score += 15;
    } else {
        $score += 10; // unknown UA
    }

    // Incognito / Private mode heuristic
    if (!empty($integrity['incognito'])) $score += 10;

    // If JS features missing, suspicious 
    $features = $integrity['features'] ?? [];
    if (empty($features['webgl'])) $score += 10;
    if (empty($features['touch'])) $score += 5;

    // Sensor availability: if all sensors disabled, suspicious
    $sensors = $integrity['sensors'] ?? [];
    if (empty($sensors['accelerometer']) && empty($sensors['gyroscope'])) $score += 8;

    // Battery API spoofing: if battery charging info seems impossible 
    if (!empty($integrity['battery']) && isset($integrity['battery']['level'])) {
        $level = floatval($integrity['battery']['level']);
        if ($level < 0 || $level > 1) $score += 20; // spoofed
    }

    // Timezone mismatch vs reported timezone offset
    if (isset($integrity['timezone_offset']) && isset($integrity['client_time'])) {
        
        $offset = (int)$integrity['timezone_offset'];
        if (abs($offset) > 14*60) $score += 10;
    }

    
    $perms = $integrity['permissions'] ?? [];
    if (isset($perms['camera']) && $perms['camera'] === 'denied') $score += 3;

    
    return $score;
}

function generate_summary(array $integrity, int $risk): string {

    $browser   = $integrity['browser']      ?? 'Not Provided';
    $platform  = $integrity['platform']     ?? 'Not Provided';
    $timezone  = $integrity['timezone']     ?? 'Not Provided';
    $model     = $integrity['deviceModel']  ?? 'Not Provided';
    $os        = $integrity['osVersion']    ?? 'Not Provided';

    $riskLevel = $risk < 10 ? "Low"
               : ($risk < 20 ? "Medium"
               : "High");

    $yesNo = fn($v) => !empty($v) ? "Yes" : "No";

    $incognito = $yesNo($integrity['incognito'] ?? false);
    $adblock   = $yesNo($integrity['adblock']   ?? false);

    return 
        "Device Model: {$model}\n" .
        "OS Version: {$os}\n" .
        "Browser: {$browser}\n" .
        "Platform: {$platform}\n" .
        "Timezone: {$timezone}\n" .
        "Incognito Mode: {$incognito}\n" .
        "AdBlock Detected: {$adblock}\n" .
        "Overall Risk: {$riskLevel} ({$risk})";
}
