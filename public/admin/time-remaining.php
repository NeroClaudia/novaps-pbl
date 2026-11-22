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
        <link rel="stylesheet" href="/assets/css/admin-layout.css?v=1">
    <meta name="admin-csrf" content="<?= $esc($_SESSION['admin_csrf']) ?>">
    <style>
        .time-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }

        .time-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
            margin: 20px 0 24px;
        }

        .time-card {
            background: white;
            border: 1px solid transparent;
            border-radius: 8px;
            padding: 16px;
            text-align: left;
            cursor: pointer;
        }

        .time-card[aria-pressed="false"] {
            border-color: transparent;
            box-shadow: none;
        }

        .time-card[aria-pressed="true"] {
            border-color: #6366f1;
            box-shadow: 0 0 0 1px #6366f1;
        }

        .time-card:focus:not(:focus-visible) {
            outline: none;
        }

        .time-card:focus-visible {
            outline: 2px solid #0891b2;
            outline-offset: 3px;
        }


        .time-card-label {
            display: block;
            color: #64748b;
            font-size: 14px;
        }

        .time-card-value {
            display: block;
            font-size: 28px;
            font-weight: 700;
            color: #1e293b;
        }

        .time-panel {
            background: white;
            border-radius: 8px;
            padding: 24px;
        }

        .time-toolbar {
            display: flex;
            gap: 12px;
            margin-bottom: 16px;
        }

        .time-input {
            border: 1px solid #cbd5e1;
            background: white;
            border-radius: 6px;
            padding: 10px 12px;
            min-width: 0;
        }

        .time-search {
            flex: 1;
        }

        .time-table-wrap {
            overflow-x: auto;
        }

        .time-table {
            width: 100%;
            min-width: 900px;
            border-collapse: collapse;
            text-align: left;
        }

        .time-table th,
        .time-table td {
            padding: 13px 10px;
            border-bottom: 1px solid #cbd5e1;
            font-size: 14px;
        }

        .time-table th {
            color: #475569;
            font-weight: 600;
        }

        .time-pill {
            display: inline-block;
            border-radius: 6px;
            padding: 5px 10px;
            font-weight: 600;
            font-variant-numeric: tabular-nums;
        }

        .time-ok {
            background: #dcfce7;
            color: #166534;
        }

        .time-warning {
            background: #fef3c7;
            color: #92400e;
        }

        .time-danger {
            background: #fee2e2;
            color: #b91c1c;
        }

        .time-btn {
            border: 0;
            border-radius: 6px;
            padding: 9px 14px;
            background: #6366f1;
            color: white;
            font-weight: 600;
            cursor: pointer;
        }

        .time-btn:disabled {
            opacity: .55;
            cursor: not-allowed;
        }

        .time-modal {
            position: fixed;
            inset: 0;
            z-index: 60;
            display: grid;
            place-items: center;
            padding: 16px;
            background: rgba(2, 6, 23, .65);
        }

        .time-modal[hidden] {
            display: none !important;
        }

        .time-dialog {
            width: min(100%, 520px);
            max-height: 90vh;
            overflow: auto;
            border-radius: 12px;
            background: white;
            padding: 24px;
        }

        .time-detail {
            display: grid;
            grid-template-columns: 130px 1fr;
            gap: 10px;
            margin: 18px 0;
            font-size: 14px;
        }

        .time-detail dt {
            color: #64748b;
        }

        .time-detail dd {
            overflow-wrap: anywhere;
        }

        .time-modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 18px;
        }

        .time-secondary {
            border: 1px solid #cbd5e1;
            background: white;
            border-radius: 6px;
            padding: 9px 14px;
            cursor: pointer;
        }

        .time-note {
            color: #64748b;
            font-size: 13px;
        }

        @media (max-width: 900px) {
            .time-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 600px) {
            .time-toolbar {
                flex-direction: column;
            }

            .time-grid {
                grid-template-columns: 1fr 1fr;
            }

            .time-detail {
                grid-template-columns: 105px 1fr;
            }
        }

        body {
            font-family: "Inter", sans-serif;
            font-size: 16px;
            color: #1e293b;
        }

        /* Heading menggunakan Poppins */
        .time-header h1,
        .time-header h2,
        .time-card-value {
            font-family: "Poppins", sans-serif;
        }

        /* Ukuran heading utama seperti Dashboard */
        .time-header h1 {
            font-size: 32px;
            font-weight: 700;
            line-height: 1.3;
        }

        /* Kartu statistik */
        .time-card-label {
            font-family: "Inter", sans-serif;
            font-size: 16px;
            font-weight: 400;
        }

        .time-card-value {
            font-size: 32px;
            font-weight: 700;
            line-height: 1.25;
        }

        /* Input, select, dan tombol */
        .time-input,
        .time-btn,
        .time-secondary {
            font-family: "Inter", sans-serif;
            font-size: 14px;
        }

        /* Tabel */
        .time-table th,
        .time-table td {
            font-family: "Inter", sans-serif;
            font-size: 14px;
        }

        /* Countdown */
        .time-pill {
            font-family: "Inter", sans-serif;
            font-size: 14px;
            font-weight: 600;
            font-variant-numeric: tabular-nums;
        }

        /* Teks keterangan */
        .time-note {
            font-family: "Inter", sans-serif;
            font-size: 14px;
        }

        .time-card,
        .time-btn,
        .time-secondary {
            transition:
                transform 150ms ease,
                box-shadow 150ms ease,
                background-color 150ms ease,
                border-color 150ms ease,
                color 150ms ease;
        }

        /* Kartu statistik */
        .time-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(15, 23, 42, .12);

            @media (prefers-reduced-motion: reduce) {
                .time-card,
                .time-btn,
                .time-secondary,
                .units-primary,
                .units-secondary,
                .users-btn {
                    transition: none;
                }
            }
        }

        /* Jangan hilangkan warna aktif ketika hover */
        .time-card[aria-pressed="true"]:hover {
            box-shadow:
                0 0 0 1px #6366f1,
                0 6px 16px rgba(99, 102, 241, .15);

            @media (prefers-reduced-motion: reduce) {
                .time-card,
                .time-btn,
                .time-secondary,
                .units-primary,
                .units-secondary,
                .users-btn {
                    transition: none;
                }
            }
        }

        /* Tombol utama: Refresh, Detail, Confirm Return */
        .time-btn:not(:disabled):hover {
            background-color: #4f46e5;
            transform: translateY(-2px);
            box-shadow: 0 5px 12px rgba(99, 102, 241, .25);

            @media (prefers-reduced-motion: reduce) {
                .time-card,
                .time-btn,
                .time-secondary,
                .units-primary,
                .units-secondary,
                .users-btn {
                    transition: none;
                }
            }
        }

        /* Tombol sekunder: Close, Cancel */
        .time-secondary:not(:disabled):hover {
            background-color: #f1f5f9;
            border-color: #94a3b8;
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(15, 23, 42, .08);

            @media (prefers-reduced-motion: reduce) {
                .time-card,
                .time-btn,
                .time-secondary,
                .units-primary,
                .units-secondary,
                .users-btn {
                    transition: none;
                }
            }
        }

        /* Efek ketika tombol diklik */
        .time-card:active,
        .time-btn:not(:disabled):active,
        .time-secondary:not(:disabled):active {
            transform: scale(.98);
        }

        /* Disabled tidak memiliki efek hover */
        .time-btn:disabled,
        .time-secondary:disabled {
            transform: none;
            box-shadow: none;
        }

        #extension-review[hidden] {
            display: none !important;
        }

        #review-extensions {
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }

        #extension-count {
            padding: 2px 7px;
            border-radius: 999px;
            background: #fff;
            color: #4f46e5;
            font-size: 12px;
            font-weight: 700;
        }
    </style>
    <script src="/assets/js/admin-time-remaining-ssr.js?v=1" defer></script>
    <script src="/assets/js/admin-extensions.js?v=1" defer></script>
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
                Overdue rentals are flagged without a late fee.
            </p>
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
