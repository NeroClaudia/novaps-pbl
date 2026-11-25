<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/includes/bootstrap.php';
require_once __DIR__ . '/../../app/functions/bookings.php';

$auth = require_customer_page();

header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

$code = $_GET['order'] ?? '';
$code = is_string($code) ? strtoupper(trim($code)) : '';

if (!preg_match('/^[A-Z0-9]{8}$/', $code)) {
    http_response_code(400);
    exit('Invalid booking code.');
}

try {
    $booking = get_customer_booking(
        $pdo,
        (int) $auth['user_id'],
        $code
    );
} catch (Throwable $e) {
    error_log('Receipt error: ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to load receipt.');
}

if ($booking === null) {
    http_response_code(404);
    exit('Receipt not found.');
}

$method = $booking['payment_method'];
$status = $booking['payment']['status'];

// QRIS: struk setelah upload bukti berhasil.
// Cash: struk langsung setelah booking dibuat.
$allowed = (
    $method === 'Cash'
    && in_array($status, ['Pending', 'Paid'], true)
) || (
    $method === 'QRIS'
    && in_array($status, ['AwaitingVerification', 'Paid'], true)
);

if (!$allowed) {
    http_response_code(403);
    exit('Receipt is not available for this order.');
}

function receiptEscape(mixed $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function receiptMoney(float $value): string
{
    return 'Rp' . number_format($value, 0, ',', '.');
}

$date = (new DateTimeImmutable($booking['created_at']))
    ->setTimezone(new DateTimeZone('Asia/Jakarta'))
    ->format('d M Y, H:i');

$statusLabel = match ($status) {
    'Paid' => 'PAID',
    'AwaitingVerification' => 'WAITING VERIFICATION',
    default => 'PENDING CASH PAYMENT'
};

$statusNote = match ($status) {
    'Paid' => 'Payment has been verified by NovaPS.',
    'AwaitingVerification' =>
        'Payment proof received. Awaiting admin verification.',
    default =>
        'Please complete your Cash payment with NovaPS staff.'
};

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/css/receipt.css">
    <title>Receipt <?= receiptEscape($code) ?> - NovaPS</title>
</head>
<body>

<main class="receipt">
    <header class="brand">
        <h1>NovaPS</h1>
        <p>PLAYSTATION RENTAL</p>
        <p>ORDER RECEIPT</p>
    </header>

    <div class="line"></div>

    <div class="row">
        <span>Booking Code</span>
        <strong><?= receiptEscape($code) ?></strong>
    </div>

    <div class="row">
        <span>Date</span>
        <span><?= receiptEscape($date) ?> WIB</span>
    </div>

    <div class="row">
        <span>Customer</span>
        <span>
            <?= receiptEscape($booking['customer']['full_name']) ?>
        </span>
    </div>

    <div class="row">
        <span>Payment Method</span>
        <strong><?= receiptEscape($method) ?></strong>
    </div>

    <div class="line"></div>

    <strong>Rental Details</strong>

    <?php foreach ($booking['items'] as $item): ?>
        <div class="item">
            <div class="row">
                <strong>
                    <?= receiptEscape($item['device_name']) ?>
                </strong>
                <strong>
                    <?= receiptMoney((float) $item['total_cost']) ?>
                </strong>
            </div>

            <div class="item-details">
                <?= (int) $item['duration_days'] ?> day(s)
                ×
                <?= receiptMoney((float) $item['price_per_day']) ?>
            </div>
        </div>
    <?php endforeach; ?>

    <div class="line"></div>

    <div class="row total">
        <span>TOTAL</span>
        <strong>
            <?= receiptMoney((float) $booking['total_cost']) ?>
        </strong>
    </div>

    <div class="line"></div>

    <div class="status">
        <strong><?= receiptEscape($statusLabel) ?></strong>
        <?= receiptEscape($statusNote) ?>
    </div>

    <p class="note">
        Thank you for choosing NovaPS!
        <br>
        Keep this receipt for your rental records.
    </p>
</main>

<div class="actions">
    <a
        class="back-button"
        href="/pages/order-detail.php?order=<?= rawurlencode($code) ?>">
        Back to Order
    </a>

    <button
        class="print-button"
        type="button"
        onclick="window.print()">
        Print Receipt
    </button>
</div>

</body>
</html>
