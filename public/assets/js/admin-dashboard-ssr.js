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

  const orderDetail = window.NovaOrderDetail;
  const modal = el("orderModal");

  function renderQueue(bookings) {
    const head = el("queue-head");
    const body = el("queue-body");

    head.replaceChildren();
    body.replaceChildren();

    const columns = [
      "Customer Name",
      "Booking Code",
      "Total Unit",
      "Total",
      "Date",
      "Status",
      ""
    ];

    const headerRow = document.createElement("tr");

    for (const label of columns) {
      const th = document.createElement("th");
      th.className =
        "border-b border-slate-300 px-2 py-3 text-left text-sm";
      th.textContent = label;
      headerRow.appendChild(th);
    }

    head.appendChild(headerRow);

    const queue = bookings
      .filter(booking =>
        booking.fulfillment_status === "Shipped" ||
        (
          booking.fulfillment_status === "Pending" &&
          (
            booking.payment_status === "AwaitingVerification" ||
            (
              booking.payment_method === "Cash" &&
              booking.payment_status === "Pending"
            )
          )
        )
      )
      .sort((a, b) =>
        new Date(a.created_at) - new Date(b.created_at)
      );

    for (const booking of queue) {
      const tr = document.createElement("tr");

      tr.appendChild(td(booking.full_name));
      tr.appendChild(td(booking.booking_code));
      tr.appendChild(td(booking.items_count));
      tr.appendChild(td(rupiah(booking.total_cost)));
      tr.appendChild(td(dateText(booking.created_at)));
      tr.appendChild(td(bookingStatus(booking)));

      const action = td("");
      const button = document.createElement("button");

      button.type = "button";
      button.className =
        "btn btn-indigo px-3 py-1 text-sm";
      button.textContent = "Detail";

      button.addEventListener("click", async () => {
        try {
          await orderDetail.open(booking.booking_id);
        } catch (error) {
          alert(error.message);
        }
      });

      action.appendChild(button);
      tr.appendChild(action);
      body.appendChild(tr);
    }

    el("queue-empty").hidden = queue.length > 0;
  }

  async function fetchDashboardData() {
    try {
      const [summary, list] = await Promise.all([
        getJSON("dashboard.php"),
        getJSON("bookings/list.php?per_page=100")
      ]);

      renderSummary(summary.data);
      renderQueue(list.data);

      //Buka detail dari halaman manage orders. Parameter hanya menentukan booking mana yang diminta. API tetap memeriksa hak admin
      const url = new URL(window.location.href);
      const requestBookingId = url.searchParams.get("booking_id");

      if (requestBookingId && /^[1-9]\d*$/.test(requestBookingId)) {
        //Hapus parameter agar refresh tidak selalu membuka modal
        url.searchParams.delete("booking_id");
        history.replaceState(null, "", url);

        try {
          await orderDetail.open(requestBookingId);
        } catch (error) {
          console.error("Gagal membuka detail bookings:", error);
          alert(error.message);
        }
      }

      // List API dibatasi 100 booking per halaman.
      // Jika mencapai batas, paginasi perlu ditambahkan.
      if (list.meta?.total > list.data.length) {
        console.warn(
          "Antrean dashboard hanya memuat 100 booking terbaru."
        );
      }
    } catch (error) {
      console.error("Dashboard load failed:", error);
      const empty = el("queue-empty");
      if (empty) {
        empty.hidden = false;
        empty.textContent = "Gagal memuat data dashboard.";
      }
    }
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

  function loadDashboard() {
    if (activeRefresh) {
      refreshAgain = true;
      return activeRefresh;
    }

    activeRefresh = (async () => {
      if (refreshButton) refreshButton.disabled = true;

      try {
        do {
          refreshAgain = false;
          setRefreshStatus("Memperbarui...");

          await fetchDashboardData();
        } while (refreshAgain);

        setRefreshStatus(
          "Diperbarui " +
          new Date().toLocaleTimeString("id-ID", {
            timeZone: "Asia/Jakarta",
            hour: "2-digit",
            minute: "2-digit"
          })
        );
      } finally {
        if (refreshButton) refreshButton.disabled = false;
        activeRefresh = null;
      }
    })();

    return activeRefresh;
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
  orderDetail.setOnChanged(loadDashboard);

  loadDashboard();
})();
