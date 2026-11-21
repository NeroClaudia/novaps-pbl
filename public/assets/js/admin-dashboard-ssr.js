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

  function createDocumentViewer({
    title,
    url,
    available
  }) {
    const section = document.createElement("section");
    section.className =
      "mt-4 rounded-lg border border-slate-200 p-3";

    const heading = document.createElement("h3");
    heading.className = "mb-2 font-semibold";
    heading.textContent = title;

    const status = document.createElement("p");
    status.className = "text-sm text-slate-500";

    section.append(heading, status);

    const ready = new Promise(resolve => {
      if (!available) {
        status.textContent = "Dokumen belum tersedia.";
        resolve(false);
        return;
      }

      status.textContent = "Memuat dokumen...";

      const image = document.createElement("img");

      image.className =
        "mt-2 max-h-[320px] w-full rounded-lg " +
        "border object-contain";

      image.alt = title;
      image.referrerPolicy = "no-referrer";

      image.addEventListener("load", () => {
        status.textContent =
          "Dokumen berhasil dimuat. Silakan periksa isinya.";
        resolve(true);
      }, { once: true });

      image.addEventListener("error", () => {
        status.textContent =
          "Dokumen gagal dimuat. Verifikasi tidak dapat dilakukan.";
        image.remove();
        resolve(false);
      }, { once: true });

      section.appendChild(image);
      image.src = url;
    });

    return { section, ready };
  }

  let deliveryMap = null;

  function destroyDeliveryMap() {
    if (deliveryMap) {
      deliveryMap.remove();
      deliveryMap = null;
    }
  }

  function renderDeliveryMap(booking) {
    destroyDeliveryMap();

    const latitude = booking.delivery_latitude;
    const longitude = booking.delivery_longitude;

    // Booking dengan alamat manual tidak memiliki pin.
    if (latitude === null || longitude === null) {
      addDetailRow(
        modalBody,
        "Titik lokasi",
        "Tidak ada pin peta. Gunakan alamat pengiriman."
      );
      return;
    }

    const lat = Number(latitude);
    const lng = Number(longitude);

    if (
      !Number.isFinite(lat) ||
      !Number.isFinite(lng) ||
      lat < -90 || lat > 90 ||
      lng < -180 || lng > 180
    ) {
      addDetailRow(
        modalBody,
        "Titik lokasi",
        "Koordinat tidak valid."
      );
      return;
    }

    const section = document.createElement("section");
    section.className = "mt-4";

    const heading = document.createElement("h3");
    heading.className = "mb-2 font-semibold";
    heading.textContent = "Titik Pengiriman Customer";

    const mapElement = document.createElement("div");
    mapElement.style.height = "260px";
    mapElement.style.width = "100%";
    mapElement.style.borderRadius = "8px";
    mapElement.style.zIndex = "0";

    const coordinates = document.createElement("p");
    coordinates.className = "mt-2 text-xs text-slate-500";
    coordinates.textContent =
      `Latitude: ${lat.toFixed(7)}, Longitude: ${lng.toFixed(7)}`;

    const mapLink = document.createElement("a");
    mapLink.className =
      "mt-2 inline-block text-sm text-blue-600 underline";

    mapLink.href =
      `https://www.openstreetmap.org/` +
      `?mlat=${encodeURIComponent(lat)}` +
      `&mlon=${encodeURIComponent(lng)}` +
      `#map=17/${lat}/${lng}`;

    mapLink.target = "_blank";
    mapLink.rel = "noopener noreferrer";
    mapLink.textContent = "Buka lokasi di OpenStreetMap";

    section.append(heading, mapElement, coordinates, mapLink);
    modalBody.appendChild(section);

    if (typeof L === "undefined") {
      const message = document.createElement("p");
      message.textContent = "Peta gagal dimuat.";
      section.appendChild(message);
      return;
    }

    deliveryMap = L.map(mapElement, {
      scrollWheelZoom: false
    }).setView([lat, lng], 17);

    L.tileLayer(
      "https://tile.openstreetmap.org/{z}/{x}/{y}.png",
      {
        maxZoom: 19,
        attribution:
          '&copy; <a href="https://www.openstreetmap.org/copyright">' +
          'OpenStreetMap contributors</a>'
      }
    ).addTo(deliveryMap);

    L.marker([lat, lng])
      .addTo(deliveryMap)
      .bindPopup("Tujuan pengiriman NovaPS");

    // Modal harus terlihat sebelum Leaflet menghitung ukuran.
    requestAnimationFrame(() => {
      requestAnimationFrame(() => {
        deliveryMap?.invalidateSize();
      });
    });
  }

  async function postAdmin(path, payload) {
    const response = await fetch(`${API}/${path}`, {
      method: "POST",
      credentials: "same-origin",
      cache: "no-store",
      headers: {
        "Content-Type": "application/json"
      },
      body: JSON.stringify(payload)
    });

    if (response.status === 401 || response.status === 403) {
      location.replace("/admin/login.php");
      throw new Error("Sesi admin tidak valid");
    }

    const result = await response.json();

    if (!response.ok || result.success !== true) {
      throw new Error(result.message || "Tindakan admin gagal");
    }

    return result.data;
  }

  function renderDeliveryAction(booking) {
    if (!booking.verification?.can_deliver) return;

    const button = document.createElement("button");

    button.type = "button";
    button.className = "btn btn-indigo px-4 py-2 text-sm font-semibold";
    button.textContent = "Konfirmasi Perangkat Diterima";

    button.addEventListener("click", async () => {
      const confirmed = window.confirm(`Pastikan perangkat booking ${booking.booking_code} ` + "sudah diterima customer. Lanjutkan?");

      if (!confirmed) return;

      button.disabled = true;
      button.textContent = "Memproses...";

      try {
        await postAdmin("bookings/deliver.php", {
          booking_id: booking.booking_id,
          delivery_confirmed: true
        });

        closeModal();
        await loadDashboard();

      } catch (error) {
        alert(error.message);
        button.disabled = false;
        button.textContent = "Konfirmasi Perangkat Diterima";
      }
    });

    modalActions.appendChild(button);
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
    renderDeliveryMap(booking);
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

    renderDeliveryAction(booking);

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

    // Dokumen identitas customer.
    const identityDocument = createDocumentViewer({
      title: "Dokumen Identitas Customer",
      url:
        `${API}/bookings/identity-document.php` +
        `?booking_id=${encodeURIComponent(booking.booking_id)}`,
      available: Boolean(
        booking.customer?.identity_doc_uploaded
      )
    });

    // Bukti pembayaran, hanya wajib untuk QRIS.
    const isQRIS = booking.payment?.method === "QRIS";

    const paymentDocument = isQRIS
      ? createDocumentViewer({
          title: "Bukti Pembayaran QRIS",
          url:
            `${API}/bookings/payment-proof.php` +
            `?booking_id=${encodeURIComponent(booking.booking_id)}`,
          available: Boolean(booking.payment?.proof_uploaded)
        })
      : {
          section: document.createElement("div"),
          ready: Promise.resolve(true)
        };

    modalBody.appendChild(identityDocument.section);

    if (isQRIS) {
      modalBody.appendChild(paymentDocument.section);
    } else {
      addDetailRow(
        modalBody,
        "Pembayaran Cash",
        "Admin wajib memastikan pembayaran telah diterima."
      );
    }

    renderVerificationAction(booking, {
      identity: identityDocument,
      payment: paymentDocument
    });

    renderDeliveryAction(booking);

    // Pada tahap ini modal masih read-only.
    // Verifikasi dan deliver disambungkan setelah UI diuji.
    modal.setAttribute("data-open", "");
    modal.setAttribute("aria-hidden", "false");
  }

  function renderVerificationAction(booking, documents) {
    if (!booking.verification?.can_verify) return;

    const panel = document.createElement("div");
    panel.className =
      "mt-4 w-full rounded-lg border border-slate-200 p-3";

    const heading = document.createElement("h3");
    heading.className = "mb-3 font-semibold";
    heading.textContent = "Verifikasi Admin";

    function makeCheckbox(labelText) {
      const label = document.createElement("label");
      label.className =
        "mb-3 flex items-start gap-2 text-sm cursor-pointer";

      const input = document.createElement("input");
      input.type = "checkbox";
      input.className = "mt-1";

      const text = document.createElement("span");
      text.textContent = labelText;

      label.append(input, text);

      return { label, input };
    }

    const identity = makeCheckbox(
      "Saya sudah memeriksa dokumen identitas customer."
    );

    const payment = makeCheckbox(
      booking.payment.method === "QRIS"
        ? "Saya sudah memeriksa bukti pembayaran QRIS."
        : "Saya sudah menerima dan memeriksa pembayaran Cash."
    );

    const status = document.createElement("p");
    status.className = "mb-3 text-xs text-slate-500";
    status.textContent = "Menunggu dokumen dimuat...";

    const button = document.createElement("button");
    button.type = "button";
    button.className =
      "btn btn-indigo px-4 py-2 text-sm font-semibold";
    button.textContent = "Verify Payment";
    button.disabled = true;

    let documentsReady = false;
    let submitting = false;

    function updateButton() {
      button.disabled =
        !documentsReady ||
        submitting ||
        !identity.input.checked ||
        !payment.input.checked;
    }

    identity.input.addEventListener("change", updateButton);
    payment.input.addEventListener("change", updateButton);

    Promise.all([
      documents.identity.ready,
      documents.payment.ready
    ]).then(([identityReady, paymentReady]) => {
      documentsReady = identityReady && paymentReady;

      status.textContent = documentsReady
        ? "Dokumen tersedia. Periksa sebelum mengonfirmasi."
        : "Verifikasi tidak tersedia karena dokumen gagal dimuat.";

      updateButton();
    });

    button.addEventListener("click", async () => {
      if (
        !documentsReady ||
        !identity.input.checked ||
        !payment.input.checked ||
        submitting
      ) {
        return;
      }

      const confirmed = window.confirm(
        `Setujui identitas dan pembayaran booking ` +
        `${booking.booking_code}?`
      );

      if (!confirmed) return;

      submitting = true;
      button.textContent = "Verifying...";
      updateButton();

      try {
        await postAdmin("bookings/verify.php", {
          booking_id: booking.booking_id,
          identity_confirmed: true,
          payment_confirmed: true
        });

        closeModal();
        await loadDashboard();

      } catch (error) {
        status.textContent = error.message;
        submitting = false;
        button.textContent = "Verify Payment";
        updateButton();
      }
    });

    panel.append(
      heading,
      identity.label,
      payment.label,
      status,
      button
    );

    modalActions.appendChild(panel);
  }

  function closeModal() {
    destroyDeliveryMap();

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
