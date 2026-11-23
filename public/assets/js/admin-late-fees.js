(() => {
  "use strict";

  const body = document.getElementById("late-fee-body");
  const message = document.getElementById("late-fee-message");
  const refreshButton = document.getElementById("refresh-late-fees");

  if (!body || !message || !refreshButton) return;

  const csrf = document.querySelector(
    'meta[name="admin-csrf"]'
  )?.content || "";

  const money = amount =>
    new Intl.NumberFormat("id-ID", {
      style: "currency",
      currency: "IDR",
      maximumFractionDigits: 0
    }).format(amount);

  async function readResponse(response) {
    const result = await response.json();

    if (!response.ok || result.success !== true) {
      throw new Error(result.message || "Request gagal");
    }

    return result.data;
  }

  function makeCell(row, value) {
    const cell = document.createElement("td");
    cell.textContent = String(value ?? "—");
    row.appendChild(cell);
    return cell;
  }

  async function markAsPaid(rental) {
    const confirmed = window.confirm(
      `Konfirmasi pembayaran denda Cash?\n\n` +
      `Booking: ${rental.booking_code}\n` +
      `Device: ${rental.device_name}\n` +
      `Nominal: ${money(rental.late_fee)}\n\n` +
      `Lanjutkan hanya jika uang sudah diterima.`
    );

    if (!confirmed) return;

    message.textContent = "Mencatat pembayaran...";

    try {
      const response = await fetch(
        "/api/admin/rentals/pay-late-fee.php",
        {
          method: "POST",
          credentials: "same-origin",
          cache: "no-store",
          headers: {
            "Content-Type": "application/json",
            "X-CSRF-Token": csrf
          },
          body: JSON.stringify({
            rental_id: rental.rental_id
          })
        }
      );

      await readResponse(response);
      await loadLateFees();

      message.textContent =
        "Pembayaran denda berhasil dicatat.";

    } catch (error) {
      await loadLateFees();
      message.textContent = error.message;
    }
  }

  async function loadLateFees() {
    refreshButton.disabled = true;
    message.textContent = "Loading late fees...";
    body.replaceChildren();

    try {
      const response = await fetch(
        "/api/admin/rentals/unpaid-late-fees.php",
        {
          credentials: "same-origin",
          cache: "no-store"
        }
      );

      const rentals = await readResponse(response);

      for (const rental of rentals) {
        const row = document.createElement("tr");

        makeCell(row, rental.full_name);
        makeCell(row, rental.booking_code);
        makeCell(row, rental.device_name);
        makeCell(row, money(rental.late_fee));

        const actionCell = document.createElement("td");
        const button = document.createElement("button");

        button.type = "button";
        button.className = "time-btn";
        button.textContent = "Mark as Paid";

        button.addEventListener("click", async () => {
          button.disabled = true;
          try {
            await markAsPaid(rental);
          } finally {
            button.disabled = false;
          }
        });

        actionCell.appendChild(button);
        row.appendChild(actionCell);
        body.appendChild(row);
      }

      message.textContent = rentals.length
        ? `${rentals.length} tagihan denda belum lunas.`
        : "Tidak ada denda yang belum dilunasi.";

    } catch (error) {
      message.textContent = error.message;
    } finally {
      refreshButton.disabled = false;
    }
  }

  refreshButton.addEventListener("click", loadLateFees);

  // Sinkronisasi ketika admin kembali ke halaman.
  document.addEventListener("visibilitychange", () => {
    if (!document.hidden) loadLateFees();
  });

  loadLateFees();
})();
