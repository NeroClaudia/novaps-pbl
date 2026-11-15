
/*
 * NovaPS - Customer Registration
 * Backend: PHP + PostgreSQL
 */
(() => {
  const API_BASE = "/api";
  const form = document.getElementById("signup-form");

  if (!form) return;

  const button = form.querySelector(
    'button[type="submit"]'
  );

  function showError(input, message) {
    const field = input.closest("[data-field]");
    if (!field) return;

    field.toggleAttribute("data-invalid", !!message);

    const error = field.querySelector("[data-error]");
    if (error) error.textContent = message;
  }

  function clearErrors() {
    form.querySelectorAll("[data-field]").forEach(field => {
      field.removeAttribute("data-invalid");

      const error = field.querySelector("[data-error]");
      if (error) error.textContent = "";
    });
  }

  form.addEventListener("submit", async event => {
    event.preventDefault();
    clearErrors();

    const fullName = form.elements.fullname;
    const phone = form.elements.phone;
    const email = form.elements.email;
    const address = form.elements.address;
    const password = form.elements.password;
    const confirm = form.elements.confirm;

    const fields = [
      fullName,
      phone,
      email,
      address,
      password,
      confirm
    ];

    if (!fields.every(input => NovaPS.checkField(input, form))) {
      return;
    }

    const passwordValue = password.value;

    if (passwordValue.length > 72) {
      showError(password, "Password terlalu panjang.");
      return;
    }

    button.disabled = true;
    button.textContent = "Creating account...";

    try {
      const response = await fetch(
        `${API_BASE}/auth/register.php`,
        {
          method: "POST",
          credentials: "same-origin",
          headers: {
            "Content-Type": "application/json"
          },
          body: JSON.stringify({
            full_name: fullName.value.trim(),
            phone: phone.value.trim(),
            email: email.value.trim(),
            address: address.value.trim(),
            password: passwordValue
          })
        }
      );

      const result = await response.json();

      if (!response.ok || result.success !== true) {
        throw new Error(
          result.message || "Registrasi gagal."
        );
      }

      // Registrasi tidak otomatis login.
      // Customer masuk menggunakan kredensial barunya.
      window.location.replace(
        "login.html?registered=1"
      );

    } catch (error) {
      showError(email, error.message);

      button.disabled = false;
      button.textContent = "Sign Up";
    }
  });
})();
