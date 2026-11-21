<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/includes/bootstrap.php';

header('Cache-Control: private, no-store');

$auth = $_SESSION['auth'] ?? null;

if (!is_array($auth) || (int)($auth['user_id'] ?? 0) < 1) {
  header('Location: /admin/login.php');
  exit;
}

if (!in_array($auth['role'] ?? null, ['admin', 'owner'], true)) {
  http_response_code(403);
  exit('403 Forbidden');
}

$stmt = $pdo->prepare(
  "SELECT full_name, role FROM users WHERE user_id = :id AND role IN ('admin','owner')"
);

$stmt->execute(['id' => (int)$auth['user_id']]);

$staff = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$staff || $staff['role'] !== $auth['role']) {
  unset($_SESSION['auth']);
  header('Location: /admin/login.php');
  exit;
}

$_SESSION['admin_csrf'] ??= bin2hex(random_bytes(32));

$esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

?>

<!doctype html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Manage Unit - NovaPS Admin</title>
  <link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Poppins:wght@600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/admin-units.css">
  <meta name="admin-csrf" content="<?= $esc($_SESSION['admin_csrf']) ?>">
  <style>
    .units-toolbar {
      display: flex;
      gap: 1rem;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap
    }

    .units-primary {
      background: #6366f1;
      color: white;
      border: 0;
      border-radius: .5rem;
      padding: .65rem 1.1rem;
      font-weight: 600;
      cursor: pointer
    }

    .units-primary:hover {
      background: #4f46e5
    }

    .units-primary:disabled {
      opacity: .55;
      cursor: wait
    }

    .units-secondary {
      background: white;
      color: #334155;
      border: 1px solid #cbd5e1;
      border-radius: .5rem;
      padding: .6rem 1rem;
      cursor: pointer
    }

    .units-field {
      width: 100%;
      border: 1px solid #cbd5e1;
      border-radius: .4rem;
      padding: .6rem;
      background: white
    }

    .units-field:focus {
      outline: 2px solid #818cf8;
      outline-offset: 1px
    }

    .units-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
      gap: 1rem;
      margin: 1.2rem 0
    }

    .units-stat {
      background: white;
      padding: 1rem;
      border-radius: .5rem
    }

    .units-number {
      font-size: 1.7rem;
      font-weight: 700
    }

    .units-table {
      width: 100%;
      border-collapse: collapse;
      text-align: left;
      min-width: 850px
    }

    .units-table th,
    .units-table td {
      border-bottom: 1px solid #e2e8f0;
      padding: .85rem .6rem;
      vertical-align: middle
    }

    .units-table th {
      color: #64748b;
      font-weight: 600;
      font-size: .85rem
    }

    .units-modal {
      position: fixed;
      inset: 0;
      z-index: 50;
      background: #02061799;
      display: grid;
      place-items: center;
      padding: 1rem
    }

    .units-modal[hidden] {
      display: none !important
    }

    .units-dialog {
      background: white;
      border-radius: .75rem;
      padding: 1.5rem;
      width: min(100%, 520px);
      max-height: 90vh;
      overflow: auto
    }

    .units-form-grid {
      display: grid;
      gap: .9rem;
      margin-top: 1rem
    }

    .units-form-grid label {
      display: block;
      font-weight: 600;
      font-size: .85rem;
      margin-bottom: .25rem
    }

    .units-error {
      color: #b91c1c;
      font-size: .9rem
    }

    .units-status {
      color: #64748b;
      font-size: .9rem
    }

    .units-tag {
      padding: .2rem .5rem;
      border-radius: .35rem;
      background: #f1f5f9;
      font-size: .8rem
    }

    @media(max-width:700px) {
      .units-table {
        min-width: 760px
      }
    }
  </style>
  <script src="/assets/js/admin-units-ssr.js?v=1" defer></script>
</head>

