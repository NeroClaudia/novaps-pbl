
/*
 * NovaPS - Order Status
 * PHP SSR + AJAX cancellation
 */

(() => {
    const $ = id => document.getElementById(id);

    const tabs = $("tabs");
    const cards = Array.from(
        document.querySelectorAll("[data-order-card]")
    );

    const emptyState = $("empty-state");
    const emptyMessage = $("empty-message");
    const emptyCta = $("empty-cta");

    if (!tabs || !emptyState || !emptyMessage || !emptyCta) {
        return;
    }

    const messages = {
        "all": "You haven’t ordered anything yet",
        "not-paid": "No unpaid orders.",
        "pending": "No pending orders.",
        "shipped": "No shipped orders.",
        "complete": "No completed orders yet.",
        "canceled": "No canceled orders.",
        "rejected": "No rejected orders."
    };

    let currentTab = "all";

    function render() {
        let visible = 0;

        cards.forEach(card => {
            const matches =
                currentTab === "all" ||
                card.dataset.status === currentTab;

            card.hidden = !matches;

            if (matches) visible++;
        });

        emptyState.hidden = visible > 0;

        emptyMessage.textContent =
            messages[currentTab] || "No orders.";

        emptyCta.hidden =
            currentTab !== "all" || cards.length > 0;
    }

    // Tabs: mouse dan keyboard
    const tabButtons = Array.from(
        tabs.querySelectorAll('[role="tab"]')
    );

    function selectTab(button, focus = false) {
        currentTab = button.dataset.tab;

        tabButtons.forEach(tab => {
            const selected = tab === button;

            tab.setAttribute(
                "aria-selected",
                String(selected)
            );

            tab.tabIndex = selected ? 0 : -1;
        });

        if (focus) button.focus();

        render();
    }

    tabButtons.forEach((tab, index) => {
        tab.addEventListener("click", () => {
            selectTab(tab);
        });

        tab.addEventListener("keydown", event => {
            let next = -1;

            if (event.key === "ArrowRight") {
                next = (index + 1) % tabButtons.length;
            } else if (event.key === "ArrowLeft") {
                next =
                    (index - 1 + tabButtons.length) %
                    tabButtons.length;
            } else if (event.key === "Home") {
                next = 0;
            } else if (event.key === "End") {
                next = tabButtons.length - 1;
            }

            if (next >= 0) {
                event.preventDefault();
                selectTab(tabButtons[next], true);
            }
        });
    });

    // Countdown berdasarkan waktu dari backend.
    const clocks = Array.from(
        document.querySelectorAll("[data-countdown]")
    ).map(element => ({
        element,
        seconds: Number(element.dataset.seconds),
        startedAt: performance.now()
    }));

    let refreshingExpired = false;

    function updateCountdowns() {
        let expired = false;

        for (const clock of clocks) {
            const elapsed = Math.floor(
                (performance.now() - clock.startedAt) / 1000
            );

            const remaining = Math.max(
                0,
                clock.seconds - elapsed
            );

            const minutes = Math.floor(remaining / 60);
            const seconds = remaining % 60;

            clock.element.textContent =
                String(minutes).padStart(2, "0") +
                ":" +
                String(seconds).padStart(2, "0");

            if (remaining === 0) {
                expired = true;
            }
        }

        if (expired && !refreshingExpired) {
            refreshingExpired = true;

            // Backend memproses expiry saat halaman dimuat.
            window.location.reload();
        }
    }

    // Modal pembatalan
    const modal = $("cancelModal");
    const reason = $("cancelReason");
    const error = $("cancelError");
    const keepOrder = $("keepOrder");
    const confirmCancel = $("confirmCancel");

    let selectedCode = null;
    let busy = false;
    let lastFocus = null;

    function setModal(open) {
        modal.toggleAttribute("data-open", open);
        modal.setAttribute("aria-hidden", String(!open));

        document.body.classList.toggle(
            "overflow-hidden",
            open
        );

        if (open) {
            lastFocus = document.activeElement;
            reason.focus();
        } else {
            lastFocus?.focus();
        }
    }

    document.querySelectorAll("[data-cancel-order]")
        .forEach(button => {
            button.addEventListener("click", () => {
                if (busy) return;

                selectedCode = button.dataset.bookingCode;

                reason.value = "";
                error.textContent = "";

                setModal(true);
            });
        });

    modal.querySelectorAll("[data-reason]")
        .forEach(button => {
            button.addEventListener("click", () => {
                reason.value = button.dataset.reason;
                error.textContent = "";
                reason.focus();
            });
        });

    keepOrder.addEventListener("click", () => {
        if (!busy) setModal(false);
    });

    modal.addEventListener("click", event => {
        if (event.target === modal && !busy) {
            setModal(false);
        }
    });

    document.addEventListener("keydown", event => {
        if (
            event.key === "Escape" &&
            modal.hasAttribute("data-open") &&
            !busy
        ) {
            setModal(false);
        }
    });

    confirmCancel.addEventListener("click", async () => {
        if (busy || !selectedCode) return;

        const cancellationReason = reason.value.trim();

        if (
            cancellationReason.length < 5 ||
            cancellationReason.length > 200
        ) {
            error.textContent =
                "Please enter a reason (5–200 characters).";

            reason.focus();
            return;
        }

        busy = true;
        confirmCancel.disabled = true;
        keepOrder.disabled = true;

        confirmCancel.textContent = "Cancelling...";
        error.textContent = "";

        try {
            const response = await fetch(
                "/api/bookings/cancel.php",
                {
                    method: "POST",
                    credentials: "same-origin",
                    headers: {
                        "Content-Type": "application/json"
                    },
                    body: JSON.stringify({
                        booking_code: selectedCode,
                        reason: cancellationReason
                    })
                }
            );

            if (response.status === 401) {
                window.location.replace("/pages/login.php");
                return;
            }

            const result = await response.json();

            if (!response.ok || result.success !== true) {
                throw new Error(
                    result.message || "Cancellation failed."
                );
            }

            window.location.replace(
                "/pages/order-detail.php?order=" +
                encodeURIComponent(selectedCode)
            );

        } catch (err) {
            error.textContent = err.message;

        } finally {
            busy = false;
            confirmCancel.disabled = false;
            keepOrder.disabled = false;
            confirmCancel.textContent = "Cancel order";
        }
    });

    render();
    updateCountdowns();

    setInterval(updateCountdowns, 1000);

    window.addEventListener("pageshow", event => {
        if (event.persisted) {
            window.location.reload();
        }
    });

    document.addEventListener("visibilitychange", () => {
        if (!document.hidden) {
            window.location.reload();
        }
    });
})();
