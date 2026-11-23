<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/includes/bootstrap.php';
require_once __DIR__ . '/../../app/functions/bookings.php';

$auth = require_customer_page();

header('Cache-Control: private, no-store');

$currentPage = 'order-detail';
$showOrderButton = true;

$rawCode = $_GET['order'] ?? null;

$bookingCode = is_string($rawCode)
    ? strtoupper(trim($rawCode))
    : '';

if (!preg_match('/^[A-Z0-9]{8}$/', $bookingCode)) {
    http_response_code(400);
    exit('Invalid booking code.');
}

try {
    expire_unpaid_bookings($pdo);
    mark_overdue($pdo);

    $booking = get_customer_booking(
        $pdo,
        (int) $auth['user_id'],
        $bookingCode
    );

    if ($booking === null) {
        http_response_code(404);
        exit('Booking not found.');
    }

} catch (Throwable $e) {
    error_log('Order Detail Error: ' . $e->getMessage());

    http_response_code(500);
    exit('Unable to load order details.');
}

$status = $booking['payment']['status'];

$rentalStatuses = array_column(
    $booking['items'],
    'rental_status'
);

$hasOverdue = in_array(
    'Overdue',
    $rentalStatuses,
    true
);

$hasRented = in_array(
    'Rented',
    $rentalStatuses,
    true
);

$allReturned = count($rentalStatuses) > 0
    && count(array_unique($rentalStatuses)) === 1
    && $rentalStatuses[0] === 'Returned';

$view = match (true) {
    $status === 'Expired' => [
        'title' => 'Order Canceled',
        'subtitle' => 'The payment deadline has expired.',
        'reason' => 'Payment time expired',
        'note' => 'You can place a new order anytime.',
        'tone' => 'text-red-500',
        'glyph' => 'cross'
    ],

    $status === 'Cancelled' => [
        'title' => 'Order Canceled',
        'subtitle' => 'This order has been canceled.',
        'reason' => $booking['cancel_reason'] ?? '',
        'note' => 'You can place a new order anytime.',
        'tone' => 'text-red-500',
        'glyph' => 'cross'
    ],

    $status === 'Rejected' => [
        'title' => 'Order Rejected',
        'subtitle' => 'Your payment was rejected.',
        'reason' => $booking['cancel_reason'] ?? '',
        'note' => 'Please contact the admin for assistance.',
        'tone' => 'text-red-500',
        'glyph' => 'cross'
    ],

    $status === 'AwaitingVerification' => [
        'title' => 'Waiting for Verification',
        'subtitle' => 'Your payment proof was received and is awaiting admin verification.',
        'reason' => '',
        'note' => 'Your rental is not active yet.',
        'tone' => 'text-amber-500',
        'glyph' => 'clock'
    ],

    $status === 'Pending' => [
        'title' => 'Waiting for Payment',
        'subtitle' => 'Please complete your payment before the deadline.',
        'reason' => '',
        'note' => 'Your rental is not active yet.',
        'tone' => 'text-amber-500',
        'glyph' => 'clock'
    ],

    $status === 'Paid' && $hasOverdue => [
        'title' => 'Rental Overdue',
        'subtitle' => 'One or more rental units have passed their return deadline.',
        'reason' => '',
        'note' => 'Please return overdue units. A late fee of Rp10.000 per hour applies.',
        'tone' => 'text-red-500',
        'glyph' => 'clock'
    ],

    $status === 'Paid' && $hasRented => [
        'title' => 'Rental in Progress',
        'subtitle' => 'Your payment is verified and your rental is active.',
        'reason' => '',
        'note' => 'Check Time Remaining for your rental countdown.',
        'tone' => 'text-green-500',
        'glyph' => 'check'
    ],

    $status === 'Paid' && $allReturned => [
        'title' => 'Rental Completed',
        'subtitle' => 'All rental units have been returned.',
        'reason' => '',
        'note' => 'Thank you for renting with NovaPS!',
        'tone' => 'text-green-500',
        'glyph' => 'check'
    ],

    $status === 'Paid' => [
        'title' => 'Payment Verified',
        'subtitle' => 'Your payment has been verified.',
        'reason' => '',
        'note' => 'Please check the rental status below.',
        'tone' => 'text-green-500',
        'glyph' => 'check'
    ],

    default => [
        'title' => 'Order Status',
        'subtitle' => 'Please check your order information.',
        'reason' => '',
        'note' => '',
        'tone' => 'text-amber-500',
        'glyph' => 'clock'
    ]
};

