<?php 
declare(strict_types=1);

require_once __DIR__ . '/../../app/includes/bootstrap.php';

if (($_SESSION['auth']['role'] ?? null) === 'customer') {
    header('Location: /index.php');
    exit;
}

$authPage = 'login';

?>

<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Login - NovaPS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
      href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Poppins:wght@600;700&display=swap"
      rel="stylesheet"
    />
    <link rel="stylesheet" href="/assets/css/login.css" />
    <script src="/assets/js/shared/session.js"></script>
    <script src="/assets/js/shared/validation.js" defer></script>
    <script src="/assets/js/login.js?v=3" defer></script>
  </head>
  <body class="min-h-screen bg-slate-50 text-slate-800">
    <?php require __DIR__ . '/../../app/partials/auth-navbar.php'; ?>
    <main class="mx-auto mt-9 w-[340px] max-w-[calc(100%-2rem)] sm:mt-[60px]">
      <a
        href="/index.php"
        class="mb-7 inline-block text-base text-blue-600 underline underline-offset-4 transition-all duration-150 hover:-translate-x-1 hover:text-blue-700"
        >&larr; Back to Home</a
      >
      <section class="rounded-lg border border-slate-200 bg-white p-6">
        <h1
          class="text-center font-display text-[32px] font-bold leading-[1.3] text-black"
        >
          Login
        </h1>
        <p class="mb-3.5 mt-1.5 text-center leading-tight">
          Log in to rent and track<br />your order status
        </p>
        <form id="login-form" method="post" novalidate>
          <div data-field class="group/field mb-2.5">
            <label for="identifier">
              Email or Phone Number
            </label>
            <input
                    type="text"
                    id="identifier"
                    name="identifier"
                    autocomplete="username"
                    placeholder="Email atau nomor HP"
                    data-validate="required"
                    class="h-8 w-full rounded-md border border-slate-200 bg-slate-50 px-2.5 text-sm transition-all duration-150 hover:border-indigo-300 focus:border-indigo-500 focus:bg-white focus:outline-none focus:ring-[3px] focus:ring-indigo-500/20 group-data-[invalid]/field:border-red-600"
            />
            <p
              data-error
              role="alert"
              class="mt-0.5 text-[11px] text-red-600 empty:hidden"
            ></p>
          </div>
          <div data-field class="group/field mb-2.5">
            <label for="password" class="mb-0.5 block text-base"
              >Password</label
            >
            <input
              type="password"
              id="password"
              name="password"
              autocomplete="current-password"
              data-validate="required"
              class="h-8 w-full rounded-md border border-slate-200 bg-slate-50 px-2.5 text-sm transition-all duration-150 hover:border-indigo-300 focus:border-indigo-500 focus:bg-white focus:outline-none focus:ring-[3px] focus:ring-indigo-500/20 group-data-[invalid]/field:border-red-600"
            />
            <p
              data-error
              role="alert"
              class="mt-0.5 text-[11px] text-red-600 empty:hidden"
            ></p>
          </div>
          <button
            type="submit"
            class="btn btn-indigo mt-[18px] h-12 w-full p-0 text-[22px] disabled:cursor-wait disabled:opacity-80 disabled:hover:translate-y-0 disabled:hover:shadow-none"
          >
            Login
          </button>
        </form>
        <div class="mt-4 grid gap-1.5 text-center text-xs">
          <p>
            Forget your password?
            <a
              href="/pages/forgot-password.php"
              class="ml-2 text-blue-600 underline underline-offset-[3px] transition-all duration-150 hover:text-blue-700 hover:underline-offset-[5px]"
              >Change Password</a
            >
          </p>
          <p>
            Doesn’t have account?
            <a
              href="/pages/signup.php"
              class="ml-2 text-blue-600 underline underline-offset-[3px] transition-all duration-150 hover:text-blue-700 hover:underline-offset-[5px]"
              >Sign Up</a
            >
          </p>
        </div>
      </section>
    </main>
  </body>
</html>
