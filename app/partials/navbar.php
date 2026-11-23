<?php 
     $currentPage = $currentPage ?? 'home';
     $showOrderButton = $showOrderButton ?? true;
     $isCustomerLoggedIn = ($_SESSION['auth']['role'] ?? null) === 'customer'  && (int) ($_SESSION['auth']['user_id'] ?? 0) > 0;

     $hasOverdueRental = false;

     if ($isCustomerLoggedIn) {
        $hasOverdueRental = customer_has_overdue_rental($pdo, (int) $_SESSION['auth']['user_id']);
     }

     $orderBlockedMessage = 'You have an overdue rental. Please return your ' . 'PlayStation to NovaPS before placing a new order.';
?>

<!-- Navbar -->
<?php   $orderBlockedFlash = ($_SESSION['order_blocked_flash'] ?? false) == true; 
        unset($_SESSION['order_blocked_flash']);
?>
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

<?php if ($hasOverdueRental): ?>
    <div
        id="overdue-warning"
        role="alert"
        style="
            background:#b91c1c;
            color:#fff;
            padding:14px 20px;
        "
    >
        <div
            class="wrap"
            style="
                display:flex;
                align-items:flex-start;
                gap:12px;
            "
        >
            <span aria-hidden="true">⚠</span>

            <div>
                <strong>
                    You have an overdue rental!
                </strong>

                <p style="margin:4px 0 8px">
                    Please return your PlayStation
                    directly to NovaPS.
                    New orders are temporarily restricted.
                </p>

                <a
                    href="/pages/time-remaining.php"
                    style="
                        color:#fff;
                        font-weight:600;
                        text-decoration:underline;
                    "
                >
                    View Time Remaining
                </a>
            </div>
        </div>
    </div>
<?php endif; ?>


<?php if ($isCustomerLoggedIn): ?>
<style>
    .overdue-modal {
        position: fixed;
        inset: 0;
        z-index: 9999;
        display: grid;
        place-items: center;
        padding: 24px;
        background: rgba(2, 6, 23, 0.65);
        opacity: 0;
        visibility: hidden;
        transition: opacity .2s, visibility .2s;
    }

    .overdue-modal[data-open] {
        opacity: 1;
        visibility: visible;
    }

    .overdue-modal-card {
        width: 100%;
        max-width: 380px;
        border-radius: 14px;
        background: white;
        padding: 32px 28px 28px;
        text-align: center;
        box-shadow: 0 20px 60px rgba(0,0,0,.25);
        transform: translateY(16px) scale(.95);
        transition: transform .25s;
    }

    .overdue-modal[data-open] .overdue-modal-card {
        transform: translateY(0) scale(1);
    }

    .overdue-modal-icon {
        width: 56px;
        height: 56px;
        margin: 0 auto 14px;
        display: grid;
        place-items: center;
        border-radius: 50%;
        background: #fee2e2;
        color: #dc2626;
    }

    .overdue-modal-title {
        font-family: Poppins, sans-serif;
        font-size: 24px;
        font-weight: 700;
        color: #0f172a;
    }

    .overdue-modal-description {
        margin: 8px 0 24px;
        color: #64748b;
        font-size: 15px;
        line-height: 1.6;
    }

    .overdue-modal-actions {
        display: flex;
        gap: 12px;
    }

    .overdue-modal-actions > * {
        flex: 1;
        padding: 12px 8px;
        border-radius: 8px;
        text-align: center;
        font-size: 15px;
        font-weight: 600;
        cursor: pointer;
        text-decoration: none;
    }

    .overdue-modal-cancel {
        background: #f1f5f9;
        color: #334155;
        border: 1px solid #e2e8f0;
    }

    .overdue-modal-primary {
        background: #4f46e5;
        color: white;
        border: 1px solid #4f46e5;
    }

    .overdue-modal-primary:hover {
        background: #4338ca;
    }
</style>

<div
    id="overdue-order-modal"
    class="overdue-modal"
    role="dialog"
    aria-modal="true"
    aria-labelledby="overdue-modal-title"
    aria-describedby="overdue-modal-description"
    aria-hidden="true"
>
    <div class="overdue-modal-card">
        <div class="overdue-modal-icon">
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
                <path d="M12 3 2 21h20L12 3Z"/>
                <path d="M12 9v5"/>
                <path d="M12 17h.01"/>
            </svg>
        </div>

        <h2
            id="overdue-modal-title"
            class="overdue-modal-title"
        >
            Rental Overdue
        </h2>

        <p
            id="overdue-modal-description"
            class="overdue-modal-description"
        >
            You have an overdue rental.
            Please return your PlayStation
            to NovaPS before placing a new order.
        </p>

        <div class="overdue-modal-actions">
            <button
                type="button"
                id="overdue-modal-cancel"
                class="overdue-modal-cancel"
            >
                Cancel
            </button>

            <a
                href="/pages/time-remaining.php"
                class="overdue-modal-primary"
            >
                Time Remaining
            </a>
        </div>
    </div>
</div>

<script>
(() => {
    "use strict";

    const blocked = <?= $hasOverdueRental ? 'true' : 'false' ?>;
    const showFlash = <?= $orderBlockedFlash ? 'true' : 'false' ?>;

    const modal = document.getElementById(
        "overdue-order-modal"
    );

    const cancelButton = document.getElementById(
        "overdue-modal-cancel"
    );

    let previousFocus = null;

    function openModal() {
        if (!modal || modal.hasAttribute("data-open")) {
            return;
        }

        previousFocus = document.activeElement;

        modal.setAttribute("data-open", "");
        modal.setAttribute("aria-hidden", "false");

        document.body.style.overflow = "hidden";
        cancelButton.focus();
    }

    function closeModal() {
        if (!modal) return;

        modal.removeAttribute("data-open");
        modal.setAttribute("aria-hidden", "true");

        document.body.style.overflow = "";
        previousFocus?.focus();
    }

    cancelButton?.addEventListener(
        "click",
        closeModal
    );

    modal?.addEventListener("click", event => {
        if (event.target === modal) {
            closeModal();
        }
    });

    document.addEventListener("keydown", event => {
        if (!modal?.hasAttribute("data-open")) {
            return;
        }

        if (event.key === "Escape") {
            event.preventDefault();
            closeModal();
        }

        if (event.key === "Tab") {
            const primary = modal.querySelector(
                ".overdue-modal-primary"
            );

            if (
                event.shiftKey &&
                document.activeElement === cancelButton
            ) {
                event.preventDefault();
                primary.focus();
            } else if (
                !event.shiftKey &&
                document.activeElement === primary
            ) {
                event.preventDefault();
                cancelButton.focus();
            }
        }
    });

    if (blocked) {
        document.addEventListener("click", event => {
            const link = event.target.closest("a[href]");
            if (!link) return;

            const url = new URL(link.href, location.href);

            if (
                url.origin === location.origin &&
                url.pathname === "/pages/order-form.php"
            ) {
                event.preventDefault();
                event.stopImmediatePropagation();
                openModal();
            }
        }, true);
    }

    if (showFlash) {
        if (document.readyState === "loading") {
            document.addEventListener(
                "DOMContentLoaded",
                openModal,
                { once: true }
            );
        } else {
            openModal();
        }
    }
})();
</script>
<?php endif; ?>
