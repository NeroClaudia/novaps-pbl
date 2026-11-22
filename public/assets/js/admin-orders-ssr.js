
/*
 * NovaPS - Manage Orders SSR
 * Menggunakan API admin dan PHP Session.
 */
(() => {
  "use strict";

  const API = "/api/admin/bookings/list.php";
  const PAGE_SIZE = 10;

  const search = document.getElementById("search");
  const filter = document.getElementById("status-filter");
  const head = document.getElementById("orders-head");
  const body = document.getElementById("orders-body");
  const empty = document.getElementById("orders-empty");
  const count = document.getElementById("orders-count");
  const previous = document.getElementById("prev-page");
  const next = document.getElementById("next-page");
  const pageLabel = document.getElementById("page-label");

  if (!search || !filter || !head || !body) return;

  let page = 1;
  let requestVersion = 0;
  let searchTimer = null;

  const money = value => new Intl.NumberFormat("id-ID", {
    style: "currency",
    currency: "IDR",
    maximumFractionDigits: 0
  }).format(Number(value) || 0);

  const formatDate = value => {
    if (!value) return "—";

    const date = new Date(value);

    return Number.isNaN(date.getTime())
      ? "—"
      : date.toLocaleString("id-ID", {
          timeZone: "Asia/Jakarta",
          dateStyle: "medium",
          timeStyle: "short"
        });
  };

  const filters = {
    not_paid: ["payment_status", "Pending"],
    pending: ["payment_status", "AwaitingVerification"],
    shipped: ["fulfillment_status", "Shipped"],
    complete: ["fulfillment_status", "Complete"],
    canceled: ["fulfillment_status", "Cancelled"],
    rejected: ["payment_status", "Rejected"],
    returned: ["status", "Returned"],
    overdue: ["status", "Overdue"]
  };

  function statusText(order) {
    const payment = order.payment_status;
    const fulfillment = order.fulfillment_status;
    const rentals = order.status_count || {};

    if (payment === "Rejected") return "Rejected";
    if (payment === "Expired") return "Expired";
    if (payment === "Cancelled") return "Canceled";
    if (fulfillment === "Cancelled") return "Canceled";

    if (payment === "Pending") return "Not Paid";
    if (payment === "AwaitingVerification") {
      return "Awaiting Verification";
    }

    if (Number(rentals.Overdue) > 0) return "Overdue";
    if (
      Number(rentals.Returned) > 0 &&
      Number(rentals.Returned) === Number(order.items_count)
    ) {
      return "Returned";
    }

    if (fulfillment === "Shipped") return "Shipped";
    if (fulfillment === "Complete") return "Complete";

    return fulfillment || "—";
  }

  function makeCell(value) {
    const cell = document.createElement("td");
    cell.className =
      "border-b border-slate-300 px-2 py-3 align-middle";
    cell.textContent = String(value ?? "—");
    return cell;
  }

  function makeStackCell(values) {
    const cell = makeCell("");
    const container = document.createElement("div");

    container.className = "grid gap-1";

    for (const value of values) {
      const line = document.createElement("span");
      line.textContent = String(value);
      container.appendChild(line);
    }

    if (values.length === 0) {
      const line = document.createElement("span");
      line.textContent = "—";
      container.appendChild(line);
    }

    cell.appendChild(container);
    return cell;
  }

  function renderHeader() {
    head.replaceChildren();

    const row = document.createElement("tr");

    for (const label of [
      "Customer Name",
      "Order ID",
      "Unit Type",
      "Total Unit",
      "Duration",
      "Total",
      "Date",
      "Status",
      "Action"
    ]) {
      const th = document.createElement("th");
      th.className =
        "border-b border-slate-300 px-2 py-3 text-left text-sm";
      th.textContent = label;
      row.appendChild(th);
    }

    head.appendChild(row);
  }

      const orderModal = document.getElementById("orderModal");
    const orderBody = document.getElementById("orderBody");
    const orderActions = document.getElementById("orderActions");

    function addOrderDetail(label, value) {
      const row = document.createElement("p");
      row.className = "mb-2 text-sm text-slate-700";

      const heading = document.createElement("strong");
      heading.textContent = `${label}: `;

      row.append(
        heading,
        document.createTextNode(String(value ?? "—"))
      );

      orderBody.appendChild(row);
    }

    async function openOrderDetail(bookingId) {
      const response = await fetch(
        `/api/admin/bookings/detail.php?booking_id=${encodeURIComponent(bookingId)}`,
        {
          credentials: "same-origin",
          cache: "no-store"
        }
      );

      if (response.status === 401 || response.status === 403) {
        location.replace("/admin/login.php");
        return;
      }

      const result = await response.json();

      if (!response.ok || result.success !== true) {
        throw new Error(
          result.message || "Gagal mengambil detail pesanan."
        );
      }

      const booking = result.data;

      orderBody.replaceChildren();
      orderActions.replaceChildren();

      addOrderDetail("Booking", booking.booking_code);
      addOrderDetail("Customer", booking.customer?.full_name);
      addOrderDetail("Phone", booking.customer?.phone);
      addOrderDetail("Address", booking.address_snapshot);
      addOrderDetail("Payment Method", booking.payment?.method);
      addOrderDetail("Payment Status", booking.payment?.status);
      addOrderDetail("Delivery Status", booking.fulfillment_status);
      addOrderDetail("Total", money(booking.total_cost));

      if (booking.additional_notes) {
        addOrderDetail("Notes", booking.additional_notes);
      }

      if (booking.cancel_reason) {
        addOrderDetail("Cancel Reason", booking.cancel_reason);
      }

      const title = document.createElement("h3");
      title.className = "mt-4 mb-2 font-semibold";
      title.textContent = "Rental Items";
      orderBody.appendChild(title);

      for (const item of booking.items || []) {
        addOrderDetail(
          item.device_name,
          `${item.duration_days} hari · ${item.rental_status} · ${money(item.total_cost)}`
        );
      }

      document.getElementById("rejectBox").hidden = true;

      orderModal.setAttribute("data-open", "");
      orderModal.setAttribute("aria-hidden", "false");
    }

    function closeOrderDetail() {
      orderModal.removeAttribute("data-open");
      orderModal.setAttribute("aria-hidden", "true");
    }

    orderModal.querySelector("[data-close]")
      ?.addEventListener("click", closeOrderDetail);

    orderModal.addEventListener("click", event => {
      if (event.target === orderModal) {
        closeOrderDetail();
      }
    });

    document.addEventListener("keydown", event => {
      if (
        event.key === "Escape" &&
        orderModal.hasAttribute("data-open")
      ) {
        closeOrderDetail();
      }
    });

  function renderRows(orders) {
    body.replaceChildren();

    for (const order of orders) {
      const row = document.createElement("tr");

      const items = Array.isArray(order.items_summary)
        ? order.items_summary
        : [];

      const unitNames = items.map(item =>
        item.device_name || item.category || "Unknown unit"
      );

      const durations = items.map(item => {
        const days = Number(item.duration_days);
        return Number.isFinite(days)
          ? `${days} hari`
          : "—";
      });

      row.appendChild(makeCell(order.full_name));
      row.appendChild(makeCell(order.booking_code));

      row.appendChild(makeStackCell(unitNames));
      row.appendChild(makeCell(order.items_count));
      row.appendChild(makeStackCell(durations));

      row.appendChild(makeCell(money(order.total_cost)));
      row.appendChild(makeCell(formatDate(order.created_at)));
      row.appendChild(makeCell(statusText(order)));

      const actions = makeCell("");

      const button = document.createElement("button");

      button.type = "button";
      button.className = "btn btn-indigo px-3 py-1 text-sm";
      button.textContent = "Detail";

      button.addEventListener("click", async () => {
        button.disabled = true;

        try {
          await openOrderDetail(order.booking_id);
        } catch (error) {
          alert(error.message);
        } finally {
          button.disabled = false;
        }
      });

      actions.appendChild(button);
      row.appendChild(actions);
      body.appendChild(row);
    }

    empty.hidden = orders.length > 0;
    empty.textContent = "No orders found.";
  }

  function queryString() {
    const params = new URLSearchParams({
      page: String(page),
      per_page: String(PAGE_SIZE)
    });

    const term = search.value.trim();

    if (term) {
      params.set("search", term);
    }

    const selected = filters[filter.value];

    if (selected) {
      params.set(selected[0], selected[1]);
    }

    if (filter.value === "canceled") {
      params.set("exclude_rejected", "true");
    }

    return params.toString();
  }

  async function loadOrders() {
    const version = ++requestVersion;

    empty.hidden = false;
    empty.textContent = "Loading orders...";

    previous.disabled = true;
    next.disabled = true;

    try {
      const response = await fetch(
        `${API}?${queryString()}`,
        {
          credentials: "same-origin",
          cache: "no-store"
        }
      );

      if (response.status === 401 || response.status === 403) {
        location.replace("/admin/login.php");
        return;
      }

      const result = await response.json();

      if (!response.ok || result.success !== true) {
        throw new Error(
          result.message || "Gagal memuat pesanan."
        );
      }

      // Abaikan respons request sebelumnya.
      if (version !== requestVersion) return;

      const orders = Array.isArray(result.data)
        ? result.data
        : [];

      const total = Number(result.meta?.total) || 0;
      const totalPages = Math.max(
        1,
        Math.ceil(total / PAGE_SIZE)
      );

      // Jika data berkurang setelah perubahan status.
      if (page > totalPages) {
        page = totalPages;
        await loadOrders();
        return;
      }

      renderRows(orders);

      count.textContent =
        `${total} booking ditemukan`;

      pageLabel.textContent =
        `Page ${page} of ${totalPages}`;

      previous.disabled = page <= 1;
      next.disabled = page >= totalPages;

    } catch (error) {
      if (version !== requestVersion) return;

      body.replaceChildren();
      empty.hidden = false;
      empty.textContent = error.message;
      count.textContent = "Gagal memuat data";
      pageLabel.textContent = "—";
    }
  }

  search.addEventListener("input", () => {
    clearTimeout(searchTimer);

    searchTimer = setTimeout(() => {
      page = 1;
      loadOrders();
    }, 350);
  });

  filter.addEventListener("change", () => {
    page = 1;

    const url = new URL(location.href);

    if (filter.value === "all") {
      url.searchParams.delete("status");
    } else {
      url.searchParams.set("status", filter.value);
    }

    history.replaceState(null, "", url);
    loadOrders();
  });

  previous.addEventListener("click", () => {
    if (page <= 1) return;

    page--;
    loadOrders();
  });

  next.addEventListener("click", () => {
    if (next.disabled) return;

    page++;
    loadOrders();
  });

  // Filter dari kartu status dashboard.
  const requestedStatus =
    new URLSearchParams(location.search).get("status");

  if (requestedStatus && filters[requestedStatus]) {
    filter.value = requestedStatus;
  }

  const adminButton = document.getElementById("adminBtn");

  adminButton?.addEventListener("click", () => {
    const wrapper = adminButton.parentElement;
    const open = wrapper.hasAttribute("data-open");

    wrapper.toggleAttribute("data-open", !open);
    adminButton.setAttribute("aria-expanded", String(!open));
  });

  document.getElementById("adminLogout")?.addEventListener(
    "click",
    async () => {
      try {
        const response = await fetch("/api/admin/logout.php", {
          method: "POST",
          credentials: "same-origin",
          cache: "no-store"
        });

        if (!response.ok) throw new Error("Logout gagal");

        location.replace("/admin/login.php");
      } catch (error) {
        alert(error.message);
      }
    }
  );

  renderHeader();
  loadOrders();
})();
