<?php 
declare(strict_types=1);

require_once __DIR__ . '/../../app/includes/bootstrap.php';
require_once __DIR__ . '/../../app/functions/customers.php';
require_once __DIR__ . '/../../app/functions/devices.php';
require_once __DIR__ . '/../../app/functions/bookings.php';

$auth = require_customer_page();

header('Cache-Control: private, no-store');

$currentPage = 'order-form';
$showOrderButton = false;

try {
    expire_unpaid_bookings($pdo);

    $unpaidBooking = get_unpaid_booking($pdo, (int) $auth['user_id']);

    if ($unpaidBooking !== null) {
      header('Location: /pages/payment.php?order=' . rawurldecode($unpaidBooking['booking_code']));
      exit;
    }

    $profile = get_customer_profile($pdo, (int) $auth['user_id']);

    if ($profile === null) {
        unset($_SESSION['auth']);
        header('Location: /pages/login.php');
        exit;
    }

    $devices = get_devices($pdo);

} catch (PDOException $e) {
    error_log('Order Form Error: ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to load order form. Please try again later');
}

$prices = [
    'ps4' => null,
    'ps5' => null,
];

foreach ($devices as $device) {
    $category = strtolower($device['category']);
    $price = $device['price_per_day'];

    if (!array_key_exists($category, $prices)) {
        continue;
    }

    if ($prices[$category] === null || $price < $prices[$category]) {
        $prices[$category] = $price;
    }
}
?>

