<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/includes/bootstrap.php';

if (($_SESSION['auth']['role'] ?? null) === 'customer') {
    header('Location: /index.php');
    exit;
}

$authPage = 'signup';

?>

<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Sign Up - NovaPS</title>
    <link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
      href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Poppins:wght@600;700&display=swap"
      rel="stylesheet"
    />
    <link rel="stylesheet" href="/assets/css/signup.css" />
    <script src="/assets/js/shared/session.js"></script>
    <script src="/assets/js/shared/validation.js" defer></script>
    <script src="/assets/js/shared/image.js" defer></script>
    <script src="/assets/js/signup.js?v=5" defer></script>
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
          Sign up
        </h1>
        <p class="mb-3.5 mt-1.5 text-center leading-tight">
          Create your new account
        </p>
        <form id="signup-form" method="post" enctype="multipart/form-data" novalidate>
          <div data-field class="group/field mb-2.5">
            <label for="fullname" class="mb-0.5 block text-base"
              >Full Name</label
            >
            <input
              type="text"
              id="fullname"
              name="fullname"
              autocomplete="name"
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
            <label for="phone" class="mb-0.5 block text-base"
              >Phone Number (WA)</label
            >
            <input
              type="tel"
              id="phone"
              name="phone"
              autocomplete="tel"
              data-validate="phone"
              class="h-8 w-full rounded-md border border-slate-200 bg-slate-50 px-2.5 text-sm transition-all duration-150 hover:border-indigo-300 focus:border-indigo-500 focus:bg-white focus:outline-none focus:ring-[3px] focus:ring-indigo-500/20 group-data-[invalid]/field:border-red-600"
            />
            <p
              data-error
              role="alert"
              class="mt-0.5 text-[11px] text-red-600 empty:hidden"
            ></p>
          </div>
          <div data-field class="group/field mb-2.5">
            <label for="address" class="mb-0.5 block text-base">Address</label>
            <input
              type="text"
              id="address"
              name="address"
              autocomplete="street-address"
              data-validate="required"
              class="h-8 w-full rounded-md border border-slate-200 bg-slate-50 px-2.5 text-sm transition-all duration-150 hover:border-indigo-300 focus:border-indigo-500 focus:bg-white focus:outline-none focus:ring-[3px] focus:ring-indigo-500/20 group-data-[invalid]/field:border-red-600"
            />
            <p
              data-error
              role="alert"
              class="mt-0.5 text-[11px] text-red-600 empty:hidden"
            ></p>
          </div>
            <!-- Identity Document Type -->
            <div data-field class="group/field mb-2.5">
              <label
                for="identity_doc_type"
                class="mb-0.5 block text-base"
              >
                Identity Document Type
              </label>

              <select
                id="identity_doc_type"
                name="identity_doc_type"
                required
                class="h-9 w-full rounded-md border border-slate-200 bg-slate-50 px-2.5 text-sm transition-all duration-150 hover:border-indigo-300 focus:border-indigo-500 focus:bg-white focus:outline-none focus:ring-[3px] focus:ring-indigo-500/20 group-data-[invalid]/field:border-red-600"
              >
                <option value="">Select identity type</option>
                <option value="KTP">KTP</option>
                <option value="SIM">SIM</option>
                <option value="KTM">KTM</option>
                <option value="Kartu Pelajar">Kartu Pelajar</option>
              </select>

              <p
                data-error
                role="alert"
                class="mt-0.5 text-[11px] text-red-600 empty:hidden"
              ></p>
            </div>

            <!-- Identity Document Upload -->
            <div data-field class="group/field mb-2.5">
              <label
                for="idcard"
                class="mb-0.5 block text-base"
              >
                ID Card Photo
              </label>

              <label
                for="idcard"
                data-upload
                class="group/upload relative grid h-32 cursor-pointer place-items-center overflow-hidden rounded-md border-[1.5px] border-dashed border-slate-300 bg-slate-50 text-slate-400 transition-all duration-150 hover:border-indigo-500 hover:bg-indigo-50 focus-within:border-indigo-500 focus-within:ring-[3px] focus-within:ring-indigo-500/20 group-data-[invalid]/field:border-red-600"
              >
                <svg
                  data-upload-placeholder
                  width="28"
                  height="28"
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="1.5"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                >
                  <rect x="3" y="4" width="18" height="16" rx="2" />
                  <circle cx="9" cy="10" r="1.5" />
                  <path d="m21 16-5-5-9 9" />
                </svg>

                <img
                  data-preview
                  alt="ID Card preview"
                  class="hidden h-full w-full object-contain"
                />

                <input
                  type="file"
                  id="idcard"
                  name="identity_doc"
                  accept=".jpg,.jpeg,.png,image/jpeg,image/png"
                  required
                  class="absolute inset-0 h-full w-full cursor-pointer opacity-0"
                />
              </label>

              <p
                class="mt-1 text-[11px] text-slate-500"
              >
                Upload JPG or PNG, maximum 2 MB.
              </p>

              <p
                data-file-name
                class="mt-0.5 break-all text-[11px] text-slate-500"
              ></p>

              <p
                data-error
                role="alert"
                class="mt-0.5 text-[11px] text-red-600 empty:hidden"
              ></p>
            </div>
          <div data-field class="group/field mb-2.5">
            <label for="email" class="mb-0.5 block text-base">Email</label>
            <input
              type="email"
              id="email"
              name="email"
              autocomplete="email"
              data-validate="required email"
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
              autocomplete="new-password"
              data-validate="password"
              class="h-8 w-full rounded-md border border-slate-200 bg-slate-50 px-2.5 text-sm transition-all duration-150 hover:border-indigo-300 focus:border-indigo-500 focus:bg-white focus:outline-none focus:ring-[3px] focus:ring-indigo-500/20 group-data-[invalid]/field:border-red-600"
            />
            <p class="mt-0.5 text-[10px] text-slate-400">
              Must have 8 characters &amp; number.
            </p>
            <p
              data-error
              role="alert"
              class="mt-0.5 text-[11px] text-red-600 empty:hidden"
            ></p>
          </div>
          <div data-field class="group/field mb-2.5">
            <label for="confirm" class="mb-0.5 block text-base"
              >Confirm Password</label
            >
            <input
              type="password"
              id="confirm"
              name="confirm"
              autocomplete="new-password"
              data-validate="match"
              data-match="#password"
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
            Sign Up
          </button>
        </form>
        <div class="mt-4 grid gap-1.5 text-center text-xs">
          <p>
            Already have account?
            <a
              href="/pages/login.php"
              class="ml-2 text-blue-600 underline underline-offset-[3px] transition-all duration-150 hover:text-blue-700 hover:underline-offset-[5px]"
              >Login</a
            >
          </p>
        </div>
      </section>
    </main>
  </body>
</html>
