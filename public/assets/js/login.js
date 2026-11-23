
/*
 * NovaPS - Customer Login
 * Authentication: PHP Session
 * Login menggunakan email atau nomor HP.
 */
(() => {
  const API_BASE = "/api";

  const form = document.getElementById("login-form");

  if (!form) {
    console.error("Form login tidak ditemukan.");
    return;
  }

  const button = form.querySelector('button[type="submit"]');
  const identifierInput = form.querySelector("#identifier");
  const passwordInput = form.querySelector("#password");

  if (!identifierInput || !passwordInput || !button) {
    console.error("Elemen login tidak lengkap.");
    return;
  }

  function showError(message) {
    const field = identifierInput.closest("[data-field]");

    if (field) {
      field.setAttribute("data-invalid", "");

      const errorElement = field.querySelector("[data-error]");

      if (errorElement) {
        errorElement.textContent = message;
      }
    }

    console.error("Login error:", message);
  }

  function clearError() {
    const field = identifierInput.closest("[data-field]");

    if (field) {
      field.removeAttribute("data-invalid");

      const errorElement = field.querySelector("[data-error]");

      if (errorElement) {
        errorElement.textContent = "";
      }
    }
  }

  form.addEventListener("submit", async event => {
    event.preventDefault();
    clearError();

    const identifier = identifierInput.value.trim();
    const password = passwordInput.value;

    if (!identifier || !password) {
      showError("Email/nomor HP dan password wajib diisi.");
      return;
    }

    button.disabled = true;
    button.textContent = "Please wait…";

    try {
      // 1. Login customer melalui backend.
      const response = await fetch(
        `${API_BASE}/auth/login.php`,
        {
          method: "POST",
          credentials: "same-origin",
          cache: "no-store",
          headers: {
            "Content-Type": "application/json"
          },
          body: JSON.stringify({
            identifier,
            password
          })
        }
      );

      const result = await response.json();

      if (!response.ok || result.success !== true) {
        throw new Error(
          result.message || "Login gagal."
        );
      }

      const user = result.data;

      // 2. Pastikan PHP session benar-benar aktif.
      const profileResponse = await fetch(
        `${API_BASE}/me/profile.php`,
        {
          method: "GET",
          credentials: "same-origin",
          cache: "no-store"
        }
      );

      const profileResult = await profileResponse.json();

      if (!profileResponse.ok || profileResult.success !== true) {
        throw new Error(
          "Login berhasil, tetapi session tidak terbaca."
        );
      }

      // 3. Sinkronisasi sementara dengan sistem UI lama.
      // Nantinya diganti dengan session berbasis backend.
      const localEmail =
        `customer-${user.user_id}@local.test`;

      NovaPS.login(localEmail, {
        fullname: user.full_name,
        phone: user.phone,
        email: user.email || localEmail
      });

      sessionStorage.setItem(
        "novaps_customer",
        JSON.stringify(user)
      );

      // Bersihkan data pesanan demo.
      localStorage.removeItem("novaps_orders");

      // 4. Redirect ke homepage.
      window.location.replace("/index.php");

    } catch (error) {
      showError(error.message);

      button.disabled = false;
      button.textContent = "Login";
    }
  });
})();
