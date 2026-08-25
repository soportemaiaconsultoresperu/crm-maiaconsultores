# Desplegar CRM Maia Consultores en Ubuntu 24.04

Esta guía deja una instalación de producción accesible en `https://crm.maiaconsultores.com`: Caddy publica HTTPS y obtiene/renueva certificados de Let's Encrypt; PHP-FPM, MySQL, cola y scheduler permanecen en Docker. Está pensada para copiar y pegar en un servidor Ubuntu 24.04 limpio.

> **Convención de comandos:** los textos entre `<MAYÚSCULAS_Y_ÁNGULOS>` son marcadores que debés reemplazar antes de ejecutar. Los bloques que no contienen marcadores se pueden copiar tal cual. No pegues secretos reales en tickets, chat, historial compartido ni Git.

## Alcance, supuestos y no objetivos

| Tema | Esta guía asume / hace |
|---|---|
| Servidor | Ubuntu 24.04 con IPv4 pública y acceso `sudo` por SSH. |
| Red | Los puertos TCP 80 y 443 llegan desde Internet al servidor; el puerto SSH elegido también está permitido. |
| DNS | El registro `A` de `crm.maiaconsultores.com` ya apunta a la IPv4 pública antes del primer arranque. |
| Código | Tenés acceso autorizado al repositorio (URL HTTPS con credencial segura o SSH con clave de despliegue). |
| TLS | Caddy solicita y renueva automáticamente certificados de Let's Encrypt usando `CADDY_EMAIL`. |
| Datos | MySQL no expone un puerto al host. El servicio `init` ejecuta migraciones y el `DatabaseSeeder` normal; no carga datos de demostración. |

**No objetivos:** esta guía no compra ni registra un dominio, no crea ni cambia registros DNS automáticamente, no configura un balanceador/WAF/proveedor de copias externo y no sustituye una política de monitoreo, retención o recuperación ante desastres.

## Ruta rápida

1. Verificá DNS, acceso SSH por clave y la apertura externa de 80/443.
2. Instalá Docker desde el repositorio oficial de Docker, cloná el repositorio y creá `.env.docker` con secretos nuevos.
3. Ejecutá `docker compose --env-file .env.docker up --build -d`, comprobá `init` y abrí `https://crm.maiaconsultores.com/login`.
4. Hacé una copia inicial de los cuatro volúmenes nombrados y guardala fuera del servidor.

## Paso 1: Preparar DNS, acceso y firewall

### DNS antes de iniciar Caddy

En el proveedor DNS, creá o actualizá el registro siguiente. Esta acción se hace en el proveedor DNS; el servidor no la realiza por sí mismo.

| Tipo | Nombre | Valor |
|---|---|---|
| `A` | `crm` | `<IPV4_PUBLICA_DEL_SERVIDOR>` |

Esperá la propagación y, desde una máquina fuera del servidor, verificá:

```bash
getent ahostsv4 crm.maiaconsultores.com
```

La primera IP mostrada debe ser la IPv4 pública del servidor. Si hay un proxy/CDN delante, asegurate de que permita el desafío HTTP de Let's Encrypt por el puerto 80.

### Base segura de SSH

Conectate inicialmente con el usuario entregado por el proveedor. En una **segunda terminal**, creá un usuario administrador y copiá únicamente una clave pública que controles:

```bash
sudo adduser deploy
sudo usermod -aG sudo deploy
sudo install -d -m 700 -o deploy -g deploy /home/deploy/.ssh
sudo tee /home/deploy/.ssh/authorized_keys >/dev/null <<'EOF'
<TU_CLAVE_PUBLICA_SSH>
EOF
sudo chown deploy:deploy /home/deploy/.ssh/authorized_keys
sudo chmod 600 /home/deploy/.ssh/authorized_keys
```

Abrí otra sesión y confirmá que podés entrar como `deploy` **antes** de endurecer SSH. Luego aplicá una configuración mínima que conserva la escucha del puerto 22:

```bash
sudo tee /etc/ssh/sshd_config.d/99-produccion.conf >/dev/null <<'EOF'
PermitRootLogin no
PasswordAuthentication no
KbdInteractiveAuthentication no
PubkeyAuthentication yes
EOF
sudo sshd -t && sudo systemctl reload ssh
```

> Si necesitás un puerto SSH distinto de `22`, definilo primero en la configuración, comprobá `sudo sshd -t`, permitilo en UFW y probalo en una segunda sesión antes de cerrar la actual.

Configurá el firewall. Esto permite SSH y los únicos puertos públicos de la aplicación:

```bash
sudo apt update
sudo apt install -y ufw
sudo ufw allow OpenSSH
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable
sudo ufw status verbose
```

