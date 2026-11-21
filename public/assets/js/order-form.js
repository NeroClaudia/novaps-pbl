
/*
 * NovaPS - Order Form
 * Integrasi frontend lokal dengan backend PHP.
 */
(function () {
  const API_BASE = "/api";

  const form = document.getElementById("order-form");
  const unitsEl = document.getElementById("units");
  const template = document.getElementById("unit-template");
  const addWrap = document.getElementById("add-unit-wrap");
  const checkout = document.getElementById("checkout");
  const hint = document.getElementById("checkout-hint");
  const formError = document.getElementById("form-error");
  const summary = document.getElementById("summary");
  const summaryRows = document.getElementById("summary-rows");
  const deliveryAddress = document.getElementById("address");
  const deliveryLatitude = document.getElementById("delivery-latitude");
  const deliveryLongitude = document.getElementById("delivery-longitude");

  const deliveryModeInputs = form.querySelectorAll('input[name="delivery_mode"]');
  const addressLabel = document.getElementById("delivery-address-label");
  const addressHint = document.getElementById("delivery-address-hint");

  if (!form || !template) {
    console.error("Elemen Order Form tidak lengkap.", {
      form,
      template
    });
    return;
  }

  const blocks = [];

  let availabilityRequest = 0;

  // Informasi customer hanya untuk tampilan.
  // Backend mengambil data customer dari session PHP.

  const initialData = JSON.parse(
    document.getElementById("initial-order-data").textContent
  );

  const customer = initialData.customer;

  function showMessage(message) {
    formError.textContent = message;
    formError.style.whiteSpace = "pre-line";
  }

  let addressDraft = deliveryAddress.value;
  let mapDraft = "";

  function getDeliveryMode() {
    return form.querySelector(
      'input[name="delivery_mode"]:checked'
    )?.value || "address";
  }

  function switchDeliveryMode(nextMode) {
    const currentMode = getDeliveryMode();

    // Simpan draft masing-masing mode.
    if (currentMode !== nextMode) {
      if (currentMode === "address") {
        addressDraft = deliveryAddress.value;
      } else {
        mapDraft = deliveryAddress.value;
      }

      deliveryModeInputs.forEach(radio => {
        radio.checked = radio.value === nextMode;
      });

      deliveryAddress.value =
        nextMode === "map" ? mapDraft : addressDraft;
    }

    const isMapMode = nextMode === "map";

    addressLabel.textContent = isMapMode
      ? "Patokan Tambahan (Opsional)"
      : "Alamat Pengiriman";

    deliveryAddress.placeholder = isMapMode
      ? "Contoh: Kos Melati, pagar hitam"
      : "Masukkan alamat pengiriman";

    addressHint.textContent = isMapMode
      ? "Pin pada peta adalah tujuan utama. " +
        "Isi patokan tambahan jika diperlukan."
      : "Alamat ini akan digunakan sebagai tujuan " +
        "pengiriman tanpa koordinat pin.";

    refresh();
  }

  // Customer mengganti pilihan secara manual.
  deliveryModeInputs.forEach(radio => {
    radio.addEventListener("change", () => {
      if (radio.checked) {
        switchDeliveryMode(radio.value);
      }
    });
  });

  async function getAPI(path) {
    const response = await fetch(`${API_BASE}/${path}`, {
      credentials: "same-origin",
      cache: "no-store"
    });

    const result = await response.json();

    if (!response.ok || result.success !== true) {
      throw new Error(result.message || "Gagal mengambil data.");
    }

    return result.data;
  }
  

function loadIdentityPreview() {
    const upload = form.querySelector("[data-upload]");
    const preview = upload?.querySelector("img");
    const customerHint = document.getElementById("customer-hint");

    if (!upload || !preview || !customerHint) return;

    upload.style.cursor = "default";
    upload.removeAttribute("data-has-image");

    preview.removeAttribute("src");
    preview.style.display = "none";

    if (!customer.identity_doc_uploaded) {
        customerHint.textContent =
            "ID Card belum diunggah. Silakan unggah melalui My Account.";

        return;
    }

    preview.onload = () => {
        preview.classList.remove("hidden");
        preview.style.display = "block";
        preview.style.width = "100%";
        preview.style.height = "100%";
        preview.style.objectFit = "contain";

        upload.setAttribute("data-has-image", "");
        upload.style.minHeight = "160px";
    };

    preview.onerror = () => {
        preview.style.display = "none";
        upload.removeAttribute("data-has-image");
        upload.style.minHeight = "";

        customerHint.textContent =
            "Foto ID Card gagal ditampilkan. Silakan cek My Account.";
    };

    preview.src =
        `${API_BASE}/me/profile.php?image=1&t=${Date.now()}`;

    customerHint.textContent = customer.identity_doc_verified
        ? "ID Card sudah diverifikasi oleh admin."
        : "ID Card sudah diunggah. Admin akan memverifikasinya bersama pembayaran.";
}

  async function postAPI(path, body) {
    const response = await fetch(`${API_BASE}/${path}`, {
      method: "POST",
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/json"
      },
      body: JSON.stringify(body)
    });

    const result = await response.json();

    if (!response.ok || result.success !== true) {
      throw new Error(result.message || "Checkout gagal.");
    }

    return result.data;
  }

  function unitOf(block) {
    const selected = block.querySelector(
        'input[type="radio"]:checked'
    );

    return selected ? selected.value : "";
  }

  function daysOf(block) {
    return Number(
        block.querySelector("[data-duration]").value
    );
  }


  function getCurrentWIB() {
    const parts = new Intl.DateTimeFormat("en-GB", {
      timeZone: "Asia/Jakarta",
      year: "numeric",
      month: "2-digit",
      day: "2-digit",
      hour: "2-digit",
      minute: "2-digit",
      second: "2-digit",
      hourCycle: "h23"
    }).formatToParts(new Date());

    const values = Object.fromEntries(
        parts.map(part => [part.type, part.value])
    );

    return (
        `${values.year}-${values.month}-${values.day}` +
        `T${values.hour}:${values.minute}:${values.second}`
    );
  }

  function renumber() {
    blocks.forEach((block, index) => {
      block.querySelector("[data-unit-title]").textContent =
          blocks.length > 1
              ? `Select a Unit #${index + 1}`
              : "Select a Unit";

      block.querySelector("[data-remove-unit]").hidden =
          index === 0;

      block.querySelectorAll('input[type="radio"]').forEach(
          radio => {
            radio.name = `unit-${index + 1}`;
          }
      );
    });

    addWrap.hidden = blocks.length >= 2;
  }

  function addBlock() {
    const block = template.content.firstElementChild.cloneNode(true);

    unitsEl.appendChild(block);
    blocks.push(block);

    block.addEventListener("change", () => {
      refresh();
      updateAvailability();
    });

    block.querySelector("[data-remove-unit]").addEventListener(
        "click",
        () => {
          const index = blocks.indexOf(block);

          blocks.splice(index, 1);
          block.remove();

          renumber();
          refresh();
          updateAvailability();
        }
    );

    renumber();
    refresh();

    return block;
  }

  document.getElementById("add-unit").addEventListener(
      "click",
      () => {
        addBlock();
        updateAvailability();
      }
  );

  const prices = {
    ps4: Number(initialData.prices.ps4),
    ps5: Number(initialData.prices.ps5)
  }

  function refresh() {
    const items = blocks.map(block => ({
      unit: unitOf(block),
      days: daysOf(block)
    }));

    form.querySelectorAll("[data-price]").forEach(el => {
      const price = prices[el.dataset.price];

      el.textContent = Number.isFinite(price) && price > 0
        ? NovaPS.money(price) + "/day"
        : "— /day";
    });

    blocks.forEach(block => {
      const selected = unitOf(block);
      const duration = block.querySelector("[data-duration]");

      duration.disabled = !selected;
    });

    const validItems = items.filter(item =>
        item.unit &&
        Number.isInteger(item.days) &&
        item.days >= 1 &&
        item.days <= 7 &&
        Number.isFinite(prices[item.unit]) &&
        prices[item.unit] > 0
    );

    summary.hidden = validItems.length === 0;

    if (validItems.length) {
      const total = validItems.reduce(
          (sum, item) =>
              sum + prices[item.unit] * item.days,
          0
      );

      NovaPS.renderRows(
          summaryRows,
          NovaPS.summaryRows({
            customer: {
              fullname: customer?.full_name || "-",
              address: getDeliveryMode() === "map" ? 
                ("Titik lokasi pada peta" + (deliveryAddress.value.trim() ? " — " + deliveryAddress.value.trim() : "")) 
                    : (deliveryAddress.value.trim() || "-")
            },
            items: validItems.map(item => ({
              unit: item.unit,
              days: item.days,
              price: prices[item.unit] * item.days
            })),
            total
          })
      );
    }

    const hasDeliveryAddress = deliveryAddress.value.trim() !== "";

    const hasDeliveryPin = deliveryLatitude.value !== "" && deliveryLongitude.value !== "";

  const deliveryMode = getDeliveryMode();

  const validDelivery = deliveryMode === "map"
    ? hasDeliveryPin
    : hasDeliveryAddress;

  const valid =
      customer.identity_doc_uploaded &&
      Boolean(customer.identity_doc_type) &&
      validDelivery &&
      items.length > 0 &&
      items.length === validItems.length;

    checkout.disabled = !valid;

    hint.textContent = !customer.identity_doc_uploaded
      ? "Upload ID Card melalui My Account terlebih dahulu."
      : !customer.identity_doc_type
        ? "Lengkapi jenis identitas melalui My Account."
        : !valid
            ? "Pilih PS dan durasi penyewaan."
            : "Waktu mulai sewa akan dihitung otomatis saat Checkout.";
  }

  deliveryAddress.addEventListener("input", refresh);

  function onDeliveryPinChange() {
    const hasPin =
      deliveryLatitude.value !== "" &&
      deliveryLongitude.value !== "";

    // Klik peta atau GPS otomatis mengaktifkan mode peta.
    if (hasPin && getDeliveryMode() !== "map") {
      switchDeliveryMode("map");
    } else {
      refresh();
    }
  }

  deliveryLatitude.addEventListener(
    "change",
    onDeliveryPinChange
  );

  deliveryLongitude.addEventListener(
    "change",
    onDeliveryPinChange
  );

  // Cari device_id nyata dari database.
  async function resolveItems(startTime) {
    const usedIds = new Set();
    const result = [];

    for (const block of blocks) {
      const category = unitOf(block).toUpperCase();
      const durationDays = daysOf(block);

      const params = new URLSearchParams({
        start_time: startTime,
        duration_days: String(durationDays),
        category
      });

      console.log("GET devices/available.php", params.toString());

      const devices = await getAPI(
          `devices/available.php?${params.toString()}`
      );

      const device = devices.find(
          item => !usedIds.has(Number(item.device_id))
      );

      if (!device) {
        throw new Error(
            `Tidak ada ${category} yang tersedia pada jadwal ini.`
        );
      }

      usedIds.add(Number(device.device_id));

      result.push({
        device_id: Number(device.device_id),
        duration_days: durationDays
      });
    }

    return result;
  }

  form.addEventListener("submit", async event => {
    event.preventDefault();

    console.log("CHECKOUT TERPANGGIL");
    showMessage("");

    if (checkout.disabled) return;

    checkout.disabled = true;
    checkout.textContent = "Creating booking...";

    try {
      const checkTime = getCurrentWIB();

      console.log("Waktu pengecekan:", checkTime);

      const items = await resolveItems(checkTime);

      console.log("Device terpilih:", items);

      const deliveryMode = getDeliveryMode();
      const address = deliveryAddress.value.trim();

      const latitudeText = deliveryLatitude.value;
      const longitudeText = deliveryLongitude.value;

      const hasPin =
        latitudeText !== "" &&
        longitudeText !== "";

      if (deliveryMode === "address" && !address) {
        throw new Error(
          "Alamat pengiriman wajib diisi."
        );
      }

      if (deliveryMode === "map" && !hasPin) {
        throw new Error(
          "Pilih titik pengiriman pada peta terlebih dahulu."
        );
      }

      const notes = document.getElementById("notes").value.trim();

      const payload = {
        payment_method: "QRIS",
        delivery_mode: deliveryMode,
        delivery_address: address,
        additional_notes: notes,
        items
      };

      // Koordinat hanya dikirim untuk mode peta.
      if (deliveryMode === "map") {
        payload.delivery_latitude = Number(latitudeText);
        payload.delivery_longitude = Number(longitudeText);
      }

      const booking = await postAPI(
        "bookings/create.php",
        payload
      );

      checkout.textContent = "Redirecting to payment...";
      checkout.disabled = true;

      window.location.replace("/pages/payment.php?order=" + encodeURIComponent(booking.booking_code));

    } catch (error) {
      console.error("CHECKOUT ERROR:", error);

      showMessage(error.message);

      checkout.disabled = false;
      checkout.textContent = "Checkout";
    }
  });

  const first = addBlock();

  const wanted = new URLSearchParams(
      location.search
  ).get("unit");

  if (wanted === "ps4" || wanted === "ps5") {
    const radio = first.querySelector(
        `input[value="${wanted}"]`
    );

    if (radio) {
      radio.checked = true;
    }
  }

  refresh();

  updateAvailability();

  document.addEventListener("visibilitychange", () => {
    if (!document.hidden) {
      updateAvailability();
    }
  });

  setInterval(() => {
    if (!document.hidden) {
      updateAvailability();
    }
  }, 15000);

async function updateAvailability() {
    const requestId = ++availabilityRequest;

    const startTime = getCurrentWIB();

    for (const block of blocks) {
        const selectedDays = daysOf(block);
        const days = selectedDays >= 1 ? selectedDays : 1;

        for (const radio of block.querySelectorAll(
            'input[type="radio"]'
        )) {
            const label = radio.closest("label")
                ?.querySelector("[data-available]");

            if (label) {
                label.textContent = "Checking...";
                label.className = "shrink-0 text-slate-500";
            }
        }

        for (const category of ["PS4", "PS5"]) {
            const params = new URLSearchParams({
                start_time: startTime,
                duration_days: String(days),
                category
            });

            try {
                const devices = await getAPI(
                    `devices/available.php?${params}`
                );

                if (requestId !== availabilityRequest) {
                    return;
                }

                const radio = block.querySelector(
                    `input[value="${category.toLowerCase()}"]`
                );

                const label = radio?.closest("label")
                    ?.querySelector("[data-available]");

                if (!radio || !label) continue;

                const count = devices.length;

                radio.disabled = count === 0;

                if (count === 0) {
                  label.textContent = "Out of stock";
                  label.style.color = "#dc2626";
                } else if (count <= 5) {
                  label.textContent = `${count} available`;
                  label.style.color = "#f97316";
                } else {
                  label.textContent = `${count} available`;
                  label.style.color = "#16a34a";
                }

                if (radio.disabled && radio.checked) {
                    radio.checked = false;
                    refresh();
                }

            } catch (error) {
                if (requestId !== availabilityRequest) return;

                console.error(
                    "Availability check failed:",
                    error
                );

                const radio = block.querySelector(
                    `input[value="${category.toLowerCase()}"]`
                );

                const label = radio?.closest("label")
                    ?.querySelector("[data-available]");

                if (radio) {
                    radio.disabled = false;
                }

                if (label) {
                    label.textContent = "Unable to check";
                    label.className = "shrink-0 text-slate-500";
                }
            }
        }
    }
}

  loadIdentityPreview();

  console.log("Order Form API integration loaded.");
})();
