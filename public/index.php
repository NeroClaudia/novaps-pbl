<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/includes/bootstrap.php';
require_once __DIR__ . '/../app/functions/devices.php';

$devices = [];
$unitsMessage = '';
$deviceLoadFailed = false;

try {
    $devices = get_devices($pdo);

    if (empty($devices)) {
        $unitsMessage = 'No units are listed yet';
    }
} catch (PDOException $e) {
    error_log('Homepage device error' . $e->getMessage());
    $deviceLoadFailed = true;
    $unitsMessage = 'Unable to load units. Please try again shortly';
}

$unitSummary = [];

foreach(['PS4', 'PS5'] as $category) {
    $units = array_filter(
        $devices,
        fn(array $device): bool => $device['category'] === $category);

    $availableUnits = array_filter(
        $units,
        fn(array $device): bool => $device['status'] === 'Available' && !$device['is_rented']);

    $prices = array_column($units, 'price_per_day');

    $priceLabel = '—';

    if (!empty($prices)) {
        $lowestPrice = min($prices);
        $differentPrices = min($prices) !== max($prices);

        $priceLabel = ($differentPrices ? 'From ' : '')
            . 'Rp'
            . number_format($lowestPrice, 0, ',', '.');
    }

    $unitSummary[$category] = [
        'stock' => count($availableUnits),
        'stock_label' => $deviceLoadFailed ? 'Unable to load' : 'Available now: ' . count($availableUnits),
        'price_label' => $priceLabel,
    ];
}
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <title>NovaPS - PlayStation Rental</title>
    <link rel="preconnect" href="https://fonts.googleapis.com"/>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
    <link
            href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Poppins:wght@600;700&display=swap"
            rel="stylesheet"
    />
    <link rel="stylesheet" href="/assets/css/index.css"/>
    <script src="/assets/js/shared/session.js"></script>
    <script
            src="/assets/js/shared/api.js"
            data-api-base="/api"
            defer
    ></script>
    <script src="/assets/js/shared/navbar.js?v=2" defer></script>
    <script src="/assets/js/shared/orders.js" defer></script>
    <script src="/assets/js/shared/rental-alerts.js" defer></script>
    <script src="/assets/js/index.js?v=2" defer></script>
</head>
<body class="bg-white text-slate-800">

<?php require __DIR__ . '/../app/partials/navbar.php'; ?>

<!-- Hero -->
<section class="bg-indigo-950 pb-[106px] pt-28 text-white max-sm:py-[72px]">
    <div class="wrap">
        <h1
                class="mb-3.5 font-display text-5xl font-bold leading-[1.3] max-md:text-4xl"
        >
            Rent your PS now,<br/>And Enjoy the Game!
        </h1>
        <p class="mb-6 max-w-[720px] text-xl">
            NovaPS is a PlayStation rental shop that offers PS4 and PS5 consoles
            and has a large selection of games you can play with friends.
        </p>
        <div class="flex flex-wrap gap-6">
            <a href="pages/order-form.html" data-order class="btn btn-cyan"
            >Order Now</a
            >
            <a href="#steps" class="btn btn-outline">How to order</a>
        </div>
    </div>
</section>

