(() => {
  "use strict";

  const $ = id => document.getElementById(id);
  const modal = $("extend-modal");

  if (!modal) return;

  const requestForm = $("extend-request-form");
  const paymentForm = $("extend-payment-form");

  let rentalId = null;
  let extensionId = null;
  let deadline = 0;
  let pricePerDay = 0;
  let busy = false;
  let timerId = null;
  let previewUrl = null;

  const money = amount =>
    new Intl.NumberFormat("id-ID", {
      style: "currency",
      currency: "IDR",
      maximumFractionDigits: 0
    }).format(amount);

  async function readJSON(response) {
    const result = await response.json();

    if (!response.ok || result.success !== true) {
      throw new Error(
        result.message || "Permintaan gagal diproses."
      );
    }

    return result.data;
  }

  function showError(message) {
    $("extend-error").textContent = message;
  }

  function updatePrice() {
    const days = Number($("extend-days").value);

    $("extend-price").textContent =
      `Additional Cost: ${money(pricePerDay * days)}`;
  }

  function updateTimer() {
    const seconds = Math.max(
      0,
      Math.ceil((deadline - Date.now()) / 1000)
    );

    $("extend-payment-timer").textContent =
      `Payment deadline: ${String(Math.floor(seconds / 60)).padStart(2, "0")}:${String(seconds % 60).padStart(2, "0")}`;

    $("extend-upload-submit").disabled =
      seconds === 0 || busy;

    if (seconds === 0) {
      showError("Batas pembayaran telah habis. Silakan ajukan kembali.");
      clearInterval(timerId);
      timerId = null;
    }
  }

  function showPayment(data) {
    extensionId = Number(data.extension_id);

    if (data.total_cost !== undefined) {
      $("extend-payment-amount").textContent =
        money(Number(data.total_cost));
    } else {
      $("extend-payment-amount").textContent =
        money(pricePerDay * Number(data.extra_days));
    }

    deadline = Date.parse(data.payment_expires_at);

    requestForm.hidden = true;
    paymentForm.hidden = false;

    clearInterval(timerId);
    timerId = setInterval(updateTimer, 1000);
    updateTimer();
  }

    function clearProofPreview() {
    const image = $("extend-preview-image");

    if (previewUrl !== null) {
        URL.revokeObjectURL(previewUrl);
        previewUrl = null;
    }

    image.removeAttribute("src");

    $("extend-proof").value = "";
    $("extend-file-name").textContent = "";
    $("extend-preview-wrapper").hidden = true;
    }

    function updateProofPreview() {
    const file = $("extend-proof").files[0];

    // Bersihkan preview lama tanpa menghapus pilihan baru.
    if (previewUrl !== null) {
        URL.revokeObjectURL(previewUrl);
        previewUrl = null;
    }

    $("extend-preview-image").removeAttribute("src");
    $("extend-preview-wrapper").hidden = true;
    $("extend-file-name").textContent = "";

    if (!file) return;

    const allowedTypes = [
        "image/jpeg",
        "image/png"
    ];

    if (!allowedTypes.includes(file.type)) {
        clearProofPreview();
        showError("File harus berupa JPG atau PNG.");
        return;
    }

    if (file.size === 0 || file.size > 2 * 1024 * 1024) {
        clearProofPreview();
        showError("Ukuran gambar maksimal 2 MB.");
        return;
    }

    showError("");

    previewUrl = URL.createObjectURL(file);

    $("extend-preview-image").src = previewUrl;
    $("extend-file-name").textContent =
        `${file.name} · ${(file.size / 1024).toFixed(1)} KB`;

    $("extend-preview-wrapper").hidden = false;
    }

  function openModal(button) {
    rentalId = Number(button.dataset.rentalId);
    pricePerDay = Number(button.dataset.price);

    $("extend-device").textContent = button.dataset.device;
    $("extend-error").textContent = "";
    clearProofPreview();

    requestForm.hidden = false;
    paymentForm.hidden = true;

    const select = $("extend-days");
    select.replaceChildren();

    const maxDays = Number(button.dataset.maxDays);

    for (let days = 1; days <= maxDays; days++) {
      const option = new Option(
        `${days} day(s)`,
        String(days)
      );

      select.add(option);
    }

    updatePrice();

    modal.hidden = false;

    if (button.dataset.extensionId) {
      const createdAt = Date.parse(button.dataset.createdAt);

      showPayment({
        extension_id: button.dataset.extensionId,
        extra_days: Number(button.dataset.extraDays),
        payment_expires_at: new Date(
          createdAt + 5 * 60 * 1000
        ).toISOString()
      });
    } else {
      select.focus();
    }
  }

  function closeModal() {
    if (busy) return;

    clearProofPreview();

    modal.hidden = true;
    clearInterval(timerId);
    timerId = null;
  }

  document.querySelectorAll("[data-extend-open]")
    .forEach(button => {
      button.addEventListener("click", () => openModal(button));
    });

  $("extend-close").addEventListener("click", closeModal);

  $("extend-proof").addEventListener("change", updateProofPreview);

  $("extend-remove-proof").addEventListener("click", clearProofPreview);

  modal.addEventListener("click", event => {
    if (event.target === modal) closeModal();
  });

  document.addEventListener("keydown", event => {
    if (event.key === "Escape" && !modal.hidden) {
      closeModal();
    }
  });

  $("extend-days").addEventListener("change", updatePrice);

  requestForm.addEventListener("submit", async event => {
    event.preventDefault();
    if (busy) return;

    busy = true;
    showError("");
    $("extend-request-submit").disabled = true;

    try {
      const response = await fetch(
        "/api/rentals/extend-request.php",
        {
          method: "POST",
          credentials: "same-origin",
          headers: {
            "Content-Type": "application/json"
          },
          body: JSON.stringify({
            rental_id: rentalId,
            extra_days: Number($("extend-days").value)
          })
        }
      );

      const data = await readJSON(response);

      showPayment(data);

    } catch (error) {
      showError(error.message);
    } finally {
      busy = false;
      $("extend-request-submit").disabled = false;
      updateTimerIfPaymentOpen();
    }
  });

  function updateTimerIfPaymentOpen() {
    if (!paymentForm.hidden) updateTimer();
  }

  paymentForm.addEventListener("submit", async event => {
    event.preventDefault();
    if (busy || !extensionId) return;

    const file = $("extend-proof").files[0];

    if (!file) {
      showError("Pilih bukti pembayaran terlebih dahulu.");
      return;
    }

    if (
      file.size > 2 * 1024 * 1024 ||
      !["image/jpeg", "image/png"].includes(file.type)
    ) {
      showError("File harus JPG/PNG maksimal 2 MB.");
      return;
    }

    busy = true;
    showError("");
    $("extend-upload-submit").disabled = true;

    try {
      const formData = new FormData();

      formData.append("extension_id", String(extensionId));
      formData.append("payment_proof", file);

      const response = await fetch(
        "/api/rentals/extension-payment-proof.php",
        {
          method: "POST",
          credentials: "same-origin",
          body: formData
        }
      );

      await readJSON(response);

      alert(
        "Bukti pembayaran berhasil dikirim. " +
        "Menunggu verifikasi admin."
      );

      location.reload();

    } catch (error) {
      showError(error.message);
    } finally {
      busy = false;
      updateTimerIfPaymentOpen();
    }
  });
})();
