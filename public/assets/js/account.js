
/*
 * NovaPS - My Account
 * Profil disinkronkan dengan backend PHP.
 */
(function () {
  const API_BASE = "/api";
  const PROFILE_URL = `${API_BASE}/me/profile.php`;

  const form = document.getElementById("profile-form");
  const editButton = document.getElementById("edit-profile");
  const actions = document.getElementById("account-actions");
  const cancelButton = document.getElementById("cancel-field");
  const saveButton = actions.querySelector('[type="submit"]');
  const banner = document.getElementById("edit-banner");
  const toast = document.getElementById("toast");

  const fileInput = document.getElementById("idcard");
  const typeInput = document.getElementById("identity_doc_type");
  const upload = form.querySelector("[data-upload]");
  const preview = upload.querySelector("img");
  const identityStatus = document.getElementById("identity-status");

  const fields = {
    full_name: document.getElementById("fullname"),
    phone: document.getElementById("phone"),
    email: document.getElementById("email"),
    address: document.getElementById("address")
  };

  let profile = null;
  let editing = false;
  let previewUrl = null;

  function notify(message) {
    toast.textContent = message;
    toast.setAttribute("data-open", "");

    setTimeout(() => {
      toast.removeAttribute("data-open");
    }, 3000);
  }

  function revokePreview() {
    if (previewUrl) {
      URL.revokeObjectURL(previewUrl);
      previewUrl = null;
    }
  }

  function displayImage(src) {
    if (src) {
      preview.src = src;

      // Tampilkan preview secara eksplisit
      preview.classList.remove("hidden");
      preview.style.display = "block";
      preview.style.width = "100%";
      preview.style.height = "100%";
      preview.style.objectFit = "contain";

      upload.setAttribute("data-has-image", "");
      upload.style.minHeight = "160px";
    } else {
      preview.removeAttribute("src");

      preview.style.display = "none";
      preview.classList.add("hidden");

      upload.removeAttribute("data-has-image");
      upload.style.minHeight = "";
    }
  }

  function render() {
    if (!profile) return;

    fields.full_name.value = profile.full_name || "";
    fields.phone.value = profile.phone || "";
    fields.email.value = profile.email || "";
    fields.address.value = profile.address || "";

    typeInput.value = profile.identity_doc_type || "";
    fileInput.value = "";

    revokePreview();

    if (profile.identity_doc_uploaded) {
      displayImage(`${PROFILE_URL}?image=1&t=${Date.now()}`);
    } else {
      displayImage("");
    }

    if (!profile.identity_doc_uploaded) {
      identityStatus.textContent = "Identity document not uploaded.";
    } else if (profile.identity_doc_verified) {
      identityStatus.textContent = "Identity document verified.";
    } else {
      identityStatus.textContent =
        "Identity document pending admin verification.";
    }

    // Menyelaraskan data yang masih dipakai UI frontend lama.
    const displayProfile = {
      fullname: profile.full_name,
      phone: profile.phone,
      email: profile.email || "",
      address: profile.address || ""
    };

    NovaPS.profile.set(displayProfile);

    sessionStorage.setItem(
      "novaps_customer",
      JSON.stringify(profile)
    );
  }

  function setEditing(value) {
    editing = value;

    Object.values(fields).forEach(input => {
      input.readOnly = !editing;
    });

    fileInput.disabled = !editing;
    typeInput.disabled = !editing;

    editButton.hidden = editing;
    actions.hidden = !editing;

    banner.hidden = !editing;
    banner.textContent = editing
      ? "You can edit your account information below."
      : "";

    if (editing) {
      fields.full_name.focus();
    }
  }

  async function loadProfile() {
    try {
      const response = await fetch(PROFILE_URL, {
        method: "GET",
        credentials: "same-origin",
        cache: "no-store"
      });

      if (response.status === 401 || response.status === 403) {
        location.replace("/pages/login.php");
        return;
      }

      const result = await response.json();

      if (response.status === 401 || response.status === 403) {
        location.replace("/pages/login.php");
        return;
      }

      if (!response.ok || result.success !== true) {
        throw new Error(result.message || "Failed to load profile.");
      }

      profile = result.data;
      render();
      setEditing(false);

    } catch (error) {
      notify(error.message);
      console.error("Profile load error:", error);
    }
  }

  editButton.addEventListener("click", () => {
    if (!profile) return;
    setEditing(true);
  });

  cancelButton.addEventListener("click", () => {
    setEditing(false);
    render();
  });

  preview.addEventListener("error", () => {
    if (previewUrl) return;

    // File demo lama mungkin belum tersedia di server.
    displayImage("");
    identityStatus.textContent =
      "Image unavailable. Upload a new ID card to replace it.";
  });

  fileInput.addEventListener("change", () => {
    const file = fileInput.files[0];

    revokePreview();

    if (!file) {
      render();
      return;
    }

    if (!["image/jpeg", "image/png"].includes(file.type)) {
      notify("Only JPG and PNG files are accepted.");
      fileInput.value = "";
      return;
    }

    if (file.size > 2 * 1024 * 1024) {
      notify("Image must not exceed 2 MB.");
      fileInput.value = "";
      return;
    }

    previewUrl = URL.createObjectURL(file);
    displayImage(previewUrl);
  });

  form.addEventListener("submit", async event => {
    event.preventDefault();

    if (!editing || !profile) return;

    const name = fields.full_name.value.trim();
    const phone = fields.phone.value.trim();
    const email = fields.email.value.trim();
    const address = fields.address.value.trim();

    if (!name || !phone || !address) {
      notify("Name, phone and address are required.");
      return;
    }

    if (!/^08\d{8,13}$/.test(phone.replace(/[\s-]/g, ""))) {
      notify("Please enter a valid phone number.");
      return;
    }

    if (email && !fields.email.checkValidity()) {
      notify("Please enter a valid email address.");
      return;
    }

    const file = fileInput.files[0];

    if (file && !typeInput.value) {
      notify("Select the identity document type first.");
      return;
    }

    const data = new FormData();

    data.append("full_name", name);
    data.append("phone", phone);
    data.append("email", email);
    data.append("address", address);
    data.append("identity_doc_type", typeInput.value);

    if (file) {
      data.append("identity_doc", file);
    }

    saveButton.disabled = true;
    saveButton.textContent = "Saving...";

    try {
      const response = await fetch(PROFILE_URL, {
        method: "POST",
        credentials: "same-origin",
        body: data
      });

      const result = await response.json();

      if (!response.ok || result.success !== true) {
        throw new Error(result.message || "Failed to update profile.");
      }

      profile = result.data;

      setEditing(false);
      render();
      notify("Profile successfully updated.");

    } catch (error) {
      notify(error.message);
      console.error("Profile save error:", error);

    } finally {
      saveButton.disabled = false;
      saveButton.textContent = "Save Changes";
    }
  });

  // Endpoint penggantian password yang aman belum dibuat.
  // Jangan menjalankan mekanisme password demo lama.
  const passwordButton = document.getElementById("change-password");

  if (passwordButton) {
    passwordButton.disabled = true;
    passwordButton.title =
      "Change password will be enabled after backend integration.";
  }

  function initializeProfile() {
    const initialProfile = document.getElementById("initial-profile");

    if (!initialProfile) {
      loadProfile();
      return;
    }

    try {
      const data = JSON.parse(initialProfile.textContent);

      if (!data || !data.user_id) {
        throw new Error("Invalid initial profile");
      }

      profile = data;

      render();
      setEditing(false);

    } catch (error) {
      console.error("Initial profile error:", error);
      loadProfile();
    }
  }

  initializeProfile();
})();
