
/*
 * NovaPS - Customer Session
 *
 * PHP Session adalah sumber autentikasi utama.
 * localStorage hanya digunakan untuk kompatibilitas UI lama.
 *
 * API:
 * NovaPS.authReady
 * NovaPS.refreshSession()
 * NovaPS.isLoggedIn()
 * NovaPS.login(email, profile)
 * NovaPS.logout()
 * NovaPS.profile.get()
 * NovaPS.profile.set(patch)
 * NovaPS.users.*
 */

(() => {
  const API_BASE = "/api";

  const SESSION_KEY = "novaps_user";
  const PROFILE_KEY = "novaps_profile";
  const USERS_KEY = "novaps_users";

  const PROFILE_FIELDS = [
    "fullname",
    "phone",
    "address",
    "idcard"
  ];

  let authenticated = false;
  let currentUser = null;
  let authStatus = "checking";
  let pendingCheck = null;

  const loginUrl =
    document.currentScript?.dataset.requireLogin || null;

  const protectedPage = Boolean(loginUrl);

  function read(key) {
    try {
      return JSON.parse(localStorage.getItem(key));
    } catch {
      return null;
    }
  }

  function write(key, value) {
    try {
      localStorage.setItem(key, JSON.stringify(value));
    } catch (error) {
      console.warn("Storage unavailable:", error);
    }
  }

  function remove(key) {
    try {
      localStorage.removeItem(key);
    } catch (error) {
      console.warn("Storage unavailable:", error);
    }
  }

  function emailKey(email) {
    return String(email || "").toLowerCase();
  }

  function usersMap() {
    return read(USERS_KEY) || {};
  }

  function pick(source) {
    const result = {};

    PROFILE_FIELDS.forEach(key => {
      if (source && source[key]) {
        result[key] = source[key];
      }
    });

    return result;
  }

  // Kompatibilitas untuk fitur demo yang belum dimigrasi.
  // Data ini BUKAN sumber otorisasi.
  const users = {
    all() {
      return Object.values(usersMap()).sort(
        (a, b) =>
          (b.joinedAt || "").localeCompare(
            a.joinedAt || ""
          )
      );
    },

    get(email) {
      return usersMap()[emailKey(email)] || null;
    },

    upsert(email, patch) {
      const map = usersMap();
      const key = emailKey(email);

      const user = map[key] || {
        email,
        joinedAt: new Date().toISOString(),
        blocked: false
      };

      Object.assign(user, patch, { email });

      map[key] = user;
      write(USERS_KEY, map);

      return user;
    },

    rename(oldEmail, newEmail) {
      const map = usersMap();
      const oldKey = emailKey(oldEmail);
      const newKey = emailKey(newEmail);

      if (!map[oldKey] || oldKey === newKey) {
        return;
      }

      map[newKey] = {
        ...map[oldKey],
        email: newEmail
      };

      delete map[oldKey];
      write(USERS_KEY, map);
    },

    isBlocked(email) {
      const user = usersMap()[emailKey(email)];
      return Boolean(user?.blocked);
    },

    setBlocked(email, blocked, reason) {
      return users.upsert(email, {
        blocked,
        blockedAt: blocked
          ? new Date().toISOString()
          : null,
        blockReason: blocked ? reason || "" : ""
      });
    }
  };

  function updateAuthUI() {
    document.documentElement.classList.toggle(
      "logged-in",
      authenticated
    );

    document.dispatchEvent(
      new CustomEvent("novaps:authchange", {
        detail: {
          authenticated,
          status: authStatus,
          user: currentUser
        }
      })
    );
  }

  function clearCustomerState() {
    authenticated = false;
    currentUser = null;

    remove(SESSION_KEY);
    remove(PROFILE_KEY);
    remove("novaps_orders");

    try {
      sessionStorage.removeItem("novaps_customer");
    } catch {
      // Storage tidak tersedia.
    }
  }

  function applyAuthenticatedProfile(profile) {
    authenticated = true;
    authStatus = "authenticated";

    currentUser = {
      ...profile,
      role: "customer"
    };

    const displayProfile = {
      fullname: profile.full_name || "",
      phone: profile.phone || "",
      email: profile.email || "",
      address: profile.address || ""
    };

    write(PROFILE_KEY, displayProfile);

    try {
      sessionStorage.setItem(
        "novaps_customer",
        JSON.stringify(currentUser)
      );
    } catch {
      // Storage tidak tersedia.
    }

    // Hapus penanda login demo lama.
    remove(SESSION_KEY);

    updateAuthUI();
  }

  async function verifySession() {
    if (pendingCheck) {
      return pendingCheck;
    }

    pendingCheck = (async () => {
      try {
        const response = await fetch(
          `${API_BASE}/me/profile.php`,
          {
            method: "GET",
            credentials: "same-origin",
            cache: "no-store"
          }
        );

        if (response.status === 401 ||
            response.status === 403 ||
            response.status === 404) {
          clearCustomerState();
          authStatus = "guest";
          updateAuthUI();
          return false;
        }

        if (!response.ok) {
          throw new Error(
            `Session check failed: HTTP ${response.status}`
          );
        }

        const result = await response.json();

        if (
          result.success !== true ||
          !result.data ||
          !Number.isInteger(Number(result.data.user_id))
        ) {
          throw new Error(
            "Respons profil customer tidak valid."
          );
        }

        applyAuthenticatedProfile(result.data);
        return true;

      } catch (error) {
        // Kegagalan jaringan bukan bukti bahwa user logout.
        // Tetap fail-closed: jangan tampilkan akses customer.
        authenticated = false;
        currentUser = null;
        authStatus = "error";

        console.error(
          "Gagal memverifikasi session:",
          error
        );

        updateAuthUI();
        return false;
      } finally {
        pendingCheck = null;
      }
    })();

    return pendingCheck;
  }

  const NovaPS = window.NovaPS || {};

  NovaPS.users = users;

  NovaPS.isLoggedIn = () => authenticated;

  NovaPS.getAuthStatus = () => authStatus;

  NovaPS.getCurrentUser = () => currentUser;

  NovaPS.refreshSession = verifySession;

  // Kompatibilitas dengan login.js saat ini.
  // Fungsi ini tidak lagi membuat status autentikasi.
  NovaPS.login = (email, profile) => {
    if (profile) {
      write(PROFILE_KEY, {
        ...profile,
        email: profile.email || email
      });
    }
  };

  // Promise: harus ditunggu oleh tombol Logout.
  NovaPS.logout = async () => {
    const response = await fetch(
      `${API_BASE}/auth/logout.php`,
      {
        method: "POST",
        credentials: "same-origin",
        cache: "no-store"
      }
    );

    if (!response.ok) {
      throw new Error(
        `Logout gagal: HTTP ${response.status}`
      );
    }

    const result = await response.json();

    if (result.success !== true) {
      throw new Error(
        result.message || "Logout gagal."
      );
    }

    clearCustomerState();
    authStatus = "guest";
    updateAuthUI();

    return true;
  };

  NovaPS.profile = {
    get() {
      if (currentUser) {
        return {
          fullname: currentUser.full_name || "",
          phone: currentUser.phone || "",
          email: currentUser.email || "",
          address: currentUser.address || ""
        };
      }

      return authenticated
        ? read(PROFILE_KEY) || {}
        : {};
    },

    set(patch) {
      if (!authenticated) return;

      const current = read(PROFILE_KEY) || {};
      write(PROFILE_KEY, { ...current, ...patch });

      if (currentUser) {
        const fields = {
          fullname: "full_name",
          phone: "phone",
          email: "email",
          address: "address"
        };

        Object.entries(fields).forEach(
          ([localKey, backendKey]) => {
            if (Object.prototype.hasOwnProperty.call(
              patch, localKey
            )) {
              currentUser[backendKey] = patch[localKey];
            }
          }
        );
      }
    }
  };

  window.NovaPS = NovaPS;

  // Cegah halaman protected terlihat sebelum verifikasi.
  if (protectedPage) {
    document.documentElement.style.visibility =
      "hidden";
  }

  NovaPS.authReady = verifySession().then(
    isAuthenticated => {
      if (protectedPage) {
        if (authStatus === "guest") {
          window.location.replace(loginUrl);
          return false;
        }

        if (authStatus === "error") {
          // Jangan menganggap outage API sebagai logout.
          // Halaman belum boleh menampilkan data sensitif.
          document.documentElement.style.visibility =
            "visible";

          document.body?.replaceChildren();

          const message = document.createElement("p");
          message.textContent =
            "Tidak dapat memverifikasi sesi. " +
            "Periksa koneksi lalu muat ulang halaman.";
          message.style.padding = "24px";

          document.body?.appendChild(message);
          return false;
        }

        if (isAuthenticated) {
          document.documentElement.style.visibility =
            "visible";
        }
      }

      return isAuthenticated;
    }
  );
})();
