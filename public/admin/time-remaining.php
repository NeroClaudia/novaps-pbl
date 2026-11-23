<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/includes/bootstrap.php';
require_once __DIR__ . '/../../app/partials/admin-layout.php';

header('Cache-Control: private, no-store');

$auth = $_SESSION['auth'] ?? null;

if (
    !is_array($auth) ||
    (int) ($auth['user_id'] ?? 0) < 1
) {
    header('Location: /admin/login.php');
    exit;
}

if (!in_array($auth['role'] ?? null, ['admin', 'owner'], true)) {
    http_response_code(403);
    exit('403 Forbidden');
}

$stmt = $pdo->prepare(
    "SELECT full_name, role
     FROM users
     WHERE user_id = :id
       AND role IN ('admin', 'owner')"
);

$stmt->execute(['id' => (int) $auth['user_id']]);
$staff = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$staff || $staff['role'] !== $auth['role']) {
    unset($_SESSION['auth']);
    header('Location: /admin/login.php');
    exit;
}

$_SESSION['admin_csrf'] ??= bin2hex(random_bytes(32));

$esc = static fn(string $value): string =>
    htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Time Remaining - NovaPS Admin</title>
        <link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
        <link rel="preconnect"href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Poppins:wght@600;700&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="/assets/css/admin-time-remaining.css">
        <link rel="stylesheet" href="/assets/css/time-remaining-admin-ssr.css">
        <link rel="stylesheet" href="/assets/css/admin-layout.css?v=1">
    <meta name="admin-csrf" content="<?= $esc($_SESSION['admin_csrf']) ?>">
    <script src="/assets/js/admin-time-remaining-ssr.js?v=1" defer></script>
    <script src="/assets/js/admin-extensions.js?v=1" defer></script>
    <script src="/assets/js/admin-late-fees.js?v=1" defer></script>
</head>

<body class="min-h-screen bg-slate-100 text-slate-800">

<?php render_admin_header((string) $staff['full_name']); ?>

<div class="admin-shell">

    <?php render_admin_sidebar('time'); ?>

    <main>
        <div class="time-header">
            <h1 class="font-display text-[32px] font-bold">
                Time Remaining Orders
            </h1>

            <button id="refresh-rentals"
                    type="button"
                    class="time-btn">
                Refresh Data
            </button>
            <button id="review-extensions" type="button" class="time-btn">
                Extension Requests
                <span id="extension-count">0</span>
            </button>
        </div>

        <section class="time-grid"
                 aria-label="Rental summary">

            <button class="time-card"
                    type="button"
                    data-chip="all"
                    aria-pressed="true">
                <span class="time-card-label">In rental</span>
                <span class="time-card-value"
                      data-chip-value>0</span>
            </button>

            <button class="time-card"
                    type="button"
                    data-chip="h4"
                    aria-pressed="false">
                <span class="time-card-label">
                    Ending within 4 hours
                </span>
                <span class="time-card-value"
                      data-chip-value>0</span>
            </button>

            <button class="time-card"
                    type="button"
                    data-chip="h1"
                    aria-pressed="false">
                <span class="time-card-label">
                    Ending within 1 hour
                </span>
                <span class="time-card-value"
                      data-chip-value>0</span>
            </button>

            <button class="time-card"
                    type="button"
                    data-chip="overdue"
                    aria-pressed="false">
                <span class="time-card-label">Overdue</span>
                <span class="time-card-value"
                      data-chip-value>0</span>
            </button>
        </section>

        <section class="time-panel">
            <div class="time-toolbar">
                <input id="search"
                       class="time-input time-search"
                       type="search"
                       maxlength="100"
                       placeholder="Search name, phone, device or booking code...">

                <select id="status-filter"
                        class="time-input">
                    <option value="all">All Status</option>
                    <option value="over4">More than 4 hours</option>
                    <option value="h4">Within 4 hours</option>
                    <option value="h1">Within 1 hour</option>
                    <option value="overdue">Overdue</option>
                </select>
            </div>

            <div class="time-table-wrap">
                <table class="time-table">
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Booking</th>
                            <th>Device</th>
                            <th>Duration</th>
                            <th>Delivered</th>
                            <th>Time Remaining</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="tr-body"></tbody>
                </table>
            </div>

            <p id="tr-empty"
               class="time-note"
               hidden>
                No active rentals.
            </p>

            <p id="tr-message"
               class="time-note"
               role="status"
               aria-live="polite">
                Loading rentals...
            </p>

            <p class="time-note" style="margin-top:16px">
                Late fees are charged at Rp10.000 per hour, rounded up.
            </p>
        </section>

        <section class="mt-6 rounded-lg bg-white p-6 shadow-sm">
            <h2 class="mb-2 text-xl font-semibold">
                Unpaid Late Fees
            </h2>

            <p class="mb-4 text-sm text-slate-500">
                Receive late fee payments by Cash or QRIS
                after the console has been returned.
            </p>

            <button
                type="button"
                id="refresh-late-fees"
                class="time-btn mb-4"
            >
                Refresh Late Fees
            </button>

            <div class="time-table-wrap">
                <table class="time-table">
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Booking</th>
                            <th>Device</th>
                            <th>Late Fee</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="late-fee-body"></tbody>
                </table>
            </div>

            <p
                id="late-fee-message"
                class="time-note"
                role="status"
                aria-live="polite"
            ></p>
        </section>

    </main>
