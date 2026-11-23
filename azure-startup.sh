#!/bin/sh
set -eu

CONFIG="/etc/nginx/sites-available/default"

if ! grep -Eq 'root[[:space:]]+/home/site/wwwroot[[:space:]]*;' "$CONFIG"; then
    echo "Document root default tidak ditemukan."
    echo "Periksa konfigurasi NGINX sebelum melanjutkan."
    exit 1
fi

sed -i -E \
  's#root[[:space:]]+/home/site/wwwroot[[:space:]]*;#root /home/site/wwwroot/public;#' \
  "$CONFIG"

nginx -t
service nginx reload
