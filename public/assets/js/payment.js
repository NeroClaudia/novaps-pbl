
/*
 * NovaPS - Payment Confirmation
 * Payment data berasal dari backend PHP.
 */

(() => {
    const initialElement = document.getElementById(
        "initial-payment-data"
    );

    const countdown = document.getElementById("countdown");
    const status = document.getElementById("payment-status");
    const sendBtn = document.getElementById("send-btn");
    const cancelBtn = document.getElementById("cancel-btn");
    const proof = document.getElementById("proof");

    if (!initialElement || !countdown) return;

    const booking = JSON.parse(initialElement.textContent);

    if (!booking.is_payable) return;

    const initialSeconds = Number(
        booking.payment.seconds_left
    );

    const startedAt = performance.now();

    let timer = null;

    function secondsLeft() {
        const elapsed = Math.floor(
            (performance.now() - startedAt) / 1000
        );

        return Math.max(0, initialSeconds - elapsed);
    }

    function formatCountdown(seconds) {
        const minutes = Math.floor(seconds / 60);
        const remainder = seconds % 60;

        return (
            String(minutes).padStart(2, "0") + ":" +
            String(remainder).padStart(2, "0")
        );
    }

    function tick() {
        const remaining = secondsLeft();

        countdown.textContent =
            formatCountdown(remaining);

        countdown.classList.toggle(
            "text-red-600",
            remaining <= 60
        );

        countdown.classList.toggle(
            "animate-pulse",
            remaining > 0 && remaining <= 60
        );

        if (remaining === 0) {
            clearInterval(timer);

            sendBtn.disabled = true;
            cancelBtn.disabled = true;
            proof.disabled = true;

            status.textContent = "Checking payment status...";

            // Backend akan memperbarui pembayaran
            // yang telah kedaluwarsa saat halaman dimuat.
            window.location.reload();
        }
    }

    timer = setInterval(tick, 1000);

    tick();

    document.addEventListener("visibilitychange", () => {
        if (document.visibilityState === "visible") {
            window.location.reload();
        }
    });

    window.addEventListener("pageshow", event => {
        if (event.persisted) {
            window.location.reload();
        }
    });
})();