<!-- Why choose us -->
<section class="bg-slate-800 pb-16 pt-[62px] text-white">
    <div class="wrap">
        <h2 class="mb-10 font-display text-[44px] font-bold max-md:text-4xl">
            Why choose us?
        </h2>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div
                    class="group min-h-[165px] rounded-lg bg-slate-700 px-4 py-8 text-center font-display text-xl font-semibold leading-snug transition-colors duration-200 hover:bg-slate-600"
            >
                <svg
                        class="mx-auto mb-4 transition-transform duration-300 group-hover:-translate-y-1 group-hover:scale-110"
                        width="36"
                        height="36"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="#fff"
                        stroke-width="1.2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                >
                    <circle cx="12" cy="12" r="10"/>
                    <path d="M12 6v6l4 2"/>
                </svg>
                Booking 24/7
            </div>
            <div
                    class="group min-h-[165px] rounded-lg bg-slate-700 px-4 py-8 text-center font-display text-xl font-semibold leading-snug transition-colors duration-200 hover:bg-slate-600"
            >
                <svg
                        class="mx-auto mb-4 transition-transform duration-300 group-hover:-translate-y-1 group-hover:scale-110"
                        width="36"
                        height="36"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="#fff"
                        stroke-width="1.2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                >
                    <path d="M12 2l3 3-3 3-3-3z"/>
                    <path d="M12 16l3 3-3 3-3-3z"/>
                    <path d="M2 12l3-3 3 3-3 3z"/>
                    <path d="M16 12l3-3 3 3-3 3z"/>
                </svg>
                A large collection of games
            </div>
            <div
                    class="group min-h-[165px] rounded-lg bg-slate-700 px-4 py-8 text-center font-display text-xl font-semibold leading-snug transition-colors duration-200 hover:bg-slate-600"
            >
                <svg
                        class="mx-auto mb-4 transition-transform duration-300 group-hover:-translate-y-1 group-hover:scale-110"
                        width="36"
                        height="36"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="#fff"
                        stroke-width="1.2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                >
                    <circle cx="5.5" cy="17.5" r="3.5"/>
                    <circle cx="18.5" cy="17.5" r="3.5"/>
                    <path d="M15 6h-3l-3.5 6.5M5.5 17.5 9 10h9l.5 7.5M15 6h2"/>
                </svg>
                Free Shipping
            </div>
            <div
                    class="group min-h-[165px] rounded-lg bg-slate-700 px-4 py-8 text-center font-display text-xl font-semibold leading-snug transition-colors duration-200 hover:bg-slate-600"
            >
                <svg
                        class="mx-auto mb-4 transition-transform duration-300 group-hover:-translate-y-1 group-hover:scale-110"
                        width="36"
                        height="36"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="#fff"
                        stroke-width="1.2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                >
                    <rect x="3" y="3" width="7" height="7" rx="1"/>
                    <rect x="14" y="3" width="7" height="7" rx="1"/>
                    <rect x="3" y="14" width="7" height="7" rx="1"/>
                    <path d="M14 14h2v2h-2zM19 14h2M14 19h2M18 18h3v3"/>
                </svg>
                Payment via QRIS
            </div>
        </div>
    </div>
</section>

<!-- Steps -->
<section id="steps" class="bg-white pb-[50px] pt-14">
    <div class="wrap">
        <h2 class="mb-10 font-display text-[44px] font-bold max-md:text-4xl">
            3 Easy Step Order
        </h2>
        <div
                class="flex flex-col items-center gap-4 px-2.5 md:flex-row md:items-start md:justify-between"
        >
            <div class="w-[180px] text-center font-display text-xl font-semibold">
                <div
                        class="mx-auto mb-2 grid size-16 place-items-center rounded-full bg-indigo-500 text-[32px] text-white"
                >
                    1
                </div>
                Select a Unit &amp; Schedule
            </div>
            <svg
                    class="mt-2 hidden shrink-0 text-slate-800 md:block"
                    width="124"
                    height="44"
                    viewBox="0 0 124 44"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.3"
                    aria-hidden="true"
            >
                <path d="M0 22h122M101 1l21 21-21 21"/>
            </svg>
            <svg
                    class="shrink-0 text-slate-800 md:hidden"
                    width="44"
                    height="56"
                    viewBox="0 0 44 56"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.3"
                    aria-hidden="true"
            >
                <path d="M22 0v54M1 33l21 21 21-21"/>
            </svg>
            <div class="w-[180px] text-center font-display text-xl font-semibold">
                <div
                        class="mx-auto mb-2 grid size-16 place-items-center rounded-full bg-indigo-500 text-[32px] text-white"
                >
                    2
                </div>
                Fill the Form &amp; Pay
            </div>
            <svg
                    class="mt-2 hidden shrink-0 text-slate-800 md:block"
                    width="124"
                    height="44"
                    viewBox="0 0 124 44"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.3"
                    aria-hidden="true"
            >
                <path d="M0 22h122M101 1l21 21-21 21"/>
            </svg>
            <svg
                    class="shrink-0 text-slate-800 md:hidden"
                    width="44"
                    height="56"
                    viewBox="0 0 44 56"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.3"
                    aria-hidden="true"
            >
                <path d="M22 0v54M1 33l21 21 21-21"/>
            </svg>
            <div class="w-[180px] text-center font-display text-xl font-semibold">
                <div
                        class="mx-auto mb-2 grid size-16 place-items-center rounded-full bg-indigo-500 text-[32px] text-white"
                >
                    3
                </div>
                The PS will be delivered
            </div>
        </div>
    </div>
</section>

