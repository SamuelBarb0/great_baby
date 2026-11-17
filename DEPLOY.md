# 🚀 Deploy · GREAT BABY ERP

Guía paso a paso para poner el ERP en un servidor real (Hostinger, DigitalOcean, VPS Ubuntu).

---

## 1. Requisitos del servidor

- **PHP 8.2+** con extensiones: `bcmath`, `ctype`, `curl`, `dom`, `fileinfo`, `gd`, `intl`, `json`, `mbstring`, `mysql/pdo_mysql`, `openssl`, `tokenizer`, `xml`, `zip`, `zlib`
- **MySQL 8+ / MariaDB 10.5+** (`utf8mb4_unicode_ci`, InnoDB, columnas generadas)
- **Composer 2**
- **Node 18+** + **npm** (solo para build inicial)
- **Nginx** (o Apache) con https (Let's Encrypt)
- **Supervisor** (Ubuntu VPS) o **cron** (Hostinger shared)

---

## 2. Preparar código

```bash
cd /var/www
git clone <repo-url> greatbaby-erp
cd greatbaby-erp

composer install --no-dev --optimize-autoloader
npm ci
npm run build           # genera public/build/ y NO se necesita node en runtime
```

## 3. Configurar `.env`

```bash
cp .env.production.example .env
nano .env                # llenar DB_*, MAIL_*, APP_URL, etc.
php artisan key:generate --force
```

## 4. Migrar base de datos

```bash
php artisan migrate --force
php artisan db:seed --class=RolesYPermisosSeeder --force   # roles Aracely/Alistador/Gerente/Contador
```

**Cargar catálogo real y contactos:** Aracely los importa desde el panel:
- `/app/dropi/productos/importar` → Importar Excel (plantilla en `/dropi/plantilla/productos`). En `/admin` ya no está: ahí «Carga masiva en Dropi» abre la carga de productos de Dropi.
- `/admin` → Contactos → Importar Excel (plantilla en `/cartera/plantilla/contactos`)
- `/admin` → Listas de precios → Cargar precios por variante

## 5. Storage & permisos

```bash
php artisan storage:link
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
```

## 6. Optimizar

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
php artisan icons:cache        # blade-icons
```

## 7. Cola de jobs (obligatorio para NC Siigo, correos, cobranza WhatsApp)

### Opción A · Supervisor (VPS)

`/etc/supervisor/conf.d/gb-erp-worker.conf`:
```ini
[program:gb-erp-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/greatbaby-erp/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/gb-erp-worker.log
stopwaitsecs=3600
```

```bash
supervisorctl reread && supervisorctl update && supervisorctl start gb-erp-worker:*
```

### Opción B · Cron (Hostinger shared)

```bash
* * * * * cd /home/user/greatbaby-erp && php artisan queue:work --stop-when-empty --max-time=55 >> /dev/null 2>&1
```

## 8. Scheduler (segmentación clientes, purga fotos, cobranza diaria)

```bash
* * * * * cd /var/www/greatbaby-erp && php artisan schedule:run >> /dev/null 2>&1
```

## 9. Nginx (VPS)

`/etc/nginx/sites-available/greatbaby-erp`:
```nginx
server {
    listen 443 ssl http2;
    server_name erp.greatbaby.com.co;

    ssl_certificate     /etc/letsencrypt/live/erp.greatbaby.com.co/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/erp.greatbaby.com.co/privkey.pem;

    root /var/www/greatbaby-erp/public;
    index index.php;
    charset utf-8;

    client_max_body_size 20M;

    location / { try_files $uri $uri/ /index.php?$query_string; }
    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }
    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 120;
    }

    location ~ /\.(?!well-known).* { deny all; }
}
server {
    listen 80;
    server_name erp.greatbaby.com.co;
    return 301 https://$host$request_uri;
}
```

```bash
ln -s /etc/nginx/sites-available/greatbaby-erp /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx
```

## 10. Backups automáticos

**Diario** con `mysqldump` + rotación 30 días (VPS):
```cron
0 3 * * * mysqldump -u greatbaby_user -p'CLAVE' greatbaby_prod | gzip > /var/backups/gb-$(date +\%F).sql.gz && find /var/backups -name "gb-*.sql.gz" -mtime +30 -delete
```

Idealmente subir el dump a S3/GDrive con `spatie/laravel-backup` (config ya lista en el proyecto).

---

## 11. Post-deploy checklist

| # | Verificar |
|---|---|
| 1 | `https://erp.greatbaby.com.co/app/login` responde 200 |
| 2 | Login con `aracely@greatbaby.com.co` (crear con `php artisan tinker` + `User::create(...)` + `assignRole('Aracely')`) |
| 3 | `/app` muestra Torre de Control sin errores |
| 4 | `/app/plantillas` — Aracely puede subir logo y guardar plantilla predeterminada |
| 5 | `/app/empresa` — llenar razón social, NIT, resolución DIAN, banco |
| 6 | Crear una factura de prueba y descargar PDF → verificar branding correcto |
| 7 | `/portal/login` con cliente demo funciona; crear pedido; aprobar/facturar en `/app/pedidos-b2b` |
| 8 | Correos: enviar factura por email desde el detalle → llega a bandeja |
| 9 | `php artisan queue:work` procesando (o `supervisorctl status`) |
| 10 | `crontab -l` muestra scheduler + backup + queue (según opción) |
| 11 | Log rota: `ls -la storage/logs/` — máximo 14 archivos por `daily` |
| 12 | `curl -I https://erp.greatbaby.com.co` → SSL válido |

## 12. Onboarding clientes portal B2B

Para habilitar un cliente:

```bash
php artisan tinker
```
```php
use App\Models\Contacto;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

$c = Contacto::where('numero_documento', 'XXXXX')->firstOrFail();
$temp = Str::random(10);
$c->forceFill([
    'password' => Hash::make($temp),
    'portal_habilitado' => true,
    'lista_precios_id' => 1, // ID de la lista Mayorista/Distribuidor
])->save();
echo "Password temporal: $temp — enviar por WhatsApp al cliente";
```

O usar la (próxima) UI de onboarding para no entrar por tinker.

---

## 13. Troubleshooting común

| Síntoma | Causa | Fix |
|---|---|---|
| PDF factura falla con "class not found" | falta `dompdf` | `composer install --no-dev` |
| Login "419 Page Expired" | cookies https + `SESSION_SECURE_COOKIE=true` con http | usar https, o poner SESSION_SECURE_COOKIE=false en dev |
| Storage foto 404 | falta symlink | `php artisan storage:link` |
| Cron/queue no corre | supervisor no arrancado | `supervisorctl start gb-erp-worker:*` |
| Vite assets 404 | falta `npm run build` | correr el build |
| Sesión no persiste | falta tabla `sessions` | `php artisan session:table && php artisan migrate` |
| SIIGO 401 | credenciales vencidas | rotar en `.env`, `config:cache`, restart queue |

---

## 14. Rollback rápido

```bash
git log --oneline -20                # elige commit anterior estable
git reset --hard <commit>
composer install --no-dev
php artisan migrate:rollback --step=1 --force   # solo si el commit anterior no requiere las últimas migraciones
php artisan config:cache && php artisan view:cache
supervisorctl restart gb-erp-worker:*
```

---

## 15. Contacto soporte

**MyTech Solutions** · Michael Cárdenas
Los tickets críticos van al WhatsApp, no al email.
