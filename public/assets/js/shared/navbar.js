
/*
 * NovaPS - Shared Navbar
 *
 * Menangani dropdown My Account dan logout.
 * Autentikasi menggunakan PHP Session.
 *
 * Requires: js/shared/session.js
 */

(() => {
  const profileBtn = document.getElementById("profileBtn");
  const logoutBtn = document.getElementById("logoutBtn");

  if (!profileBtn || !logoutBtn) {
    return;
  }

  const profileMenu = profileBtn.parentElement;

  let loggingOut = false;

  function setOpen(open) {
    profileMenu.toggleAttribute("data-open", open);
    profileBtn.setAttribute("aria-expanded", String(open));
  }

  function closeMenu() {
    setOpen(false);
  }

  profileBtn.addEventListener("click", event => {
    event.stopPropagation();

    if (!NovaPS.isLoggedIn()) {
      closeMenu();
      return;
    }

    const isOpen = profileMenu.hasAttribute("data-open");
    setOpen(!isOpen);
  });

  document.addEventListener("click", closeMenu);

  document.addEventListener("keydown", event => {
    if (event.key === "Escape") {
      closeMenu();
    }
  });

  // Tutup dropdown apabila session tidak valid.
  document.addEventListener("novaps:authchange", () => {
    if (!NovaPS.isLoggedIn()) {
      closeMenu();
    }
  });

  logoutBtn.addEventListener("click", async event => {
    event.preventDefault();
    event.stopPropagation();

    if (loggingOut) {
      return;
    }

    loggingOut = true;
    logoutBtn.disabled = true;

    const originalText = logoutBtn.textContent;

    logoutBtn.textContent = "Logging out...";

    try {
      // Tunggu server benar-benar menghapus PHP Session.
      await NovaPS.logout();

      closeMenu();

      // Setelah logout, kembali ke halaman utama.
      const redirect = document.body.dataset.logoutRedirect || "/index.php";

      window.location.replace(redirect);

    } catch (error) {
      console.error("Logout gagal:", error);

      alert(
        "Gagal logout dari server. " +
        "Silakan coba lagi."
      );

    } finally {
      loggingOut = false;
      logoutBtn.disabled = false;
      logoutBtn.textContent = originalText;
    }
  });

  // Jika session baru kedaluwarsa, tutup dropdown.
  NovaPS.authReady.then(isAuthenticated => {
    if (!isAuthenticated) {
      closeMenu();
    }
  });
})();