También verificá las reglas de red/firewall del proveedor cloud: deben permitir TCP 80 y 443. No abras 3306; MySQL es privado dentro de la red de Compose.

## Paso 2: Instalar Docker Engine y Docker Compose

Usá el repositorio oficial de Docker para Ubuntu, no el paquete `docker.io` de Ubuntu:

```bash
sudo apt update
sudo apt install -y ca-certificates curl
sudo install -m 0755 -d /etc/apt/keyrings
sudo curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
sudo chmod a+r /etc/apt/keyrings/docker.asc

echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo \"${UBUNTU_CODENAME}\") stable" | \
  sudo tee /etc/apt/sources.list.d/docker.list >/dev/null

sudo apt update
sudo apt install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
sudo systemctl enable --now docker
sudo usermod -aG docker deploy
```

Cerrá la sesión SSH de `deploy` y volvé a entrar para que se aplique el grupo `docker`. Confirmá versiones y acceso sin `sudo`:

```bash
docker --version
docker compose version
docker run --rm hello-world
```

> El grupo `docker` equivale a privilegios administrativos sobre el host. Otorgalo solo a administradores de confianza.

## Paso 3: Obtener la aplicación y crear el entorno de producción

Elegí un directorio administrado por `deploy`, cloná el repositorio y fijá la revisión aprobada. Reemplazá los dos marcadores:

```bash
sudo install -d -o ubuntu -g ubuntu /html/crm-maiaconsultores
sudo -u ubuntu git clone <URL_DEL_REPOSITORIO> /html/crm-maiaconsultores
cd /html/crm-maiaconsultores
sudo -u ubuntu git checkout <TAG_O_SHA_APROBADO>
```

Para un repositorio privado por SSH, configurá una clave de despliegue de solo lectura para `deploy` antes de clonar. No guardes un token de Git dentro de `.env.docker`.

### Crear `.env.docker` con secretos nuevos

El siguiente bloque crea el archivo con permisos `600`, genera secretos aleatorios locales y deja solo los valores que requieren reemplazo como marcadores. Reemplazá `<CORREO_DE_CONTACTO_TLS>` por un correo operativo válido para Let's Encrypt y `<EMAIL_ADMIN_INICIAL>` por la cuenta que usará la persona administradora.

```bash
cd /html/crm-maia-consultores
umask 077
APP_KEY_VALUE="base64:$(openssl rand -base64 32)"
DB_PASSWORD_VALUE="$(openssl rand -hex 32)"
DB_ROOT_PASSWORD_VALUE="$(openssl rand -hex 32)"
ADMIN_PASSWORD_VALUE="$(openssl rand -hex 24)"

cat > .env.docker <<EOF
APP_NAME="CRM Maia Consultores"
APP_ENV=production
APP_KEY="${APP_KEY_VALUE}"
APP_DEBUG=false
APP_TIMEZONE=America/Lima
APP_URL=https://crm.maiaconsultores.com
CADDY_EMAIL=<CORREO_DE_CONTACTO_TLS>
APP_LOCALE=es
APP_FALLBACK_LOCALE=es
APP_MAINTENANCE_DRIVER=file
LOG_CHANNEL=stack
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
MYSQL_DATABASE=crm_maia
MYSQL_USER=crm_maia
MYSQL_PASSWORD="${DB_PASSWORD_VALUE}"
MYSQL_ROOT_PASSWORD="${DB_ROOT_PASSWORD_VALUE}"
DB_DATABASE=crm_maia
DB_USERNAME=crm_maia
DB_PASSWORD="${DB_PASSWORD_VALUE}"

SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=database
FILESYSTEM_DISK=local

ADMIN_NAME="CRM Admin"
ADMIN_EMAIL=<EMAIL_ADMIN_INICIAL>
ADMIN_PASSWORD="${ADMIN_PASSWORD_VALUE}"
EOF
unset APP_KEY_VALUE DB_PASSWORD_VALUE DB_ROOT_PASSWORD_VALUE ADMIN_PASSWORD_VALUE
chmod 600 .env.docker
```

Comprobá que los marcadores se reemplazaron y que los secretos no quedaron vacíos, sin imprimir sus valores:

```bash
grep -nE '^(CADDY_EMAIL|ADMIN_EMAIL)=' .env.docker
grep -qE '^APP_KEY="base64:.+"$' .env.docker && echo 'APP_KEY presente'
grep -qE '^MYSQL_PASSWORD=".+"$' .env.docker && echo 'MYSQL_PASSWORD presente'
grep -qE '^ADMIN_PASSWORD=".+"$' .env.docker && echo 'ADMIN_PASSWORD presente'
```

