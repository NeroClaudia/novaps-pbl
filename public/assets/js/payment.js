
/*
 * NovaPS - Payment Confirmation
 * PHP SSR + AJAX + Supabase Storage
 */

(() => {
    const $ = id => document.getElementById(id);

    const initialElement = $("initial-payment-data");

    if (!initialElement) return;

    const booking = JSON.parse(initialElement.textContent);

    const countdown = $("countdown");
    const status = $("payment-status");
    const sendBtn = $("send-btn");
    const cancelBtn = $("cancel-btn");

    const proof = $("proof");
    const upload = proof.closest("[data-upload]");
    const preview = upload.querySelector("img");
    const proofField = proof.closest("[data-field]");

    const modal = $("cancelModal");
    const reason = $("reason");
    const reasonField = reason.closest("[data-field]");
    const confirmCancel = $("confirm-cancel");

    const detailUrl =
        "/pages/order-detail.php?order=" +
        encodeURIComponent(booking.booking_code);

    const hasDeadline = booking.payment.seconds_left !== null;
    const initialSeconds = Number(booking.payment.seconds_left);
    const startedAt = performance.now();

    let timer = null;
    let previewUrl = null;
    let selectedFile = null;
    let busy = false;
    let finished = false;

    function secondsLeft() {
        if (!hasDeadline) return Infinity;

        const elapsed = Math.floor(
            (performance.now() - startedAt) / 1000
        );

        return Math.max(0, initialSeconds - elapsed);
    }

    function goToDetail() {
        finished = true;
        clearInterval(timer);
        location.replace(detailUrl);
    }

    function updateButtons() {
        const expired = secondsLeft() <= 0;

        proof.disabled =
            busy || expired || !booking.is_payable;

        sendBtn.disabled =
            busy || expired ||
            !booking.is_payable || !selectedFile;

        cancelBtn.disabled = busy || expired;
        confirmCancel.disabled = busy || expired;
    }

    /* ---------- Countdown ---------- */

    function tick() {
        if (finished || !hasDeadline) return;

        const remaining = secondsLeft();
        const minutes = Math.floor(remaining / 60);
        const seconds = remaining % 60;

        countdown.textContent =
            String(minutes).padStart(2, "0") + ":" +
            String(seconds).padStart(2, "0");

        countdown.classList.toggle(
            "text-red-600",
            remaining <= 60
        );

        countdown.classList.toggle(
            "animate-pulse",
            remaining > 0 && remaining <= 60
        );

        if (remaining === 0) {
            updateButtons();

            if (!busy) {
                status.textContent = "Checking payment status...";

                // Server memeriksa status sebenarnya.
                location.replace(
                    "/pages/payment.php?order=" +
                    encodeURIComponent(booking.booking_code)
                );
                finished = true;
                clearInterval(timer);
            }
        }
    }

    /* ---------- Error messages ---------- */

    function setError(field, message) {
        field.toggleAttribute(
            "data-invalid",
            Boolean(message)
        );

        field.querySelector("[data-error]").textContent =
            message;
    }

    /* ---------- API requests ---------- */

    async function post(path, body, isJson = false) {
        const options = {
            method: "POST",
            credentials: "same-origin",
            body
        };

        if (isJson) {
            options.headers = {
                "Content-Type": "application/json"
            };

            options.body = JSON.stringify(body);
        }

        const response = await fetch(
            `/api/bookings/${path}`,
            options
        );

        if (response.status === 401) {
            location.replace("/pages/login.php");
            throw new Error("Session expired.");
        }

        const result = await response.json();

        if (!response.ok || result.success !== true) {
            const error = new Error(
                result.message || "Request failed."
            );

            error.status = response.status;
            error.code = result.code;

            throw error;
        }

        return result.data;
    }

    /* ---------- Image preview ---------- */

    function clearPreview() {
        if (previewUrl) {
            URL.revokeObjectURL(previewUrl);
            previewUrl = null;
        }

        preview.removeAttribute("src");
        preview.style.display = "none";

        upload.removeAttribute("data-has-image");

        selectedFile = null;
    }

    proof.addEventListener("change", () => {
        clearPreview();
        setError(proofField, "");

        const file = proof.files[0];

        if (!file) {
            updateButtons();
            return;
        }

        if (!["image/jpeg", "image/png"].includes(file.type)) {
            setError(proofField, "Only JPG and PNG are accepted.");
            proof.value = "";
            updateButtons();
            return;
        }

        if (file.size < 1 || file.size > 2 * 1024 * 1024) {
            setError(proofField, "Maximum image size is 2 MB.");
            proof.value = "";
            updateButtons();
            return;
        }

        previewUrl = URL.createObjectURL(file);

        preview.onload = () => {
            selectedFile = file;

            preview.classList.remove("hidden");
            preview.style.display = "block";
            preview.style.width = "100%";
            preview.style.height = "100%";
            preview.style.objectFit = "contain";

            upload.setAttribute("data-has-image", "");

            updateButtons();
        };

        preview.onerror = () => {
            clearPreview();
            proof.value = "";

            setError(proofField, "Invalid image.");
            updateButtons();
        };

        preview.src = previewUrl;
        updateButtons();
    });

    /* ---------- Payment upload ---------- */

    sendBtn.addEventListener("click", async () => {
        if (
            busy ||
            !selectedFile ||
            !booking.is_payable ||
            secondsLeft() <= 0
        ) return;

        busy = true;
        updateButtons();

        sendBtn.textContent = "Sending...";
        setError(proofField, "");

        try {
            const data = new FormData();

            data.append("booking_code", booking.booking_code);
            data.append("payment_proof", selectedFile);

            await post("payment-proof.php", data);

            finished = true;
            clearInterval(timer);

            window.location.replace("/pages/receipt.php?order=" + encodeURIComponent(booking.booking_code));

            goToDetail();

        } catch (error) {
            setError(proofField, error.message);

            if (error.status === 409) {
                location.reload();
                return;
            }

        } finally {
            busy = false;
            sendBtn.textContent = "Send payment";

            updateButtons();
            tick();
        }
    });

    /* ---------- Cancel order modal ---------- */

    let lastFocus = null;

    function setModal(open) {
        modal.toggleAttribute("data-open", open);
        modal.setAttribute("aria-hidden", String(!open));

        document.body.classList.toggle("overflow-hidden", open);

        if (open) {
            lastFocus = document.activeElement;
            reason.focus();
        } else {
            lastFocus?.focus();
        }
    }

    cancelBtn.addEventListener("click", () => {
        if (busy || secondsLeft() <= 0) return;

        setError(reasonField, "");
        setModal(true);
    });

    modal.addEventListener("click", event => {
        if (
            event.target === modal ||
            event.target.closest("[data-close]")
        ) {
            if (!busy) setModal(false);
        }
    });

    document.addEventListener("keydown", event => {
        if (event.key === "Escape" && !busy) {
            setModal(false);
        }
    });

    modal.querySelectorAll("[data-reason]").forEach(chip => {
        chip.addEventListener("click", () => {
            reason.value = chip.dataset.reason;
            setError(reasonField, "");
            reason.focus();
        });
    });

    reason.addEventListener("input", () => {
        if (reason.value.trim().length >= 5) {
            setError(reasonField, "");
        }
    });

    confirmCancel.addEventListener("click", async () => {
        if (busy || secondsLeft() <= 0) return;

        const text = reason.value.trim();

        if (text.length < 5 || text.length > 200) {
            setError(
                reasonField,
                "Please enter a reason (5–200 characters)."
            );

            return;
        }

        busy = true;
        updateButtons();

        confirmCancel.textContent = "Cancelling...";

        try {
            await post(
                "cancel.php",
                {
                    booking_code: booking.booking_code,
                    reason: text
                },
                true
            );

            setModal(false);
            goToDetail();

        } catch (error) {
            setError(reasonField, error.message);

            if (error.status === 409) {
                location.reload();
                return;
            }

        } finally {
            busy = false;
            confirmCancel.textContent = "Cancel order";

            updateButtons();
            tick();
        }
    });

    /* ---------- Initialization ---------- */

    updateButtons();

    if (hasDeadline) {
        timer = setInterval(tick, 1000);
        tick();
    }

    document.addEventListener("visibilitychange", () => {
        if (!document.hidden) tick();
    });

    window.addEventListener("pageshow", event => {
        if (event.persisted) location.reload();
    });
})();
