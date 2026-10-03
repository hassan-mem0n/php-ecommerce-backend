<?php
declare(strict_types=1);

function authRegister(): void {
    $b = body();
    $name = s($b, 'name');
    $email = strtolower(s($b, 'email'));
    $password = isset($b['password']) && is_scalar($b['password']) ? (string)$b['password'] : '';

    if ($name === '' || $email === '' || $password === '') respond(400, ['message' => 'All fields are required']);
    if (strlen($password) < 6) respond(400, ['message' => 'Password must be at least 6 characters']);
    if (q('SELECT id FROM users WHERE email = ?', [$email])->fetch()) respond(400, ['message' => 'User already exists']);

    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
    try {
        q('INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, "user")', [$name, $email, $hash]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') respond(400, ['message' => 'User already exists']);
        throw $e;
    }
    respond(201, [
        'message' => 'User registered successfully',
        'user' => ['id' => (string)db()->lastInsertId(), 'name' => $name, 'email' => $email, 'role' => 'user'],
    ]);
}

function authLogin(): void {
    $b = body();
    $email = strtolower(s($b, 'email'));
    $password = isset($b['password']) && is_scalar($b['password']) ? (string)$b['password'] : '';

    $user = q('SELECT * FROM users WHERE email = ?', [$email])->fetch();
    // password_verify() also accepts hashes created earlier by bcryptjs ($2a$ / $2b$)
    if (!$user || !password_verify($password, $user['password'])) {
        respond(401, ['message' => 'Invalid email or password']);
    }
    $ttl = 7 * 24 * 60 * 60;
    $token = jwtSign(['userId' => (string)$user['id'], 'role' => $user['role']], $ttl);
    setTokenCookie($token, time() + $ttl);

    respond(200, [
        'message' => 'Login successful',
        'user' => ['id' => (string)$user['id'], 'name' => $user['name'], 'email' => $user['email'], 'role' => $user['role']],
    ]);
}

function authLogout(): void {
    setTokenCookie('', time() - 3600);
    respond(200, ['message' => 'Logout successful']);
}

function authMe(array $auth): void {
    $user = findById('users', $auth['userId'] ?? '');
    if (!$user) respond(404, ['message' => 'User not found']);
    respond(200, ['success' => true, 'user' => fmtUser($user)]);
}

function usersAll(): void {
    $rows = q('SELECT * FROM users ORDER BY created_at DESC, id DESC')->fetchAll();
    respond(200, ['success' => true, 'users' => array_map('fmtUser', $rows)]);
}
