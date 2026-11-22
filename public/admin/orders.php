<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/includes/bootstrap.php';
require_once __DIR__ . '/../../app/partials/admin-layout.php';

header('Cache-Control: private, no-store');

$auth = $_SESSION['auth'] ?? null;

if (!is_array($auth) || !isset($auth['user_id'])) {
    header('Location: /admin/login.php');
    exit;
}

$userId = filter_var(
    $auth['user_id'],
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($userId === false || $userId === null) {
    unset($_SESSION['auth']);
    header('Location: /admin/login.php');
    exit;
}

if (!in_array($auth['role'] ?? null, ['admin', 'owner'], true)) {
    http_response_code(403);
    exit('403 Forbidden - Akses admin ditolak.');
}

// Validasi ulang terhadap database.
// Session lama dari sebelum TRUNCATE tidak otomatis dipercaya.
$stmt = $pdo->prepare(
    "SELECT user_id, full_name, role
     FROM users
     WHERE user_id = :id
       AND role IN ('admin', 'owner')"
);

$stmt->execute(['id' => $userId]);
$staff = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$staff || $staff['role'] !== $auth['role']) {
    unset($_SESSION['auth']);
    header('Location: /admin/login.php');
    exit;
}

$staffName = htmlspecialchars(
    (string) $staff['full_name'],
    ENT_QUOTES,
    'UTF-8'
);
?>

<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Manage Orders - NovaPS Admin</title>
    <link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
      href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Poppins:wght@600;700&display=swap"
      rel="stylesheet"
    />
    <link rel="stylesheet" href="/assets/css/admin-orders.css" />
    <link rel="stylesheet" href="/assets/css/admin-layout.css?v=1">
    <script src="/assets/js/admin-order-detail.js?v=1"></script>
    <script src="/assets/js/admin-orders-ssr.js?v=3" defer></script>
  </head>
  <body class="min-h-screen bg-slate-100 text-slate-800">

  <?php render_admin_header((string) $staff['full_name']); ?>
  
    <div class="admin-shell">

      <?php render_admin_sidebar('orders'); ?>
      
      <main class="min-w-0 p-4 md:p-6">
        <h1 class="font-display text-[32px] font-bold text-slate-800">
          Manage Orders
        </h1>
        <section class="mt-5 rounded-lg bg-white p-6">
          <div class="flex flex-col gap-3 sm:flex-row">
            <input
              type="search"
              id="search"
              placeholder="Search customer name, phone or booking code..."
              aria-label="Search orders"
              class="field-input h-10 flex-1"
              maxlength="50"
            />
            <select
              id="status-filter"
              aria-label="Filter by status"
              class="field-input h-10 sm:w-52"
            >
              <option value="all">All Status</option>
              <option value="not_paid">Not Paid</option>
              <option value="pending">Awaiting Verification</option>
              <option value="shipped">Shipped</option>
              <option value="complete">Complete</option>
              <option value="canceled">Canceled</option>
              <option value="rejected">Rejected</option>
              <option value="returned">Returned</option>
              <option value="overdue">Overdue</option>
            </select>
          </div>
          <div class="mt-4 overflow-x-auto">
            <table class="w-full min-w-[860px] text-left">
              <thead id="orders-head"></thead>
              <tbody id="orders-body"></tbody>
            </table>
          </div>
          <p id="orders-empty" hidden class="py-8 text-center text-slate-500">
            No orders found.
          </p>
          <div
            class="mt-4 flex flex-wrap items-center justify-between gap-3 text-sm text-slate-500"
          >
            <p id="orders-count" aria-live="polite"></p>
            <div class="flex items-center gap-2">
              <button
                type="button"
                id="prev-page"
                class="btn btn-mini btn-mini-blue"
              >
                Previous
              </button>
              <span id="page-label"></span>
              <button
                type="button"
                id="next-page"
                class="btn btn-mini btn-mini-blue"
              >
                Next
              </button>
            </div>
          </div>
        </section>
      </main>
    </div>
    <div
      id="orderModal"
      role="dialog"
      aria-modal="true"
      aria-labelledby="orderModalTitle"
      aria-hidden="true"
      class="group invisible fixed inset-0 z-50 grid place-items-center bg-slate-950/60 p-4 opacity-0 transition-all duration-200 data-[open]:visible data-[open]:opacity-100"
    >
      <div
        class="max-h-[92vh] w-full max-w-[580px] translate-y-4 scale-95 overflow-y-auto rounded-xl bg-white p-6 transition-transform duration-300 ease-[cubic-bezier(.2,.9,.3,1.2)] group-data-[open]:translate-y-0 group-data-[open]:scale-100"
      >
        <div class="flex items-start justify-between gap-4">
          <h2
            id="orderModalTitle"
            class="font-display text-xl font-bold text-black"
          >
            Order Detail
          </h2>
          <button
            type="button"
            data-close
            aria-label="Close"
            class="grid size-8 shrink-0 cursor-pointer place-items-center rounded-md text-slate-500 transition-all duration-150 hover:scale-110 hover:bg-slate-100 hover:text-slate-900 active:scale-90"
          >
            <svg
              width="20"
              height="20"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="1.6"
              stroke-linecap="round"
              stroke-linejoin="round"
              aria-hidden="true"
            >
              <path d="M6 6l12 12M18 6L6 18" />
            </svg>
          </button>
        </div>
        <div id="orderBody" class="mt-3"></div>
        <div id="rejectBox" hidden class="mt-4 rounded-md bg-red-50 p-3">
          <label
            for="rejectReason"
            class="mb-0.5 block text-sm font-medium text-red-800"
            >Why is this order rejected?</label
          >
          <textarea
            id="rejectReason"
            rows="2"
            maxlength="200"
            placeholder="The customer will see this reason"
            class="field-input h-auto resize-none py-2"
          ></textarea>
          <p
            id="rejectError"
            role="alert"
            class="mt-0.5 text-[11px] text-red-600 empty:hidden"
          ></p>
          <button
            type="button"
            id="confirmReject"
            class="btn mt-2 bg-red-600 px-4 py-2 text-sm font-semibold text-white"
          >
            Confirm reject
          </button>
        </div>
        <div
          id="orderActions"
          class="mt-5 flex flex-wrap justify-end gap-3"
        ></div>
      </div>
    </div>
    <div
      id="toast"
      role="status"
      aria-live="polite"
      class="pointer-events-none fixed bottom-6 left-1/2 z-[60] -translate-x-1/2 translate-y-4 rounded-lg bg-slate-900 px-4 py-2.5 text-sm text-white opacity-0 shadow-lg transition-all duration-300 data-[open]:translate-y-0 data-[open]:opacity-100"
    ></div>
  </body>
</html>
