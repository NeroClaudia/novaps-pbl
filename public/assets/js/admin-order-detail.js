/*
 * NovaPS - Shared Admin Order Detail
 * Digunakan oleh Dashboard dan Manage Orders.
 */

window.NovaOrderDetail = (() => {
  "use strict";

  const API = "/api/admin";

  const el = id => document.getElementById(id);

  const rupiah = value =>
    new Intl.NumberFormat("id-ID", {
      style: "currency",
      currency: "IDR",
      maximumFractionDigits: 0
    }).format(Number(value) || 0);

  let onChanged = async () => {};

  function setOnChanged(callback) {
    onChanged = typeof callback === "function"
      ? callback
      : async () => {};
  }

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
      throw new Error(
        result.message || "Gagal mengambil data."
      );
    }

    return result;
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
      const confirmed = await window.confirm(`Pastikan perangkat booking ${booking.booking_code} ` 
          + "sudah diterima customer. Lanjutkan?", 
          {
            title: "Confirm Delivery",
            confirmText: "Confirm Delivery",
            cancelText: "Cancel"
          }
        );

      if (!confirmed || button.disabled || !button.isConnected || !modal.hasAttribute("data-open")) {
        return;
      } 

      button.disabled = true;
      button.textContent = "Memproses...";

      try {
        await postAdmin("bookings/deliver.php", {
          booking_id: booking.booking_id,
          delivery_confirmed: true
        });

        closeModal();
        await onChanged();

      } catch (error) {
        await Popup.error(error.message, "Delivery Confirmation Failed");
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
    
    if (booking.additional_notes) {
      addDetailRow(
        modalBody,
        "Additional Notes",
        booking.additional_notes
      );
    }
  
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

    if (booking.cancel_reason) {
      addDetailRow(
        modalBody,
        "Alasan Penolakan / Pembatalan",
        booking.cancel_reason
      );
    }

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
    renderRejectAction(booking);

    // Pada tahap ini modal masih read-only.
    // Verifikasi dan deliver disambungkan setelah UI diuji.
    modal.setAttribute("data-open", "");
    modal.setAttribute("aria-hidden", "false");
  }

  function renderRejectAction(booking) {
    const rejectBox = document.getElementById("rejectBox");
    const rejectReason = document.getElementById("rejectReason");
    const rejectError = document.getElementById("rejectError");
    const confirmReject = document.getElementById("confirmReject");

    if (!rejectBox || !rejectReason || !rejectError || !confirmReject) {
      return;
    }

    rejectBox.hidden = true;
    rejectReason.value = "";
    rejectError.textContent = "";
    confirmReject.textContent = "Confirm Reject";
    confirmReject.onclick = null;

    if (!booking.verification?.can_reject) return;

    const rejectButton = document.createElement("button");

    rejectButton.type = "button";
    rejectButton.className = "btn bg-red-600 px-4 py-2 text-sm font-semibold text-white";
    rejectButton.textContent = "Reject";

    rejectButton.addEventListener("click", () => {
      rejectBox.hidden = false;
      rejectReason.focus();
    });

    confirmReject.onclick = async function rejectHandler() {
      const reason = rejectReason.value.trim();

      if (reason.length < 5 || reason.length > 200) {
        rejectError.textContent = "Alasan penolakan harus 5 sampai 200 karakter";
        return;
      }

      const confirmed = await window.confirm(
        `Tolak booking ${booking.booking_code}?\n\n` +
        `Alasan ${reason}\n\n` +
        "Tindakan ini akan membatalkan rental",
        {
          title: "Reject Booking?",
          confirmText: "Reject Booking",
          cancelText: "Cancel",
          danger: true
        }
      );

      if (!confirmed || confirmReject.disabled || !modal.hasAttribute("data-open") 
        || confirmReject.onclick !== rejectHandler || rejectReason.value.trim() !== reason) {
        return;
      }

      confirmReject.disabled = true;
      confirmReject.textContent = "Rejecting...";
      rejectError.textContent = "";

      try {
        await postAdmin("bookings/reject.php", {
          booking_id: booking.booking_id,
          reason
        });

        closeModal();
        await onChanged();

      } catch (error) {
        rejectError.textContent = error.message;
        confirmReject.disabled = false;
        confirmReject.textContent = "Confirm Reject";
      }
    };

    modalActions.appendChild(rejectButton);
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

      const confirmed = await window.confirm(
        `Setujui identitas dan pembayaran booking ` +
        `${booking.booking_code}?`,
        {
          title: "Verify Booking?",
          confirmText: "Verify Booking",
          cancelText: "Cancel"
        }
      );

      if (!confirmed || !documentsReady || !identity.input.checked 
        || !payment.input.checked || submitting || !button.isConnected || !modal.hasAttribute("data-open")) {
          return;
        }

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
        await onChanged();

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

    const rejectBox = document.getElementById("rejectBox");

    if (rejectBox) {
      rejectBox.hidden = true;
    }

    modal?.removeAttribute("data-open");
    modal?.setAttribute("aria-hidden", "true");
  }

  document.addEventListener("keydown", event => {
    if (
      event.key === "Escape" &&
      modal?.hasAttribute("data-open")
    ) {
      closeModal();
    }
  });

  modal?.querySelector("[data-close]")?.addEventListener("click", closeModal);

  modal?.addEventListener("click", event => {
    if (event.target === modal) {
        closeModal();
    }
  });

  return {
    open: openBooking,
    close: closeModal,
    setOnChanged
  };
})();
