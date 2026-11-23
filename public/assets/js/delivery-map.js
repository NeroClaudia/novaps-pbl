
/*
 * NovaPS - Delivery Location Map
 * Leaflet 1.9.4 + OpenStreetMap
 *
 * Tidak memakai reverse geocoding.
 * Koordinat baru disimpan ke database saat checkout.
 */
(() => {
  "use strict";

  const mapElement = document.getElementById("delivery-map");
  const locateButton = document.getElementById("use-current-location");
  const clearButton = document.getElementById("clear-delivery-pin");
  const latitudeInput = document.getElementById("delivery-latitude");
  const longitudeInput = document.getElementById("delivery-longitude");
  const statusElement = document.getElementById("delivery-location-status");
  const coordinatesElement = document.getElementById("delivery-coordinates");

  if (!mapElement) return;

  const fields = [
    locateButton,
    clearButton,
    latitudeInput,
    longitudeInput,
    statusElement,
    coordinatesElement
  ];

  if (typeof L === "undefined" || fields.some(field => !field)) {
    console.error("Leaflet atau elemen lokasi belum tersedia.");
    statusElement?.replaceChildren(
      document.createTextNode("Peta gagal dimuat.")
    );
    return;
  }

  // Hanya titik awal tampilan peta, BUKAN titik pengiriman.
  const MALANG_CENTER = [-7.9666, 112.6326];

  const map = L.map(mapElement).setView(MALANG_CENTER, 13);

  L.tileLayer(
    "https://tile.openstreetmap.org/{z}/{x}/{y}.png",
    {
      maxZoom: 19,
      attribution:
        '&copy; <a href="https://www.openstreetmap.org/copyright">' +
        'OpenStreetMap contributors</a>'
    }
  ).addTo(map);

  let marker = null;

  function setStatus(message) {
    statusElement.textContent = message;
  }

  function setPin(lat, lng, zoom = false) {
    const latitude = Number(lat);
    const longitude = Number(lng);

    if (
      !Number.isFinite(latitude) ||
      !Number.isFinite(longitude) ||
      latitude < -90 ||
      latitude > 90 ||
      longitude < -180 ||
      longitude > 180
    ) {
      setStatus("Koordinat tidak valid.");
      return;
    }

    const position = [latitude, longitude];

    if (!marker) {
      marker = L.marker(position, {
        draggable: true
      }).addTo(map);

      marker.on("dragend", () => {
        const point = marker.getLatLng();
        setPin(point.lat, point.lng);
        latitudeInput.dispatchEvent(new Event("change", {bubbles: true}));
        longitudeInput.dispatchEvent(new Event("change", {bubbles: true}));
      });
    } else {
      marker.setLatLng(position);
    }

    latitudeInput.value = latitude.toFixed(7);
    longitudeInput.value = longitude.toFixed(7);

    coordinatesElement.textContent =
      `Latitude: ${latitudeInput.value} | ` +
      `Longitude: ${longitudeInput.value}`;

    setStatus("Titik pengiriman dipilih. Pastikan lokasinya benar.");

    if (zoom) {
      map.setView(position, 17);
    }
  }

  // Customer dapat klik pada peta untuk memindahkan pin.
  map.on("click", event => {
    setPin(event.latlng.lat, event.latlng.lng);
    latitudeInput.dispatchEvent(new Event("change", {bubbles: true}));
    longitudeInput.dispatchEvent(new Event("change", {bubbles: true}));
  });

  // Menggunakan geolokasi browser dengan izin customer.
  locateButton.addEventListener("click", () => {
    if (!navigator.geolocation) {
      setStatus("Browser tidak mendukung akses lokasi.");
      return;
    }

    locateButton.disabled = true;
    setStatus("Sedang mengambil lokasi perangkat...");

    navigator.geolocation.getCurrentPosition(
      position => {
        setPin(
          position.coords.latitude,
          position.coords.longitude,
          true
        );

        latitudeInput.dispatchEvent(new Event("change", {bubbles: true}));
        longitudeInput.dispatchEvent(new Event("change", {bubbles: true}));

        locateButton.disabled = false;
      },
      error => {
        const messages = {
          1: "Izin lokasi ditolak. Silakan pilih pin manual.",
          2: "Lokasi tidak tersedia. Silakan pilih pin manual.",
          3: "Pencarian lokasi habis waktu. Coba lagi."
        };

        setStatus(messages[error.code] || "Gagal membaca lokasi.");
        locateButton.disabled = false;
      },
      {
        enableHighAccuracy: true,
        timeout: 15000,
        maximumAge: 0
      }
    );
  });

  // Hapus titik lokasi, tanpa menghapus alamat teks.
  clearButton.addEventListener("click", () => {
    if (marker) {
      map.removeLayer(marker);
      marker = null;
    }

    latitudeInput.value = "";
    longitudeInput.value = "";
    coordinatesElement.textContent = "";

    latitudeInput.dispatchEvent(new Event("change", {bubbles: true}));
    longitudeInput.dispatchEvent(new Event("change", {bubbles: true}));

    setStatus("Pin dihapus. Gunakan alamat teks atau pilih titik baru.");
  });

  // Agar peta tampil benar saat layout selesai dihitung.
  window.addEventListener("load", () => {
    map.invalidateSize();
  });
})();
