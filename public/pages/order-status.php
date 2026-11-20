<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/includes/bootstrap.php';
require_once __DIR__ . '/../../app/functions/bookings.php';

$auth = require_customer_page();

header('Cache-Control: private, no-store');

$currentPage = 'order-status';
$showOrderButton = true;

try {
    expire_unpaid_bookings($pdo);
    mark_overdue($pdo);

    $bookings = get_customer_bookings(
        $pdo,
        (int) $auth['user_id']
    );
} catch (Throwable $e) {
    error_log('Order Status Page Error: ' . $e->getMessage());

    http_response_code(500);
    exit('Unable to load order history.');
}

function order_status_escape(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function order_status_money(float $amount): string
{
    return 'Rp' . number_format($amount, 0, ',', '.');
}

function order_status_label(array $booking): string
{
    return match ($booking['payment_status']) {
        'Pending' => 'Not Paid',
        'AwaitingVerification' => 'Waiting Verification',
        'Paid' => match (true) {
            in_array('Overdue', $booking['rental_statuses'], true)
                => 'Overdue',
            in_array('Rented', $booking['rental_statuses'], true)
                => 'Rented',
            count($booking['rental_statuses']) > 0 &&
            count(array_unique($booking['rental_statuses'])) === 1 &&
            $booking['rental_statuses'][0] === 'Returned'
                => 'Returned',
            default => 'Payment Verified'
        },
        'Expired', 'Cancelled' => 'Canceled',
        'Rejected' => 'Rejected',
        default => $booking['payment_status']
    };
}

function order_status_color(string $status): string
{
    return match ($status) {
        'Pending' => '#d97706',
        'AwaitingVerification' => '#6366f1',
        'Paid' => '#16a34a',
        'Expired', 'Cancelled', 'Rejected' => '#dc2626',
        default => '#64748b'
    };
}

$tabs = [
    'all' => 'All',
    'not-paid' => 'Not paid',
    'pending' => 'Pending',
    'shipped' => 'Shipped',
    'complete' => 'Complete',
    'canceled' => 'Canceled',
    'rejected' => 'Rejected'
];

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1">

    <title>Order Status - NovaPS</title>
    <link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
    <link rel="preconnect"
          href="https://fonts.googleapis.com">
    <link rel="preconnect"
          href="https://fonts.gstatic.com"
          crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Poppins:wght@600;700&display=swap"
        rel="stylesheet"
    >

    <link rel="stylesheet"
          href="/assets/css/order-status.css">

    <script
        src="/assets/js/shared/session.js"
        data-require-login="/pages/login.php"
    ></script>

    <style>
        [hidden] {
            display: none !important;
        }
    </style>

    <script src="/assets/js/shared/navbar.js" defer></script>
    <script src="/assets/js/order-status.js?v=2" defer></script>
</head>

<body
    class="min-h-screen bg-slate-50 text-slate-800"
    data-logout-redirect="/index.php"
>
    <?php require __DIR__ . '/../../app/partials/navbar.php'; ?>

    <section class="bg-white">
        <div class="wrap pt-5">

            <a href="/index.php"
               class="inline-block text-2xl text-blue-600 underline underline-offset-4">
                &larr; Back to home
            </a>

            <h1 class="mt-4 mb-5 font-display text-[32px] font-bold text-black">
                Order Status
            </h1>

            <div
                id="tabs"
                role="tablist"
                aria-label="Order Status"
                class="-mx-3 flex justify-between gap-2 overflow-x-auto"
            >
                <?php foreach ($tabs as $key => $label): ?>
                    <button
                        type="button"
                        role="tab"
                        data-tab="<?= order_status_escape($key) ?>"
                        aria-selected="<?= $key === 'all' ? 'true' : 'false' ?>"
                        tabindex="<?= $key === 'all' ? '0' : '-1' ?>"
                        class="tab"
                    >
                        <?= order_status_escape($label) ?>
                    </button>
                <?php endforeach; ?>
            </div>

        </div>
    </section>

    <main class="wrap py-8">

        <ul id="order-list" class="grid gap-5">

            <?php foreach ($bookings as $booking): ?>

                <?php
                $code = $booking['booking_code'];
                $status = $booking['payment_status'];

                $detailUrl =
                    '/pages/order-detail.php?order=' .
                    rawurlencode($code);

                $paymentUrl =
                    '/pages/payment.php?order=' .
                    rawurlencode($code);

                $unitNames = array_map(
                    static fn(array $item): string =>
                        $item['category'],
                    $booking['items']
                );

                $unitDescriptions = array_map(
                    static fn(array $item): string =>
                        $item['device_name'] .
                        ' (' . $item['duration_days'] .
                        ' day(s))',
                    $booking['items']
                );
                ?>

                <li
                    data-order-card
                    data-status="<?= order_status_escape($booking['status']) ?>"
                    class="rounded-lg border border-slate-200 bg-white p-6"
                >
                    <div
                        class="flex flex-wrap items-start justify-between gap-x-4 gap-y-1"
                    >
                        <div class="min-w-0">

                            <p class="font-semibold text-black">
                                <?= order_status_escape(
                                    implode(' + ', $unitNames)
                                ) ?>
                            </p>

                            <p class="text-[13px] break-words text-slate-500">
                                <?= order_status_escape(
                                    implode(', ', $unitDescriptions)
                                ) ?>
                                · <?= order_status_escape($booking['address']) ?>
                            </p>

                            <p class="text-[13px] text-slate-500">
                                <?= order_status_escape(
                                    (new DateTimeImmutable(
                                        $booking['created_at']
                                    ))
                                    ->setTimezone(
                                        new DateTimeZone('Asia/Jakarta')
                                    )
                                    ->format('d M Y, H:i')
                                ) ?>
                            </p>

                            <p class="text-[13px] text-slate-500">
                                <?= order_status_money(
                                    $booking['total_cost']
                                ) ?>
                            </p>

                            <p class="text-[12px] text-slate-400">
                                <?= order_status_escape($code) ?>
                            </p>

                        </div>

                        <p class="flex items-center gap-3 text-[15px]">

                            <?php if (
                                $status === 'Pending' &&
                                $booking['payment']['seconds_left'] !== null
                            ): ?>

                                <span
                                    data-countdown
                                    data-seconds="<?= (int) $booking['payment']['seconds_left'] ?>"
                                    class="text-black tabular-nums"
                                ></span>

                            <?php endif; ?>

                            <span
                                style="color: <?= order_status_color($status) ?>;"
                                class="font-medium"
                            >
                                <?= order_status_escape(
                                    order_status_label($booking)
                                ) ?>
                            </span>
                        </p>
                    </div>

                    <div class="mt-3 flex flex-wrap justify-end gap-2">

                        <a
                            href="<?= order_status_escape($detailUrl) ?>"
                            class="btn px-3 py-1 text-sm font-medium border-slate-800 bg-white text-slate-800 hover:bg-slate-800 hover:text-white"
                        >
                            Look Detail
                        </a>

                        <?php if ($status === 'Pending'): ?>

                            <button
                                type="button"
                                data-cancel-order
                                data-booking-code="<?= order_status_escape($code) ?>"
                                class="btn px-3 py-1 text-sm font-medium border-red-600 bg-white text-red-600 hover:bg-red-600 hover:text-white"
                            >
                                Cancel
                            </button>

                            <?php if ($booking['payment_method'] === 'QRIS'): ?>
                                <a
                                    href="<?= order_status_escape($paymentUrl) ?>"
                                    class="btn btn-indigo px-3 py-1 text-sm font-medium"
                                >
                                    Pay Now
                                </a>
                            <?php endif; ?>

                        <?php elseif (
                            $status === 'Paid' &&
                            count($booking['rental_statuses']) > 0 &&
                            count(array_unique($booking['rental_statuses'])) === 1 &&
                            $booking['rental_statuses'][0] === 'Returned'
                        ): ?>

                            <a
                                href="/pages/order-form.php"
                                class="btn btn-indigo px-3 py-1 text-sm font-medium"
                            >
                                Order Again
                            </a>

                        <?php endif; ?>

                    </div>
                </li>

            <?php endforeach; ?>

        </ul>

        <div
            id="empty-state"
            class="flex flex-col items-center gap-4 pt-[100px] text-center"
            <?= count($bookings) > 0 ? 'hidden' : '' ?>
        >
            <p
                id="empty-message"
                aria-live="polite"
                class="text-2xl text-slate-500"
            >
                You haven’t ordered anything yet
            </p>

            <a
                id="empty-cta"
                href="/pages/order-form.php"
                class="btn btn-indigo px-[25px] font-semibold"
            >
                Order Now
            </a>
        </div>

    </main>

    <!-- Cancellation modal -->
    <div
        id="cancelModal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="cancelTitle"
        aria-hidden="true"
        class="group invisible fixed inset-0 z-50 grid place-items-center bg-slate-950/60 p-6 opacity-0 transition-all duration-200 data-[open]:visible data-[open]:opacity-100"
    >
        <div
            class="w-full max-w-[420px] rounded-xl bg-white px-7 pb-7 pt-8"
        >
            <h2 id="cancelTitle"
                class="font-display text-2xl font-bold">
                Cancel this order?
            </h2>

            <p class="mt-1 text-slate-500">
                Please provide a reason for cancellation.
                The reserved units will be released.
            </p>

            <div class="mt-4 flex flex-wrap gap-2">
                <?php foreach ([
                    'Changed my mind',
                    'Wrong unit or duration',
                    'Payment problem'
                ] as $reason): ?>
                    <button
                        type="button"
                        data-reason="<?= order_status_escape($reason) ?>"
                        class="cursor-pointer rounded-full border border-slate-300 px-3 py-1 text-xs text-slate-600"
                    >
                        <?= order_status_escape($reason) ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <label
                for="cancelReason"
                class="mt-3 mb-0.5 block text-base"
            >
                Reason
            </label>

            <textarea
                id="cancelReason"
                rows="3"
                maxlength="200"
                placeholder="Write your reason here"
                class="field-input h-auto resize-none py-2"
            ></textarea>

            <p
                id="cancelError"
                role="alert"
                class="mt-1 text-sm text-red-600"
            ></p>

            <div class="mt-5 flex gap-3">
                <button
                    type="button"
                    id="keepOrder"
                    class="btn btn-ghost flex-1 px-0 py-[11px] text-base"
                >
                    Keep order
                </button>

                <button
                    type="button"
                    id="confirmCancel"
                    class="btn flex-1 bg-red-600 px-0 py-[11px] text-base text-white"
                >
                    Cancel order
                </button>
            </div>
        </div>
    </div>
</body>
</html>
