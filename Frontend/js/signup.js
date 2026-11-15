
/*
 * NovaPS - Customer Registration
 *
 * Backend: PHP + PostgreSQL
 * Identity document: Private Supabase Storage
 */

(() => {
  const API_BASE = "/api";
  const MAX_FILE_SIZE = 2 * 1024 * 1024;

  const ALLOWED_TYPES = [
    "image/jpeg",
    "image/png"
  ];

  const form = document.getElementById("signup-form");

  if (!form) {
    console.error("Signup form tidak ditemukan.");
    return;
  }

  const button = form.querySelector(
    'button[type="submit"]'
  );

  const fields = {
    fullName: form.elements.fullname,
    phone: form.elements.phone,
    address: form.elements.address,
    email: form.elements.email,
    password: form.elements.password,
    confirm: form.elements.confirm,
    identityType: form.elements.identity_doc_type,
    identityFile: form.elements.identity_doc
  };

  const upload = form.querySelector("[data-upload]");
  const preview = form.querySelector("[data-preview]");
  const placeholder = form.querySelector(
    "[data-upload-placeholder]"
  );

  const fileName = form.querySelector(
    "[data-file-name]"
  );

  let previewUrl = null;
  let submitting = false;

  /* ---------- Field errors ---------- */

  function showError(input, message) {
    const field = input?.closest("[data-field]");

    if (!field) return;

    field.toggleAttribute(
      "data-invalid",
      Boolean(message)
    );

    const errorElement = field.querySelector(
      "[data-error]"
    );

    if (errorElement) {
      errorElement.textContent = message || "";
    }
  }

  function clearErrors() {
    form.querySelectorAll("[data-field]").forEach(
      field => {
        field.removeAttribute("data-invalid");

        const errorElement = field.querySelector(
          "[data-error]"
        );

        if (errorElement) {
          errorElement.textContent = "";
        }
      }
    );
  }

  /* ---------- Image preview ---------- */

  function clearPreview() {
    if (previewUrl) {
      URL.revokeObjectURL(previewUrl);
      previewUrl = null;
    }

    preview.removeAttribute("src");
    preview.classList.add("hidden");

    if (placeholder) {
      placeholder.classList.remove("hidden");
    }

    upload.removeAttribute("data-has-image");

    if (fileName) {
      fileName.textContent = "";
    }
  }

  function validateImage(file) {
    if (!file) {
      return "Please upload your ID Card.";
    }

    if (!ALLOWED_TYPES.includes(file.type)) {
      return "Only JPG and PNG images are accepted.";
    }

    if (file.size < 1) {
      return "The selected image is empty.";
    }

    if (file.size > MAX_FILE_SIZE) {
      return "Image must not exceed 2 MB.";
    }

    return "";
  }

  function showPreview(file) {
    clearPreview();

    previewUrl = URL.createObjectURL(file);

    preview.onload = () => {
      preview.classList.remove("hidden");

      if (placeholder) {
        placeholder.classList.add("hidden");
      }

      upload.setAttribute("data-has-image", "");
    };

    preview.onerror = () => {
      clearPreview();
      fields.identityFile.value = "";

      showError(
        fields.identityFile,
        "Unable to preview this image. " +
        "Please choose another file."
      );
    };

    preview.src = previewUrl;

    if (fileName) {
      fileName.textContent = file.name;
    }
  }

  fields.identityFile.addEventListener(
    "change",
    () => {
      const file = fields.identityFile.files?.[0];

      clearPreview();

      const error = validateImage(file);

      if (error) {
        showError(fields.identityFile, error);

        if (file) {
          fields.identityFile.value = "";
        }

        return;
      }

      showError(fields.identityFile, "");
      showPreview(file);
    }
  );

  window.addEventListener(
    "pagehide",
    clearPreview
  );

  /* ---------- Identity type validation ---------- */

  const allowedIdentityTypes = [
    "KTP",
    "SIM",
    "KTM",
    "Kartu Pelajar"
  ];

  function validateIdentityType() {
    const valid = allowedIdentityTypes.includes(
      fields.identityType.value
    );

    showError(
      fields.identityType,
      valid ? "" : "Please select an identity type."
    );

    return valid;
  }

  fields.identityType.addEventListener(
    "change",
    validateIdentityType
  );

  /* ---------- Form validation ---------- */

  function validateForm() {
    const textInputs = [
      fields.fullName,
      fields.phone,
      fields.address,
      fields.email,
      fields.password,
      fields.confirm
    ];

    let valid = true;

    for (const input of textInputs) {
      if (!NovaPS.checkField(input, form)) {
        valid = false;
      }
    }

    if (!validateIdentityType()) {
      valid = false;
    }

    const password = fields.password.value;

    if (new TextEncoder().encode(password).length > 72) {
      showError(
        fields.password,
        "Password must not exceed 72 bytes."
      );

      valid = false;
    }

    const file = fields.identityFile.files?.[0];
    const fileError = validateImage(file);

    if (fileError) {
      showError(fields.identityFile, fileError);
      valid = false;
    } else if (!previewUrl) {
      showError(
        fields.identityFile,
        "Please choose a valid image."
      );

      valid = false;
    }

    return valid;
  }

  /* ---------- API Registration ---------- */

  async function registerCustomer() {
    const data = new FormData();

    data.append(
      "full_name",
      fields.fullName.value.trim()
    );

    data.append(
      "phone",
      fields.phone.value.trim()
    );

    data.append(
      "email",
      fields.email.value.trim()
    );

    data.append(
      "address",
      fields.address.value.trim()
    );

    data.append(
      "password",
      fields.password.value
    );

    data.append(
      "identity_doc_type",
      fields.identityType.value
    );

    data.append(
      "identity_doc",
      fields.identityFile.files[0]
    );

    const response = await fetch(
      `${API_BASE}/auth/register.php`,
      {
        method: "POST",
        credentials: "same-origin",
        cache: "no-store",
        body: data
      }
    );

    let result;

    try {
      result = await response.json();
    } catch {
      throw new Error(
        `Server returned an invalid response ` +
        `(HTTP ${response.status}).`
      );
    }

    if (!response.ok || result.success !== true) {
      const error = new Error(
        result.message || "Registration failed."
      );

      error.code = result.code || "";
      error.status = response.status;

      throw error;
    }

    return result.data;
  }

  /* ---------- Submit ---------- */

  form.addEventListener(
    "submit",
    async event => {
      event.preventDefault();

      if (submitting) return;

      clearErrors();

      if (!validateForm()) {
        const firstInvalid = form.querySelector(
          "[data-invalid] input, " +
          "[data-invalid] select"
        );

        firstInvalid?.focus();
        return;
      }

      submitting = true;

      button.disabled = true;
      button.textContent = "Creating account...";

      try {
        const customer = await registerCustomer();

        console.log(
          "Customer registered:",
          customer.user_id
        );

        // Registration does not automatically
        // create a PHP login session.
        window.location.replace(
          "login.html?registered=1"
        );

      } catch (error) {
        console.error(
          "Registration failed:",
          error
        );

        if (
          error.status === 409 ||
          error.code === "DUPLICATE_ACCOUNT"
        ) {
          showError(
            fields.email,
            error.message
          );
        } else if (
          error.code === "IDENTITY_DOC_REQUIRED" ||
          error.code === "INVALID_FILE_TYPE" ||
          error.code === "INVALID_IMAGE" ||
          error.code === "UPLOAD_ERROR" ||
          error.status === 413
        ) {
          showError(
            fields.identityFile,
            error.message
          );
        } else {
          showError(
            fields.email,
            error.message
          );
        }

      } finally {
        submitting = false;
        button.disabled = false;
        button.textContent = "Sign Up";
      }
    }
  );
})();
