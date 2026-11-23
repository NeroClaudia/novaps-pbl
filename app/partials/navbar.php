<?php 
     $currentPage = $currentPage ?? 'home';
     $showOrderButton = $showOrderButton ?? true;

     $isCustomerLoggedIn = ($_SESSION['auth']['role'] ?? null) === 'customer' 
                           && (int) ($_SESSION['auth']['user_id'] ?? 0) > 0;
?>

<!-- Navbar -->
<header class="h-[68px] bg-slate-900">
    <div class="wrap flex h-full items-center justify-between">
        <a
                href="/index.php"
                aria-label="NovaPS home"
                class="group flex items-center gap-2.5 font-display text-2xl font-bold text-white transition-transform duration-150 active:scale-95"
        >
          <span
                  class="grid size-[30px] place-items-center rounded-[7px] bg-cyan-400 transition-transform duration-300 ease-[cubic-bezier(.3,1.6,.5,1)] group-hover:-rotate-[8deg] group-hover:scale-110"
          >
            <svg
                    width="20"
                    height="20"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="#0f172a"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
            >
              <rect x="2" y="6" width="20" height="12" rx="3"/>
              <path d="M6 12h4M8 10v4"/>
              <circle cx="15.5" cy="11" r=".6"/>
              <circle cx="17.5" cy="13" r=".6"/>
            </svg>
          </span>
            Nova<span class="text-cyan-400">PS</span>
        </a>

        <!-- Not logged in -->
        <?php if (!$isCustomerLoggedIn): ?>
        <nav
                class="flex items-center gap-3 sm:gap-6"
                aria-label="Account"
        >
            <a
                    href="/pages/signup.php"
                    class="btn btn-outline rounded px-[18px] py-2 text-[15px] font-semibold"
            >Sign Up</a
            >
            <a
                    href="/pages/login.php"
                    class="btn btn-cyan rounded px-[18px] py-2 text-[15px] font-semibold"
            >Login</a
            >
        </nav>
        <?php else: ?>

        <!-- Logged in -->
        <nav
                class="flex items-center gap-1.5 sm:gap-[18px]"
                aria-label="Account"
        >
            <div class="group/tip relative">
                <a
                        href="/pages/time-remaining.php"
                        class="icon-btn aria-[current=page]:bg-tip"
                        aria-label="Time Remaining"
                        aria-describedby="tip-time"
                >
                    <svg
                            width="24"
                            height="24"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.6"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            aria-hidden="true"
                    >
                        <circle cx="12" cy="12" r="10"/>
                        <path d="M12 6v6l4 2"/>
                    </svg
                    >
                </a>
                <span id="tip-time" role="tooltip" class="tooltip"
                ><span class="block text-sm font-medium">Time Remaining</span
                ><span class="block text-xs text-sky-200"
                >See how much rental time is left</span
                ></span
                >
            </div>
            <div class="group/tip relative">
                <a
                        href="/pages/order-status.php"
                        class="icon-btn aria-[current=page]:bg-tip"
                        aria-label="Order Status"
                        aria-describedby="tip-orders"
                >
                    <svg
                            width="24"
                            height="24"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.6"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            aria-hidden="true"
                    >
                        <rect x="5" y="3" width="14" height="18" rx="2"/>
                        <path d="M9 3h6v3H9zM9 11h6M9 15h6"/>
                    </svg
                    >
                </a>
                <span id="tip-orders" role="tooltip" class="tooltip"
                ><span class="block text-sm font-medium">Order Status</span
                ><span class="block text-xs text-sky-200"
                >View your order history &amp; status</span
                ></span
                >
            </div>
            <div class="group group/tip relative">
                <button
                        type="button"
                        id="profileBtn"
                        class="icon-btn w-14 border-white aria-[current=page]:bg-tip"
                        aria-haspopup="true"
                        aria-expanded="false"
                        aria-label="My Account"
                        aria-describedby="tip-account"
                        <?= $currentPage === 'account' ? 'aria-current="page"' : '' ?>
                >
                    <svg
                            width="24"
                            height="24"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.6"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            aria-hidden="true"
                    >
                        <circle cx="12" cy="8" r="4"/>
                        <path d="M4 21c0-4.4 3.6-7 8-7s8 2.6 8 7"/>
                    </svg>
                </button>
                <span
                        id="tip-account"
                        role="tooltip"
                        class="tooltip group-data-[open]:hidden"
                ><span class="block text-sm font-medium">My Account</span
                ><span class="block text-xs text-sky-200"
                >View your account details</span
                ></span
                >
                <div
                        class="invisible absolute right-0 top-[50px] z-10 min-w-[140px] -translate-y-1.5 rounded-lg bg-white p-1.5 opacity-0 shadow-xl transition-all duration-200 group-data-[open]:visible group-data-[open]:translate-y-0 group-data-[open]:opacity-100"
                >
                    <a
                            href="/pages/account.php"
                            class="block w-full rounded-md px-3 py-2 text-left text-[15px] font-medium text-slate-800 transition-colors hover:bg-slate-100"
                    >My Account</a
                    >
                    <button
                            type="button"
                            id="logoutBtn"
                            class="w-full cursor-pointer rounded-md px-3 py-2 text-left text-[15px] font-medium text-slate-800 transition-colors hover:bg-slate-100"
                    >
                        Logout
                    </button>
                </div>
            </div>
            <?php if ($showOrderButton): ?>
            <a
                    href="/pages/order-form.php"
                    data-order
                    class="btn btn-cyan px-3 py-[7px] text-[15px] sm:px-[18px] sm:text-lg"
            >Order Now</a>
            <?php endif; ?>
        </nav>
        <?php endif; ?>
    </div>
</header>