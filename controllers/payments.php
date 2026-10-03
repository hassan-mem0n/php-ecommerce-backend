<?php
declare(strict_types=1);

function paymentCreateElavon(): void {
    $bookingId = s(body(), 'bookingId');
    if ($bookingId === '') respond(400, ['success' => false, 'message' => 'Booking ID is required']);

    $booking = findById('bookings', $bookingId);
    if (!$booking) respond(404, ['success' => false, 'message' => 'Booking not found']);
    if ($booking['payment_status'] === 'paid') respond(400, ['success' => false, 'message' => 'Booking is already paid']);

    $result = elavonCreatePaymentLink(
        (float)$booking['total_price'], 'GBP', (string)$booking['customer_email'],
        (string)$booking['id'], 'Event Booking ' . $booking['id']
    );

    if (!$result['success']) {
        updateRow('bookings', (int)$booking['id'], ['payment_status' => 'failed']);
        respond(400, ['success' => false, 'message' => 'Unable to create Elavon payment', 'error' => $result['error']]);
    }

    updateRow('bookings', (int)$booking['id'], [
        'payment_status' => 'pending',
        'payment_link_id' => $result['paymentLinkId'],
        'payment_url' => $result['paymentUrl'],
    ]);

    respond(200, [
        'success' => true, 'message' => 'Elavon payment link created',
        'bookingId' => (string)$booking['id'],
        'paymentLinkId' => $result['paymentLinkId'], 'paymentUrl' => $result['paymentUrl'],
        'amount' => $result['amount'], 'currencyCode' => $result['currencyCode'], 'expiresAt' => $result['expiresAt'],
    ]);
}

function paymentCheckElavon(array $p): void {
    $booking = findById('bookings', $p['bookingId']);
    if (!$booking) respond(404, ['success' => false, 'message' => 'Booking not found']);
    if (!$booking['payment_link_id']) respond(400, ['success' => false, 'message' => 'Payment link not found']);

    $result = elavonGetPaymentLink($booking['payment_link_id']);
    if (!$result['success']) respond(400, ['success' => false, 'message' => 'Unable to check payment', 'error' => $result['error']]);

    respond(200, ['success' => true, 'bookingId' => (string)$booking['id'],
                  'paymentStatus' => $booking['payment_status'], 'elavon' => $result['data']]);
}
