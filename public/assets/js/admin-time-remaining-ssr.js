(() => {
  "use strict";

  const $ = id => document.getElementById(id);

  const search = $("search");
  const filter = $("status-filter");
  const tbody = $("tr-body");
  const modal = $("return-modal");
  const csrf = document.querySelector(
    'meta[name="admin-csrf"]'
  )?.content || "";

  const HOUR = 3600000;

  let rentals = [];
  let selectedRental = null;
  let loading = false;
  let returning = false;
  let clockOffset = 0;

  const money = value => new Intl.NumberFormat("id-ID", {
    style: "currency",
    currency: "IDR",
    maximumFractionDigits: 0
  }).format(Number(value) || 0);

  const dateText = value => {
    const date = new Date(value);

    return Number.isNaN(date.getTime())
      ? "—"
      : date.toLocaleString("id-ID", {
          timeZone: "Asia/Jakarta",
          dateStyle: "medium",
          timeStyle: "short"
        });
  };

  function currentTime() {
    return Date.now() + clockOffset;
  }

  function timeLeft(rental) {
    return Date.parse(rental.end_time) - currentTime();
  }

  function estimatedLateFee(rental) {
    const overdueMs = Math.max(0, -timeLeft(rental));
    const lateHours = Math.ceil(overdueMs / HOUR);

    return lateHours * 10000;
  }

  function formatDuration(ms) {
    const totalSeconds = Math.floor(Math.abs(ms) / 1000);
    const days = Math.floor(totalSeconds / 86400);
    const hours = Math.floor((totalSeconds % 86400) / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;

    const hhmmss = [hours, minutes, seconds]
      .map(value => String(value).padStart(2, "0"))
      .join(":");

    return days > 0
      ? `${days}d ${hhmmss}`
      : hhmmss;
  }

  function countdownText(ms) {
    return ms <= 0
      ? `Overdue ${formatDuration(ms)}`
      : formatDuration(ms);
  }

  function countdownClass(ms) {
    if (ms <= 0) return "time-pill time-danger";
    if (ms <= HOUR) return "time-pill time-warning";
    return "time-pill time-ok";
  }

  function matchesFilter(rental) {
    const ms = timeLeft(rental);
    const status = filter.value;

    if (status === "overdue" && ms > 0) return false;
    if (status === "h1" && !(ms > 0 && ms <= HOUR)) {
      return false;
    }
    if (status === "h4" && !(ms > 0 && ms <= 4 * HOUR)) {
      return false;
    }
    if (status === "over4" && ms <= 4 * HOUR) {
      return false;
    }

    const keyword = search.value.trim().toLowerCase();

    if (!keyword) return true;

    return [
      rental.full_name,
      rental.phone,
      rental.email,
      rental.booking_code,
      rental.device_name,
      rental.category
    ].some(value =>
      String(value || "").toLowerCase().includes(keyword)
    );
  }

  function makeCell(row, content) {
    const cell = document.createElement("td");
    cell.textContent = String(content ?? "—");
    row.appendChild(cell);
    return cell;
  }

  function renderCounters() {
    const counts = {
      all: rentals.length,
      h4: 0,
      h1: 0,
      overdue: 0
    };

    for (const rental of rentals) {
      const ms = timeLeft(rental);

      if (ms <= 0) {
        counts.overdue++;
      } else {
        if (ms <= 4 * HOUR) counts.h4++;
        if (ms <= HOUR) counts.h1++;
      }
    }

    document.querySelectorAll("[data-chip]").forEach(chip => {
      chip.querySelector("[data-chip-value]").textContent =
        counts[chip.dataset.chip] ?? 0;

      chip.setAttribute(
        "aria-pressed",
        String(filter.value === chip.dataset.chip)
      );
    });
  }

  function renderRows() {
    tbody.replaceChildren();

    const visible = rentals
      .filter(matchesFilter)
      .sort((a, b) =>
        Date.parse(a.end_time) - Date.parse(b.end_time)
      );

    for (const rental of visible) {
      const row = document.createElement("tr");

      row.dataset.rentalId = String(rental.rental_id);

      makeCell(row, rental.full_name);
      makeCell(row, rental.booking_code);
      makeCell(row, rental.device_name);
      makeCell(row, `${rental.duration_days} hari`);
      makeCell(
        row,
        dateText(rental.rented_at || rental.start_time)
      );

      const countdownCell = makeCell(row, "");
      const pill = document.createElement("span");
      const ms = timeLeft(rental);

      pill.className = countdownClass(ms);
      pill.textContent = countdownText(ms);
      pill.dataset.endTime = rental.end_time;
      countdownCell.appendChild(pill);

      const action = makeCell(row, "");
      const button = document.createElement("button");
      button.type = "button";
      button.className = "time-btn";
      button.textContent = "Detail";
      button.addEventListener("click", () => openReturnModal(rental));

      action.appendChild(button);
      tbody.appendChild(row);
    }

    $("tr-empty").hidden = visible.length > 0;
    renderCounters();
  }

    function tick() {
    const visible = rentals
        .filter(matchesFilter)
        .sort((a, b) =>
        Date.parse(a.end_time) - Date.parse(b.end_time)
        );

    const visibleIds = visible.map(r => String(r.rental_id));
    const renderedIds = [...tbody.querySelectorAll("tr")]
        .map(row => row.dataset.rentalId);

    if (JSON.stringify(visibleIds) !== JSON.stringify(renderedIds)) {
        renderRows();
        return;
    }

    tbody.querySelectorAll("[data-end-time]").forEach(pill => {
        const ms = Date.parse(pill.dataset.endTime) - currentTime();

        pill.className = countdownClass(ms);
        pill.textContent = countdownText(ms);
    });

    renderCounters();
    }

  async function readJSON(response) {
    const result = await response.json();

    if (!response.ok || result.success !== true) {
      throw new Error(
        result.message || "Permintaan gagal diproses."
      );
    }

    return result;
  }

  async function loadRentals() {
    if (loading || returning) return;

    loading = true;
    $("refresh-rentals").disabled = true;
    $("tr-message").textContent = "Memperbarui data...";

    try {
      const response = await fetch(
        "/api/admin/rentals/active.php",
        {
          credentials: "same-origin",
          cache: "no-store"
        }
      );

      if (response.status === 401 || response.status === 403) {
        location.replace("/admin/login.php");
        return;
      }

      const result = await readJSON(response);

      const serverTime = Date.parse(result.meta.server_now);

      if (Number.isFinite(serverTime)) {
        clockOffset = serverTime - Date.now();
      }

      rentals = Array.isArray(result.data)
        ? result.data
        : [];

      renderRows();

      $("tr-message").textContent =
        `${rentals.length} rental aktif. ` +
        `Diperbarui ${new Date().toLocaleTimeString("id-ID", {
          timeZone: "Asia/Jakarta",
          hour: "2-digit",
          minute: "2-digit"
        })}`;

    } catch (error) {
      $("tr-message").textContent = error.message;
    } finally {
      loading = false;
      $("refresh-rentals").disabled = false;
    }
  }

  function addDetail(label, value) {
    const dt = document.createElement("dt");
    const dd = document.createElement("dd");

    dt.textContent = label;
    dd.textContent = String(value ?? "—");

    $("return-detail").append(dt, dd);
  }

  function openReturnModal(rental) {
    selectedRental = rental;
    $("return-detail").replaceChildren();

    addDetail("Customer", rental.full_name);
    addDetail("Phone", rental.phone);
    addDetail("Booking", rental.booking_code);
    addDetail("Device", rental.device_name);
    addDetail("Duration", `${rental.duration_days} hari`);
    addDetail("Start", dateText(rental.start_time));
    addDetail("End", dateText(rental.end_time));
    addDetail("Status", rental.rental_status);
    addDetail("Total", money(rental.total_cost));

    const lateFee = estimatedLateFee(rental);

    addDetail("Estimated Late Fee", money(lateFee));

    if (lateFee > 0) {
      addDetail("Late Fee Payment", "Unpaid — Cash payment required");
    }

    $("return-condition").value = "";
    $("return-error").textContent = "";
    $("return-submit").disabled = false;

    modal.hidden = false;
    $("return-condition").focus();
  }

  function closeReturnModal() {
    if (returning) return;

    modal.hidden = true;
    selectedRental = null;
  }

  async function submitReturn() {
    if (!selectedRental || returning) return;

    const rental = selectedRental;
    const condition = $("return-condition").value.trim();

    if (condition.length > 100) {
      $("return-error").textContent =
        "Kondisi perangkat maksimal 100 karakter.";
      return;
    }

    const lateFee = estimatedLateFee(rental);

    const confirmationMessage =
      `Konfirmasi pengembalian ${rental.device_name}?\n\n` +
      `Booking: ${rental.booking_code}\n` +
      `Estimasi denda: ${money(lateFee)}\n\n` +
      (lateFee > 0
        ? "Denda belum otomatis dianggap lunas."
        : "Tidak ada denda keterlambatan.");

    if (!confirm(confirmationMessage)) {
      return;
    }

    returning = true;
    $("return-submit").disabled = true;
    $("return-submit").textContent = "Processing...";
    $("return-error").textContent = "";

    try {
      const response = await fetch(
        "/api/admin/bookings/return.php",
        {
          method: "POST",
          credentials: "same-origin",
          cache: "no-store",
          headers: {
            "Content-Type": "application/json",
            "X-CSRF-Token": csrf
          },
          body: JSON.stringify({
            rental_id: rental.rental_id,
            condition_at_return: condition || null
          })
        }
      );

      if (response.status === 401 || response.status === 403) {
        location.replace("/admin/login.php");
        return;
      }

      await readJSON(response);

      modal.hidden = true;
      selectedRental = null;

      // Data harus diambil ulang setelah Return sukses.
      returning = false;
      await loadRentals();

    } catch (error) {
      $("return-error").textContent = error.message;
    } finally {
      returning = false;
      $("return-submit").disabled = false;
      $("return-submit").textContent = "Confirm Return";
    }
  }

  // Filter dan pencarian tanpa reload halaman.
  search.addEventListener("input", renderRows);
  filter.addEventListener("change", renderRows);

  document.querySelectorAll("[data-chip]").forEach(chip => {
    chip.addEventListener("click", () => {
      filter.value = chip.dataset.chip;
      renderRows();
    });
  });

  $("refresh-rentals").addEventListener("click", loadRentals);
  $("return-close").addEventListener("click", closeReturnModal);
  $("return-cancel").addEventListener("click", closeReturnModal);
  $("return-submit").addEventListener("click", submitReturn);

  modal.addEventListener("click", event => {
    if (event.target === modal) closeReturnModal();
  });

  document.addEventListener("keydown", event => {
    if (event.key === "Escape" && !modal.hidden) {
      closeReturnModal();
    }
  });

  // Menu akun dari shared layout.
  const adminBtn = $("adminBtn");

  adminBtn?.addEventListener("click", () => {
    const parent = adminBtn.parentElement;
    const open = parent.hasAttribute("data-open");

    parent.toggleAttribute("data-open", !open);
    adminBtn.setAttribute("aria-expanded", String(!open));
  });

  $("adminLogout")?.addEventListener("click", async () => {
    try {
      const response = await fetch("/api/admin/logout.php", {
        method: "POST",
        credentials: "same-origin"
      });

      if (!response.ok) throw new Error("Logout gagal");

      location.replace("/admin/login.php");
    } catch (error) {
      alert(error.message);
    }
  });

  setInterval(tick, 1000);

  // Polling database setiap 60 detik.
  setInterval(() => {
    if (!document.hidden && modal.hidden) {
      void loadRentals();
    }
  }, 60000);

  document.addEventListener("visibilitychange", () => {
    if (!document.hidden && modal.hidden) {
      void loadRentals();
    }
  });

  void loadRentals();
})();
