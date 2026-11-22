<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/includes/bootstrap.php';
require_once __DIR__ . '/../../app/functions/bookings.php';

$auth = require_customer_page();

header('Cache-Control: private, no-store');

$currentPage = 'time-remaining';
$showOrderButton = true;

try {
    expire_unpaid_bookings($pdo);
    mark_overdue($pdo);

    $rentals = get_customer_rentals(
        $pdo,
        (int) $auth['user_id']
    );

    $extensionStmt = $pdo->prepare(
        "SELECT
        e.extension_id,
        e.rental_id,
        e.extra_days,
        e.status,
        e.created_at
     FROM rental_extension e
     JOIN rental r ON r.rental_id = e.rental_id
     JOIN booking b ON b.booking_id = r.booking_id
     WHERE b.customer_id = :customer_id
       AND (
           e.status = 'AwaitingVerification'
           OR (
               e.status = 'PendingPayment'
               AND e.created_at + INTERVAL '5 minutes' > NOW()
           )
       )
     ORDER BY e.extension_id DESC"
    );

    $extensionStmt->execute(['customer_id' => (int) $auth['user_id']]);

    $activeExtensions = [];

    foreach ($extensionStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rentalId = (int) $row['rental_id'];

        //Hanya simpan pengajuan extend yang aktif terbaru untuk rental ini
        $activeExtensions[$rentalId] ??= $row;
    }

    $hasAnyOrders = !empty(
        get_customer_bookings($pdo, (int) $auth['user_id'])
    );

    $serverTimestamp = time();

} catch (Throwable $e) {
    error_log(
        'Time Remaining SSR Error: ' . $e->getMessage()
    );

    http_response_code(500);
    exit('Unable to load rental information.');
}

function rental_escape(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function rental_money(float $amount): string
{
    return 'Rp' . number_format($amount, 0, ',', '.');
}

function rental_date(?string $value): string
{
    if ($value === null) {
        return '—';
    }

    return (new DateTimeImmutable($value))
        ->setTimezone(new DateTimeZone('Asia/Jakarta'))
        ->format('d M Y, H:i');
}

$tabs = [
    'all' => 'All',
    '4-hours' => '4 Hours',
    '1-hour' => '1 Hour',
    'overdue' => 'Overdue',
    'returned' => 'Returned',
];

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1">

    <title>Time Remaining Status - NovaPS</title>
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

    <link
        rel="stylesheet"
        href="/assets/css/time-remaining.css"
    >

    <style>
        [hidden] {
            display: none !important;
        }

        #extend-modal[hidden],
        #extend-request-form[hidden],
        #extend-payment-form[hidden] {
            display: none !important;
        }

        #extend-modal[hidden],
        #extend-request-form[hidden],
        #extend-payment-form[hidden] {
            display: none !important;
        }

        .extend-modal-overlay {
            position: fixed;
            inset: 0;
            z-index: 50;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
            background: rgba(15, 23, 42, 0.6);
        }

        .extend-modal-card {
            width: 100%;
            max-width: 560px;
            background: #ffffff;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 20px 50px rgba(15, 23, 42, 0.18);
        }

        .extend-modal-title {
            font-size: 1.5rem;
            font-weight: 700;
            line-height: 1.2;
            color: #0f172a;
            margin: 0;
        }

        .extend-modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 20px;
        }

        .extend-modal-close {
            border: 1px solid #cbd5e1;
            background: #ffffff;
            color: #334155;
            border-radius: 10px;
            padding: 8px 12px;
            cursor: pointer;
            transition: background-color 150ms ease,
                        border-color 150ms ease,
                        transform 150ms ease;
        }

        .extend-modal-close:hover {
            background: #f8fafc;
            border-color: #94a3b8;
            transform: translateY(-1px);
        }

        .extend-modal-close:active {
            transform: scale(0.98);
        }

        .extend-field {
            margin-top: 14px;
        }

        .extend-field label {
            display: block;
            font-size: 14px;
            font-weight: 500;
            color: #334155;
            margin-bottom: 8px;
        }

        .extend-field select,
        .extend-field input[type="file"] {
            width: 100%;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            padding: 12px 14px;
            font-size: 14px;
            background: #ffffff;
        }

        .extend-device-name {
            margin-bottom: 8px;
            font-weight: 600;
            color: #0f172a;
        }

        .extend-price {
            margin-top: 18px;
            font-size: 1.35rem;
            font-weight: 700;
            color: #0f172a;
        }

        .extend-note {
            margin-top: 6px;
            font-size: 12px;
            color: #64748b;
        }

        .extend-action {
            margin-top: 20px;
        }

        .extend-action .btn {
            width: 100%;
            justify-content: center;
        }

        .extend-qris {
            display: block;
            max-width: 240px;
            max-height: 240px;
            margin: 16px auto 0;
            object-fit: contain;
        }

        .extend-payment-amount {
            margin-top: 16px;
            text-align: center;
            font-size: 1.35rem;
            font-weight: 700;
            color: #0f172a;
        }

        .extend-payment-timer {
            margin-top: 8px;
            text-align: center;
            font-weight: 600;
            color: #dc2626;
        }

        .extend-error {
            margin-top: 12px;
            font-size: 14px;
            color: #dc2626;
        }

        @media (max-width: 640px) {
            .extend-modal-card {
                max-width: 100%;
                padding: 18px;
                border-radius: 14px;
            }
        }
    </style>
    <script src="/assets/js/shared/session.js" data-require-login="/pages/login.php"></script>
    <script src="/assets/js/shared/navbar.js" defer></script>
    <script src="/assets/js/time-remaining.js?v=2" defer></script>
    <script src="/assets/js/customer-extend.js?v=1" defer></script>