<!-- Unit options (both cards stretch to the same height) -->
<section id="units" class="bg-slate-50 pb-8 pt-[38px]">
    <div class="wrap">
        <h2 class="text-center font-display text-5xl font-bold max-md:text-4xl">
            Unit Options &amp; Prices
        </h2>
        <p
                id="unitsMessage"
                role="status"
                aria-live="polite"
                class="mt-3 text-center text-sm text-slate-500"
        ><?= htmlspecialchars($unitsMessage) ?></p>
        <div
                class="mt-1 grid grid-cols-[minmax(0,340px)] items-stretch justify-center gap-7 md:grid-cols-[repeat(2,340px)]"
        >
            <!-- PS4 -->
            <article
                    class="flex flex-col rounded-[10px] border border-slate-200 bg-white p-6"
            >
                <div class="flex justify-between text-[15px]">
                    <strong class="font-semibold text-black">PS4</strong
                    ><span data-stock="ps4"><?= htmlspecialchars($unitSummary['PS4']['stock_label']) ?></span>
                </div>
                <p class="mb-[18px] mt-9 font-display text-[32px] font-bold text-black">
                    <span data-price="ps4"><?= htmlspecialchars($unitSummary['PS4']['price_label']) ?></span>
                    <small class="font-sans text-base font-normal text-slate-500">
                        /day
                    </small>
                </p>
                <ul class="grid gap-1 text-[15px]">
                    <li class="feature">1 PlayStation console + 2 controller</li>
                    <li class="feature">A large collection of games</li>
                    <li class="feature">HDMI Cable</li>
                </ul>
                <details
                        class="group/games mt-4 w-fit rounded-xl open:w-full open:bg-slate-200 open:px-1.5 open:pb-4 open:pt-1.5"
                >
                    <summary
                            class="cursor-pointer list-none rounded-md bg-slate-200 px-[18px] py-[3px] text-[15px] text-slate-500 transition-colors hover:bg-slate-300 group-open/games:bg-transparent group-open/games:p-0 group-open/games:pb-1.5 group-open/games:hover:bg-transparent [&::-webkit-details-marker]:hidden"
                    >
                        See all game
                    </summary>
                    <ol
                            class="list-decimal pl-[34px] text-[13px] leading-[1.55] text-slate-500"
                    >
                        <li>EA FC26 (FIFA 26)</li>
                        <li>Grand Theft Auto V (GTA V)</li>
                        <li>Naruto Shippuden Ultimate Ninja Storm 4</li>
                        <li>MotoGP 24</li>
                        <li>Mortal Kombat 11</li>
                        <li>Need For Speed Heat</li>
                        <li>Takken 7</li>
                        <li>Red Dead Redemption 2 (RDR 2)</li>
                    </ol>
                </details>
                <div class="mt-auto pt-6">
                    <a
                            href="pages/order-form.html?unit=ps4"
                            data-order
                            data-order-unit="ps4"
                            class="btn btn-indigo w-full py-3"
                    >Choose &amp; Rent</a
                    >
                </div>
            </article>

            <!-- PS5 -->
            <article
                    class="flex flex-col rounded-[10px] border border-indigo-500 bg-white p-6"
            >
                <div class="flex justify-between text-[15px]">
                    <strong class="font-semibold text-black">PS5</strong
                    ><span data-stock="ps5" class="text-yellow-600"><?= htmlspecialchars($unitSummary['PS5']['stock_label']) ?></span
                >
                </div>
                <p class="mb-[18px] mt-9 font-display text-[32px] font-bold text-black">
                    <span data-price="ps5"><?= htmlspecialchars($unitSummary['PS5']['price_label']) ?></span>
                    <small class="font-sans text-base font-normal text-slate-500">
                        /day
                    </small>
                </p>
                <ul class="grid gap-1 text-[15px]">
                    <li class="feature">1 PlayStation console + 2 controller</li>
                    <li class="feature">A large collection of games</li>
                    <li class="feature">HDMI Cable</li>
                    <li class="feature">
                        Best display quality &amp; new additional features
                    </li>
                </ul>
                <details
                        class="group/games mt-4 w-fit rounded-xl open:w-full open:bg-slate-200 open:px-1.5 open:pb-4 open:pt-1.5"
                >
                    <summary
                            class="cursor-pointer list-none rounded-md bg-slate-200 px-[18px] py-[3px] text-[15px] text-slate-500 transition-colors hover:bg-slate-300 group-open/games:bg-transparent group-open/games:p-0 group-open/games:pb-1.5 group-open/games:hover:bg-transparent [&::-webkit-details-marker]:hidden"
                    >
                        See all game
                    </summary>
                    <ol
                            class="list-decimal pl-[34px] text-[13px] leading-[1.55] text-slate-500"
                    >
                        <li>EA FC26 (FIFA 26)</li>
                        <li>Grand Theft Auto V (GTA V)</li>
                        <li>Minecraft</li>
                        <li>Need For Speed Unbound</li>
                    </ol>
                </details>
                <div class="mt-auto pt-6">
                    <a
                            href="pages/order-form.html?unit=ps5"
                            data-order
                            data-order-unit="ps5"
                            class="btn btn-indigo w-full py-3"
                    >Choose &amp; Rent</a
                    >
                </div>
            </article>
        </div>
    </div>