`ADMIN_NAME`, `ADMIN_EMAIL` y `ADMIN_PASSWORD` pertenecen al administrador del **CRM** creado o actualizado por `AdminUserSeeder` en la tabla `users`; no son el usuario MySQL. Conservá el valor de `ADMIN_PASSWORD` en un gestor de secretos antes de cerrar la sesión, porque el comando anterior no lo muestra luego de crearlo.

## Paso 4: Desplegar y verificar

Primero validá que Compose pueda resolver la configuración. Este comando no inicia contenedores:

```bash
cd /html/crm-maia-consultores
docker compose --env-file .env.docker config --quiet
```

Construí las imágenes y levantá los servicios:

```bash
docker compose --env-file .env.docker up --build -d
docker compose --env-file .env.docker ps
```

El servicio `init` no queda activo: espera MySQL saludable, ejecuta `php artisan migrate --force` y `php artisan db:seed --force`, y termina con código 0. `app`, `queue` y `scheduler` esperan esa finalización correcta. Revisalo explícitamente:

```bash
docker compose --env-file .env.docker logs --no-color init
docker compose --env-file .env.docker ps -a
curl -I https://crm.maiaconsultores.com/login
```

Resultado esperado: `init` muestra migraciones/seeders sin error y termina en `Exited (0)`; `mysql`, `app`, `caddy`, `queue` y `scheduler` aparecen en ejecución; `curl` devuelve una respuesta HTTPS (normalmente `200` o una redirección). Caddy solicita el certificado al primer inicio y lo renueva automáticamente mientras DNS y 80/443 sigan disponibles.

### Primer acceso

Abrí `https://crm.maiaconsultores.com/login` e iniciá sesión con `ADMIN_EMAIL` y `ADMIN_PASSWORD` de `.env.docker`. Cambiá la contraseña desde la interfaz administrativa y actualizá el gestor de secretos. No hay usuarios ni datos comerciales de demostración creados por el despliegue normal.

## Paso 5: Operación diaria

### Estado y logs

Ejecutá estos comandos desde `/html/crm-maia-consultores`:

```bash
# Estado de todos los servicios, incluido init finalizado.
docker compose --env-file .env.docker ps -a

# Logs en tiempo real de la aplicación y de la cola.
docker compose --env-file .env.docker logs -f --tail=100 app queue scheduler

# TLS, redirecciones y errores del proxy.
docker compose --env-file .env.docker logs -f --tail=100 caddy

# Migraciones/seeders de la última puesta en marcha.
docker compose --env-file .env.docker logs --no-color init
```

No uses `docker compose down -v` en producción: elimina los volúmenes y, con ellos, datos de MySQL, archivos cargados y estado de certificados.

### Copias de seguridad de volúmenes nombrados

La instalación crea cuatro volúmenes: MySQL (`mysql-data`), archivos de la aplicación (`storage-data`) y el estado/configuración de Caddy (`caddy-data`, `caddy-config`). Compose les agrega un prefijo según el nombre del proyecto. Identificalos antes de respaldar:

```bash
docker volume ls --format '{{.Name}}' | grep -E '(mysql-data|storage-data|caddy-data|caddy-config)$'
```

Definí las variables con los **nombres exactos** que devolvió el comando anterior y elegí un directorio de respaldo con espacio suficiente. El ejemplo detiene brevemente la aplicación para obtener una copia coherente de los volúmenes; durante ese intervalo el sitio no estará disponible.

```bash
export MYSQL_VOLUME=<NOMBRE_REAL_DEL_VOLUMEN_MYSQL>
export STORAGE_VOLUME=<NOMBRE_REAL_DEL_VOLUMEN_STORAGE>
export CADDY_DATA_VOLUME=<NOMBRE_REAL_DEL_VOLUMEN_CADDY_DATA>
export CADDY_CONFIG_VOLUME=<NOMBRE_REAL_DEL_VOLUMEN_CADDY_CONFIG>
export BACKUP_DIR=/srv/backups/crm-maia/$(date +%F-%H%M%S)

mkdir -p "$BACKUP_DIR"
docker compose --env-file .env.docker stop app queue scheduler caddy
for volume in "$MYSQL_VOLUME" "$STORAGE_VOLUME" "$CADDY_DATA_VOLUME" "$CADDY_CONFIG_VOLUME"; do
  docker run --rm -v "$volume":/source:ro -v "$BACKUP_DIR":/backup alpine \
    sh -c 'tar czf "/backup/'"$(basename "$volume")"'.tar.gz" -C /source .'
done
docker compose --env-file .env.docker start app queue scheduler caddy
sha256sum "$BACKUP_DIR"/*.tar.gz | tee "$BACKUP_DIR/SHA256SUMS"
```