</head>

<body
    class="min-h-screen bg-slate-50 text-slate-800"
    data-logout-redirect="/index.php"
    data-server-timestamp="<?= $serverTimestamp ?>"
>

<?php require __DIR__ . '/../../app/partials/navbar.php'; ?>

<section class="bg-white">
    <div class="wrap pt-5">

        <a
            href="/index.php"
            class="inline-block text-2xl text-blue-600 underline underline-offset-4"
        >
            &larr; Back to home
        </a>

        <h1
            class="mt-4 mb-5 font-display text-[32px] font-bold text-black"
        >
            Time Remaining Status
        </h1>

        <div
            id="tabs"
            role="tablist"
            aria-label="Time Remaining Status"
            class="-mx-3 flex justify-between gap-2 overflow-x-auto"
        >
            <?php foreach ($tabs as $key => $label): ?>
                <button
                    type="button"
                    role="tab"
                    data-tab="<?= rental_escape($key) ?>"
                    aria-selected="<?= $key === 'all' ? 'true' : 'false' ?>"
                    tabindex="<?= $key === 'all' ? '0' : '-1' ?>"
                    class="tab"
                >
                    <?= rental_escape($label) ?>
                </button>
            <?php endforeach; ?>
        </div>

    </div>
</section>

<main class="wrap py-8">

    <ul id="rental-list" class="grid gap-5">

        <?php foreach ($rentals as $rental): ?>

            <?php
            $status = $rental['rental_status'];
            $returned = $status === 'Returned';

            $detailUrl =
                '/pages/order-detail.php?order=' .
                rawurlencode($rental['booking_code']);

            $remaining = max(
                0,
                $rental['end_timestamp'] - $serverTimestamp
            );

            $overdue = !$returned &&
                ($status === 'Overdue' || $remaining === 0);

            $initialColor = $returned
                ? '#16a34a'
                : ($overdue
                    ? '#dc2626'
                    : ($remaining <= 3600
                        ? '#f97316'
                        : ($remaining <= 14400
                            ? '#d97706'
                            : '#16a34a')));
            ?>

            <li
                data-rental-card
                data-status="<?= rental_escape($status) ?>"
                data-end="<?= (int) $rental['end_timestamp'] ?>"
                class="rounded-lg border border-slate-200 bg-white p-6"
            >
                <div
                    class="flex flex-wrap items-start justify-between gap-x-4 gap-y-1"
                >
                    <div class="min-w-0">

                        <p class="font-semibold text-black">
                            <?= rental_escape($rental['device_name']) ?>
                        </p>

                        <p class="text-[13px] break-words text-slate-500">
                            <?= rental_escape($rental['category']) ?>
                            (<?= $rental['duration_days'] ?> day(s))
                            · <?= rental_escape($rental['address']) ?>
                        </p>

                        <p class="text-[13px] text-slate-500">
                            <?php if ($returned): ?>
                                Returned:
                                <?= rental_date($rental['returned_at']) ?>
                            <?php else: ?>
                                Return before:
                                <?= rental_date($rental['end_time']) ?>
                            <?php endif; ?>
                        </p>

                        <p class="text-[13px] text-slate-500">
                            Total <?= rental_money($rental['total_cost']) ?>
                        </p>

                        <p class="text-[12px] text-slate-400">
                            Booking:
                            <?= rental_escape($rental['booking_code']) ?>
                        </p>

                    </div>

                    <div class="text-right">
                        <?php if ($returned): ?>
                            <span
                                class="font-medium"
                                style="color: #16a34a;"
                            >
                                Returned
                            </span>

                            <?php if ($rental['returned_late']): ?>
                                <p
                                    class="text-xs"
                                    style="color: #dc2626;"
                                >
                                    Returned late
                                </p>
                            <?php endif; ?>

                        <?php else: ?>
                            <span
                                data-rental-clock
                                class="inline-block rounded-md px-2.5 py-0.5 text-[15px] text-white tabular-nums"
                                style="background-color: <?= $initialColor ?>;"
                            >
                                <?= $overdue ? 'Overdue' : 'Calculating...' ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="mt-3 flex flex-wrap justify-end gap-2">

                    <?php 
                        $extension = $activeExtensions[(int) $rental['rental_id']] ?? null;
                        
                        $canExtend = $status === 'Rented'
                            && !$overdue
                            && $rental['duration_days'] < 7;
                    ?>

                    <?php if ($extension !== null): ?>
                        <?php if ($extension['status'] === 'PendingPayment'): ?>
                            <button 
                                    type="button"
                                    class="btn btn-indigo px-3 py-1 text-sm font-medium" 
                                    data-extend-open 
                                    data-extension-id="<?= (int) $extension['extension_id'] ?>"
                                    data-extra-days="<?= (int) $extension['extra_days'] ?>"
                                    data-created-at="<?= rental_escape((new DateTimeImmutable($extension['created_at']))->format(DATE_ATOM)) ?>"
                                    data-rental-id="<?= (int) $rental['rental_id'] ?>"
                                    data-device="<?= rental_escape($rental['device_name']) ?>"
                                    data-price="<?= rental_escape($rental['price_per_day']) ?>"
                                    data-max-days="<?= 7 - (int) $rental['duration_days'] ?>"
                                >Complete Extend Payment
                            </button>
                        <?php else: ?>
                            <span class="text-sm font-semibold text-amber-700">
                                Extend Awaiting Verification
                            </span>
                        <?php endif; ?>

                    <?php elseif ($canExtend): ?>
                        <button 
                            type="button"
                            class="btn btn-indigo px-3 py-1 text-sm font-medium"
                            data-extend-open
                            data-rental-id="<?= (int) $rental['rental_id'] ?>"
                            data-device="<?= rental_escape($rental['device_name']) ?>"
                            data-price="<?= rental_escape($rental['price_per_day']) ?>"
                            data-max-days="<?= 7 - (int) $rental['duration_days'] ?>"
                        >Extend Rental
                        </button>
                    <?php endif; ?>

                    <a
                        href="<?= rental_escape($detailUrl) ?>"
                        class="btn px-3 py-1 text-sm font-medium border-slate-800 bg-white text-slate-800 hover:bg-slate-800 hover:text-white"
                    >
                        Look Detail
                    </a>

                    <?php if ($returned): ?>
                        <a
                            href="/pages/order-form.php?unit=<?= rawurlencode(
                                strtolower($rental['category'])
                            ) ?>"
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
        <?= count($rentals) > 0 ? 'hidden' : '' ?>
    >
        <p
            id="empty-message"
            aria-live="polite"
            class="mx-auto max-w-md text-2xl text-slate-500"
        >
            <?php if ($hasAnyOrders): ?>
                No rental in progress.
                The countdown appears once your rental is active.
            <?php else: ?>
                You haven’t ordered anything yet
            <?php endif; ?>
        </p>

        <a
            id="empty-cta"
            href="<?= $hasAnyOrders
                ? '/pages/order-status.php'
                : '/pages/order-form.php' ?>"
            class="btn btn-indigo px-[25px] font-semibold"
        >
            <?= $hasAnyOrders ? 'Order Status' : 'Order Now' ?>
        </a>
    </div>

