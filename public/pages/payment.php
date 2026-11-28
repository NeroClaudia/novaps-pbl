<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/includes/bootstrap.php';
require_once __DIR__ . '/../../app/functions/bookings.php';

$auth = require_customer_page();

header('Cache-Control: private, no-store');

$currentPage = 'payment';
$showOrderButton = false;

$rawCode = $_GET['order'] ?? null;

$bookingCode = is_string($rawCode)
    ? strtoupper(trim($rawCode))
    : '';

if (!preg_match('/^[A-Z0-9]{8}$/', $bookingCode)) {
    http_response_code(400);
    exit('Invalid booking code.');
}

try {
    expire_unpaid_bookings($pdo);

    $booking = get_customer_booking(
        $pdo,
        (int) $auth['user_id'],
        $bookingCode
    );

    if ($booking === null) {
        http_response_code(404);
        exit('Booking not found.');
    }

} catch (Throwable $e) {
    error_log('Payment Page Error: ' . $e->getMessage());

    http_response_code(500);
    exit('Unable to load payment information.');
}

if ($booking['payment']['status'] !== 'Pending') {
    header('Location: /pages/order-detail.php?order=' . rawurldecode($bookingCode));
    exit;
}

$isPayable =
    $booking['payment']['status'] === 'Pending'
    && $booking['payment_method'] === 'QRIS'
    && $booking['payment']['seconds_left'] !== null
    && $booking['payment']['seconds_left'] > 0;

function payment_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function payment_money(float $amount): string
{
    return 'Rp' . number_format($amount, 0, ',', '.');
}

?>

