#!/usr/bin/env bash
# =================================================
# GREAT BABY ERP — Deploy script (VPS / SSH)
# Uso: bash scripts/deploy.sh [--fresh]
# --fresh: primera vez (crea schema y roles). Sin flag: update.
# =================================================
set -euo pipefail

# --- Config ---
APP_DIR="${APP_DIR:-/var/www/greatbaby-erp}"
BRANCH="${BRANCH:-main}"
PHP="${PHP:-php}"

echo "▶ Deploy $BRANCH → $APP_DIR"
cd "$APP_DIR"

# --- Enter maintenance mode ---
$PHP artisan down --render="errors::503" || true

trap '$PHP artisan up' EXIT

# --- Pull code ---
git fetch --all --prune
git reset --hard "origin/$BRANCH"

# --- PHP deps (sin dev, autoloader optimizado) ---
composer install --no-dev --optimize-autoloader --no-interaction

# --- Assets (SOLO si el build no se genera en CI/CD) ---
if [ ! -d "public/build" ] || [ ! -z "${REBUILD_ASSETS:-}" ]; then
    npm ci
    npm run build
fi

# --- Storage symlink (idempotente) ---
$PHP artisan storage:link || true

# --- DB ---
if [ "${1:-}" = "--fresh" ]; then
    echo "▶ Primera vez: migrar + seed"
    $PHP artisan migrate --force
    $PHP artisan db:seed --class=RolesYPermisosSeeder --force
else
    $PHP artisan migrate --force
fi

# Catálogo de oficinas y almacenes (logística).
#
# La migración crea las dos tablas vacías y vacías el reparto del contenedor
# no funciona: sin pares no hay a dónde mandar la mercancía. El sembrador es
# idempotente —no pisa lo que se haya editado a mano— así que corre siempre,
# también en un despliegue de actualización.
$PHP artisan db:seed --class=CatalogoOficinasAlmacenesSeeder --force

# --- Purgar caches y regenerar optimizados ---
$PHP artisan optimize:clear
$PHP artisan config:cache
$PHP artisan route:cache
$PHP artisan view:cache
$PHP artisan event:cache

# --- Reiniciar workers para que tomen nuevo código ---
$PHP artisan queue:restart

# --- Reiniciar OPcache/PHP-FPM (según distro) ---
if command -v systemctl >/dev/null; then
    systemctl reload php8.2-fpm 2>/dev/null || systemctl reload php-fpm 2>/dev/null || true
fi

# --- Salir mantenimiento (el trap lo hace) ---
echo "✅ Deploy completo. Revisando salud…"

# --- Smoke test ---
URL="${APP_URL:-https://erp.greatbaby.com.co}/app/login"
CODE=$(curl -sk -o /dev/null -w "%{http_code}" "$URL" || echo 000)
if [ "$CODE" != "200" ] && [ "$CODE" != "302" ]; then
    echo "⚠ Smoke test falló ($CODE en $URL) — revisar logs storage/logs/laravel.log"
    exit 1
fi
echo "✅ $URL responde $CODE"