<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Order Form - NovaPS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
      href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Poppins:wght@600;700&display=swap"
      rel="stylesheet"
    />
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <link rel="stylesheet" href="/assets/css/order-form.css" />
    <script
      src="/assets/js/shared/session.js"
      data-require-login="/pages/login.php"
    ></script>
    <script src="/assets/js/shared/navbar.js" defer></script>
    <script src="/assets/js/shared/validation.js" defer></script>
    <script src="/assets/js/shared/image.js" defer></script>
    <script src="/assets/js/shared/orders.js" defer></script>
    <script src="/assets/js/shared/rental-alerts.js" defer></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" defer></script>
    <script src="/assets/js/delivery-map.js?v=1" defer></script>
    <script src="/assets/js/order-form.js?v=11" defer></script>
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
        Order Form
      </h1>

      <form id="order-form" method="post" novalidate>
        <section class="rounded-lg border border-slate-200 bg-white p-6">
          <h2 class="font-display text-base font-bold text-black mb-3">
            Customer Information
          </h2>
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
              class="field-input h-10"
              readonly
              value="<?= htmlspecialchars($profile['full_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
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
              class="field-input h-10"
              readonly
              value="<?= htmlspecialchars($profile['phone'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
            />
            <p
              data-error
              role="alert"
              class="mt-0.5 text-[11px] text-red-600 empty:hidden"
            ></p>
          </div>
          <div data-field class="group/field mb-2.5">
            <label for="address" class="mb-0.5 block text-base">Delivery Address / Landmark</label>
            <input
              type="text"
              id="address"
              name="address"
              autocomplete="street-address"
              class="field-input h-10"
              value="<?= htmlspecialchars($profile['address'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
            />
            <p
              data-error
              role="alert"
              class="mt-0.5 text-[11px] text-red-600 empty:hidden"
            ></p>
          </div>

          <!-- Delivery Location -->
           <div class="mb-4">
            <h3 class="mb-2 text-base font-semibold">
              Delivery Location
            </h3>
            <p class="mb-3 text-sm text-slate-500">
                Pilih titik pengiriman pada peta.
                Klik peta untuk memindahkan pin, atau gunakan GPS.
                Alamat dan patokan tetap dapat diisi secara manual.
            </p>

            <div id="delivery-map" class="w-full rounded-lg border-slate-200" aria-label="Peta lokasi pengiriman"></div>
            <div class="mt-3 flex flex-wrap gap-2">
              <button type="button" id="use-current-location" class="btn btn-indigo px-4 py-2 text-sm">Gunakan lokasi saat ini</button>
              <button type="button" id="clear-delivery-pin" class="btn border border-slate-300 px-4 py-2 text-sm">Hapus pin</button>
            </div>

            <p id="delivery-location-status" role="status" aria-live="polite" class="mt-2 text-sm text-slate-600">Belum ada titik pengiriman yang dipilih</p>
            <p id="delivery-coordinates" class="mt-1 text-xs text-slate-500"></p>

            <input type="hidden" id="delivery-latitude" name="delivery_latitude">
            <input type="hidden" id="delivery-longitude" name="delivery_longitude">

            <p class="mt-2 text-xs text-slate-500">
              Pastikan pin sesuai tujuan pengiriman.
              Alamat di My Profile tidak akan berubah
            </p>
           </div>

          <div data-field class="group/field mb-2.5">
            <label for="email" class="mb-0.5 block text-base">Email</label>
            <input
              type="email"
              id="email"
              name="email"
              autocomplete="email"
              data-validate="email"
              class="field-input h-10"
              readonly
              value="<?= htmlspecialchars($profile['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
            />
            <p
              data-error
              role="alert"
              class="mt-0.5 text-[11px] text-red-600 empty:hidden"
            ></p>
          </div>
          <div data-field class="group/field mb-1">
            <p class="mb-0.5 block text-base">
              ID Card Photo (From My Account)
            </p>
            <label
              for="idcard"
              data-upload
              class="group/upload relative grid h-16 cursor-pointer place-items-center overflow-hidden rounded-lg border-[1.5px] border-dashed border-slate-300 bg-slate-100 text-slate-400 transition-all duration-150 hover:border-indigo-500 hover:bg-indigo-50 hover:text-indigo-500 focus-within:ring-[3px] focus-within:ring-indigo-500/20 has-[:disabled]:cursor-default has-[:disabled]:hover:border-slate-300 has-[:disabled]:hover:bg-slate-100 has-[:disabled]:hover:text-slate-400 group-data-[invalid]/field:border-red-600 data-[has-image]:h-40"
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
                alt="Your uploaded ID Card"
                class="hidden size-full object-contain group-data-[has-image]/upload:block"
              />
              <input
                type="file"
                id="idcard"
                name="idcard"
                accept="image/*"
                data-validate="file"
                class="absolute inset-0 cursor-pointer opacity-0 disabled:cursor-default"
              />
            </label>
            <p
              data-error
              role="alert"
              class="mt-0.5 text-[11px] text-red-600 empty:hidden"
            ></p>
          </div>
          <p id="customer-hint" class="mt-3 text-xs text-slate-500">
            Filled in from your account. To change it, go to
            <a
              href="account.php"
              class="text-blue-600 underline underline-offset-2 hover:text-blue-700"
              >My Profile</a
            >.
          </p>
        </section>

        <section class="mt-6 rounded-lg border border-slate-200 bg-white p-6">
          <h2 class="font-display text-base font-bold text-black mb-3">
            Rental Details
          </h2>

          <p class="mb-5 text-sm text-slate-500">
            Waktu mulai penyewaan akan dihitung otomatis saat Checkout.
            Seluruh waktu menggunakan WIB.
          </p>

          <div id="units"></div>

          <div id="add-unit-wrap" class="mt-3">
            <p class="mb-0.5 text-base">Order unit #2</p>
            <button
              type="button"
              id="add-unit"
              class="flex w-full cursor-pointer flex-col items-center gap-1 rounded-lg border border-dashed border-slate-300 py-3 text-slate-400 transition-all duration-150 hover:-translate-y-0.5 hover:border-indigo-500 hover:bg-indigo-50 hover:text-indigo-500 active:scale-[.98]"
            >
              <svg
                width="32"
                height="32"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.6"
                stroke-linecap="round"
                stroke-linejoin="round"
                aria-hidden="true"
                class="stroke-1"
              >
                <path d="M12 4v16M4 12h16" />
              </svg>
              <span class="text-[10px]">Add Unit #2</span>
            </button>
          </div>
          <div class="mt-3">
            <label for="notes" class="mb-0.5 block text-base"
              >Additional Notes</label
            >
            <textarea
              id="notes"
              name="notes"
              rows="2"
              maxlength="300"
              placeholder="Optional"
              class="field-input h-auto resize-none py-2"
            ></textarea>
          </div>
        </section>

        <section
          id="summary"
          hidden
          class="mt-6 rounded-lg border border-slate-200 bg-white p-6"
        >
          <h2 class="font-display text-base font-bold text-black mb-3">
            Order Summary
          </h2>
          <div id="summary-rows"></div>
        </section>

        <p
          id="form-error"
          role="alert"
          class="mt-4 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700 empty:hidden"
        ></p>
        <div class="mt-6 flex items-center justify-between">
          <a
            href="/index.php"
            class="btn border-2 border-slate-800 bg-white px-[25px] py-2.5 text-base font-semibold text-slate-800 hover:bg-slate-800 hover:text-white"
            >Back to home</a
          >
          <button
            type="submit"
            id="checkout"
            disabled
            class="btn btn-indigo px-6 py-2.5 text-base font-semibold"
          >
            Checkout
          </button>
        </div>
        <p
          id="checkout-hint"
          aria-live="polite"
          class="mt-2 text-right text-xs text-slate-500"
        ></p>
      </form>

      <!-- One unit block (cloned by js/order-form.js) -->
      <template id="unit-template">
        <div data-unit-block class="mb-4">
          <div class="mb-0.5 flex items-center justify-between">
            <span data-unit-title class="text-base">Select a Unit</span>
            <button
              type="button"
              data-remove-unit
              hidden
              aria-label="Remove this unit"
              class="grid size-8 cursor-pointer place-items-center rounded-md text-slate-700 transition-all duration-150 hover:scale-110 hover:text-red-600 active:scale-90"
            >
              <svg
                width="22"
                height="22"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.6"
                stroke-linecap="round"
                stroke-linejoin="round"
                aria-hidden="true"
              >
                <path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14M10 11v5M14 11v5" />
              </svg>
            </button>
          </div>
          <div role="radiogroup" class="grid gap-2">
            <label
              class="flex cursor-pointer items-center justify-between gap-3 rounded-lg border border-slate-300 bg-white px-4 py-4 text-base transition-all duration-150 hover:border-indigo-300 hover:shadow-sm active:scale-[.99] has-[:checked]:border-indigo-500 has-[:checked]:bg-indigo-50 has-[:disabled]:cursor-not-allowed has-[:disabled]:opacity-50 has-[:disabled]:hover:border-slate-300 has-[:disabled]:hover:shadow-none has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-indigo-500/25"
            >
              <input type="radio" value="ps4" class="sr-only" />
            <span>PS 4<span class="mx-2 text-slate-300">|</span>
                <span data-price="ps4"><?= $prices['ps4'] !== null ? 'Rp' . number_format($prices['ps4'], 0, ',', '.') . '/day' : '— /day' ?>
                </span>
            </span>
              <span data-available class="shrink-0 text-slate-800"></span>
            </label>
            <label
              class="flex cursor-pointer items-center justify-between gap-3 rounded-lg border border-slate-300 bg-white px-4 py-4 text-base transition-all duration-150 hover:border-indigo-300 hover:shadow-sm active:scale-[.99] has-[:checked]:border-indigo-500 has-[:checked]:bg-indigo-50 has-[:disabled]:cursor-not-allowed has-[:disabled]:opacity-50 has-[:disabled]:hover:border-slate-300 has-[:disabled]:hover:shadow-none has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-indigo-500/25"
            >
              <input type="radio" value="ps5" class="sr-only" />
            <span>PS 5<span class="mx-2 text-slate-300">|</span>
                <span data-price="ps5"><?= $prices['ps5'] !== null ? 'Rp' . number_format($prices['ps5'], 0, ',', '.') . '/day' : '— /day' ?></span>
            </span>
              <span data-available class="shrink-0 text-slate-800"></span>
            </label>
          </div>
          <div class="mt-4">
            <label class="mb-0.5 block text-base">Rental duration</label>
            <select
              data-duration
              disabled
              aria-label="Rental duration"
              class="field-input h-12"
            >
              <option value="">-- day</option>
              <option value="1">1 day</option>
              <option value="2">2 days</option>
              <option value="3">3 days</option>
              <option value="4">4 days</option>
              <option value="5">5 days</option>
              <option value="6">6 days</option>
              <option value="7">7 days</option>
            </select>
            <p class="mt-0.5 text-[10px] text-slate-400">Max. 7 days</p>
          </div>
          <p
            data-block-error
            role="alert"
            class="mt-1 text-[11px] text-red-600 empty:hidden"
          ></p>
        </div>
      </template>
    </main>
    <script type="application/json" id="initial-order-data">
        <?= json_encode(
            [
                'customer' => $profile,
                'prices' => $prices,
            ],
            JSON_HEX_TAG |
            JSON_HEX_AMP |
            JSON_HEX_APOS |
            JSON_HEX_QUOT |
            JSON_INVALID_UTF8_SUBSTITUTE
        ) ?>
    </script>
  </body>
</html>

