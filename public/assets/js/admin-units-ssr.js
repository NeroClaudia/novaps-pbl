(() => {
  "use strict";

  const $ = id => document.getElementById(id);
  const csrf = document.querySelector('meta[name="admin-csrf"]')?.content || "";
  const ps4Body = $("ps4-units-body");
  const ps5Body = $("ps5-units-body");
  const message = $("units-message");
  const modal = $("unitModal");
  const form = $("unit-form");

  let devices = [], editingId = null, submitting = false;
  let previousFocus = null;

  const rupiah = n => new Intl.NumberFormat("id-ID", { style: "currency", currency: "IDR", maximumFractionDigits: 0 }).format(Number(n) || 0);
  const field = name => $("f-" + name);

  async function request(path, data) {
    const response = await fetch('/api/admin/devices/' + path, {
      method: data ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store',
      headers: data ? { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf } : {},
      ...(data ? { body: JSON.stringify(data) } : {})
    });

    if (response.status === 401 || response.status === 403 && path === 'list.php') {
      location.replace('/admin/login.php'); throw Error('Sesi admin berakhir');
    }

    const result = await response.json();

    if (!response.ok || result.success !== true) throw Error(result.message || 'Permintaan gagal');

    return result.data;
  }
  
  function textCell(row, content) { 
    const td = document.createElement('td'); 
    td.textContent = String(content ?? '—'); 
    row.appendChild(td); 
    return td; 
  }
  
  function render() {
    ps4Body.replaceChildren();
    ps5Body.replaceChildren();

    $("stat-total").textContent = devices.length;

    $("stat-maintenance").textContent =
      devices.filter(d => d.status === "Maintenance").length;

    $("stat-rented").textContent =
      devices.filter(d => d.is_rented).length;

    $("stat-available").textContent =
      devices.filter(
        d => d.status === "Available" && !d.is_rented
      ).length;

    function renderCategory(category, target) {
      const filtered = devices
        .filter(d => d.category === category)
        .sort((a, b) =>
          a.device_name.localeCompare(
            b.device_name,
            undefined,
            { numeric: true }
          )
        );

      for (const device of filtered) {
        const row = document.createElement("tr");

        textCell(row, device.device_name);
        textCell(row, device.serial_number);
        textCell(row, rupiah(device.price_per_day));
        textCell(row, device.status);

        textCell(
          row,
          device.is_rented ? "Reserved / Rented" : "—"
        );

        const action = textCell(row, "");
        const editButton = document.createElement("button");

        editButton.type = "button";
        editButton.className = "units-secondary";
        editButton.textContent = "Edit";
        editButton.addEventListener(
          "click",
          () => openModal(device)
        );

        action.appendChild(editButton);
        target.appendChild(row);
      }

      if (filtered.length === 0) {
        const row = document.createElement("tr");
        const cell = textCell(
          row,
          `Belum ada perangkat ${category}.`
        );
        cell.colSpan = 6;
        target.appendChild(row);
      }
    }

    renderCategory("PS4", ps4Body);
    renderCategory("PS5", ps5Body);

    message.textContent =
      `${devices.length} perangkat ditemukan.`;
  }

  async function load() {
    message.textContent = 'Loading devices...';
    try { 
      devices = await request('list.php'); render(); 
    }
    catch (error) { 
      message.textContent = error.message; 
    }
  }

  function openModal(device = null, category = "PS4") {
    previousFocus = document.activeElement;
    editingId = device?.device_id ?? null;

    form.reset();
    $("unit-error").textContent = "";

    const selectedCategory = device?.category ?? category;

    field("category").value = selectedCategory;
    field("name").value = device?.device_name ?? "";
    field("price").value = device?.price_per_day ??
      (selectedCategory === "PS4" ? 50000 : 100000);
    field("status").value = device?.status ?? "Available";

    $("unitModalTitle").textContent = device
      ? `Edit ${selectedCategory} Device`
      : `Add ${selectedCategory} Device`;

    $("inventory-code-hint").textContent = device
      ? `Inventory Code: ${device.serial_number}`
      : "Inventory code will be generated automatically.";

    $("save-unit").textContent =
      device ? "Save Changes" : "Add Device";

    modal.hidden = false;
    field("name").focus();
  }

  function closeModal() { 
    if (submitting) return; 
    modal.hidden = true; 
    previousFocus?.focus(); 
  }

  document.querySelectorAll("[data-add-category]")
    .forEach(button => {
      button.addEventListener("click", () => {
        openModal(null, button.dataset.addCategory);
      });
    });

  $('reload-units').addEventListener('click', load);
  $('modal-close').addEventListener('click', closeModal);
  $('cancel-unit').addEventListener('click', closeModal);

  modal.addEventListener('click', event => { 
    if (event.target === modal) closeModal(); 
  });

  document.addEventListener('keydown', event => { 
    if (event.key === 'Escape' && !modal.hidden) closeModal(); 
  });

  form.addEventListener('submit', async event => {
    event.preventDefault(); if (submitting) return;
    
    const price = Number(field("price").value);

    const data = {
      device_name: field("name").value.trim(),
      category: field("category").value,
      price_per_day: price,
      status: field("status").value
    };

    if (
      !data.device_name ||
      !["PS4", "PS5"].includes(data.category) ||
      !["Available", "Maintenance"].includes(data.status) ||
      !Number.isInteger(price) ||
      price < 1 ||
      price > 99999999
    ) {
      $("unit-error").textContent =
        "Lengkapi nama, harga harian, dan status yang valid.";
      return;
    }

    if (editingId !== null) {
      data.device_id = editingId;
    }

    submitting = true; $('save-unit').disabled = true; $('unit-error').textContent = '';

    try {
      await request(editingId === null ? 'create.php' : 'update.php', data);
      modal.hidden = true; previousFocus?.focus(); await load();

    } catch (error) { 
      $('unit-error').textContent = error.message; 
    } finally { 
      submitting = false; 
      $('save-unit').disabled = false; 
    }
  });

  const accountButton = $("adminBtn");

  accountButton?.addEventListener("click", () => {
    const wrapper = accountButton.parentElement;
    const isOpen = wrapper.hasAttribute("data-open");

    wrapper.toggleAttribute("data-open", !isOpen);
    accountButton.setAttribute(
      "aria-expanded",
      String(!isOpen)
    );
  });
  
  $('adminLogout')?.addEventListener('click', async () => {
    try {
      const response = await fetch('/api/admin/logout.php', 
        { method: 'POST', credentials: 'same-origin' });

      if (!response.ok) throw Error('Logout gagal'); location.replace('/admin/login.php');

    } catch (e) { alert(e.message); }
  });

  load();

})();
