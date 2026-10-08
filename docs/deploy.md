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
| Lainnya | Composer 2, Node.js 20+ (untuk build aset), Certbot (HTTPS), `mysql-client` (berisi `mysqldump` dan `mysql`, dipakai menu Backup) |

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

# Email dipakai untuk notifikasi panitia. 2FA login admin dimatikan (config/voting.php, admin_two_factor).
# Email juga dipakai untuk notifikasi internal (tidak memakai WhatsApp).
MAIL_MAILER=smtp
MAIL_HOST=
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=
VOTING_ALERT_EMAILS=<email-ketua-panitia>

# Backup (menu Sistem > Backup & Restore). Password zip WAJIB diisi.
# Simpan juga password ini di luar server (mis. dicatat pemilik); tanpa password, backup tidak bisa dibuka.
BACKUP_PASSWORD=
BACKUP_MYSQLDUMP_PATH=mysqldump
BACKUP_MYSQL_PATH=mysql
# Opsional: nama disk di config/filesystems.php untuk salinan di luar server (sftp/s3)
BACKUP_OFFSITE_DISK=
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

Pasang cron untuk tugas terjadwal. Isinya: menghanguskan izin bilik yang tidak dipakai (tiap menit), menghapus keterkaitan pemilih–pilihan 30 hari setelah publikasi (harian 02:00), dan backup otomatis (tiap 6 jam; tiap 15 menit saat ada pemilihan berlangsung; disimpan 30 hari):

```bash
sudo crontab -u www-data -e
# tambahkan baris:
* * * * * cd /var/www/rtrw && php artisan schedule:run >> /dev/null 2>&1
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
    client_max_body_size 16M;   # foto calon langsung dari HP (maks. 10 MB per foto)
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

`/etc/php/8.3/fpm/conf.d/20-rtrw.ini` (unggah foto calon langsung dari HP, maks. 10 MB per foto; bawaan Ubuntu hanya 2 MB):

```ini
upload_max_filesize=12M
post_max_size=16M
memory_limit=256M
```

Karena `validate_timestamps=0`, **setiap deploy** wajib diakhiri dengan `sudo systemctl reload php8.3-fpm`.

## 6. Akun deploy (supaya pemilik bisa update tanpa menghubungi pengelola server)

Lakukan **sekali** saat instalasi. Akun `deploy` hanya mengurus folder aplikasi dan boleh me-reload PHP-FPM, bukan akses root.

```bash
# 1. Buat akun dan pasang kunci SSH publik milik pemilik (dikirim pemilik, berawalan "ssh-ed25519")
sudo adduser --disabled-password --gecos "" deploy
sudo mkdir -p /home/deploy/.ssh
echo "<kunci-publik-pemilik>" | sudo tee /home/deploy/.ssh/authorized_keys
sudo chown -R deploy:deploy /home/deploy/.ssh
sudo chmod 700 /home/deploy/.ssh && sudo chmod 600 /home/deploy/.ssh/authorized_keys

# 2. Folder aplikasi milik deploy, grup www-data (PHP-FPM tetap bisa menulis)
sudo chown -R deploy:www-data /var/www/rtrw
sudo find /var/www/rtrw/storage /var/www/rtrw/bootstrap/cache /var/www/rtrw/public/status -type d -exec chmod 2775 {} \;
sudo find /var/www/rtrw/storage /var/www/rtrw/bootstrap/cache /var/www/rtrw/public/status -type f -exec chmod 664 {} \;
sudo -u deploy git config --global --add safe.directory /var/www/rtrw

# 3. Izinkan deploy me-reload PHP-FPM saja (tanpa password)
echo "deploy ALL=(root) NOPASSWD: /usr/bin/systemctl reload php8.3-fpm" | sudo tee /etc/sudoers.d/deploy
sudo chmod 440 /etc/sudoers.d/deploy

# 4. Akses baca repo GitHub privat untuk server: buat deploy key (read-only)
sudo -u deploy ssh-keygen -t ed25519 -N "" -f /home/deploy/.ssh/github
sudo -u deploy tee /home/deploy/.ssh/config >/dev/null <<'EOF'
Host github.com
    IdentityFile ~/.ssh/github
    IdentitiesOnly yes
EOF
sudo cat /home/deploy/.ssh/github.pub
# Tempel kunci ini di GitHub: repo > Settings > Deploy keys > Add deploy key (tanpa centang "Allow write access")
# Lalu pastikan remote memakai SSH:
sudo -u deploy git -C /var/www/rtrw remote set-url origin git@github.com:nasihitam98-code/sananbersuara.git
sudo -u deploy git -C /var/www/rtrw pull --ff-only
```

Setelah itu, pemilik melakukan update dari laptopnya dengan:

```bash
ssh deploy@<ip-server> /var/www/rtrw/deploy.sh
```

Skrip `deploy.sh` (ada di repo) akan: menolak jika ada pemilihan berlangsung → mode pemeliharaan → `git pull` → composer → build aset → migrasi → `optimize` → reload PHP-FPM → situs aktif lagi.

**Jangan deploy saat ada pemilihan berstatus Berlangsung.** Skrip menolaknya otomatis; `FORCE=1` hanya untuk keadaan darurat.

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

Lalu login di `https://<domain>/admin`. Saat login pertama akan diminta mengganti password. 2FA kode email dimatikan atas keputusan pemilik; untuk menyalakan lagi ubah `admin_two_factor` di `config/voting.php` menjadi `true`.

Jika email belum terkirim, cek pengaturan `MAIL_*` dan `QUEUE_CONNECTION=sync` (kode dikirim lewat antrean; dengan `sync` langsung terkirim tanpa worker).

Terakhir, buka **Sistem > Backup & Restore**, lalu klik **Backup Sekarang**. Statusnya harus **BERHASIL**. Kalau GAGAL, pesan error di tabel biasanya menunjukkan path `mysqldump` atau password database yang salah.

## 9. Lupa password

| Siapa yang lupa | Cara |
|---|---|
| Panitia, Petugas Pintu, Admin RT | Super Admin: menu **Akun** → **Ubah** → **Reset password** (beri password sementara; pengguna wajib menggantinya saat login) |
| Super Admin, dan ada Super Admin lain | Super Admin lain mereset lewat menu **Akun**, sama seperti di atas |
| Satu-satunya Super Admin | Jalankan di server (bisa dari akun `deploy`): |

```bash
ssh deploy@<ip-server> "cd /var/www/rtrw && php artisan pemilihan:reset-password <email>"
```

Password sementara tampil sekali di terminal. Login dengan password itu, lalu langsung buat password baru. Reset ini tercatat di Audit Log.

Saran: buat **dua akun Super Admin** (mis. ketua panitia dan pemilik) agar saling bisa mereset.
