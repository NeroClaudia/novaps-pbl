#!/bin/sh
set -eu

echo "[NovaPS startup v2] Checking Apache MPM configuration"

a2dismod mpm_event mpm_worker
a2enmod mpm_prefork

echo "[NovaPS startup v2] Active MPM declarations"

grep -R -n 'LoadModule.*mpm_' \
    /etc/apache2/apache2.conf \
    /etc/apache2/mods-enabled \
    /etc/apache2/conf-enabled \
    /etc/apache2/sites-enabled || true

apache2ctl -t

exec apache2-foreground
