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

    $expireExtensions = $pdo->prepare(
        "UPDATE rental_extension e
        SET status = 'Expired'
        FROM rental r
        JOIN booking b
        ON b.booking_id = r.booking_id
        WHERE e.rental_id = r.rental_id
        AND b.customer_id = :customer_id
        AND e.status = 'PendingPayment'
        AND e.created_at + INTERVAL '5 minutes'
            <= clock_timestamp()"
    );

    $expireExtensions->execute([
        'customer_id' => (int) $auth['user_id']
    ]);

    // Ambil seluruh riwayat, termasuk pengajuan lama.
    $extensionStmt = $pdo->prepare(
        "SELECT
            e.extension_id,
            e.rental_id,
            e.extra_days,
            e.status,
            e.created_at,
            e.reviewed_at,
            (e.payment_proof_url IS NOT NULL) AS has_proof
        FROM rental_extension e
        JOIN rental r
        ON r.rental_id = e.rental_id
        JOIN booking b
        ON b.booking_id = r.booking_id
        WHERE b.customer_id = :customer_id
        ORDER BY e.created_at DESC, e.extension_id DESC"
    );

    $extensionStmt->execute([
        'customer_id' => (int) $auth['user_id']
    ]);

    $activeExtensions = [];
    $extensionHistory = [];

    foreach ($extensionStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rentalId = (int) $row['rental_id'];

        // Normalisasi nilai boolean dari PostgreSQL.
        $row['has_proof'] = in_array(
            $row['has_proof'],
            [true, 1, '1', 't', 'true'],
            true
        );

        $extensionHistory[$rentalId][] = $row;

        // Tetap pertahankan alur Extend yang sudah berjalan.
        if (!isset($activeExtensions[$rentalId]) && in_array($row['status'], ['PendingPayment', 'AwaitingVerification'], true)) {
            $activeExtensions[$rentalId] = $row;
        }
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Poppins:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/time-remaining.css">
    <link rel="stylesheet" href="/assets/css/time-remaining-ssr.css?v=1">
    <script src="/assets/js/shared/session.js" data-require-login="/pages/login.php"></script>

    <?php require __DIR__ . '/../../app/partials/popup-assets.php'; ?>

    <script src="/assets/js/shared/navbar.js" defer></script>
    <script src="/assets/js/time-remaining.js?v=2" defer></script>
    <script src="/assets/js/customer-extend.js?v=3" defer></script>
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

                        <?php if ($rental['late_fee'] > 0): ?>
                            <div class="mt-2">
                                <p class="text-sm font-semibold text-red-600">
                                    Late Fee: <?= rental_money($rental['late_fee']) ?>
                                </p>

                                <?php if ($rental['late_fee_paid_at'] !== null): ?>
                                    <p class="text-xs text-green-600">
                                        Late fee paid
                                    </p>

                                <?php else: ?>
                                    <p class="text-xs text-red-500">
                                        Late fee unpaid
                                    </p>
                                <?php endif; ?>
                                
                            </div>
                        <?php endif; ?>
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
                                <?php if ($overdue): ?>
                                    -<?= sprintf('%02d:%02d:%02d',
                                            intdiv(max(0, $serverTimestamp - $rental['end_timestamp']), 3600),
                                            intdiv(max(0, $serverTimestamp - $rental['end_timestamp']) % 3600, 60),
                                            max(0, $serverTimestamp - $rental['end_timestamp']) % 60 
                                    )?>
                                <?php else: ?>
                                    Calculating...
                                <?php endif; ?>
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

                    <?php
                    $history = $extensionHistory[
                        (int) $rental['rental_id']
                    ] ?? [];

                    $extensionLabels = [
                        'PendingPayment' => 'Waiting for Payment',
                        'AwaitingVerification' => 'Awaiting Verification',
                        'Approved' => 'Approved',
                        'Rejected' => 'Rejected',
                        'Expired' => 'Expired'
                    ];

                    $extensionClasses = [
                        'PendingPayment' => 'pending',
                        'AwaitingVerification' => 'review',
                        'Approved' => 'approved',
                        'Rejected' => 'rejected',
                        'Expired' => 'expired'
                    ];
                    ?>

                    <?php if ($history !== []): ?>
                        <details class="extension-history">
                            <summary class="extension-history-toggle">
                                <span>
                                    Extend History
                                    <span class="extension-history-count">
                                        <?= count($history) ?>
                                    </span>
                                </span>
                                <span class="extension-history-chevron"
                                    aria-hidden="true">⌄</span>
                            </summary>

                            <div class="extension-history-list">
                                <?php foreach ($history as $item): ?>
                                    <?php
                                    $extensionId = (int) $item['extension_id'];
                                    $extensionStatus = (string) $item['status'];

                                    $statusLabel =
                                        $extensionLabels[$extensionStatus]
                                        ?? $extensionStatus;

                                    $statusClass =
                                        $extensionClasses[$extensionStatus]
                                        ?? 'pending';
                                    ?>

                                    <article class="extension-history-item">
                                        <div class="extension-history-top">
                                            <div>
                                                <p class="extension-history-days">
                                                    +<?= (int) $item['extra_days'] ?>
                                                    day(s)
                                                </p>

                                                <p class="extension-history-date">
                                                    Requested:
                                                    <?= rental_escape(
                                                        rental_date($item['created_at'])
                                                    ) ?>
                                                </p>
                                            </div>

                                            <span class="extension-history-status <?= rental_escape($statusClass) ?>">
                                                <?= rental_escape($statusLabel) ?>
                                            </span>
                                        </div>

                                        <?php if ($item['reviewed_at'] !== null): ?>
                                            <p class="extension-history-date">
                                                Reviewed:
                                                <?= rental_escape(
                                                    rental_date($item['reviewed_at'])
                                                ) ?>
                                            </p>
                                        <?php endif; ?>

                                        <?php if ($extensionStatus === 'Approved'): ?>
                                            <p class="extension-history-message">
                                                Rental extension approved.
                                                Your rental duration has been updated.
                                            </p>
                                        <?php elseif ($extensionStatus === 'Rejected'): ?>
                                            <p class="extension-history-message">
                                                This extension request was rejected
                                                by admin.
                                            </p>
                                        <?php elseif ($extensionStatus === 'Expired'): ?>
                                            <p class="extension-history-message">
                                                Payment deadline expired.
                                                No extra rental duration was added.
                                            </p>
                                        <?php elseif ($extensionStatus === 'PendingPayment'): ?>
                                            <p class="extension-history-message">
                                                Complete payment before the
                                                five-minute deadline.
                                            </p>
                                        <?php else: ?>
                                            <p class="extension-history-message">
                                                Your payment proof is waiting
                                                for admin verification.
                                            </p>
                                        <?php endif; ?>

                                        <?php if ($item['has_proof']): ?>
                                            <details class="extension-proof-details">
                                                <summary class="extension-proof-toggle">
                                                    View Payment Proof
                                                </summary>

                                                <div class="extension-proof-content">
                                                    <img
                                                        src="/api/rentals/extension-proof.php?extension_id=<?= $extensionId ?>"
                                                        alt="Payment proof for extension #<?= $extensionId ?>"
                                                        class="extension-proof-image"
                                                        loading="lazy"
                                                        referrerpolicy="no-referrer"
                                                    >

                                                    <a
                                                        href="/api/rentals/extension-proof.php?extension_id=<?= $extensionId ?>"
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        class="extension-proof-link"
                                                    >
                                                        Open full image
                                                    </a>
                                                </div>
                                            </details>
                                        <?php else: ?>
                                            <p class="extension-history-no-proof">
                                                No payment proof uploaded.
                                            </p>
                                        <?php endif; ?>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        </details>
                    <?php endif; ?>
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

            <div class="extend-payment-summary">
                <p class="extend-payment-label">
                    Scan QRIS to complete payment
                </p>

                <img
                    src="/assets/img/qris.png"
                    alt="NovaPS QRIS payment"
                    class="extend-qris"
                >

                <p id="extend-payment-amount"
                class="extend-payment-amount"></p>

                <p id="extend-payment-timer"
                class="extend-payment-timer"
                aria-live="off"></p>
            </div>

            <div class="extend-field">
                <label for="extend-proof">
                    Payment Proof
                </label>

                <label for="extend-proof"
                    class="extend-upload-area">
                    <span class="extend-upload-icon" aria-hidden="true">
                        ↑
                    </span>

                    <span class="extend-upload-title">
                        Choose Payment Screenshot
                    </span>

                    <span class="extend-upload-hint">
                        JPG or PNG, maximum 2 MB
                    </span>
                </label>

                <input
                    id="extend-proof"
                    type="file"
                    accept="image/jpeg,image/png"
                    required
                    class="extend-file-input"
                >
            </div>

            <div id="extend-preview-wrapper"
                class="extend-preview-wrapper"
                hidden>

                <div class="extend-preview-header">
                    <span>Image Preview</span>

                    <button
                        id="extend-remove-proof"
                        type="button"
                        class="extend-remove-proof">
                        Remove
                    </button>
                </div>

                <img
                    id="extend-preview-image"
                    alt="Selected payment proof preview"
                    class="extend-preview-image"
                >

                <p id="extend-file-name"
                class="extend-file-name"></p>
            </div>

            <div class="extend-action">
                <button
                    id="extend-upload-submit"
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
