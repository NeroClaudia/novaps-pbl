<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/includes/bootstrap.php';
require_once __DIR__ . '/../../app/functions/customers.php';

$auth = require_customer_page();

header('Cache-Control: private, no-store');

$currentPage = 'account';
$showOrderButton = true;

try {
    $profile = get_customer_profile($pdo, (int) $auth['user_id']);

    if ($profile === null) {
        unset($_SESSION['auth']);
        header('Location: /pages/login.php');
        exit;
    }
} catch (PDOException $e) {
    error_log('Account Page Error: ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to load your profile. Please try again later.');
}

?>

<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>My Profile - NovaPS</title>
    <link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
      href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Poppins:wght@600;700&display=swap"
      rel="stylesheet"
    />
    <link rel="stylesheet" href="/assets/css/account.css" />
    <script src="/assets/js/shared/session.js" data-require-login="/pages/login.php"></script>

    <?php require __DIR__ . '/../../app/partials/popup-assets.php'; ?>
    
    <script src="/assets/js/shared/navbar.js" defer></script>
    <script src="/assets/js/shared/validation.js" defer></script>
    <script src="/assets/js/shared/image.js" defer></script>
    <script src="/assets/js/shared/orders.js" defer></script>
    <script src="/assets/js/shared/rental-alerts.js" defer></script>
    <script src="<?= asset('/assets/js/account.js') ?>" defer></script>
  </head>
  <body
    class="min-h-screen bg-slate-50 text-slate-800"
    data-logout-redirect="/index.php"
  >
    <?php require __DIR__ . '/../../app/partials/navbar.php'; ?>

    <main class="mx-auto w-[640px] max-w-[calc(100%-2rem)] pt-5 pb-16">
      <a
        href="/index.php"
        class="inline-block text-2xl text-blue-600 underline underline-offset-4 transition-all duration-150 hover:-translate-x-1 hover:text-blue-700"
        >&larr; Back to Home</a
      >
      <h1
        class="my-6 text-center font-display text-[32px] font-bold text-black"
      >
        My Profile
      </h1>

      <!-- Account data: one field is editable at a time -->
      <section
        id="account-card"
        class="rounded-lg border border-slate-200 bg-white p-6"
      >
      <div class="flex items-center justify-between gap-3">
        <h2 class="font-display text-base font-bold text-black">
          Account Data
        </h2>

        <button
          type="button"
          id="edit-profile"
          class="btn btn-edit px-4 py-2"
        >
          Edit Profile
        </button>
      </div>
        <p
          id="edit-banner"
          role="status"
          hidden
          class="mt-3 rounded-md bg-blue-100 px-3 py-2 text-center text-sm text-blue-950"
        ></p>
        <form id="profile-form" method="post" enctype="multipart/form-data" novalidate class="mt-3">
          <div data-field="fullname" class="group/field mb-2.5">
            <div class="mb-0.5 flex items-center justify-between">
              <label for="fullname" class="text-base">Full Name</label>
            </div>
            <input
              type="text"
              id="fullname"
              name="fullname"
              autocomplete="name"
              data-validate="required"
              readonly
              placeholder="Not set yet"
              class="field-input"
              value="<?= htmlspecialchars($profile['full_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
            />
            <p
              data-error
              role="alert"
              class="mt-0.5 text-[11px] text-red-600 empty:hidden"
            ></p>
          </div>
          <div data-field="phone" class="group/field mb-2.5">
            <div class="mb-0.5 flex items-center justify-between">
              <label for="phone" class="text-base">Phone Number (WA)</label>
            </div>
            <input
              type="tel"
              id="phone"
              name="phone"
              autocomplete="tel"
              data-validate="phone"
              readonly
              placeholder="Not set yet"
              class="field-input"
              value="<?= htmlspecialchars($profile['phone'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
            />
            <p
              data-error
              role="alert"
              class="mt-0.5 text-[11px] text-red-600 empty:hidden"
            ></p>
          </div>
          <div data-field="email" class="group/field mb-2.5">
            <div class="mb-0.5 flex items-center justify-between">
              <label for="email" class="text-base">Email</label>
            </div>
            <input
              type="email"
              id="email"
              name="email"
              autocomplete="email"
              data-validate="email"
              readonly
              placeholder="Not set yet"
              class="field-input"
              value="<?= htmlspecialchars($profile['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
            />
            <p
              data-error
              role="alert"
              class="mt-0.5 text-[11px] text-red-600 empty:hidden"
            ></p>
          </div>
          <div data-field="address" class="group/field mb-2.5">
            <div class="mb-0.5 flex items-center justify-between">
              <label for="address" class="text-base">Address</label>
            </div>
            <input
              type="text"
              id="address"
              name="address"
              autocomplete="street-address"
              data-validate="required"
              readonly
              placeholder="Not set yet"
              class="field-input"
              value="<?= htmlspecialchars($profile['address'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
            />
            <p
              data-error
              role="alert"
              class="mt-0.5 text-[11px] text-red-600 empty:hidden"
            ></p>
          </div>
          <div data-field="idcard" class="group/field mb-2.5">
            <div class="mb-0.5 flex items-center justify-between">
              <label for="idcard" class="text-base">ID Card</label>
            </div>
            <label
              for="identity_doc_type"
              class="mb-1 block text-sm font-medium"
            >
              Identity Document Type
            </label>
            <select
              id="identity_doc_type"
              name="identity_doc_type"
              disabled
              class="field-input mb-3 h-10 w-full"
            >
            <option value="Select document type"></option>
            <?php foreach (['KTP', 'SIM', 'KTM', 'Kartu Pelajar'] as $type): ?>
                <option value="<?= htmlspecialchars($type) ?>" <?= $profile['identity_doc_type'] === $type ? 'selected' : '' ?>>
                    <?= htmlspecialchars($type) ?>
                </option>
            <?php endforeach; ?>
            </select>
            <label
              for="idcard"
              data-upload
              class="group/upload relative grid h-16 cursor-default place-items-center overflow-hidden rounded-md border-[1.5px] border-dashed border-slate-300 bg-slate-100 text-slate-400 transition-all duration-150 group-data-[editing]/field:cursor-pointer group-data-[editing]/field:bg-slate-50 group-data-[editing]/field:hover:border-indigo-500 group-data-[editing]/field:hover:bg-indigo-50 group-data-[editing]/field:hover:text-indigo-500 group-data-[editing]/field:focus-within:ring-[3px] group-data-[editing]/field:focus-within:ring-indigo-500/20 group-data-[invalid]/field:border-red-600 data-[has-image]:h-40"
            >
              <svg
                class="group-data-[has-image]/upload:hidden"
                width="28"
                height="28"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.5"
                stroke-linecap="round"
                stroke-linejoin="round"
                aria-hidden="true"
              >
                <rect x="3" y="4" width="18" height="16" rx="2" />
                <circle cx="9" cy="10" r="1.5" />
                <path d="m21 16-5-5-9 9" />
              </svg>
              <img
                id="idcard-preview"
                alt="ID card preview"
                class="hidden h-full w-full object-contain"
              />
              <input
                type="file"
                id="idcard"
                name="idcard"
                accept="image/jpeg,image/png"
                disabled
                class="absolute inset-0 cursor-pointer opacity-0"
              />
            <p id="identity-status" class="mt-2 text-xs text-slate-500">
                <?php if (!$profile['identity_doc_uploaded']): ?>
                    Identity document not uploaded
                <?php elseif ($profile['identity_doc_uploaded']): ?>
                    Identity document verified
                <?php else: ?>
                    Identity document pending admin verification
                <?php endif; ?>
            </p>
            </label>
            <p
              class="mt-0.5 hidden text-[10px] text-slate-400 group-data-[editing]/field:block"
            >
              Put your ID Card here
            </p>
            <p
              data-error
              role="alert"
              class="mt-0.5 text-[11px] text-red-600 empty:hidden"
            ></p>
          </div>
        </form>
      </section>
      <div
        id="account-actions"
        hidden
        class="mt-6 flex items-center justify-between"
      >
        <button
          type="button"
          id="cancel-field"
          class="btn border-2 border-slate-800 bg-white px-[25px] py-2.5 text-base font-semibold text-slate-800 hover:bg-slate-800 hover:text-white"
        >
          Cancel
        </button>
        <button
          type="submit"
          form="profile-form"
          class="btn btn-indigo px-6 py-2.5 text-base font-semibold"
        >
          Save Changes
        </button>
      </div>

      <!-- Password -->
      <section
        id="password-view"
        class="mt-6 rounded-lg border border-slate-200 bg-white p-6"
      >
        <div class="flex items-start justify-between">
          <h2 class="font-display text-base font-bold text-black">Password</h2>
          <button type="button" id="change-password" class="btn btn-edit">
            Change Password
          </button>
        </div>
        <p class="mt-1 tracking-widest" aria-label="Hidden password">
          **********
        </p>
      </section>

      <form id="password-form" hidden novalidate>
        <section class="mt-0 rounded-lg border border-slate-200 bg-white p-6">
          <h2 class="font-display text-base font-bold text-black">Password</h2>
          <p
            id="password-banner"
            role="status"
            class="mt-3 rounded-md bg-blue-100 px-3 py-2 text-center text-sm text-blue-950"
          >
            You are currently editing password, don’t forget to save your
            changes.
          </p>
          <div id="reset-email-field" hidden class="mt-3">
            <label for="reset-email" class="mb-0.5 block text-base"
              >Email</label
            >
            <input
              type="email"
              id="reset-email"
              readonly
              class="field-input"
              aria-describedby="reset-email-hint"
            />
            <p id="reset-email-hint" class="mt-0.5 text-[11px] text-slate-400">
              This is the email of your account.
            </p>
            <button
              type="button"
              id="remember-toggle"
              class="mt-1 inline-block cursor-pointer text-xs text-blue-600 underline underline-offset-2 transition-all duration-150 hover:translate-x-0.5 hover:text-blue-700 active:scale-95"
            >
              Remembered it? Use your current password
            </button>
          </div>
          <div id="current-field" data-field class="group/field mt-3">
            <label for="current-password" class="mb-0.5 block text-base"
              >Current Password</label
            >
            <div class="group/pw relative">
              <input
                type="password"
                id="current-password"
                name="current-password"
                autocomplete="current-password"
                data-validate="required"
                class="field-input pr-9"
              />
              <button
                type="button"
                data-toggle-password
                aria-label="Show password"
                aria-pressed="false"
                class="absolute top-1/2 right-1 grid size-7 -translate-y-1/2 cursor-pointer place-items-center rounded text-slate-500 transition-all duration-150 hover:scale-110 hover:text-indigo-500 focus-visible:outline-2 focus-visible:outline-indigo-500 active:scale-90"
              >
                <span class="group-data-[visible]/pw:hidden"
                  ><svg
                    width="18"
                    height="18"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.6"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true"
                  >
                    <path
                      d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"
                    />
                    <circle cx="12" cy="12" r="3" /></svg
                ></span>
                <span class="hidden group-data-[visible]/pw:block"
                  ><svg
                    width="18"
                    height="18"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.6"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true"
                  >
                    <path
                      d="M3 3l18 18M6.6 6.6A17 17 0 0 0 2 12s3.6 7 10 7a10 10 0 0 0 4-.8M10 5.1A10 10 0 0 1 12 5c6.4 0 10 7 10 7a17 17 0 0 1-3.2 4"
                    />
                    <path d="M9.9 9.9a3 3 0 0 0 4.2 4.2" /></svg
                ></span>
              </button>
            </div>
            <p
              data-error
              role="alert"
              class="mt-0.5 text-[11px] text-red-600 empty:hidden"
            ></p>
            <button
              type="button"
              id="forgot-toggle"
              class="mt-1 inline-block cursor-pointer text-xs text-blue-600 underline underline-offset-2 transition-all duration-150 hover:translate-x-0.5 hover:text-blue-700 active:scale-95"
            >
              Forgot your current password?
            </button>
          </div>
          <div data-field class="group/field mt-3">
            <label for="new-password" class="mb-0.5 block text-base"
              >New Password</label
            >
            <div class="group/pw relative">
              <input
                type="password"
                id="new-password"
                name="new-password"
                autocomplete="new-password"
                data-validate="password different"
                data-other="#current-password"
                class="field-input pr-9"
              />
              <button
                type="button"
                data-toggle-password
                aria-label="Show password"
                aria-pressed="false"
                class="absolute top-1/2 right-1 grid size-7 -translate-y-1/2 cursor-pointer place-items-center rounded text-slate-500 transition-all duration-150 hover:scale-110 hover:text-indigo-500 focus-visible:outline-2 focus-visible:outline-indigo-500 active:scale-90"
              >
                <span class="group-data-[visible]/pw:hidden"
                  ><svg
                    width="18"
                    height="18"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.6"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true"
                  >
                    <path
                      d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"
                    />
                    <circle cx="12" cy="12" r="3" /></svg
                ></span>
                <span class="hidden group-data-[visible]/pw:block"
                  ><svg
                    width="18"
                    height="18"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.6"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true"
                  >
                    <path
                      d="M3 3l18 18M6.6 6.6A17 17 0 0 0 2 12s3.6 7 10 7a10 10 0 0 0 4-.8M10 5.1A10 10 0 0 1 12 5c6.4 0 10 7 10 7a17 17 0 0 1-3.2 4"
                    />
                    <path d="M9.9 9.9a3 3 0 0 0 4.2 4.2" /></svg
                ></span>
              </button>
            </div>
            <p
              data-error
              role="alert"
              class="mt-0.5 text-[11px] text-red-600 empty:hidden"
            ></p>
            <div class="mt-1.5" aria-live="polite">
              <div class="flex gap-1">
                <span data-bar></span><span data-bar></span
                ><span data-bar></span><span data-bar></span>
              </div>
              <p
                id="strength-label"
                class="mt-1 min-h-4 text-xs text-slate-500"
              ></p>
              <ul class="mt-1 grid gap-0.5 text-xs text-slate-400">
                <li
                  data-req="length"
                  class="transition-colors before:content-['○_'] data-[ok]:text-green-600 data-[ok]:before:content-['✓_']"
                >
                  At least 8 characters
                </li>
                <li
                  data-req="number"
                  class="transition-colors before:content-['○_'] data-[ok]:text-green-600 data-[ok]:before:content-['✓_']"
                >
                  Contains a number
                </li>
              </ul>
            </div>
          </div>
          <div data-field class="group/field mt-3">
            <label for="confirm-password" class="mb-0.5 block text-base"
              >Confirm New Password</label
            >
            <div class="group/pw relative">
              <input
                type="password"
                id="confirm-password"
                name="confirm-password"
                autocomplete="new-password"
                data-validate="match"
                data-match="#new-password"
                class="field-input pr-9"
              />
              <button
                type="button"
                data-toggle-password
                aria-label="Show password"
                aria-pressed="false"
                class="absolute top-1/2 right-1 grid size-7 -translate-y-1/2 cursor-pointer place-items-center rounded text-slate-500 transition-all duration-150 hover:scale-110 hover:text-indigo-500 focus-visible:outline-2 focus-visible:outline-indigo-500 active:scale-90"
              >
                <span class="group-data-[visible]/pw:hidden"
                  ><svg
                    width="18"
                    height="18"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.6"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true"
                  >
                    <path
                      d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"
                    />
                    <circle cx="12" cy="12" r="3" /></svg
                ></span>
                <span class="hidden group-data-[visible]/pw:block"
                  ><svg
                    width="18"
                    height="18"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.6"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true"
                  >
                    <path
                      d="M3 3l18 18M6.6 6.6A17 17 0 0 0 2 12s3.6 7 10 7a10 10 0 0 0 4-.8M10 5.1A10 10 0 0 1 12 5c6.4 0 10 7 10 7a17 17 0 0 1-3.2 4"
                    />
                    <path d="M9.9 9.9a3 3 0 0 0 4.2 4.2" /></svg
                ></span>
              </button>
            </div>
            <p
              data-error
              role="alert"
              class="mt-0.5 text-[11px] text-red-600 empty:hidden"
            ></p>
          </div>
        </section>
        <div class="mt-6 flex items-center justify-between">
          <button
            type="button"
            id="cancel-password"
            class="btn border-2 border-slate-800 bg-white px-[25px] py-2.5 text-base font-semibold text-slate-800 hover:bg-slate-800 hover:text-white"
          >
            Cancel
          </button>
          <button
            type="submit"
            class="btn btn-indigo px-6 py-2.5 text-base font-semibold"
          >
            Save Change
          </button>
        </div>
      </form>
    </main>

    <div
      id="toast"
      role="status"
      aria-live="polite"
      class="pointer-events-none fixed bottom-6 left-1/2 z-50 -translate-x-1/2 translate-y-4 rounded-lg bg-slate-900 px-4 py-2.5 text-sm text-white opacity-0 shadow-lg transition-all duration-300 data-[open]:translate-y-0 data-[open]:opacity-100"
    ></div>
    <script type="application/json" id="initial-profile">
        <?= json_encode(
            $profile,
            JSON_HEX_TAG |
            JSON_HEX_AMP |
            JSON_HEX_APOS |
            JSON_HEX_QUOT |
            JSON_INVALID_UTF8_SUBSTITUTE
        ) ?>
    </script>
  </body>
</html>
