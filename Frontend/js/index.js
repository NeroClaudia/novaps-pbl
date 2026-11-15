
/*
 * NovaPS - Homepage
 *
 * Live stock, login-required popup,
 * dan tombol Order Now / Choose & Rent.
 *
 * Requires:
 * - js/shared/session.js
 * - js/shared/api.js
 * - js/shared/orders.js
 */

(() => {
  function createModal(element, focusSelector = "a") {
    let lastFocus = null;

    return {
      isOpen() {
        return Boolean(element?.hasAttribute("data-open"));
      },

      open() {
        if (!element) return;

        lastFocus = document.activeElement;

        element.setAttribute("data-open", "");
        element.setAttribute("aria-hidden", "false");

        document.body.classList.add("overflow-hidden");

        setTimeout(() => {
          element.querySelector(focusSelector)?.focus();
        }, 50);
      },

      close() {
        if (!element?.hasAttribute("data-open")) return;

        element.removeAttribute("data-open");
        element.setAttribute("aria-hidden", "true");

        document.body.classList.remove("overflow-hidden");
        lastFocus?.focus();
      }
    };
  }

  const loginElement = document.getElementById("loginModal");
  const unpaidElement = document.getElementById("unpaidModal");

  const loginModal = createModal(loginElement);
  const unpaidModal = createModal(unpaidElement);

  [
    [loginElement, loginModal],
    [unpaidElement, unpaidModal]
  ].forEach(([element, modal]) => {
    if (!element) return;

    element.addEventListener("click", event => {
      if (
        event.target === element ||
        event.target.hasAttribute("data-close")
      ) {
        modal.close();
      }
    });
  });

  document.addEventListener("keydown", event => {
    if (event.key === "Escape") {
      loginModal.close();
      unpaidModal.close();
    }
  });

  /* ---------- Live stock ---------- */

  const unitMessage = document.getElementById(
    "unitsMessage"
  );

  const rupiah = new Intl.NumberFormat("id-ID", {
    style: "currency",
    currency: "IDR",
    maximumFractionDigits: 0
  });

  let loadingUnits = false;

  function renderUnits(devices) {
    for (const category of ["PS4", "PS5"]) {
      const key = category.toLowerCase();

      const units = devices.filter(
        device => device.category === category
      );

      const availableCount = units.filter(
        device =>
          device.status === "Available" &&
          !device.is_rented
      ).length;

      const stockElement = document.querySelector(
        `[data-stock="${key}"]`
      );

      const priceElement = document.querySelector(
        `[data-price="${key}"]`
      );

      if (stockElement) {
        stockElement.textContent =
          `Available now: ${availableCount}`;
      }

      if (!priceElement) continue;

      if (units.length === 0) {
        priceElement.textContent = "—";
        continue;
      }

      const prices = units.map(
        device => Number(device.price_per_day)
      );

      const lowestPrice = Math.min(...prices);

      const differentPrices = prices.some(
        price => price !== lowestPrice
      );

      priceElement.textContent =
        (differentPrices ? "From " : "") +
        rupiah.format(lowestPrice);
    }
  }

  async function updateStock() {
    if (loadingUnits || document.hidden) {
      return;
    }

    loadingUnits = true;

    try {
      const devices = await NovaPS.api.get(
        "devices/list.php"
      );

      if (!Array.isArray(devices)) {
        throw new Error(
          "Format daftar device tidak sesuai."
        );
      }

      renderUnits(devices);

      if (unitMessage) {
        unitMessage.textContent =
          devices.length === 0
            ? "No units are listed yet."
            : "";
      }

    } catch (error) {
      document.querySelectorAll("[data-stock]")
        .forEach(element => {
          element.textContent = "Unable to load";
        });

      document.querySelectorAll("[data-price]")
        .forEach(element => {
          element.textContent = "—";
        });

      if (unitMessage) {
        unitMessage.textContent =
          "Unable to load units. Please try again shortly.";
      }

      console.error(
        "Failed to load units:",
        error
      );

    } finally {
      loadingUnits = false;
    }
  }

  /* ---------- Demo unpaid-order popup ---------- */

  let unpaidTimer = null;

  function showUnpaid(order) {
    if (!order || !unpaidElement) return;

    const payLink = document.getElementById(
      "unpaidPay"
    );

    const info = document.getElementById(
      "unpaidInfo"
    );

    const countdown = document.getElementById(
      "unpaidCountdown"
    );

    if (!payLink || !info || !countdown) return;

    payLink.href =
      "pages/payment.html?order=" +
      encodeURIComponent(order.id);

    info.textContent =
      `${order.id} · ${NovaPS.money(order.total)}`;

    function tick() {
      const current = NovaPS.orders.get(order.id);

      if (!current || current.status !== "not_paid") {
        clearInterval(unpaidTimer);
        unpaidTimer = null;
        unpaidModal.close();
        updateStock();
        return;
      }

      countdown.textContent = NovaPS.formatClock(
        NovaPS.orders.msLeft(current),
        true
      );
    }

    clearInterval(unpaidTimer);

    tick();

    unpaidTimer = setInterval(tick, 1000);
    unpaidModal.open();
  }

  /* ---------- Authentication ---------- */

  async function checkCustomerSession() {
    // Tunggu proses pemeriksaan awal selesai.
    await NovaPS.authReady;

    // Periksa lagi sebelum navigasi agar session
    // yang sudah expired tidak dianggap aktif.
    return NovaPS.refreshSession();
  }

  function getUnpaidDemoOrder() {
    // Sistem pesanan lama masih memakai localStorage.
    // Akan diganti API booking saat integrasi payment.
    if (!NovaPS.orders?.unpaid) {
      return null;
    }

    return NovaPS.orders.unpaid();
  }

  /* ---------- Order Now / Choose & Rent ---------- */

  document.querySelectorAll("[data-order]")
    .forEach(element => {
      let processing = false;

      element.addEventListener("click", async event => {
        // Batalkan navigasi terlebih dahulu karena
        // pemeriksaan session bersifat asynchronous.
        event.preventDefault();

        if (processing) return;
        processing = true;

        const destination = element.href;

        try {
          const authenticated =
            await checkCustomerSession();

          if (!authenticated) {
            if (NovaPS.getAuthStatus() === "error") {
              alert(
                "Tidak dapat memeriksa session. " +
                "Periksa koneksi lalu coba lagi."
              );
              return;
            }

            loginModal.open();
            return;
          }

          // Kompatibilitas sementara untuk order demo.
          const unpaidOrder = getUnpaidDemoOrder();

          if (unpaidOrder) {
            showUnpaid(unpaidOrder);
            return;
          }

          // PHP Session valid: buka Order Form.
          window.location.assign(destination);

        } catch (error) {
          console.error(
            "Gagal membuka order form:",
            error
          );

          alert(
            "Terjadi kesalahan saat memeriksa login."
          );

        } finally {
          processing = false;
        }
      });
    });

  /* ---------- Initial data ---------- */

  updateStock();

  window.addEventListener(
    "pageshow",
    updateStock
  );

  document.addEventListener(
    "visibilitychange",
    () => {
      if (!document.hidden) {
        updateStock();
      }
    }
  );

  setInterval(updateStock, 15000);

  // Tampilkan pengingat demo hanya setelah
  // session customer dikonfirmasi oleh backend.
  NovaPS.authReady.then(isAuthenticated => {
    if (!isAuthenticated) return;

    const unpaidOrder = getUnpaidDemoOrder();

    if (unpaidOrder) {
      showUnpaid(unpaidOrder);
    }
  });

  // Tutup popup apabila autentikasi berubah.
  document.addEventListener(
    "novaps:authchange",
    event => {
      if (!event.detail.authenticated) {
        unpaidModal.close();

        if (unpaidTimer) {
          clearInterval(unpaidTimer);
          unpaidTimer = null;
        }
      }
    }
  );
})();
