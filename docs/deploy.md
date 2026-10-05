# Panduan Instalasi Server (untuk pengelola VPS)

Aplikasi: Laravel 13 + Filament 5 (pemilihan warga, Mode Dadakan: QR + PIN).
Target pemakaian pertama: rapat penjaringan **10 Oktober 2026**, 300–600 HP bersamaan.

## 1. Syarat server

| Komponen | Versi / catatan |
|---|---|
| OS | Ubuntu 22.04 / 24.04, RAM ≥ 4 GB, VPS ini sebaiknya **khusus** untuk aplikasi ini selama masa pemilihan |
| Web server | Nginx |
| PHP | **8.3** (PHP-FPM) dengan ekstensi: `bcmath, curl, exif, fileinfo, gd, intl, mbstring, mysql, opcache, redis, sodium, xml, zip` |
| OPcache | **Wajib aktif** (tanpa OPcache satu request bisa sekitar 1,4 detik; dengan OPcache sekitar 85 ms) |
| Database | MySQL 8 (atau MariaDB 10.11+) |
| Cache & sesi | Redis |
| Lainnya | Composer 2, Node.js 20+ (untuk build aset), Certbot (HTTPS) |

## 2. Database

Migrasi membuat **trigger** yang membuat tabel `audit_logs` tidak bisa di-UPDATE/DELETE (append-only).
Karena MySQL memakai binary log, pembuatan trigger butuh salah satu:
- jalankan `php artisan migrate` dengan user yang punya hak SUPER/SYSTEM_VARIABLES_ADMIN, **atau**
- set `log_bin_trust_function_creators = 1` di konfigurasi MySQL.

```sql
CREATE DATABASE rtrw CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'rtrw'@'localhost' IDENTIFIED BY '<password-kuat>';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES, TRIGGER, LOCK TABLES ON rtrw.* TO 'rtrw'@'localhost';
FLUSH PRIVILEGES;
```

## 3. Instalasi aplikasi

```bash
cd /var/www
git clone <url-repo-github> rtrw
cd rtrw

composer install --no-dev --optimize-autoloader
npm ci && npm run build

cp .env.example .env
php artisan key:generate
```

Edit `.env`:

```dotenv
APP_NAME="Pemilihan Warga"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://<domain>

DB_CONNECTION=mysql
DB_DATABASE=rtrw
DB_USERNAME=rtrw
DB_PASSWORD=<password-kuat>

SESSION_DRIVER=redis
SESSION_LIFETIME=30
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
CACHE_STORE=redis
QUEUE_CONNECTION=sync

# Isi keduanya dengan nilai acak (jalankan perintah ini dua kali):
#   php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
# JANGAN pernah diubah setelah peserta didata (PIN lama jadi tidak berlaku).
PIN_PEPPER=
VOTE_LINK_KEY=

# Jika memakai Cloudflare, isi dengan rentang IP Cloudflare atau "*" bila hanya bisa diakses lewat Cloudflare
TRUSTED_PROXIES=
```

Lanjutkan:

```bash
php artisan migrate --force --seed      # tabel + peran + RT 01–09
php artisan storage:link
mkdir -p public/status
php artisan optimize

sudo chown -R www-data:www-data storage bootstrap/cache public/status
sudo chmod -R 775 storage bootstrap/cache public/status
```

Buat akun Super Admin pertama (password sementara tampil sekali, **serahkan langsung ke pemilik, jangan lewat chat**):

```bash
php artisan pemilihan:buat-super-admin <email> "<Nama>"
```

## 4. Nginx

```nginx
server {
    listen 80;
    server_name <domain>;
    root /var/www/rtrw/public;
    index index.php;
    charset utf-8;
    client_max_body_size 4M;
    autoindex off;

    # Status voting dibaca ratusan HP tiap ~3 detik: file statis, jangan di-cache lama.
    location ^~ /status/ {
        add_header Cache-Control "no-cache" always;
        try_files $uri =404;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

Lalu HTTPS: `sudo certbot --nginx -d <domain>`.

## 5. PHP-FPM dan OPcache (untuk 4 core / 4 GB)

`/etc/php/8.3/fpm/pool.d/www.conf`:

```ini
pm = static
pm.max_children = 24
pm.max_requests = 1000
```

`/etc/php/8.3/fpm/conf.d/10-opcache.ini`:

```ini
opcache.enable=1
opcache.memory_consumption=256
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0
```

Karena `validate_timestamps=0`, **setiap deploy** wajib diakhiri dengan `sudo systemctl reload php8.3-fpm`.

## 6. Deploy update

```bash
cd /var/www/rtrw
php artisan down --retry=15
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan optimize
sudo systemctl reload php8.3-fpm
php artisan up
```

**Jangan deploy saat ada pemilihan berstatus Berlangsung.**

## 7. Firewall dan keamanan dasar

```bash
sudo ufw allow OpenSSH && sudo ufw allow 'Nginx Full' && sudo ufw enable
```

- MySQL dan Redis hanya mendengarkan `127.0.0.1`.
- Login SSH dengan key; matikan login password root bila memungkinkan.
- `.env` tidak boleh ada di repositori dan tidak boleh dikirim lewat chat.

## 8. Cek setelah instalasi

```bash
php artisan about                  # Environment: production, Debug: OFF
curl -I https://<domain>/up        # 200
php artisan test                   # opsional di server staging (butuh database rtrw_test)
```

Lalu login di `https://<domain>/admin`. Saat login pertama akan diminta memasang 2FA (Google Authenticator/sejenis) dan mengganti password.
