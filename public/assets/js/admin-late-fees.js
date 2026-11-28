(() => {
  "use strict";

  const $ = id => document.getElementById(id);

  const body = $("late-fee-body");
  const message = $("late-fee-message");
  const refreshButton = $("refresh-late-fees");
  const modal = $("late-fee-modal");
  const detail = $("late-fee-detail");
  const qris = $("late-fee-qris");
  const instructions = $("late-fee-instructions");
  const errorText = $("late-fee-error");
  const submitButton = $("late-fee-submit");

  if (!body || !message || !refreshButton || !modal) return;

  const csrf = document.querySelector(
    'meta[name="admin-csrf"]'
  )?.content || "";

  const money = amount => new Intl.NumberFormat("id-ID", {
    style: "currency",
    currency: "IDR",
    maximumFractionDigits: 0
  }).format(amount);

  let selectedRental = null;
  let submitting = false;
  let loading = false;

  async function readResponse(response) {
    const result = await response.json();

    if (!response.ok || result.success !== true) {
      throw new Error(
        result.message || result.error?.message || "Request gagal"
      );
    }

    return result.data;
  }

  function makeCell(row, value) {
    const cell = document.createElement("td");
    cell.textContent = String(value ?? "—");
    row.appendChild(cell);
    return cell;
  }

  function addDetail(label, value) {
    const dt = document.createElement("dt");
    const dd = document.createElement("dd");

    dt.textContent = label;
    dd.textContent = value;
    detail.append(dt, dd);
  }

  function getPaymentMethod() {
    return document.querySelector(
      'input[name="late-fee-method"]:checked'
    )?.value || "Cash";
  }

  function updateMethodView() {
    const method = getPaymentMethod();

    qris.hidden = method !== "QRIS";

    instructions.textContent = method === "QRIS"
      ? "Check the recipient's transaction history and confirm " +
        "the exact amount before marking this fee as paid."
      : "Confirm only after receiving the exact cash amount.";
  }

  function openModal(rental) {
    selectedRental = rental;
    detail.replaceChildren();

    addDetail("Customer", rental.full_name);
    addDetail("Booking", rental.booking_code);
    addDetail("Device", rental.device_name);
    addDetail("Late Fee", money(rental.late_fee));

    document.querySelector(
      'input[name="late-fee-method"][value="Cash"]'
    ).checked = true;

    errorText.textContent = "";
    submitButton.disabled = false;
    updateMethodView();

    modal.hidden = false;
  }

  function closeModal() {
    if (submitting) return;

    modal.hidden = true;
    selectedRental = null;
    errorText.textContent = "";
  }

  async function confirmPayment() {
    if (!selectedRental || submitting) return;

    const rental = selectedRental;
    const method = getPaymentMethod();

    const confirmed = await Popup.confirm(
        `Confirm ${method} late fee payment?\n\n` +
        `Booking: ${rental.booking_code}\n` +
        `Device: ${rental.device_name}\n` +
        `Amount: ${money(rental.late_fee)}\n\n` +
        "Only continue if payment has been received and verified.",
        {
            title: "Confirm Late Fee Payment",
            confirmText: "Confirm Payment",
            cancelText: "Cancel"
        }
    );

    if (
        !confirmed ||
        submitting ||
        modal.hidden ||
        selectedRental !== rental ||
        getPaymentMethod() !== method
    ) {
        return;
    }

    submitting = true;
    submitButton.disabled = true;
    submitButton.textContent = "Processing...";
    errorText.textContent = "";

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
            rental_id: rental.rental_id,
            payment_method: method
          })
        }
      );

      if (response.status === 401 || response.status === 403) {
        throw new Error(
          "Session expired or access denied. Please log in again."
        );
      }

      await readResponse(response);

      modal.hidden = true;
      selectedRental = null;

      submitting = false;
      await loadLateFees();
      
      message.textContent =
        `Late fee successfully paid via ${method}.`;
        
    } catch (error) {
      errorText.textContent = error.message;
    } finally {
      submitting = false;
      submitButton.disabled = false;
      submitButton.textContent = "Confirm Payment";
    }
  }

  async function loadLateFees() {
    if (loading || submitting) return;

    loading = true;
    refreshButton.disabled = true;
    body.replaceChildren();
    message.textContent = "Loading late fees...";

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

        const action = document.createElement("td");
        const button = document.createElement("button");

        button.type = "button";
        button.className = "time-btn";
        button.textContent = "Receive Payment";

        button.addEventListener("click", () => {
          openModal(rental);
        });

        action.appendChild(button);
        row.appendChild(action);
        body.appendChild(row);
      }

      message.textContent = rentals.length
        ? `${rentals.length} unpaid late fee(s).`
        : "No unpaid late fees.";

    } catch (error) {
      message.textContent = error.message;
    } finally {
      loading = false;
      refreshButton.disabled = false;
    }
  }

  document.querySelectorAll(
    'input[name="late-fee-method"]'
  ).forEach(radio => {
    radio.addEventListener("change", updateMethodView);
  });

  $("late-fee-close").addEventListener("click", closeModal);
  $("late-fee-cancel").addEventListener("click", closeModal);
  submitButton.addEventListener("click", confirmPayment);
  refreshButton.addEventListener("click", loadLateFees);

  modal.addEventListener("click", event => {
    if (event.target === modal) closeModal();
  });

  document.addEventListener("keydown", event => {
    if (event.key === "Escape" && !modal.hidden) {
      closeModal();
    }
  });

  document.addEventListener("visibilitychange", () => {
    if (!document.hidden && modal.hidden) {
      loadLateFees();
    }
  });

  loadLateFees();
})();
