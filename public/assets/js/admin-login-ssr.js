(() => {
  "use strict";

  const form = document.getElementById("admin-login-form");
  const button = document.getElementById("login-button");
  const errorElement = document.getElementById("login-error");

  if (!form || !button || !errorElement) return;

  form.addEventListener("submit", async (event) => {
    event.preventDefault();

    errorElement.textContent = "";

    const username = form.elements.username.value.trim();
    const password = form.elements.password.value;

    if (!username || !password) {
      errorElement.textContent =
        "Username dan password wajib diisi.";
      return;
    }

    button.disabled = true;
    button.textContent = "Logging in...";

    try {
      const response = await fetch("/api/admin/login.php", {
        method: "POST",
        credentials: "same-origin",
        cache: "no-store",
        headers: {
          "Content-Type": "application/json"
        },
        body: JSON.stringify({
          username,
          password
        })
      });

      const result = await response.json();

      if (!response.ok || result.success !== true) {
        throw new Error(
          result.message || "Login admin gagal."
        );
      }

      if (
        !["admin", "owner"].includes(result.data?.role)
      ) {
        throw new Error("Role akun tidak diizinkan.");
      }

      // Backend telah menetapkan PHP session.
      // Jangan menyimpan token atau password di localStorage.
      window.location.replace("/admin/dashboard.php");

    } catch (error) {
      errorElement.textContent =
        error.message || "Terjadi kesalahan login.";

      button.disabled = false;
      button.textContent = "Login";
    }
  });
})();
