<?php
declare(strict_types=1);

function elavonRequest(string $method, string $path, ?array $payload = null): array {
    $base = rtrim(env('ELAVON_BASE_URL', 'https://api.sandbox.elavonpayments.com'), '/');
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . env('ELAVON_ACCESS_TOKEN', ''),
        ],
    ]);
    if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

    $res  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($res === false) return ['ok' => false, 'error' => $err ?: 'Elavon request failed'];
    $data = json_decode($res, true);
    if ($code < 200 || $code >= 300) return ['ok' => false, 'error' => $data ?? $res];
    return ['ok' => true, 'data' => $data];
}

function elavonCreatePaymentLink(float $amount, string $currency, string $email, string $bookingId, string $description): array {
    if ($amount <= 0) return ['success' => false, 'error' => 'Payment amount is required'];

    $expiresAt = gmdate('Y-m-d\TH:i:s.000\Z', time() + 30 * 60);
    $payload = [
        'expiresAt' => $expiresAt,
        'doCapture' => true,
        'total' => ['amount' => number_format($amount, 2, '.', ''), 'currencyCode' => $currency],
        'description' => $description,
        'customReference' => $bookingId,
    ];
    if ($email !== '') $payload['shopperEmailAddress'] = $email;

    $r = elavonRequest('POST', '/payment-links', $payload);
    if (!$r['ok']) {
        error_log('Elavon create error: ' . json_encode($r['error']));
        return ['success' => false, 'error' => $r['error']];
    }
    $d = $r['data'];
    return [
        'success' => true,
        'paymentLinkId' => $d['id'] ?? null,
        'paymentUrl' => $d['url'] ?? null,
        'amount' => $d['total']['amount'] ?? $amount,
        'currencyCode' => $d['total']['currencyCode'] ?? $currency,
        'expiresAt' => $d['expiresAt'] ?? $expiresAt,
    ];
}

function elavonGetPaymentLink(string $id): array {
    $r = elavonRequest('GET', '/payment-links/' . rawurlencode($id));
    return $r['ok'] ? ['success' => true, 'data' => $r['data']] : ['success' => false, 'error' => $r['error']];
}

function elavonCancelPaymentLink(string $id): array {
    $r = elavonRequest('POST', '/payment-links/' . rawurlencode($id), ['doCancel' => true]);
    return $r['ok'] ? ['success' => true, 'data' => $r['data']] : ['success' => false, 'error' => $r['error']];
}
