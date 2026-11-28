
(() => {
  "use strict";

  const $ = id => document.getElementById(id);

  const search = $("search-users");
  const tbody = $("users-body");
  const message = $("users-message");
  const modal = $("userModal");

  let page = 1;
  let totalPages = 1;
  let requestVersion = 0;
  let searchTimer = null;
  let lastFocused = null;

  const PAGE_SIZE = 20;

  function cell(value) {
    const td = document.createElement("td");
    td.textContent = String(value ?? "—");
    return td;
  }

  function identityStatus(user) {
    if (user.identity_doc_verified) {
      return ["Verified", "users-verified"];
    }

    if (user.has_identity_doc) {
      return ["Pending", "users-pending"];
    }

    return ["Not Uploaded", "users-missing"];
  }

  function detailLine(label, value) {
    const dt = document.createElement("dt");
    const dd = document.createElement("dd");

    dt.textContent = label;
    dd.textContent = String(value ?? "—");

    $("user-detail").append(dt, dd);
  }

  function closeModal() {
    modal.hidden = true;
    lastFocused?.focus();
  }

  function openModal(user) {
    lastFocused = document.activeElement;

    $("user-detail").replaceChildren();

    const [identity] = identityStatus(user);

    detailLine("User ID", user.user_id);
    detailLine("Name", user.full_name);
    detailLine("Email", user.email || "—");
    detailLine("WhatsApp", user.phone || "—");
    detailLine("Address", user.address || "—");
    detailLine("Identity Type", user.identity_doc_type || "—");
    detailLine("Identity Status", identity);
    detailLine("Total Orders", user.total_orders);
    detailLine("Completed Orders", user.completed_orders);

    modal.hidden = false;
    $("close-user-modal").focus();
  }

  function renderRows(users) {
    tbody.replaceChildren();

    for (const user of users) {
      const row = document.createElement("tr");

      row.appendChild(cell(user.full_name));
      row.appendChild(cell(user.email || "—"));
      row.appendChild(cell(user.phone || "—"));
      row.appendChild(cell(user.total_orders));

      const statusCell = cell("");
      const badge = document.createElement("span");
      const [label, className] = identityStatus(user);

      badge.className = `users-badge ${className}`;
      badge.textContent = label;
      statusCell.appendChild(badge);
      row.appendChild(statusCell);

      const actionCell = cell("");
      const button = document.createElement("button");

      button.type = "button";
      button.className = "users-btn";
      button.textContent = "Detail";
      button.addEventListener("click", () => openModal(user));

      actionCell.appendChild(button);
      row.appendChild(actionCell);

      tbody.appendChild(row);
    }

    $("users-empty").hidden = users.length !== 0;
  }

  async function loadUsers() {
    const version = ++requestVersion;

    $("refresh-users").disabled = true;
    message.textContent = "Loading customers...";

    try {
      const params = new URLSearchParams({
        page: String(page),
        per_page: String(PAGE_SIZE),
        search: search.value.trim()
      });

      const response = await fetch(
        `/api/admin/users/list.php?${params}`,
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
          result.message || "Gagal mengambil data customer"
        );
      }

      // Abaikan respons request lama.
      if (version !== requestVersion) return;

      page = Number(result.meta.page);
      totalPages = Number(result.meta.total_pages);

      const users = Array.isArray(result.data)
        ? result.data
        : [];

      renderRows(users);

      $("users-count").textContent =
        `${result.meta.total} customer ditemukan`;

      $("users-page").textContent =
        `Page ${page} of ${totalPages}`;

      $("users-prev").disabled = page <= 1;
      $("users-next").disabled = page >= totalPages;

      message.textContent =
        `Menampilkan ${users.length} customer`;

    } catch (error) {
      if (version !== requestVersion) return;

      tbody.replaceChildren();
      $("users-empty").hidden = true;
      message.textContent = error.message;
      $("users-count").textContent = "Gagal memuat data";

    } finally {
      if (version === requestVersion) {
        $("refresh-users").disabled = false;
      }
    }
  }

  // Debounce pencarian agar tidak mengirim request
  // pada setiap karakter yang diketik.
  search.addEventListener("input", () => {
    clearTimeout(searchTimer);
    page = 1;
    searchTimer = setTimeout(loadUsers, 350);
  });

  $("refresh-users").addEventListener("click", loadUsers);

  $("users-prev").addEventListener("click", () => {
    if (page <= 1) return;
    page--;
    loadUsers();
  });

  $("users-next").addEventListener("click", () => {
    if (page >= totalPages) return;
    page++;
    loadUsers();
  });

  $("close-user-modal").addEventListener("click", closeModal);
  $("close-user-bottom").addEventListener("click", closeModal);

  modal.addEventListener("click", event => {
    if (event.target === modal) closeModal();
  });

  document.addEventListener("keydown", event => {
    if (event.key === "Escape" && !modal.hidden) {
      closeModal();
    }
  });

  // Header bersama NovaPS.
  const adminButton = $("adminBtn");

  adminButton?.addEventListener("click", () => {
    const wrapper = adminButton.parentElement;
    const isOpen = wrapper.hasAttribute("data-open");

    wrapper.toggleAttribute("data-open", !isOpen);
    adminButton.setAttribute(
      "aria-expanded",
      String(!isOpen)
    );
  });

  $("adminLogout")?.addEventListener("click", async () => {
    try {
      const response = await fetch("/api/admin/logout.php", {
        method: "POST",
        credentials: "same-origin",
        cache: "no-store"
      });

      if (!response.ok) {
        throw new Error("Logout gagal");
      }

      location.replace("/admin/login.php");
    } catch (error) {
      await Popup.error(error.message, "Logout Failed");
    }
  });

  loadUsers();
})();
