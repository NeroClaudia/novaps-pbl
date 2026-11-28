<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/includes/bootstrap.php';

header('Cache-Control: private, no-store');

$auth = $_SESSION['auth'] ?? null;

if (
    is_array($auth) &&
    in_array($auth['role'] ?? null, ['admin', 'owner'], true) &&
    (int) ($auth['user_id'] ?? 0) > 0
) {
    $stmt = $pdo->prepare(
        "SELECT 1 FROM users
         WHERE user_id = :id AND role IN ('admin', 'owner')"
    );

    $stmt->execute(['id' => (int) $auth['user_id']]);

    if ($stmt->fetchColumn()) {
        header('Location: /admin/dashboard.php');
        exit;
    }

    unset($_SESSION['auth']);
}
?>

<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin Login - NovaPS</title>
  <link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Poppins:wght@600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/admin-login.css">
  <script src="<?= asset('/assets/js/admin-login-ssr.js') ?>" defer></script>
</head>

<body class="grid min-h-screen place-items-center bg-slate-900 p-4 text-slate-800">
  <main class="w-[360px] max-w-full">

    <div class="mb-6 flex justify-center">
      <a
        href="/index.php"
        class="font-display text-2xl font-bold text-white"
      >
        Nova<span class="text-cyan-400">PS</span>
      </a>
    </div>

    <section class="rounded-lg bg-white p-6">
      <h1 class="text-center font-display text-[28px] font-bold text-black">
        Admin Login
      </h1>

      <p class="mt-1 mb-4 text-center text-slate-500">
        Only for NovaPS staff
      </p>

      <form id="admin-login-form" novalidate>
        <div class="mb-3">
          <label for="username" class="mb-1 block">
            Username
          </label>

          <input
            type="text"
            id="username"
            name="username"
            autocomplete="username"
            maxlength="50"
            required
            class="field-input h-10"
          >
        </div>

        <div class="mb-3">
          <label for="password" class="mb-1 block">
            Password
          </label>

          <input
            type="password"
            id="password"
            name="password"
            autocomplete="current-password"
            required
            class="field-input h-10"
          >
        </div>

        <p
          id="login-error"
          role="alert"
          aria-live="polite"
          class="mb-3 text-sm text-red-600"
        ></p>

        <button
          id="login-button"
          type="submit"
          class="btn btn-indigo mt-4 h-12 w-full p-0 text-xl disabled:cursor-wait disabled:opacity-80"
        >
          Login
        </button>
      </form>

      <a
        href="/index.php"
        class="mt-4 block text-center text-xs text-blue-600 underline"
      >
        Back to website
      </a>
    </section>
  </main>
</body>
</html>