</main>
<div id="extend-modal"
     role="dialog"
     aria-modal="true"
     aria-labelledby="extend-title"
     hidden
     class="extend-modal-overlay">

    <div class="extend-modal-card">

        <div class="extend-modal-header">
            <h2 id="extend-title" class="extend-modal-title">
                Extend Rental
            </h2>

            <button id="extend-close"
                    type="button"
                    class="extend-modal-close">
                ✕
            </button>
        </div>

        <p id="extend-device" class="extend-device-name"></p>

        <form id="extend-request-form">
            <div class="extend-field">
                <label for="extend-days">Extra Duration</label>

                <select id="extend-days"></select>
            </div>

            <p id="extend-price" class="extend-price"></p>

            <p class="extend-note">
                Maximum rental duration is 7 days in total.
            </p>

            <div class="extend-action">
                <button id="extend-request-submit"
                        type="submit"
                        class="btn btn-indigo py-3">
                    Continue to Payment
                </button>
            </div>
        </form>

        <form id="extend-payment-form"
              hidden
              enctype="multipart/form-data">

            <p class="text-sm">
                Complete payment using the existing NovaPS QRIS.
            </p>

            <img src="/assets/img/qris.png"
                 alt="NovaPS QRIS payment"
                 class="extend-qris">

            <p id="extend-payment-amount"
               class="extend-payment-amount"></p>

            <p id="extend-payment-timer"
               class="extend-payment-timer"
               aria-live="off"></p>

            <div class="extend-field">
                <label for="extend-proof">Upload Payment Proof</label>

                <input id="extend-proof"
                       type="file"
                       accept="image/jpeg,image/png"
                       required>
            </div>

            <div class="extend-action">
                <button id="extend-upload-submit"
                        type="submit"
                        class="btn btn-indigo py-3">
                    Submit Payment Proof
                </button>
            </div>
        </form>

        <p id="extend-error"
           role="alert"
           class="extend-error"></p>
    </div>
</div>
</body>
</html>