</section>

<!-- CTA -->
<section class="bg-indigo-500 py-16 text-center text-white">
    <div class="wrap">
        <h2 class="mb-[18px] font-display text-5xl font-bold max-md:text-4xl">
            Ready to play? Book Now!
        </h2>
        <a
                href="pages/order-form.html"
                data-order
                class="btn bg-white px-[25px] py-3.5 font-semibold text-indigo-500"
        >Order Now</a
        >
    </div>
</section>

<?php require __DIR__ . '/../app/partials/footer.php'; ?>

<!-- Login required pop-up -->
<div
        id="loginModal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="modalTitle"
        aria-hidden="true"
        class="group invisible fixed inset-0 z-50 grid place-items-center bg-slate-950/60 p-6 opacity-0 transition-all duration-200 data-[open]:visible data-[open]:opacity-100"
>
    <div
            class="w-full max-w-[380px] translate-y-4 scale-95 rounded-xl bg-white px-7 pb-7 pt-8 text-center transition-transform duration-300 ease-[cubic-bezier(.2,.9,.3,1.2)] group-data-[open]:translate-y-0 group-data-[open]:scale-100"
    >
        <div
                class="mx-auto mb-3.5 grid size-14 place-items-center rounded-full bg-indigo-100 text-indigo-500"
        >
            <svg
                    width="28"
                    height="28"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.8"
                    stroke-linecap="round"
                    stroke-linejoin="round"
            >
                <rect x="4" y="11" width="16" height="10" rx="2"/>
                <path d="M8 11V7a4 4 0 0 1 8 0v4"/>
            </svg>
        </div>
        <h2 id="modalTitle" class="mb-1.5 font-display text-2xl font-bold">
            Login required
        </h2>
        <p class="mb-[22px] text-slate-500">
            Please log in first before you place an order.
        </p>
        <div class="flex gap-3">
            <button
                    type="button"
                    data-close
                    class="btn btn-ghost flex-1 px-0 py-[11px] text-base"
            >
                Cancel
            </button>
            <a
                    href="/pages/login.php"
                    class="btn btn-indigo flex-1 px-0 py-[11px] text-base"
            >Login</a
            >
        </div>
    </div>
</div>

<!-- Unpaid order pop-up -->
<div
        id="unpaidModal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="unpaidTitle"
        aria-hidden="true"
        class="group invisible fixed inset-0 z-50 grid place-items-center bg-slate-950/60 p-6 opacity-0 transition-all duration-200 data-[open]:visible data-[open]:opacity-100"
>
    <div
            class="w-full max-w-[400px] translate-y-4 scale-95 rounded-xl bg-white px-7 pb-7 pt-8 text-center transition-transform duration-300 ease-[cubic-bezier(.2,.9,.3,1.2)] group-data-[open]:translate-y-0 group-data-[open]:scale-100"
    >
        <div
                class="mx-auto mb-3.5 grid size-14 place-items-center rounded-full bg-amber-100 text-amber-600"
        >
            <svg
                    width="28"
                    height="28"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.8"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true"
            >
                <circle cx="12" cy="12" r="10"/>
                <path d="M12 6v6l4 2"/>
            </svg>
        </div>
        <h2 id="unpaidTitle" class="mb-1.5 font-display text-2xl font-bold">
            Complete your payment
        </h2>
        <p class="text-slate-500">
            You still have an unpaid order. Pay before the time runs out,
            otherwise it is canceled automatically and the unit goes back to
            stock.
        </p>
        <p
                id="unpaidCountdown"
                class="mt-4 font-display text-5xl font-bold text-black"
        >
            05:00
        </p>
        <p id="unpaidInfo" class="mb-5 mt-1 text-sm text-slate-500"></p>
        <div class="flex gap-3">
            <button
                    type="button"
                    data-close
                    class="btn btn-ghost flex-1 px-0 py-[11px] text-base"
            >
                Later
            </button>
            <a
                    id="unpaidPay"
                    href="pages/payment.html"
                    class="btn btn-indigo flex-1 px-0 py-[11px] text-base"
            >Pay Now</a
            >
        </div>
    </div>
</div>
</body>
</html>