function detail_escape(string $value): string
{
    return htmlspecialchars(
        $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function detail_money(float $amount): string
{
    return 'Rp' . number_format($amount, 0, ',', '.');
}

?>

<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Order Detail - NovaPS</title>
    <link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
      href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Poppins:wght@600;700&display=swap"
      rel="stylesheet"
    />
    <link rel="stylesheet" href="/assets/css/order-detail.css" />
    <script
      src="/assets/js/shared/session.js"
      data-require-login="login.html"
    ></script>
    <script src="/assets/js/shared/navbar.js" defer></script>
  </head>
  <body
    class="min-h-screen bg-slate-50 text-slate-800"
    data-logout-redirect="/index.php"
  >
    <?php require __DIR__ . '/../../app/partials/navbar.php'; ?>
    <main class="mx-auto w-[640px] max-w-[calc(100%-2rem)] pt-6 pb-16">
      <section class="rounded-lg border border-slate-200 bg-white p-6">
        <svg
          id="badge"
          viewBox="0 0 100 100"
          aria-hidden="true"
          class="mx-auto size-[130px] transition-colors duration-300 <?= $view['tone'] ?>"
        >
          <path
            d="M95.2 50.0 L94.9 51.8 L94.1 53.5 L92.9 55.1 L91.5 56.6 L90.0 58.0 L88.6 59.3 L87.6 60.6 L86.9 62.0 L86.6 63.5 L86.7 65.2 L87.0 67.1 L87.4 69.1 L87.7 71.1 L87.7 73.1 L87.4 75.0 L86.6 76.6 L85.3 77.8 L83.7 78.7 L81.7 79.3 L79.7 79.7 L77.7 79.9 L75.8 80.2 L74.2 80.7 L72.8 81.4 L71.7 82.5 L70.8 83.9 L69.9 85.6 L69.1 87.4 L68.1 89.3 L66.9 90.9 L65.6 92.2 L64.0 93.0 L62.2 93.3 L60.3 93.0 L58.4 92.4 L56.6 91.5 L54.8 90.5 L53.1 89.6 L51.5 89.0 L50.0 88.8 L48.5 89.0 L46.9 89.6 L45.2 90.5 L43.4 91.5 L41.6 92.4 L39.7 93.0 L37.8 93.3 L36.0 93.0 L34.4 92.2 L33.1 90.9 L31.9 89.3 L30.9 87.4 L30.1 85.6 L29.2 83.9 L28.3 82.5 L27.2 81.4 L25.8 80.7 L24.2 80.2 L22.3 79.9 L20.3 79.7 L18.3 79.3 L16.3 78.7 L14.7 77.8 L13.4 76.6 L12.6 75.0 L12.3 73.1 L12.3 71.1 L12.6 69.1 L13.0 67.1 L13.3 65.2 L13.4 63.5 L13.1 62.0 L12.4 60.6 L11.4 59.3 L10.0 58.0 L8.5 56.6 L7.1 55.1 L5.9 53.5 L5.1 51.8 L4.8 50.0 L5.1 48.2 L5.9 46.5 L7.1 44.9 L8.5 43.4 L10.0 42.0 L11.4 40.7 L12.4 39.4 L13.1 38.0 L13.4 36.5 L13.3 34.8 L13.0 32.9 L12.6 30.9 L12.3 28.9 L12.3 26.9 L12.6 25.0 L13.4 23.4 L14.7 22.2 L16.3 21.3 L18.3 20.7 L20.3 20.3 L22.3 20.1 L24.2 19.8 L25.8 19.3 L27.2 18.6 L28.3 17.5 L29.2 16.1 L30.1 14.4 L30.9 12.6 L31.9 10.7 L33.1 9.1 L34.4 7.8 L36.0 7.0 L37.8 6.7 L39.7 7.0 L41.6 7.6 L43.4 8.5 L45.2 9.5 L46.9 10.4 L48.5 11.0 L50.0 11.2 L51.5 11.0 L53.1 10.4 L54.8 9.5 L56.6 8.5 L58.4 7.6 L60.3 7.0 L62.2 6.7 L64.0 7.0 L65.6 7.8 L66.9 9.1 L68.1 10.7 L69.1 12.6 L69.9 14.4 L70.8 16.1 L71.7 17.5 L72.8 18.6 L74.2 19.3 L75.8 19.8 L77.7 20.1 L79.7 20.3 L81.7 20.7 L83.7 21.3 L85.3 22.2 L86.6 23.4 L87.4 25.0 L87.7 26.9 L87.7 28.9 L87.4 30.9 L87.0 32.9 L86.7 34.8 L86.6 36.5 L86.9 38.0 L87.6 39.4 L88.6 40.7 L90.0 42.0 L91.5 43.4 L92.9 44.9 L94.1 46.5 L94.9 48.2Z"
            fill="currentColor"
            fill-opacity=".12"
            stroke="currentColor"
            stroke-width="1"
          />
          <g
            id="glyph"
            fill="none"
            stroke="currentColor"
            stroke-width="2.4"
            stroke-linecap="round"
            stroke-linejoin="round"
          ><?php if ($view['glyph'] === 'cross'): ?>
                <path d="M36 36l28 28M64 36l-28 28"/>
            <?php elseif ($view['glyph'] === 'check'): ?>
                <path d="M30 52l14 14 27-30"/>
            <?php else: ?>
                <circle cx="50" cy="50" r="17"/>
                <path d="M50 40v11l8 5"/>
            <?php endif; ?>
        </g>
        </svg>
        <h1
          id="title"
          class="mt-2 text-center font-display text-[32px] font-bold text-black"
        ><?= detail_escape($view['title']) ?></h1>
        <p
          id="subtitle"
          class="mx-auto mt-2 max-w-sm text-center text-slate-500"
        ><?= detail_escape($view['subtitle']) ?></p>
        <div id="pay-box" hidden class="mt-4 text-center">
          <p id="countdown" class="font-display text-4xl font-bold text-black">
            05:00
          </p>
          <p id="pay-box-label" class="text-[11px] text-slate-400">
            Time left to pay
          </p>
        </div>

        <div id="rows" class="mt-4 border-t border-slate-300 pt-1.5">

            <?php
            $rows = [
                'Order ID' => $booking['booking_code'],
                'Payment Status' => $status === 'Expired' ? 'Expired' : $status,
                'Customer' => $booking['customer']['full_name'],
                'Address' => $booking['customer']['address'],
                'Date' => (new DateTimeImmutable($booking['created_at']))
                    ->setTimezone(new DateTimeZone('Asia/Jakarta'))
                    ->format('d M Y, H:i')
            ];

            if ($view['reason'] !== '') {
                $rows['Reason'] = $view['reason'];
            }
            ?>

            <?php foreach ($rows as $label => $value): ?>

                <div class="flex justify-between gap-4 py-0.5 text-base">
                    <span><?= detail_escape($label) ?></span>
                    <span class="text-right break-words">
                        <?= detail_escape((string) $value) ?>
                    </span>
                </div>

            <?php endforeach; ?>

            <?php foreach ($booking['items'] as $index => $item): ?>

                <div class="my-1.5 border-t border-slate-300"></div>

                <div class="flex justify-between gap-4 py-0.5">
                    <span>Unit #<?= $index + 1 ?></span>
                    <span><?= detail_escape($item['category']) ?></span>
                </div>

                <div class="flex justify-between gap-4 py-0.5">
                    <span>Duration</span>
                    <span><?= $item['duration_days'] ?> day(s)</span>
                </div>

                <div class="flex justify-between gap-4 py-0.5">
                    <span>Price</span>
                    <span><?= detail_money($item['total_cost']) ?></span>
                </div>

                <?php
                $rentalStatus = $item['rental_status'];

                $rentalColor = match ($rentalStatus) {
                    'Rented' => '#16a34a',
                    'Overdue' => '#dc2626',
                    'Returned' => '#16a34a',
                    'Cancelled' => '#dc2626',
                    default => '#d97706'
                };

                $formatRentalDate = static function (
                    ?string $value
                ): string {
                    if ($value === null) {
                        return '—';
                    }

                    return (new DateTimeImmutable($value))
                        ->setTimezone(new DateTimeZone('Asia/Jakarta'))
                        ->format('d M Y, H:i');
                };
                ?>

                <div class="flex justify-between gap-4 py-0.5">
                    <span>Rental Status</span>

                    <span
                        class="font-semibold"
                        style="color: <?= $rentalColor ?>;"
                    >
                        <?= detail_escape($rentalStatus) ?>
                    </span>
                </div>

                <div class="flex justify-between gap-4 py-0.5">
                    <span>Rental Start</span>
                    <span class="text-right">
                        <?= detail_escape(
                            $formatRentalDate($item['start_time'])
                        ) ?>
                    </span>
                </div>

                <div class="flex justify-between gap-4 py-0.5">
                    <span>Return Deadline</span>
                    <span class="text-right">
                        <?= detail_escape(
                            $formatRentalDate($item['end_time'])
                        ) ?>
                    </span>
                </div>

                <?php if ($item['returned_at'] !== null): ?>
                    <div class="flex justify-between gap-4 py-0.5">
                        <span>Returned At</span>
                        <span class="text-right">
                            <?= detail_escape(
                                $formatRentalDate($item['returned_at'])
                            ) ?>
                        </span>
                    </div>
                <?php endif; ?>

            <?php if ($item['late_fee'] > 0): ?>
                <div class="flex justify-between gap-4 py-1 text-sm">
                    <span class="font-semibold text-red-600">
                        Late Fee
                    </span>

                    <span class="font-semibold text-red-600">
                        <?= detail_money($item['late_fee']) ?>
                    </span>
                </div>

                <div class="flex justify-between gap-4 py-1 text-sm">
                    <span>Late Fee Status</span>

                    <span>
                        <?= $item['late_fee_paid_at'] !== null
                            ? 'Paid'
                            : 'Unpaid' ?>
                    </span>
                </div>
            <?php endif; ?>

            <?php endforeach; ?>

            <div class="my-1.5 border-t border-slate-300"></div>

            <div class="flex justify-between gap-4 pt-1.5 font-display font-bold">
                <span>Total</span>
                <span><?= detail_money($booking['total_cost']) ?></span>
            </div>

        </div>

        <p
          id="note"
          class="mx-auto mt-8 max-w-xs text-center text-slate-500"
        ><?= detail_escape($view['note']) ?></p>
        <div class="mt-4 flex flex-wrap justify-center gap-4 sm:gap-6">

            <a href="/index.php"
            class="btn border-2 border-slate-400 bg-white px-6 py-2.5 text-base font-semibold text-slate-500">
                Back to the home page
            </a>

            <a href="/pages/order-status.php" class="btn border-2 border-slate-400 bg-white px-6 py-2.5 text-base font-semibold text-slate-500">
                Order Status
            </a>

            <?php if ($status === 'Paid' && ($hasRented || $hasOverdue)): ?>
                <a href="/pages/time-remaining.php" class="btn btn-indigo px-6 py-2.5 text-base font-semibold">
                    Time Remaining
                </a>
            <?php endif; ?>

            <?php if (in_array($status, ['Expired', 'Cancelled', 'Rejected'], true)): ?>

                <a href="/pages/order-form.php"
                class="btn btn-indigo px-6 py-2.5 text-base font-semibold">
                    Order Again
                </a>

            <?php elseif ($status === 'Pending' && $booking['payment_method'] === 'QRIS'): ?>

                <a href="/pages/payment.php?order=<?= rawurlencode($bookingCode) ?>"
                class="btn btn-indigo px-6 py-2.5 text-base font-semibold">
                    Pay Now
                </a>

            <?php endif; ?>

        </div>
      </section>
    </main>
  </body>
</html>
