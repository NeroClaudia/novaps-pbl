(() => {
  "use strict";

  const $ = id => document.getElementById(id);
  const modal = $("extension-modal");

  if (!modal) return;

  let requests = [];
  let selected = null;
  let busy = false;

  const csrf = document.querySelector(
    'meta[name="admin-csrf"]'
  )?.content || "";

  const money = value =>
    new Intl.NumberFormat("id-ID", {
      style: "currency",
      currency: "IDR",
      maximumFractionDigits: 0
    }).format(Number(value) || 0);

  async function readJSON(response) {
    if (response.status === 401 || response.status === 403) {
      throw new Error("Sesi atau izin admin tidak valid.");
    }

    const data = await response.json();

    if (!response.ok || data.success !== true) {
      throw new Error(
        data.message || "Permintaan gagal diproses."
      );
    }

    return data.data;
  }

  function detailLine(label, value) {
    const dt = document.createElement("dt");
    const dd = document.createElement("dd");

    dt.textContent = label;
    dd.textContent = String(value ?? "—");

    $("extension-detail").append(dt, dd);
  }

  function review(request) {
    selected = request;

    $("extension-detail").replaceChildren();
    $("extension-error").textContent = "";
    $("extension-confirm-payment").checked = false;

    detailLine("Customer", request.customer_name);
    detailLine("Booking", request.booking_code);
    detailLine("Device", request.device_name);
    detailLine("Current Duration", `${request.current_days} days`);
    detailLine("Extra Duration", `${request.extra_days} days`);
    detailLine("Total Duration", `${request.total_days} days`);
    detailLine("Additional Cost", money(request.additional_cost));
    detailLine(
      "New Return Date",
      new Date(request.proposed_end_time).toLocaleString("id-ID", {
        timeZone: "Asia/Jakarta"
      })
    );

    const proof = $("extension-proof");

    proof.src =
      `/api/admin/rentals/extension-proof.php?extension_id=${request.extension_id}`;

    proof.style.display = "block";
    proof.onerror = () => {
      $("extension-error").textContent =
        "Bukti pembayaran tidak dapat ditampilkan.";
      proof.style.display = "none";
    };

    $("extension-review").hidden = false;
    $("extension-approve").disabled = true;

    $("extension-review").scrollIntoView({
      behavior: "smooth",
      block: "nearest"
    });
  }

  async function loadRequests() {
    $("extension-message").textContent = "Loading...";
    $("extension-review").hidden = true;
    selected = null;

    const response = await fetch(
      "/api/admin/rentals/extensions.php",
      {
        credentials: "same-origin",
        cache: "no-store"
      }
    );

    requests = await readJSON(response);

    $("extension-count").textContent = String(requests.length);
    $("extension-list").replaceChildren();

    for (const request of requests) {
      const row = document.createElement("div");

      row.style.cssText =
        "display:flex;justify-content:space-between;" +
        "align-items:center;gap:12px;padding:14px;" +
        "border:1px solid #e2e8f0;border-radius:8px";

      const info = document.createElement("div");
      const name = document.createElement("strong");
      const description = document.createElement("p");

      name.textContent = request.customer_name;
      description.className = "time-note";
      description.textContent =
        `${request.device_name} · +${request.extra_days} days · ` +
        money(request.additional_cost);

      info.append(name, description);

      const button = document.createElement("button");
      button.type = "button";
      button.className = "time-btn";
      button.textContent = "Review";
      button.addEventListener("click", () => review(request));

      row.append(info, button);
      $("extension-list").appendChild(row);
    }

    $("extension-message").textContent =
      requests.length
        ? `${requests.length} request(s) awaiting verification.`
        : "No pending extension requests.";
  }

  function closeModal() {
    if (busy) return;

    modal.hidden = true;
    $("extension-proof").removeAttribute("src");
    $("extension-review").hidden = true;
    selected = null;
  }

  async function decide(action) {
    if (!selected || busy) return;

    if (
      action === "approve" &&
      !$("extension-confirm-payment").checked
    ) {
      $("extension-error").textContent =
        "Konfirmasi pembayaran terlebih dahulu.";
      return;
    }

    const isApprove = action === "approve";
    const extension = selected;

    const confirmed = await Popup.confirm(
        `${isApprove ? "Approve" : "Reject"} ` +
        `extension #${extension.extension_id}?`,
        {
            title: isApprove
                ? "Approve Extension?"
                : "Reject Extension?",

            confirmText: isApprove
                ? "Approve"
                : "Reject",

            cancelText: "Cancel",
            danger: !isApprove
        }
    );

    if (
        !confirmed ||
        busy ||
        modal.hidden ||
        selected !== extension
    ) {
        return;
    }

    if (
        isApprove &&
        !$("extension-confirm-payment").checked
    ) {
        $("extension-error").textContent =
            "Konfirmasi pembayaran terlebih dahulu.";

        return;
    }

    busy = true;
    $("extension-error").textContent = "";
    $("extension-approve").disabled = true;
    $("extension-reject").disabled = true;

    try {
      const response = await fetch(
        "/api/admin/rentals/extension-decision.php",
        {
          method: "POST",
          credentials: "same-origin",
          headers: {
            "Content-Type": "application/json",
            "X-CSRF-Token": csrf
          },
          body: JSON.stringify({
            extension_id: selected.extension_id,
            action,
            payment_confirmed:
              action === "approve"
                ? $("extension-confirm-payment").checked
                : false
          })
        }
      );

      await readJSON(response);
      await loadRequests();

      // Refresh Time Remaining tanpa mengubah halaman.
      $("refresh-rentals").click();

    } catch (error) {
      $("extension-error").textContent = error.message;
    } finally {
      busy = false;
      $("extension-approve").disabled = !selected;
      $("extension-reject").disabled = false;
    }
  }

  $("review-extensions").addEventListener("click", async () => {
    modal.hidden = false;

    try {
      await loadRequests();
    } catch (error) {
      $("extension-message").textContent = error.message;
    }
  });

  $("extension-close").addEventListener("click", closeModal);

  $("extension-confirm-payment").addEventListener("change", event => {
    $("extension-approve").disabled = !event.target.checked;
  });

  $("extension-approve").addEventListener("click", () => decide("approve"));
  $("extension-reject").addEventListener("click", () => decide("reject"));

  modal.addEventListener("click", event => {
    if (event.target === modal) closeModal();
  });

  document.addEventListener("keydown", event => {
    if (event.key === "Escape" && !modal.hidden) {
      closeModal();
    }
  });

  // Ambil jumlah pengajuan saat halaman admin dibuka.
  loadRequests().catch(error => {
    $("extension-message").textContent = error.message;
  });
})();
