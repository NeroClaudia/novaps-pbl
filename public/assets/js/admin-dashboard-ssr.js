/*
 * NovaPS Admin Dashboard - PostgreSQL-backed
 * PHP Session + admin API
 */
(() => {
  "use strict";

  const API = "/api/admin";
  const rupiah = value =>
    new Intl.NumberFormat("id-ID", {
      style: "currency",
      currency: "IDR",
      maximumFractionDigits: 0
    }).format(Number(value) || 0);

  const el = id => document.getElementById(id);

  async function getJSON(path) {
    const response = await fetch(`${API}/${path}`, {
      credentials: "same-origin",
      cache: "no-store"
    });

    if (response.status === 401 || response.status === 403) {
      location.replace("/admin/login.php");
      throw new Error("Sesi admin tidak valid.");
    }

    const result = await response.json();

    if (!response.ok || result.success !== true) {
      throw new Error(result.message || "Gagal mengambil data.");
    }

    return result;
  }

  function setText(selector, value) {
    const target = document.querySelector(selector);
    if (target) target.textContent = String(value);
  }

  function renderSummary(data) {
    const fulfillment = data.fulfillment_counts || {};
    const counts = data.counts || {};

    // Nilai yang benar-benar tersedia dari dashboard API.
    const cards = {
      pending: data.pending_payment_verifications ?? 0,
      shipped: fulfillment.Shipped ?? 0,
      completed: fulfillment.Complete ?? 0,
      canceled: fulfillment.Cancelled ?? 0,
      rejected: fulfillment.Rejected ?? 0
    };

    document.querySelectorAll("[data-count]").forEach(card => {
      const key = card.dataset.count;
      const value = card.querySelector("[data-value]");
      if (!value) return;

      // Tidak menebak angka yang tidak disediakan API.
      value.textContent = key in cards ? cards[key] : "—";
    });

    // Gunakan nama metrik sesuai definisi database.
    setText('[data-metric="units-total"]', "—");
    setText('[data-metric="units-month"]', "—");
    setText('[data-metric="revenue-total"]', "—");
    setText(
      '[data-metric="revenue-month"]',
      rupiah(data.revenue_this_month)
    );

    setText(
      "#in-progress",
      "—"
    );

    // Angka rental aktif tersedia jika diperlukan nanti.
    console.debug("Rental summary:", counts);
  }

  function td(value) {
    const cell = document.createElement("td");
    cell.className =
      "border-b border-slate-300 px-2 py-3 align-middle";
    cell.textContent = String(value ?? "—");
    return cell;
  }

  function dateText(date) {
    if (!date) return "—";

    const parsed = new Date(date);
    if (Number.isNaN(parsed.getTime())) return "—";

    return parsed.toLocaleString("id-ID", {
      timeZone: "Asia/Jakarta",
      dateStyle: "medium",
      timeStyle: "short"
    });
  }

  function bookingStatus(booking) {
    if (booking.fulfillment_status === "Shipped") {
      return "Shipped";
    }

    if (booking.payment_status === "AwaitingVerification") {
      return "Awaiting Verification";
    }

    if (
      booking.payment_method === "Cash" &&
      booking.payment_status === "Pending"
    ) {
      return "Cash - Pending";
    }

    return booking.fulfillment_status;
  }

  const refreshButton = el("refresh-dashboard");
  const refreshStatus = el("dashboard-refresh-status");

  let activeRefresh = null;
  let refreshAgain = false;

  function setRefreshStatus(message) {
    if (refreshStatus) {
      refreshStatus.textContent = message;
    }
  }

  // Refresh manual tanpa reload halaman.
  refreshButton?.addEventListener("click", () => {
    if (!modal?.hasAttribute("data-open")) {
      void loadDashboard();
    }
  });

  // Refresh otomatis setiap 60 detik.
  setInterval(() => {
    if (
      !document.hidden &&
      !modal?.hasAttribute("data-open")
    ) {
      void loadDashboard();
    }
  }, 60000);

  // Saat kembali ke tab Dashboard.
  document.addEventListener("visibilitychange", () => {
    if (
      !document.hidden &&
      !modal?.hasAttribute("data-open")
    ) {
      void loadDashboard();
    }
  });

  // Menu akun admin.
  const adminButton = el("adminBtn");

  adminButton?.addEventListener("click", () => {
    const wrapper = adminButton.parentElement;
    const open = wrapper.hasAttribute("data-open");

    wrapper.toggleAttribute("data-open", !open);
    adminButton.setAttribute("aria-expanded", String(!open));
  });

  modal?.querySelector("[data-close]")?.addEventListener(
    "click",
    closeModal
  );

  modal?.addEventListener("click", event => {
    if (event.target === modal) closeModal();
  });

  // Logout dari PHP Session.
  el("adminLogout")?.addEventListener("click", async () => {
    try {
      const response = await fetch("/api/admin/logout.php", {
        method: "POST",
        credentials: "same-origin",
        cache: "no-store"
      });

      if (!response.ok) {
        throw new Error("Logout gagal.");
      }

      location.replace("/admin/login.php");
    } catch (error) {
      alert(error.message);
    }
  });

  loadDashboard();
})();
