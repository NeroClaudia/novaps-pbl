<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/includes/bootstrap.php';

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
  <meta charset="UTF-8">
  <meta
    name="viewport"
    content="width=device-width, initial-scale=1"
  >

  <title>Dashboard - NovaPS Admin</title>

  <link
    rel="icon"
    type="image/svg+xml"
    href="/assets/img/favicon.svg"
  >

  <link rel="stylesheet" href="/assets/css/admin-dashboard.css">
</head>

<body class="min-h-screen bg-slate-100 text-slate-800">
  <header class="bg-slate-900 px-6 py-5 text-white">
    <div class="flex items-center justify-between">
      <h1 class="font-display text-2xl font-bold">
        Nova<span class="text-cyan-400">PS</span>
        Admin
      </h1>

      <span><?= $staffName ?></span>
    </div>
  </header>

  <main class="mx-auto max-w-5xl p-6">
    <section class="rounded-lg bg-white p-6 shadow-sm">
      <h2 class="text-2xl font-bold">
        Admin Dashboard
      </h2>

      <p class="mt-3 text-slate-600">
        Login berhasil. Akun staff telah diverifikasi
        melalui database PostgreSQL.
      </p>

      <p class="mt-3 text-sm text-slate-500">
        Integrasi statistik dan daftar pesanan
        akan ditambahkan pada tahap berikutnya.
      </p>
    </section>
  </main>
</body>
</html>
