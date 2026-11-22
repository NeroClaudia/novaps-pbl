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
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1">

    <title>Users - NovaPS Admin</title>
    <link rel="icon" href="/assets/img/favicon.svg"
          type="image/svg+xml">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com"
          crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Poppins:wght@600;700&display=swap"
          rel="stylesheet">

    <link rel="stylesheet"
          href="/assets/css/admin-users.css">
    <link rel="stylesheet"
          href="/assets/css/admin-layout.css?v=1">

    <style>
        body {
            font-family: Inter, sans-serif;
            color: #1e293b;
        }

        .users-heading {
            font-family: Poppins, sans-serif;
            font-size: 32px;
            font-weight: 700;
        }

        .users-toolbar {
            display: flex;
            gap: 12px;
            justify-content: space-between;
            flex-wrap: wrap;
            align-items: center;
        }

        .users-panel {
            margin-top: 20px;
            padding: 24px;
            border-radius: 8px;
            background: white;
        }

        .users-input {
            min-width: 0;
            width: min(100%, 450px);
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 10px 12px;
            font: inherit;
        }

        .users-table-wrap {
            overflow-x: auto;
            margin-top: 16px;
        }

        .users-table {
            width: 100%;
            min-width: 780px;
            border-collapse: collapse;
            text-align: left;
        }

        .users-table th,
        .users-table td {
            padding: 14px 10px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 14px;
        }

        .users-table th {
            color: #64748b;
            font-weight: 600;
        }

        .users-btn {
            padding: 9px 14px;
            border: 0;
            border-radius: 6px;
            background: #6366f1;
            color: white;
            font: 600 14px Inter, sans-serif;
            cursor: pointer;
        }

        .users-btn:disabled {
            cursor: not-allowed;
            opacity: .5;
        }

        .users-btn-secondary {
            background: #fff;
            color: #334155;
            border: 1px solid #cbd5e1;
        }

        .users-badge {
            display: inline-block;
            padding: 4px 9px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
        }

        .users-verified {
            color: #166534;
            background: #dcfce7;
        }

        .users-pending {
            color: #92400e;
            background: #fef3c7;
        }

        .users-missing {
            color: #475569;
            background: #f1f5f9;
        }

        .users-pagination {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
            margin-top: 20px;
        }

        .users-modal {
            position: fixed;
            inset: 0;
            z-index: 60;
            display: grid;
            place-items: center;
            padding: 16px;
            background: rgba(2, 6, 23, .6);
        }

        .users-modal[hidden] {
            display: none !important;
        }

        .users-dialog {
            width: min(100%, 520px);
            max-height: 90vh;
            overflow-y: auto;
            padding: 24px;
            background: white;
            border-radius: 12px;
        }

        .users-detail {
            display: grid;
            grid-template-columns: 145px minmax(0, 1fr);
            gap: 12px;
            margin-top: 20px;
            font-size: 14px;
        }

        .users-detail dt {
            color: #64748b;
        }

        .users-detail dd {
            overflow-wrap: anywhere;
        }

        .users-muted {
            color: #64748b;
            font-size: 14px;
        }

        .users-btn {
            transition:
                transform 150ms ease,
                background-color 150ms ease,
                border-color 150ms ease,
                box-shadow 150ms ease,
                color 150ms ease;
        }

        /* Refresh, Detail, Open Manage Orders */
        .users-btn:not(:disabled):not(.users-btn-secondary):hover {
            background-color: #4f46e5;
            color: #ffffff;
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

        /* Previous, Next, Cancel, Close */
        .users-btn-secondary:not(:disabled):hover {
            background-color: #f1f5f9;
            border-color: #94a3b8;
            color: #1e293b;
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

        /* Efek klik */
        .users-btn:not(:disabled):active {
            transform: scale(.98);
        }

        /* Disabled */
        .users-btn:disabled {
            transform: none;
            box-shadow: none;
            cursor: not-allowed;
        }

    </style>
    <script src="/assets/js/admin-users-ssr.js?v=1" defer></script>
</head>
<body class="min-h-screen bg-slate-100 text-slate-800">

    <?php render_admin_header((string) $staff['full_name']); ?>

    <div class="admin-shell">

        <?php render_admin_sidebar('users'); ?>

        <main>
            <div class="users-toolbar">
                <h1 class="users-heading">Users</h1>

                <button id="refresh-users"
                        class="users-btn"
                        type="button">
                    Refresh Data
                </button>
            </div>

            <section class="users-panel">
                <input
                    id="search-users"
                    class="users-input"
                    type="search"
                    maxlength="100"
                    aria-label="Search customers"
                    placeholder="Search name, email or WhatsApp...">

                <p id="users-message"
                   class="users-muted"
                   role="status"
                   aria-live="polite">
                    Loading customers...
                </p>

                <div class="users-table-wrap">
                    <table class="users-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>No. WA</th>
                                <th>Total Orders</th>
                                <th>Identity</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="users-body"></tbody>
                    </table>
                </div>

                <p id="users-empty" class="users-muted" hidden>
                    No customers found.
                </p>

                <div class="users-pagination">
                    <span id="users-count"
                          class="users-muted"></span>

                    <div class="users-toolbar">
                        <button id="users-prev"
                                class="users-btn users-btn-secondary"
                                type="button">
                            Previous
                        </button>

                        <span id="users-page"
                              class="users-muted"></span>

                        <button id="users-next"
                                class="users-btn users-btn-secondary"
                                type="button">
                            Next
                        </button>
                    </div>
                </div>
            </section>
        </main>
    </div>

    <div id="userModal"
         class="users-modal"
         role="dialog"
         aria-modal="true"
         aria-labelledby="userModalTitle"
         hidden>

        <div class="users-dialog">
            <div class="users-toolbar">
                <h2 id="userModalTitle"
                    class="font-display text-xl font-bold">
                    Customer Detail
                </h2>

                <button id="close-user-modal"
                        class="users-btn users-btn-secondary"
                        type="button"
                        aria-label="Close">
                    ✕
                </button>
            </div>

            <dl id="user-detail" class="users-detail"></dl>

            <div class="users-toolbar" style="margin-top:24px">
                <a id="user-orders-link"
                   class="users-btn"
                   href="/admin/orders.php">
                    View Orders
                </a>

                <button id="close-user-bottom"
                        class="users-btn users-btn-secondary"
                        type="button">
                    Close
                </button>
            </div>
        </div>
    </div>
</body>
</html>
