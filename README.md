# Plant Backend

Laravel API that classifies leaf images using a TensorFlow/Keras model and persists predictions to a database. The Laravel controller shells out to a Python script (`scripts/predict.py`) that loads `leaf_model.keras` and returns the predicted class + confidence as JSON.

## API surface

| Method | Path | Auth | Notes |
|---|---|---|---|
| POST | `/api/predict` | none | Multipart upload: `image` (file, ≤2 MB), `filter` (optional string). Returns plant + raw confidence (0..1). |
| POST | `/api/login` | none | Body: `username`, `password`. Returns sanctum token. |
| POST | `/api/users` | none | Register (handled by `AuthController::register`). |
| GET | `/api/plants` | sanctum | List seeded plants. |
| GET | `/api/classifications` | sanctum | List a user's prediction history. |
| POST | `/api/process-with-filters` | sanctum | Apply image filters then predict. |
| GET | `/api/health` | sanctum | Liveness check. |

The Python class labels (`scripts/predict.py`) must match `common_name` values in the `plants` table — currently: Arjun Leaf, Curry Leaf, Marsh Pennywort Leaf, Mint Leaf, Neem Leaf, Rubble Leaf.

## Requirements

- PHP 8.2+ with extensions: `mbstring`, `xml`, `bcmath`, `sqlite3` (or `mysql`), `curl`, `gd` or `imagick`
- Composer 2.x
- Python 3.10+ with `python3-venv`
- ~1 GB free disk for the TensorFlow venv
- A web server (Nginx + PHP-FPM recommended) or `php artisan serve` for dev

## Install on a fresh VPS (Ubuntu 24.04)

### 1. System packages

```bash
sudo apt update
sudo apt install -y \
    php8.3-cli php8.3-fpm php8.3-mbstring php8.3-xml php8.3-bcmath \
    php8.3-curl php8.3-sqlite3 php8.3-gd php8.3-zip \
    composer \
    python3 python3-venv \
    nginx \
    git unzip
```

If the distro ships a different PHP version, swap `php8.3-*` to match. Run `php -v` to check.

### 2. Clone the repo

```bash
sudo mkdir -p /var/www
sudo chown $USER:$USER /var/www
cd /var/www
git clone <your-repo-url> plant-backend
cd plant-backend
```

### 3. PHP dependencies

```bash
composer install --no-dev --optimize-autoloader
```

### 4. Environment file

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env` for production:

```dotenv
APP_NAME=PlantBackend
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.example.com   # public URL the API is reachable at

DB_CONNECTION=sqlite
# leave DB_DATABASE empty to use database/database.sqlite, or set an absolute path
```

Create the SQLite file (skip if you're using MySQL/Postgres):

```bash
touch database/database.sqlite
```

If using MySQL instead, set `DB_CONNECTION=mysql` and the usual `DB_HOST` / `DB_PORT` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD`.

### 5. Database

```bash
php artisan migrate --force
php artisan db:seed --force
```

This creates the `plants`, `classifications`, and updated `users` tables, seeds the six plant species the model can classify, and creates a default user (`id=1`) so anonymous `/predict` calls can attribute classifications.

### 6. Public storage symlink

The controller stores uploads to `storage/app/public/predictions` and returns `Storage::url(...)` paths. Without the symlink those URLs 404.

```bash
php artisan storage:link
```

### 7. Python AI environment

```bash
python3 -m venv scripts/venv
scripts/venv/bin/pip install --upgrade pip
scripts/venv/bin/pip install tensorflow numpy pillow
```

The Laravel controller invokes `scripts/venv/bin/python` directly — do not rename the venv path without also updating `app/Http/Controllers/API/PredictionController.php`.

Smoke-test the model:

```bash
scripts/venv/bin/python scripts/predict.py /path/to/any/leaf.jpg
# Expect: {"class": "...", "confidence": 0.99...}
```

### 8. Permissions

The web user (typically `www-data`) needs write access to `storage/` and `bootstrap/cache/`:

```bash
sudo chown -R www-data:www-data storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod 775 {} \;
sudo find storage bootstrap/cache -type f -exec chmod 664 {} \;
```

If the venv was created as your user, give `www-data` execute access:

```bash
sudo chgrp -R www-data scripts/venv
sudo chmod -R g+rX scripts/venv
sudo chmod g+x scripts/venv/bin/python
```

### 9. Nginx + PHP-FPM

Create `/etc/nginx/sites-available/plant-backend`:

```nginx
server {
    listen 80;
    server_name your-domain.example.com;
    root /var/www/plant-backend/public;

    index index.php;
    charset utf-8;
    client_max_body_size 8M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 60s;   # TF cold-start can take several seconds
    }

    location ~ /\.ht { deny all; }
}
```

Enable and reload:

```bash
sudo ln -s /etc/nginx/sites-available/plant-backend /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

For HTTPS, install certbot (`sudo apt install certbot python3-certbot-nginx`) and run `sudo certbot --nginx -d your-domain.example.com`.

### 10. Smoke test

```bash
curl -F "image=@/path/to/leaf.jpg" https://your-domain.example.com/api/predict
```

Expected:

```json
{"success":true,"plant":"Arjun Leaf","scientific_name":"Terminalia arjuna","confidence":1,"image_url":"...","classification_id":1,"filter_applied":"none"}
```

## Performance notes

- TensorFlow imports cold-start every request (~2–4 s). For higher throughput, run the model behind a long-lived Python service (FastAPI or Flask) and have Laravel call it over HTTP instead of `shell_exec`.
- The default SQLite is fine for low traffic. Switch to MySQL/Postgres before exposing publicly.

## Troubleshooting

| Symptom | Cause |
|---|---|
| `"AI model failed to run"` | Python path wrong / venv not installed / `leaf_model.keras` missing — check `storage/logs/predict.err` |
| `"Plant not found: <name>"` | Seeder didn't run, or model class label not present in `plants` table |
| `"AI response invalid"` followed by TF logs | A library is writing to stdout — keep `verbose=0` in `model.predict()` and `TF_CPP_MIN_LOG_LEVEL=3` set in `scripts/predict.py` |
| `Storage::url(...)` URLs 404 | `php artisan storage:link` not run, or `APP_URL` is wrong |
| 500 on `/predict` mentioning a foreign key | Default user (id=1) was deleted — re-run `php artisan db:seed` or pass an authenticated request |

## Local development on WSL2

If you're developing on Windows + WSL2 and need a phone on the same WiFi to reach the API:

1. `php artisan serve --host=0.0.0.0 --port=8000`
2. From an admin Windows PowerShell, forward the port to WSL2 (the WSL2 IP changes on every restart):

   ```powershell
   $wsl = (wsl hostname -I).Trim().Split(' ')[0]
   netsh interface portproxy add v4tov4 listenport=8000 listenaddress=0.0.0.0 connectport=8000 connectaddress=$wsl
   New-NetFirewallRule -DisplayName "Laravel Dev 8000" -Direction Inbound -LocalPort 8000 -Protocol TCP -Action Allow
   ```

3. From your phone, hit `http://<windows-lan-ip>:8000/api/predict`. Android requires `android:usesCleartextTraffic="true"` for plain HTTP; iOS requires an `NSAppTransportSecurity` exception.
