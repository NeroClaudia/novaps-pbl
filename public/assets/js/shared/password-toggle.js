(() => {
  "use strict";

  const inputs = document.querySelectorAll(
    '#login-form input[type="password"], ' +
    '#signup-form input[type="password"]'
  );

  if (!inputs.length) return;

  const eyeIcon = `
    <svg viewBox="0 0 24 24" width="20" height="20"
         fill="none" stroke="currentColor" stroke-width="1.8"
         stroke-linecap="round" stroke-linejoin="round"
         aria-hidden="true">
      <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/>
      <circle cx="12" cy="12" r="3"/>
    </svg>
  `;

  const eyeOffIcon = `
    <svg viewBox="0 0 24 24" width="20" height="20"
         fill="none" stroke="currentColor" stroke-width="1.8"
         stroke-linecap="round" stroke-linejoin="round"
         aria-hidden="true">
      <path d="M3 3l18 18"/>
      <path d="M10.6 5.1A11.8 11.8 0 0 1 12 5c6.5 0 10 7 10 7a16 16 0 0 1-3.2 4.1"/>
      <path d="M6.2 6.2C3.4 8.1 2 12 2 12s3.5 7 10 7a10.5 10.5 0 0 0 4-.8"/>
      <path d="M10 10a3 3 0 0 0 4 4"/>
    </svg>
  `;

  inputs.forEach(input => {
    const wrapper = document.createElement("div");
    wrapper.className = "password-input-wrapper";

    input.parentNode.insertBefore(wrapper, input);
    wrapper.appendChild(input);

    const toggle = document.createElement("button");
    toggle.type = "button";
    toggle.className = "password-toggle";
    toggle.setAttribute("aria-label", "Show password");
    toggle.setAttribute("aria-pressed", "false");
    toggle.setAttribute("aria-controls", input.id);
    toggle.innerHTML = eyeIcon;

    wrapper.appendChild(toggle);

    toggle.addEventListener("click", () => {
      const isVisible = input.type === "text";

      input.type = isVisible ? "password" : "text";

      toggle.innerHTML = isVisible ? eyeIcon : eyeOffIcon;
      toggle.setAttribute(
        "aria-label",
        isVisible ? "Show password" : "Hide password"
      );
      toggle.setAttribute(
        "aria-pressed",
        String(!isVisible)
      );
    });
  });
})();