<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Payment Confirmation - NovaPS</title>
    <link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Poppins:wght@600;700&display=swap" rel="stylesheet"/>
    <link rel="stylesheet" href="/assets/css/payment.css" />
    <script src="/assets/js/shared/session.js" data-require-login="/pages/login.php"></script>

    <?php require __DIR__ . '/../../app/partials/popup-assets.php'; ?>
    
    <script src="/assets/js/shared/navbar.js" defer></script>
    <script src="/assets/js/payment.js?v=3" defer></script>
  </head>
  <body
    class="min-h-screen bg-slate-50 text-slate-800"
    data-logout-redirect="/index.php"
  >
    <?php require __DIR__ . '/../../app/partials/navbar.php'; ?>

    <main class="mx-auto w-[896px] max-w-[calc(100%-2rem)] pt-5 pb-16">
      <a
        href="/index.php"
        class="inline-block text-2xl text-blue-600 underline underline-offset-4 transition-all duration-150 hover:-translate-x-1 hover:text-blue-700"
        >&larr; Back to Home</a
      >
      <h1
        class="my-6 text-center font-display text-[32px] font-bold text-black"
      >
        Payment Confirmation
      </h1>

      <div class="grid items-start gap-6 md:grid-cols-[412px_1fr]">
        <section
          class="rounded-lg border border-slate-200 bg-white p-6 text-center"
        >
          <h2 class="font-display text-base font-bold text-black">Scan Qris</h2>
          <p
            id="countdown"
            aria-live="off"
            class="mt-5 font-display text-5xl font-bold text-black transition-colors duration-300"
          >
            <?php 
                $seconds = $booking['payment']['seconds_left'] ?? 0;
                echo $booking['payment']['expires_at'] === null 
                    ? '--;--' 
                    : sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60);
            ?>
          </p>
          <p id="payment-status" class="mt-2 text-sm text-slate-500">
            <?= payment_escape($booking['payment']['status']) ?>
          </p>
          <p class="mt-1 text-[11px] text-slate-400">
            Please pay before time runs out
          </p>

          <?php if ($isPayable): ?>
          <img
            src="/assets/img/qris.png"
            alt="QRIS payment code"
            width="284"
            height="480"
            class="mx-auto mt-6 w-[285px] max-w-full"
          />
          <p
            id="amount"
            class="mt-4 font-display text-2xl font-bold text-black"
          > <?= payment_money($booking['total_cost']) ?></p>
          <p class="mx-auto mt-1 max-w-[260px] text-[11px] text-slate-400">
            Open your e-wallet or banking app, scan the code, and then pay the
            total amount.
          </p>
          <?php endif; ?>

          <div data-field class="group/field mt-5 text-left">
            <label for="proof" class="mb-0.5 block text-base"
              >Proof of Payment</label
            >
            <label
              for="proof"
              data-upload
              class="group/upload relative grid h-24 cursor-pointer place-items-center overflow-hidden rounded-lg border-[1.5px] border-dashed border-slate-300 bg-white text-slate-300 transition-all duration-150 hover:border-indigo-500 hover:bg-indigo-50 hover:text-indigo-500 focus-within:ring-[3px] focus-within:ring-indigo-500/20 group-data-[invalid]/field:border-red-600 data-[has-image]:h-64"
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
                alt="Proof of payment preview"
                class="hidden size-full object-contain group-data-[has-image]/upload:block"
              />
              <input
                type="file"
                id="proof"
                name="payment_proof"
                accept="image/jpeg,image/png"
                <?= !$isPayable ? 'disabled' : '' ?>
                class="absolute inset-0 cursor-pointer opacity-0"
              />
            </label>
            <p class="mt-0.5 text-[10px] text-slate-400">
              Upload JPG or PNG, maximum 2 MB.
            </p>
            <p
              data-error
              role="alert"
              class="mt-0.5 text-[11px] text-red-600 empty:hidden"
            ></p>
          </div>
        </section>

        <section class="rounded-lg border border-slate-200 bg-white p-6">
          <h2 class="font-display text-base font-bold text-black mb-3">
            Order Summary
          </h2>
            <div id="summary-rows">

                <div class="flex justify-between gap-4 py-0.5 text-base">
                    <span>Booking Code</span>
                    <span class="text-right">
                        <?= payment_escape($booking['booking_code']) ?>
                    </span>
                </div>

                <div class="flex justify-between gap-4 py-0.5 text-base">
                    <span>Customer</span>
                    <span class="text-right break-words">
                        <?= payment_escape($booking['customer']['full_name']) ?>
                    </span>
                </div>

                <div class="flex justify-between gap-4 py-0.5 text-base">
                    <span>Address</span>
                    <span class="text-right break-words">
                        <?= payment_escape($booking['customer']['address']) ?>
                    </span>
                </div>

                <?php foreach ($booking['items'] as $index => $item): ?>

                    <div class="my-1.5 border-t border-slate-300"></div>

                    <div class="flex justify-between gap-4 py-0.5 text-base">
                        <span>Unit #<?= $index + 1 ?></span>
                        <span class="text-right">
                            <?= payment_escape($item['category']) ?>
                        </span>
                    </div>

                    <div class="flex justify-between gap-4 py-0.5 text-base">
                        <span>Duration</span>
                        <span>
                            <?= $item['duration_days'] ?>
                            <?= $item['duration_days'] === 1 ? 'day' : 'days' ?>
                        </span>
                    </div>

                    <div class="flex justify-between gap-4 py-0.5 text-base">
                        <span>Price</span>
                        <span>
                            <?= payment_money($item['total_cost']) ?>
                        </span>
                    </div>

                <?php endforeach; ?>

                <div class="my-1.5 border-t border-slate-300"></div>

                <div class="flex justify-between gap-4 pt-1.5 font-display font-bold text-black">
                    <span>Total</span>
                    <span>
                        <?= payment_money($booking['total_cost']) ?>
                    </span>
                </div>

            </div>

        </section>
      </div>
      <div class="mt-6 flex items-center justify-between">
        <button
          type="button"
          id="cancel-btn"
          class="btn border-2 border-red-600 bg-white px-[25px] py-2.5 text-base font-semibold text-red-600 hover:bg-red-600 hover:text-white"
        >
          Cancel Order
        </button>
        <button
          type="button"
          id="send-btn"
          disabled
          class="btn btn-indigo px-6 py-2.5 text-base font-semibold"
        >
          Send payment
        </button>
      </div>
    </main>

    <!-- Cancel reason pop-up -->
    <div
      id="cancelModal"
      role="dialog"
      aria-modal="true"
      aria-labelledby="cancelTitle"
      aria-hidden="true"
      class="group invisible fixed inset-0 z-50 grid place-items-center bg-slate-950/60 p-6 opacity-0 transition-all duration-200 data-[open]:visible data-[open]:opacity-100"
    >
      <div
        class="w-full max-w-[420px] translate-y-4 scale-95 rounded-xl bg-white px-7 pb-7 pt-8 transition-transform duration-300 ease-[cubic-bezier(.2,.9,.3,1.2)] group-data-[open]:translate-y-0 group-data-[open]:scale-100"
      >
        <h2 id="cancelTitle" class="font-display text-2xl font-bold">
          Cancel this order?
        </h2>
        <p class="mt-1 text-slate-500">
          Please tell us why you want to cancel. The unit will go back to stock.
        </p>
        <div class="mt-4 flex flex-wrap gap-2">
          <button
            type="button"
            data-reason="Changed my mind"
            class="cursor-pointer rounded-full border border-slate-300 px-3 py-1 text-xs text-slate-600 transition-all duration-150 hover:-translate-y-0.5 hover:border-indigo-500 hover:text-indigo-600 active:scale-95"
          >
            Changed my mind
          </button>
          <button
            type="button"
            data-reason="Wrong unit or duration"
            class="cursor-pointer rounded-full border border-slate-300 px-3 py-1 text-xs text-slate-600 transition-all duration-150 hover:-translate-y-0.5 hover:border-indigo-500 hover:text-indigo-600 active:scale-95"
          >
            Wrong unit or duration
          </button>
          <button
            type="button"
            data-reason="Payment problem"
            class="cursor-pointer rounded-full border border-slate-300 px-3 py-1 text-xs text-slate-600 transition-all duration-150 hover:-translate-y-0.5 hover:border-indigo-500 hover:text-indigo-600 active:scale-95"
          >
            Payment problem
          </button>
        </div>
        <div data-field class="group/field mt-3">
          <label for="reason" class="mb-0.5 block text-base">Reason</label>
          <textarea
            id="reason"
            rows="3"
            maxlength="200"
            placeholder="Write your reason here"
            class="field-input h-auto resize-none py-2"
          ></textarea>
          <p
            data-error
            role="alert"
            class="mt-0.5 text-[11px] text-red-600 empty:hidden"
          ></p>
        </div>
        <div class="mt-5 flex gap-3">
          <button
            type="button"
            data-close
            class="btn btn-ghost flex-1 px-0 py-[11px] text-base"
          >
            Keep order
          </button>
          <button
            type="button"
            id="confirm-cancel"
            class="btn flex-1 bg-red-600 px-0 py-[11px] text-base text-white"
          >
            Cancel order
          </button>
        </div>
      </div>
    </div>
    <script type="application/json" id="initial-payment-data">
        <?= json_encode(
            [
                'booking_code' => $booking['booking_code'],
                'payment' => $booking['payment'],
                'is_payable' => $isPayable
            ],
            JSON_HEX_TAG |
            JSON_HEX_APOS |
            JSON_HEX_APOS |
            JSON_HEX_QUOT |
            JSON_INVALID_UTF8_SUBSTITUTE
        ) ?>
    </script>
  </body>
</html>

