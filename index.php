<?php
declare(strict_types=1);

require __DIR__ . '/core.php';
require __DIR__ . '/services/elavon.php';
require __DIR__ . '/controllers/auth.php';
require __DIR__ . '/controllers/catalog.php';
require __DIR__ . '/controllers/bookings.php';
require __DIR__ . '/controllers/payments.php';

loadEnv(__DIR__ . '/.env');
cors();

/* ---------- Path (works in a sub-folder and with the old /backendnew prefix) ---------- */
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
if ($base !== '' && str_starts_with($path, $base)) $path = substr($path, strlen($base));
if (str_starts_with($path, '/backendnew')) $path = substr($path, strlen('/backendnew'));
$path = '/' . trim($path, '/');

/* ---------- Routes: [method, pattern, guard, handler] ---------- */
$routes = [
    ['GET',    '/',                                  null,    fn() => respondText('Server is running!')],

    ['POST',   '/api/auth/register',                 null,    'authRegister'],
    ['POST',   '/api/auth/login',                    null,    'authLogin'],
    ['POST',   '/api/auth/logout',                   null,    'authLogout'],
    ['GET',    '/api/auth/me',                       'auth',  'authMe'],

    ['GET',    '/api/admin/dashboard',               'admin', fn($p, $u) => respond(200, ['message' => 'Welcome to Admin Dashboard', 'user' => $u])],
    ['GET',    '/api/users',                         'admin', 'usersAll'],

    ['GET',    '/api/categories',                    null,    'categoryList'],
    ['POST',   '/api/categories',                    'admin', 'categoryCreate'],
    ['PUT',    '/api/categories/:id',                'admin', 'categoryUpdate'],
    ['DELETE', '/api/categories/:id',                'admin', 'categoryDelete'],

    ['GET',    '/api/services',                      null,    'serviceList'],
    ['GET',    '/api/services/:id',                  null,    'serviceGet'],
    ['POST',   '/api/services',                      'admin', 'serviceCreate'],
    ['PUT',    '/api/services/:id',                  'admin', 'serviceUpdate'],
    ['DELETE', '/api/services/:id',                  'admin', 'serviceDelete'],

    ['POST',   '/api/bookings',                      'auth',  'bookingCreate'],
    ['GET',    '/api/bookings/my-bookings',          'auth',  'bookingMine'],
    ['GET',    '/api/bookings',                      'admin', 'bookingAll'],
    ['PUT',    '/api/bookings/:id/status',           'admin', 'bookingUpdateStatus'],

    ['POST',   '/api/payments/elavon',               null,    'paymentCreateElavon'],
    ['GET',    '/api/payments/elavon/status/:bookingId', null, 'paymentCheckElavon'],
];

/* ---------- Dispatch ---------- */
try {
    $pathMatched = false;
    foreach ($routes as [$m, $pattern, $guard, $handler]) {
        $regex = '#^' . preg_replace('#:(\w+)#', '(?P<$1>[^/]+)', $pattern) . '$#';
        if (!preg_match($regex, $path, $mm)) continue;
        $pathMatched = true;
        if ($m !== $method) continue;

        $params = array_filter($mm, 'is_string', ARRAY_FILTER_USE_KEY);
        $user = match ($guard) { 'admin' => requireAdmin(), 'auth' => requireAuth(), default => [] };

        // handlers that take the logged-in user get it as first arg, others get route params
        if (is_string($handler) && in_array($handler, ['authMe', 'bookingCreate', 'bookingMine'], true)) {
            $handler($user);
        } elseif (is_string($handler)) {
            $handler($params);
        } else {
            $handler($params, $user);
        }
        exit;
    }
    respond($pathMatched ? 405 : 404, ['message' => $pathMatched ? 'Method not allowed' : 'Route not found']);
} catch (PDOException $e) {
    error_log('DB error: ' . $e->getMessage());
    respond(500, ['success' => false, 'message' => env('APP_DEBUG') === 'true' ? $e->getMessage() : 'Database error']);
} catch (Throwable $e) {
    error_log($e->getMessage());
    respond(500, ['success' => false, 'message' => env('APP_DEBUG') === 'true' ? $e->getMessage() : 'Server error']);
}
