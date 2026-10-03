<?php
declare(strict_types=1);
date_default_timezone_set('UTC');

/* ---------- ENV ---------- */
function loadEnv(string $file): void {
    if (!is_file($file)) return;
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $_ENV[trim($k)] = trim(trim($v), "\"'");
    }
}
function env(string $k, ?string $default = null): ?string {
    $v = $_ENV[$k] ?? getenv($k);
    return ($v === false || $v === null || $v === '') ? $default : (string)$v;
}

/* ---------- DB ---------- */
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            env('DB_HOST', 'localhost'), env('DB_PORT', '3306'), env('DB_NAME', ''));
        $pdo = new PDO($dsn, env('DB_USER', ''), env('DB_PASS', ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");
    }
    return $pdo;
}
function q(string $sql, array $args = []): PDOStatement {
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st;
}
function findById(string $table, $id): ?array {
    if (!ctype_digit((string)$id)) return null;
    $row = q("SELECT * FROM `$table` WHERE id = ?", [(int)$id])->fetch();
    return $row ?: null;
}
function updateRow(string $table, int $id, array $set): void {
    if (!$set) return;
    $cols = implode(',', array_map(fn($c) => "`$c` = ?", array_keys($set)));
    q("UPDATE `$table` SET $cols WHERE id = ?", [...array_values($set), $id]);
}

/* ---------- HTTP ---------- */
function respond(int $code, array $data): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}
function respondText(string $text): never {
    header('Content-Type: text/plain; charset=utf-8');
    echo $text;
    exit;
}
function body(): array {
    static $b = null;
    if ($b === null) {
        $j = json_decode((string)file_get_contents('php://input'), true);
        $b = is_array($j) ? $j : $_POST;
    }
    return $b;
}
function s(array $b, string $k): string {
    return isset($b[$k]) && is_scalar($b[$k]) ? trim((string)$b[$k]) : '';
}
function cors(): void {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
    if ($origin) {
        $allowed = array_filter(array_map('trim', explode(',', env('ALLOWED_ORIGINS', ''))));
        if (!$allowed || in_array($origin, $allowed, true)) {
            header("Access-Control-Allow-Origin: $origin");
            header('Vary: Origin');
            header('Access-Control-Allow-Credentials: true');
            header('Access-Control-Allow-Headers: Content-Type, Authorization');
            header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        }
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

/* ---------- JWT (HS256, no library needed) ---------- */
function b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function b64d(string $s): string { return (string)base64_decode(strtr($s, '-_', '+/')); }
function jwtSecret(): string {
    $s = env('JWT_SECRET');
    if (!$s) respond(500, ['message' => 'JWT_SECRET is not configured']);
    return $s;
}
function jwtSign(array $payload, int $ttl): string {
    $h = b64u(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload['iat'] = time();
    $payload['exp'] = time() + $ttl;
    $p = b64u(json_encode($payload));
    return "$h.$p." . b64u(hash_hmac('sha256', "$h.$p", jwtSecret(), true));
}
function jwtVerify(string $token): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$h, $p, $sig] = $parts;
    $hdr = json_decode(b64d($h), true);
    if (($hdr['alg'] ?? '') !== 'HS256') return null;
    if (!hash_equals(b64u(hash_hmac('sha256', "$h.$p", jwtSecret(), true)), $sig)) return null;
    $data = json_decode(b64d($p), true);
    if (!is_array($data) || ($data['exp'] ?? 0) < time()) return null;
    return $data;
}
function setTokenCookie(string $value, int $expires): void {
    $secure = strtolower(env('COOKIE_SECURE', 'true')) !== 'false';
    setcookie('token', $value, [
        'expires' => $expires, 'path' => '/',
        'secure' => $secure, 'httponly' => true,
        'samesite' => $secure ? 'None' : 'Lax',
    ]);
}

/* ---------- Middleware ---------- */
function requireAuth(): array {
    $token = $_COOKIE['token'] ?? null;
    if (!$token) respond(401, ['message' => 'Authentication required']);
    $user = jwtVerify($token);
    if (!$user) respond(401, ['message' => 'Invalid or expired token']);
    return $user;
}
function requireAdmin(): array {
    $user = requireAuth();
    if (($user['role'] ?? '') !== 'admin') respond(403, ['message' => 'Admin access required']);
    return $user;
}

/* ---------- Formatters (keep the same JSON shape the frontend already expects) ---------- */
function iso(?string $d): ?string {
    return $d ? gmdate('Y-m-d\TH:i:s.000\Z', strtotime($d . ' UTC')) : null;
}
function fmtUser(array $r): array {
    return ['_id' => (string)$r['id'], 'name' => $r['name'], 'email' => $r['email'], 'role' => $r['role'],
            'createdAt' => iso($r['created_at']), 'updatedAt' => iso($r['updated_at'])];
}
function fmtCategory(array $r): array {
    return ['_id' => (string)$r['id'], 'name' => $r['name'], 'description' => $r['description'] ?? '',
            'image' => $r['image'] ?? '', 'isActive' => (bool)$r['is_active'],
            'createdAt' => iso($r['created_at']), 'updatedAt' => iso($r['updated_at'])];
}
function fmtService(array $r, ?array $category = null): array {
    return ['_id' => (string)$r['id'], 'name' => $r['name'],
            'category' => $category ?? (string)$r['category_id'],
            'price' => (float)$r['price'], 'priceType' => $r['price_type'],
            'availableFrom' => $r['available_from'], 'availableTo' => $r['available_to'],
            'details' => $r['details'], 'image' => $r['image'] ?? '', 'isActive' => (bool)$r['is_active'],
            'createdAt' => iso($r['created_at']), 'updatedAt' => iso($r['updated_at'])];
}
