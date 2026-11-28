(function () {
    "use strict";

    const isAdmin = window.location.pathname.startsWith("/admin/");

    const primaryClass = isAdmin ? "novaps-swal-admin" : "novaps-swal-customer";

    let active = false;

    const baseOption = {
        theme: "light",
        target: "body",
        buttonStyling: false,
        allowOutsideClick: false,
        allowEscapeKey: true,
        stopKeydownPropagation: true,
        keydownListenerCapture: true,
        heightAuto: false,
        reverseBUtton: true,
        confirmButtonText: "OK",

        customClass: {
            ontainer: "novaps-swal-container",
            popup: "novaps-swal-popup",
            title: "novaps-swal-title",
            htmlContainer: "novaps-swal-content",
            icon: "novaps-swal-icon",
            actions: "novaps-swal-actions",
            confirmButton: `novaps-swal-button ${primaryClass}`,
            cancelButton: "novaps-swal-button novaps-swal-cancel"
        }
    };

    async function show(options) {
        if (active) {
            return {
                isConfirmed: false,
                isDismissed: true
            };
        }

        if (!window.Swal) {
            console.error("SweetAlert2 belum dimuat");

            return {
                isConfirmed: false,
                isDismissed: true
            };
        }

        active = true;

        try {
            return await window.Swal.fire({
                ...baseOption, 
                ...options,

                customClass: {
                    ...baseOption.customClass,
                    ...(options.customClass || {})
                }
            });

        } finally {
            active = false;
        }
    }

    function success(message, title = "Success") {
        return show({
            icon: "success",
            iconColor: "#16a34a",
            titleText: title,
            text: String(message ?? "")
        });
    }

    function error(message, title = "Something Went Wrong") {
        return show({
            icon: "error",
            iconColor: "#dc2626",
            titleText: title,
            text: String(message ?? "")
        });
    }

    function warning(message, title = "Warning") {
        return show({
            icon: "warning",
            iconColor: "#d97706",
            titleText: title,
            text: String(message ?? "")
        });
    }

    function info(message, title = "Information") {
        return show({
            icon: "info",
            iconColor: "#0891b2",
            titleText: title,
            text: String(message ?? "")
        });
    }

    async function confirm(message, options = {}) {
        const danger = options.danger === true;

        const result = await show({
            icon: "warning",
            iconColor: danger ? "#dc2626" : "#d97706",
            titleText: options.title || "Confirmation",
            text: String(message ?? ""),

            showCancelButton: true,
            confirmButtonText: options.confirmText || "Confirm",
            cancelButtonText: options.cancelText || "Cancel",
            
            focusConfirm: false,
            focusCancel: true,

            customClass: {
                confirmButton: "novaps-swal-button " + (danger ? "novaps-swal-danger" : primaryClass)
            }
        });

        return result.isConfirmed === true;
    }

    window.Popup = Object.freeze({
        success,
        error,
        warning,
        info,
        confirm
    });
})();