Copiá el directorio resultante a almacenamiento externo cifrado y probá periódicamente una restauración en un servidor aislado. Una copia solo en el mismo host no cubre la pérdida del servidor. La restauración debe hacerse en un entorno de recuperación con los servicios detenidos y volúmenes nuevos; no sobreescribas una producción activa sin un procedimiento aprobado y una copia verificada.

## Paso 6: Actualizar una versión aprobada

Antes de actualizar, realizá y verificá una copia de seguridad. Luego obtené exactamente la revisión aprobada, validá Compose y recreá los servicios. `init` vuelve a ejecutar migraciones y el seeder normal de forma segura; el administrador se actualiza según los valores actuales de `.env.docker`.

```bash
cd /html/crm-maia-consultores
git fetch --tags origin
git checkout <NUEVO_TAG_O_SHA_APROBADO>
docker compose --env-file .env.docker config --quiet
docker compose --env-file .env.docker up --build -d
docker compose --env-file .env.docker logs --no-color init
docker compose --env-file .env.docker ps -a
curl -I https://crm.maiaconsultores.com/login
```

No ejecutes `git pull` a ciegas en producción. Revisá notas de versión, migraciones y compatibilidad antes de seleccionar el tag/SHA. Si falla `init`, no supongas que una reversión de código revierte la base de datos: evaluá las migraciones y restaurá solo desde una copia probada según el plan de recuperación.

## Paso 7: Solución de problemas

| Síntoma | Comprobación | Acción segura |
|---|---|---|
| Caddy no obtiene TLS / aparece error de ACME | `docker compose --env-file .env.docker logs --tail=200 caddy`; `getent ahostsv4 crm.maiaconsultores.com` | Confirmá que el `A` apunta a este host, que 80/443 son accesibles desde Internet, que otro proxy no ocupa esos puertos y que `CADDY_EMAIL` no está vacío. Corregí DNS/firewall y recreá Caddy con `docker compose --env-file .env.docker up -d caddy`. |
| DNS no resuelve al servidor | `getent ahostsv4 crm.maiaconsultores.com` desde una red externa | Corregí el registro en el proveedor DNS y esperá propagación. La aplicación no modifica DNS. |
| `init` termina con error | `docker compose --env-file .env.docker logs --no-color init` | No fuerces el arranque de `app`. Corregí primero el secreto/variable o la causa de MySQL, luego ejecutá `docker compose --env-file .env.docker up --build -d`. |
| MySQL no está saludable | `docker compose --env-file .env.docker logs --tail=200 mysql`; `docker compose --env-file .env.docker ps -a` | Revisá espacio en disco y que `MYSQL_PASSWORD`, `DB_PASSWORD`, `MYSQL_DATABASE`, `MYSQL_USER` sean coherentes. No borres `mysql-data`. |
| Compose dice que falta `CADDY_EMAIL` | `grep -n '^CADDY_EMAIL=' .env.docker` | Indicá `--env-file .env.docker` en todos los comandos y definí un correo real. No uses el ejemplo vacío en producción. |
| El sitio responde pero da 502 | `docker compose --env-file .env.docker ps -a`; logs de `app`, `init` y `caddy` | Verificá que `init` haya terminado con código 0 y que `app` esté en ejecución. Corregí el error raíz y levantá de nuevo con `up --build -d`. |

## Paso 8: Lista final de producción

- [ ] La IPv4 pública está configurada en el registro `A` de `crm.maiaconsultores.com`.
- [ ] TCP 80 y 443 son accesibles desde Internet; SSH usa claves y UFW está activo.
- [ ] Docker Engine y el complemento Docker Compose provienen del repositorio oficial de Docker.
- [ ] El repositorio está en `/html/crm-maia-consultores` y en un tag/SHA aprobado.
- [ ] `.env.docker` tiene permisos `600`, `CADDY_EMAIL` y `ADMIN_EMAIL` reales, y secretos no vacíos.
- [ ] MySQL no tiene ningún puerto publicado en el host.
- [ ] `init` terminó en código 0 y `mysql`, `app`, `caddy`, `queue` y `scheduler` están en ejecución.
- [ ] `https://crm.maiaconsultores.com/login` responde por HTTPS y el primer inicio de sesión funciona.
- [ ] Existe una copia verificada de los cuatro volúmenes fuera del servidor y hay un plan de restauración probado.
- [ ] Las actualizaciones se ejecutan solo con una revisión aprobada y una copia previa.

## Próximo paso

Después de validar la lista, documentá en el runbook operativo quién conserva los secretos, dónde se almacenan las copias externas, su retención y el procedimiento de recuperación probado.
