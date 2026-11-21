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
      canceled: fulfillment.Cancelled ?? 0
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

  const modal = el("orderModal");
  const modalBody = el("orderBody");
  const modalActions = el("orderActions");

  function addDetailRow(parent, label, value) {
    const row = document.createElement("p");
    row.className = "mb-2 text-sm text-slate-700";

    const name = document.createElement("strong");
    name.textContent = `${label}: `;

    row.append(name, document.createTextNode(String(value ?? "—")));
    parent.appendChild(row);
  }

  async function openBooking(bookingId) {
    const result = await getJSON(
      `bookings/detail.php?booking_id=${encodeURIComponent(bookingId)}`
    );

    const booking = result.data;
    modalBody.replaceChildren();
    modalActions.replaceChildren();

    addDetailRow(modalBody, "Booking", booking.booking_code);
    addDetailRow(
      modalBody,
      "Customer",
      booking.customer?.full_name
    );
    addDetailRow(
      modalBody,
      "Telepon",
      booking.customer?.phone
    );
    addDetailRow(
      modalBody,
      "Alamat pengiriman",
      booking.address_snapshot
    );
    addDetailRow(
      modalBody,
      "Pembayaran",
      `${booking.payment?.method} / ${booking.payment?.status}`
    );
    addDetailRow(
      modalBody,
      "Pengiriman",
      booking.fulfillment_status
    );
    addDetailRow(
      modalBody,
      "Total",
      rupiah(booking.total_cost)
    );

    const heading = document.createElement("h3");
    heading.className = "mt-4 mb-2 font-semibold";
    heading.textContent = "Perangkat";
    modalBody.appendChild(heading);

    for (const item of booking.items || []) {
      addDetailRow(
        modalBody,
        item.device_name,
        `${item.duration_days} hari - ` +
        `${item.rental_status} - ${rupiah(item.total_cost)}`
      );
    }

    // Pada tahap ini modal masih read-only.
    // Verifikasi dan deliver disambungkan setelah UI diuji.
    modal.setAttribute("data-open", "");
    modal.setAttribute("aria-hidden", "false");
  }

  function closeModal() {
    modal?.removeAttribute("data-open");
    modal?.setAttribute("aria-hidden", "true");
  }

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
          await openBooking(booking.booking_id);
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

  async function loadDashboard() {
    try {
      const [summary, list] = await Promise.all([
        getJSON("dashboard.php"),
        getJSON("bookings/list.php?per_page=100")
      ]);

      renderSummary(summary.data);
      renderQueue(list.data);

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
