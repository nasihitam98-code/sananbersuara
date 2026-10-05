#!/usr/bin/env bash
#
# Deploy update aplikasi. Dijalankan di server oleh akun "deploy":
#   ./deploy.sh            (normal; ditolak jika ada pemilihan berlangsung)
#   FORCE=1 ./deploy.sh    (darurat; tetap deploy walau pemilihan berlangsung)
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
php artisan optimize
sudo /usr/bin/systemctl reload php8.3-fpm

echo "==> Selesai: $(git log -1 --format='%h %s')"