<body class="min-h-screen bg-slate-100 text-slate-800">
  <header class="flex h-[68px] items-center justify-between bg-slate-900 px-4 md:px-[50px]">
    <a href="/admin/dashboard.php" class="font-display text-2xl font-bold text-white">Nova<span class="text-cyan-400">PS</span></a>
    <div class="group relative">
      <button type="button" id="adminBtn" aria-expanded="false" class="btn btn-outline px-4 py-1.5 text-lg"><?= $esc((string)$staff['full_name']) ?></button>
      <div id="account-menu" hidden style="position:absolute;right:0;top:45px;min-width:150px;z-index:30;background:white;border-radius:8px;padding:8px;box-shadow:0 5px 20px #0002">
        <a href="/index.php" style="display:block;padding:8px">View website</a>
        <button type="button" id="adminLogout" style="display:block;width:100%;text-align:left;padding:8px;cursor:pointer">Logout</button>
      </div>
    </div>
  </header>
  <div class="md:grid md:grid-cols-[256px_1fr]">
    <aside class="bg-slate-900 md:min-h-[calc(100vh-68px)]">
      <nav aria-label="Admin menu" class="flex gap-1 overflow-x-auto p-3 md:flex-col md:p-6">
        <a href="/admin/dashboard.php" class="block rounded-md px-4 py-2 text-base text-slate-300 hover:text-white">Dashboard</a>
        <a href="/admin/orders.php" class="block rounded-md px-4 py-2 text-base text-slate-300 hover:text-white">Orders</a>
        <span class="block rounded-md px-4 py-2 text-base text-slate-500" aria-disabled="true">Time Remaining</span>
        <a href="/admin/units.php" aria-current="page" class="block rounded-md bg-indigo-500 px-4 py-2 text-base font-semibold text-white">Unit</a>
        <span class="block rounded-md px-4 py-2 text-base text-slate-500" aria-disabled="true">Users</span>
      </nav>
    </aside>
    <main class="min-w-0 p-4 md:p-6">
      <div class="units-toolbar">
        <div>
          <h1 class="font-display text-[32px] font-bold text-slate-800">Manage Unit</h1>
          <p class="units-status">Manage individual PlayStation devices and their daily prices.</p>
        </div>
        <button id="add-unit" type="button" class="units-primary">+ Add Device</button>
      </div>
      <section class="units-grid" aria-label="Device summary">
        <div class="units-stat">
          <div class="units-status">Total units</div>
          <div id="stat-total" class="units-number">—</div>
        </div>
        <div class="units-stat">
          <div class="units-status">Available now</div>
          <div id="stat-available" class="units-number">—</div>
        </div>
        <div class="units-stat">
          <div class="units-status">Booked / rented</div>
          <div id="stat-rented" class="units-number">—</div>
        </div>
        <div class="units-stat">
          <div class="units-status">Maintenance</div>
          <div id="stat-maintenance" class="units-number">—</div>
        </div>
      </section>
      <section class="mt-5 rounded-lg bg-white p-6">
        <div class="units-toolbar" style="margin-bottom:1rem">
          <h2 class="font-display text-xl font-bold">Device Inventory</h2>
          <button id="reload-units" type="button" class="units-secondary">Refresh</button>
        </div>
        <p id="units-message" class="units-status" role="status" aria-live="polite">Loading devices...</p>
        <div class="overflow-x-auto">
          <table class="units-table">
            <thead>
              <tr>
                <th>ID</th>
                <th>Device</th>
                <th>Category</th>
                <th>Serial Number</th>
                <th>Booth</th>
                <th>Price/day</th>
                <th>Status</th>
                <th>Booking</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody id="units-body"></tbody>
          </table>
        </div>
      </section>
    </main>
  </div>
  <div id="unitModal" class="units-modal" hidden role="dialog" aria-modal="true" aria-labelledby="unitModalTitle">
    <div class="units-dialog">
      <div class="units-toolbar">
        <h2 id="unitModalTitle" class="font-display text-xl font-bold">Add Device</h2>
        <button id="modal-close" type="button" class="units-secondary" aria-label="Close">✕</button>
      </div>
      <form id="unit-form" class="units-form-grid" novalidate>
        <div>
          <label for="f-name">Device Name</label>
          <input id="f-name" class="units-field" required maxlength="50" placeholder="PS5 - Unit 004">
        </div>
        <div>
          <label for="f-category">Category</label>
          <select id="f-category" class="units-field">
            <option value="PS4">PS4</option>
            <option value="PS5">PS5</option>
          </select>
        </div>
        <div>
          <label for="f-serial">Serial Number</label>
          <input id="f-serial" class="units-field" required maxlength="50" placeholder="PS5-004">
        </div>
        <div>
          <label for="f-booth">Booth Code (optional)</label>
          <input id="f-booth" class="units-field" maxlength="20" placeholder="B04">
        </div>
        <div>
          <label for="f-price">Price per day (Rp)</label>
          <input id="f-price" class="units-field" type="number" min="1" step="1" required placeholder="100000">
        </div>
        <div>
          <label for="f-status">Status</label>
          <select id="f-status" class="units-field">
            <option value="Available">Available</option>
            <option value="Maintenance">Maintenance</option>
          </select>
        </div>
        <p id="unit-error" class="units-error" role="alert"></p>
        <div class="units-toolbar" style="justify-content:flex-end">
          <button id="cancel-unit" type="button" class="units-secondary">Cancel</button>
          <button id="save-unit" type="submit" class="units-primary">Add Device</button>
        </div>
      </form>
    </div>
  </div>
</body>
</html>