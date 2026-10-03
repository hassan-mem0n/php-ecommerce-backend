<?php
declare(strict_types=1);

function timeToMinutes(string $t): ?int {
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($t), $m)) return null;
    return (int)$m[1] * 60 + (int)$m[2];
}

/** Build booking JSON. $populate = true -> full service docs (+ user name/email when $withUser). */
function fetchBookings(string $where, array $args, bool $populate, bool $withUser = false): array {
    $rows = q("SELECT b.*, u.name AS u_name, u.email AS u_email FROM bookings b
               JOIN users u ON u.id = b.user_id $where ORDER BY b.created_at DESC, b.id DESC", $args)->fetchAll();
    if (!$rows) return [];

    $ids = array_column($rows, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $itemRows = q("SELECT * FROM booking_items WHERE booking_id IN ($in) ORDER BY id", $ids)->fetchAll();

    $services = [];
    if ($populate) {
        $sids = array_values(array_unique(array_filter(array_column($itemRows, 'service_id'))));
        if ($sids) {
            $in2 = implode(',', array_fill(0, count($sids), '?'));
            foreach (q("SELECT * FROM services WHERE id IN ($in2)", $sids)->fetchAll() as $s) $services[$s['id']] = fmtService($s);
        }
    }
    $items = [];
    foreach ($itemRows as $i) {
        $svc = $i['service_id'] === null ? null : ($populate ? ($services[$i['service_id']] ?? null) : (string)$i['service_id']);
        $items[$i['booking_id']][] = [
            'service' => $svc, 'name' => $i['name'], 'price' => (float)$i['price'],
            'priceType' => $i['price_type'], 'quantity' => (int)$i['quantity'], 'subtotal' => (float)$i['subtotal'],
        ];
    }
    return array_map(fn($b) => [
        '_id' => (string)$b['id'],
        'user' => $withUser ? ['_id' => (string)$b['user_id'], 'name' => $b['u_name'], 'email' => $b['u_email']] : (string)$b['user_id'],
        'items' => $items[$b['id']] ?? [],
        'bookingDate' => iso($b['booking_date']), 'timeFrom' => $b['time_from'], 'timeTo' => $b['time_to'],
        'totalPrice' => (float)$b['total_price'],
        'customerName' => $b['customer_name'], 'customerEmail' => $b['customer_email'],
        'customerPhone' => $b['customer_phone'], 'customerAddress' => $b['customer_address'],
        'postcode' => $b['postcode'], 'eventType' => $b['event_type'], 'guests' => (int)$b['guests'],
        'notes' => $b['notes'] ?? '', 'status' => $b['status'],
        'paymentStatus' => $b['payment_status'], 'paymentMethod' => $b['payment_method'],
        'paymentId' => $b['payment_id'], 'paymentLinkId' => $b['payment_link_id'], 'paymentUrl' => $b['payment_url'],
        'createdAt' => iso($b['created_at']), 'updatedAt' => iso($b['updated_at']),
    ], $rows);
}

function bookingCreate(array $auth): void {
    $b = body();
    $fail = fn(int $c, string $m) => respond($c, ['success' => false, 'message' => $m]);

    if (empty($auth['userId'])) $fail(401, 'Authentication required');

    $cart = $b['items'] ?? null;
    if (!is_array($cart) || count($cart) === 0) $fail(400, 'At least one service is required');
    if (s($b, 'bookingDate') === '') $fail(400, 'Booking date is required');
    if (s($b, 'timeFrom') === '' || s($b, 'timeTo') === '') $fail(400, 'Booking time is required');
    if (s($b, 'customerName') === '') $fail(400, 'Customer name is required');
    if (s($b, 'customerPhone') === '') $fail(400, 'Customer phone is required');
    if (s($b, 'customerAddress') === '') $fail(400, 'Customer address is required');

    $from = timeToMinutes(s($b, 'timeFrom'));
    $to = timeToMinutes(s($b, 'timeTo'));
    if ($from === null || $to === null) $fail(400, 'Invalid booking time');
    if ($to <= $from) $fail(400, 'End time must be after start time');

    $ts = strtotime(s($b, 'bookingDate'));
    if ($ts === false) $fail(400, 'Invalid booking date');

    $bookingItems = [];
    $total = 0.0;
    foreach ($cart as $ci) {
        $serviceId = is_array($ci) ? ($ci['serviceId'] ?? null) : null;
        if (!$serviceId) $fail(400, 'Service ID is required');

        $qty = $ci['quantity'] ?? 1;
        if (!$qty) $qty = 1;
        if (!is_numeric($qty) || (float)$qty < 1 || floor((float)$qty) != (float)$qty) $fail(400, 'Invalid quantity');
        $qty = (int)$qty;

        $svc = ctype_digit((string)$serviceId) ? q('SELECT * FROM services WHERE id = ? AND is_active = 1', [(int)$serviceId])->fetch() : null;
        if (!$svc) $fail(404, "Service not found or unavailable: $serviceId");

        $price = (float)$svc['price'];
        $subtotal = match ($svc['price_type']) {
            'day'  => $price * $qty,
            'hour' => $price * (($to - $from) / 60) * $qty,
            default => $price * $qty,
        };
        $subtotal = round($subtotal, 2);
        $bookingItems[] = ['service_id' => (int)$svc['id'], 'name' => $svc['name'], 'price' => $price,
                           'price_type' => $svc['price_type'], 'quantity' => $qty, 'subtotal' => $subtotal];
        $total += $subtotal;
    }
    $total = round($total, 2);

    $user = findById('users', $auth['userId']);
    if (!$user) $fail(401, 'User account not found');

    $pdo = db();
    $pdo->beginTransaction();
    try {
        q('INSERT INTO bookings (user_id, booking_date, time_from, time_to, total_price, customer_name, customer_email,
              customer_phone, customer_address, postcode, event_type, guests, notes, status, payment_status, payment_method)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,"pending","pending","elavon")', [
            $user['id'], gmdate('Y-m-d H:i:s', $ts), s($b, 'timeFrom'), s($b, 'timeTo'), $total,
            s($b, 'customerName'), s($b, 'customerEmail') ?: $user['email'], s($b, 'customerPhone'),
            s($b, 'customerAddress'), s($b, 'postcode'), s($b, 'eventType') ?: 'General',
            (int)($b['guests'] ?? 0), s($b, 'notes'),
        ]);
        $bookingId = (int)$pdo->lastInsertId();
        foreach ($bookingItems as $i) {
            q('INSERT INTO booking_items (booking_id, service_id, name, price, price_type, quantity, subtotal) VALUES (?,?,?,?,?,?,?)',
              [$bookingId, $i['service_id'], $i['name'], $i['price'], $i['price_type'], $i['quantity'], $i['subtotal']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $bk = fetchBookings('WHERE b.id = ?', [$bookingId], false)[0];
    respond(201, [
        'success' => true, 'message' => 'Booking created successfully',
        'booking' => array_intersect_key($bk, array_flip(['_id', 'totalPrice', 'paymentStatus', 'status', 'items',
            'customerName', 'customerPhone', 'bookingDate', 'timeFrom', 'timeTo'])),
    ]);
}

function bookingMine(array $auth): void {
    respond(200, ['success' => true, 'bookings' => fetchBookings('WHERE b.user_id = ?', [(int)$auth['userId']], true)]);
}

function bookingAll(): void {
    respond(200, ['success' => true, 'bookings' => fetchBookings('', [], true, true)]);
}

function bookingUpdateStatus(array $p): void {
    $status = s(body(), 'status');
    if (!in_array($status, ['pending', 'confirmed', 'cancelled', 'completed'], true)) {
        respond(400, ['success' => false, 'message' => 'Invalid status value']);
    }
    $bk = findById('bookings', $p['id']);
    if (!$bk) respond(404, ['success' => false, 'message' => 'Booking not found']);
    updateRow('bookings', (int)$bk['id'], ['status' => $status]);
    respond(200, ['success' => true, 'message' => 'Booking status updated',
                  'booking' => fetchBookings('WHERE b.id = ?', [(int)$bk['id']], false)[0]]);
}
