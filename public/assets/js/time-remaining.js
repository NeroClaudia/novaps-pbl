
/*
 * NovaPS - Time Remaining
 * PHP SSR + live countdown
 */

(() => {
    const $ = id => document.getElementById(id);

    const tabs = $("tabs");
    const emptyState = $("empty-state");
    const emptyMessage = $("empty-message");
    const emptyCta = $("empty-cta");

    if (!tabs || !emptyState || !emptyMessage) return;

    const cards = Array.from(
        document.querySelectorAll("[data-rental-card]")
    );

    const buttons = Array.from(
        tabs.querySelectorAll('[role="tab"]')
    );

    const serverTimestamp = Number(
        document.body.dataset.serverTimestamp
    );

    // Performance clock tidak berubah ketika jam perangkat disetel.
    const loadedAt = performance.now();

    let currentTab = "all";
    let reloading = false;

    const messages = {
        all: "No rental in progress.",
        "4-hours": "No rentals ending within 4 hours.",
        "1-hour": "No rentals ending within 1 hour.",
        overdue: "No overdue rentals.",
        returned: "No returned rentals yet."
    };

    function nowSeconds() {
        return serverTimestamp +
            (performance.now() - loadedAt) / 1000;
    }

    function getRemaining(card) {
        return Math.ceil(
            Number(card.dataset.end) - nowSeconds()
        );
    }

    function getStatus(card) {
        const status = card.dataset.status;

        if (status === "Returned") {
            return "returned";
        }

        const remaining = getRemaining(card);

        if (status === "Overdue" || remaining <= 0) {
            return "overdue";
        }

        if (remaining <= 3600) {
            return "1-hour";
        }

        if (remaining <= 14400) {
            return "4-hours";
        }

        return "active";
    }

    function formatTime(seconds) {
        const total = Math.max(0, seconds);

        const hours = Math.floor(total / 3600);
        const minutes = Math.floor((total % 3600) / 60);
        const remainder = total % 60;

        return [
            hours,
            minutes,
            remainder
        ].map(value =>
            String(value).padStart(2, "0")
        ).join(":");
    }

    function updateClock(card) {
        const clock = card.querySelector(
            "[data-rental-clock]"
        );

        if (!clock) return;

        const state = getStatus(card);
        const remaining = getRemaining(card);

        if (state === "overdue") {
            clock.textContent = "Overdue";
            clock.style.backgroundColor = "#dc2626";
            return;
        }

        clock.textContent = formatTime(remaining);

        if (remaining <= 3600) {
            clock.style.backgroundColor = "#f97316";
        } else if (remaining <= 14400) {
            clock.style.backgroundColor = "#d97706";
        } else {
            clock.style.backgroundColor = "#16a34a";
        }
    }

    function matchesTab(card) {
        const state = getStatus(card);

        switch (currentTab) {
            case "all":
                return true;

            case "4-hours":
                return state === "4-hours" ||
                    state === "1-hour";

            case "1-hour":
                return state === "1-hour";

            case "overdue":
                return state === "overdue";

            case "returned":
                return state === "returned";

            default:
                return false;
        }
    }

    function render() {
        let visible = 0;

        for (const card of cards) {
            const matches = matchesTab(card);

            card.hidden = !matches;

            if (matches) {
                visible++;
            }
        }

        emptyState.hidden = visible > 0;

        if (visible > 0) return;

        if (currentTab === "all") {
            // Pesan SSR dipertahankan.
            emptyCta.hidden = false;
        } else {
            emptyMessage.textContent =
                messages[currentTab] || "No rentals.";

            emptyCta.hidden = true;
        }
    }

    function selectTab(button, focus = false) {
        currentTab = button.dataset.tab;

        buttons.forEach(tab => {
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

    buttons.forEach((button, index) => {
        button.addEventListener("click", () => {
            selectTab(button);
        });

        button.addEventListener("keydown", event => {
            let next = -1;

            if (event.key === "ArrowRight") {
                next = (index + 1) % buttons.length;
            } else if (event.key === "ArrowLeft") {
                next = (
                    index - 1 + buttons.length
                ) % buttons.length;
            } else if (event.key === "Home") {
                next = 0;
            } else if (event.key === "End") {
                next = buttons.length - 1;
            }

            if (next !== -1) {
                event.preventDefault();

                selectTab(buttons[next], true);
            }
        });
    });

    function tick() {
        let newlyOverdue = false;

        for (const card of cards) {
            const previousStatus = card.dataset.status;

            updateClock(card);

            if (
                previousStatus === "Rented" &&
                getStatus(card) === "overdue"
            ) {
                newlyOverdue = true;
            }
        }

        render();

        // Backend harus mengubah Rented menjadi Overdue.
        if (newlyOverdue && !reloading) {
            reloading = true;
            window.location.reload();
        }
    }

    tick();

    setInterval(tick, 1000);

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