</div>

<div id="return-modal"
     class="time-modal"
     role="dialog"
     aria-modal="true"
     aria-labelledby="return-modal-title"
     hidden>

    <div class="time-dialog">
        <div class="time-header">
            <h2 id="return-modal-title"
                class="font-display text-xl font-bold">
                Rental Detail
            </h2>

            <button id="return-close"
                    class="time-secondary"
                    type="button"
                    aria-label="Close">
                ✕
            </button>
        </div>

        <dl id="return-detail" class="time-detail"></dl>

        <label for="return-condition"
               class="time-note">
            Condition at Return (optional)
        </label>

        <textarea id="return-condition"
                  class="time-input"
                  maxlength="100"
                  rows="3"
                  style="width:100%;resize:vertical"
                  placeholder="Contoh: Perangkat dalam kondisi baik"></textarea>

        <p id="return-error"
           role="alert"
           style="color:#b91c1c;margin-top:8px"></p>

        <div class="time-modal-actions">
            <button id="return-cancel"
                    type="button"
                    class="time-secondary">
                Cancel
            </button>

            <button id="return-submit"
                    type="button"
                    class="time-btn">
                Confirm Return
            </button>
        </div>
    </div>
</div>

<div
    id="late-fee-modal"
    class="time-modal"
    role="dialog"
    aria-modal="true"
    aria-labelledby="late-fee-modal-title"
    hidden
>
    <div class="time-dialog">
        <div class="time-header">
            <h2 id="late-fee-modal-title">
                Receive Late Fee Payment
            </h2>

            <button
                id="late-fee-close"
                class="time-secondary"
                type="button"
                aria-label="Close"
            >✕</button>
        </div>

        <dl id="late-fee-detail" class="time-detail"></dl>

        <fieldset class="mt-4">
            <legend class="mb-2 font-semibold">
                Payment Method
            </legend>

            <label class="mr-5">
                <input
                    type="radio"
                    name="late-fee-method"
                    value="Cash"
                    checked
                >
                Cash
            </label>

            <label>
                <input
                    type="radio"
                    name="late-fee-method"
                    value="QRIS"
                >
                QRIS
            </label>
        </fieldset>

        <div
            id="late-fee-qris"
            class="mt-4 text-center"
            hidden
        >
            <img
                src="/assets/img/qris.png"
                alt="NovaPS QRIS"
                class="mx-auto max-w-60"
            >

            <p class="mt-2 text-sm">
                Verify payment in the recipient
                account before confirmation.
            </p>
        </div>

        <p id="late-fee-instructions" class="time-note mt-4">
            Confirm only after receiving the payment.
        </p>

        <p
            id="late-fee-error"
            class="time-note"
            role="alert"
        ></p>

        <div class="mt-5 flex justify-end gap-3">
            <button
                id="late-fee-cancel"
                class="time-secondary"
                type="button"
            >
                Cancel
            </button>

            <button
                id="late-fee-submit"
                class="time-btn"
                type="button"
            >
                Confirm Payment
            </button>
        </div>
    </div>
</div>

<div id="extension-modal"
     class="time-modal"
     role="dialog"
     aria-modal="true"
     aria-labelledby="extension-modal-title"
     hidden>

    <div class="time-dialog" style="width:min(100%,680px)">
        <div class="time-header">
            <h2 id="extension-modal-title"
                class="font-display text-xl font-bold">
                Extension Requests
            </h2>

            <button id="extension-close"
                    type="button"
                    class="time-secondary">
                ✕
            </button>
        </div>

        <p id="extension-message"
           class="time-note"
           role="status"></p>

        <div id="extension-list"
             style="display:grid;gap:12px;margin-top:16px">
        </div>

        <section id="extension-review" hidden>
            <h3 class="font-display text-lg font-bold"
                style="margin-top:18px">
                Review Extension
            </h3>

            <dl id="extension-detail"
                class="time-detail"></dl>

            <img id="extension-proof"
                 alt="Extension payment proof"
                 style="max-height:340px;max-width:100%;
                        display:block;margin:12px auto">

            <label style="display:flex;align-items:center;gap:8px">
                <input id="extension-confirm-payment"
                       type="checkbox">
                I have verified the payment.
            </label>

            <p id="extension-error"
               role="alert"
               style="color:#b91c1c;margin-top:8px">
            </p>

            <div class="time-modal-actions">
                <button id="extension-reject"
                        type="button"
                        class="time-secondary">
                    Reject
                </button>

                <button id="extension-approve"
                        type="button"
                        class="time-btn">
                    Approve Extend
                </button>
            </div>
        </section>
    </div>
</div>
</body>
</html>
