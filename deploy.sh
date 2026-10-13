#!/usr/bin/env bash
#
# Deploy update aplikasi. Dijalankan di folder aplikasi di server (akun "deploy" atau root):
#   bash deploy.sh            (normal; ditolak jika ada pemilihan berlangsung)
#   FORCE=1 bash deploy.sh    (darurat; tetap deploy walau pemilihan berlangsung)
# Mendukung PHP-FPM (Nginx) dan OpenLiteSpeed (CyberPanel).
#
set -euo pipefail
cd "$(dirname "$0")"

if [ "${FORCE:-0}" != "1" ]; then
    php artisan pemilihan:cek-deploy
fi

echo "==> Mode pemeliharaan"
php artisan down --retry=15 || true
trap 'php artisan up' EXIT

echo "==> Ambil kode terbaru"
git pull --ff-only

echo "==> Paket PHP"
composer install --no-dev --optimize-autoloader --no-interaction

echo "==> Aset CSS/JS"
npm ci --no-audit --no-fund
npm run build

echo "==> Database"
php artisan migrate --force

echo "==> Cache"
php artisan optimize:clear
php artisan optimize

# Dijalankan sebagai root (mis. lewat "sudo su -"): file baru jadi milik root, kembalikan ke pemilik folder storage
# agar web server tetap bisa menulis cache/log.
if [ "$(id -u)" = "0" ]; then
    echo "==> Pemilik file"
    chown -R "$(stat -c '%U:%G' storage)" .
fi

# OPcache memakai validate_timestamps=0: PHP harus dimuat ulang agar kode baru terpakai.
echo "==> Muat ulang PHP"
if systemctl is-active --quiet lsws 2>/dev/null; then
    sudo systemctl restart lsws
else
    sudo /usr/bin/systemctl reload php8.3-fpm
fi

echo "==> Selesai: $(git log -1 --format='%h %s')"
