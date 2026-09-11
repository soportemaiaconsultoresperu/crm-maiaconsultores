# Desplegar CRM Maia Consultores en Ubuntu 24.04

Esta guía instala el CRM desde un servidor Ubuntu 24.04 limpio en
`https://crm.maiaconsultoresperu.com`. El checkout del servidor queda en
`/var/www/crm-maia-consultores`; Caddy publica HTTPS con Let's Encrypt y MySQL,
PHP-FPM, la cola y el scheduler quedan dentro de Docker.

> **Marcadores y secretos:** reemplazá únicamente los textos entre
> `<MAYÚSCULAS_Y_ÁNGULOS>`. No pegues secretos en Git, tickets, chat ni historial
> compartido. Los comandos de generación siguientes crean valores locales; no
> contienen secretos reales.

## Ruta rápida

1. Configurá DNS, SSH por clave y UFW.
2. Instalá Docker Engine, cloná en `/var/www/crm-maia-consultores` y creá
   `.env.docker` con secretos nuevos.
3. Esperá MySQL saludable, ejecutá `init` **sin `Ctrl+C`** hasta `Exited (0)` y
   recién entonces iniciá los demás servicios.
4. Verificá HTTPS, el usuario inicial, logs y una copia externa de los volúmenes.

## Alcance y requisitos

| Tema | Decisión |
|---|---|
| Dominio | El registro `A` de `crm.maiaconsultoresperu.com` apunta a la IPv4 pública del servidor. |
| Red | TCP 80 y 443 llegan desde Internet; el puerto SSH elegido también está permitido. |
| TLS | Caddy solicita y renueva certificados Let's Encrypt automáticamente con `CADDY_EMAIL`. |
| Datos | MySQL no publica un puerto en el host. `init` ejecuta migraciones y el `DatabaseSeeder` normal, sin datos de demostración. |
| Código | La revisión desplegada debe ser un tag o SHA aprobado. |

Esta guía no configura DNS en el proveedor, un WAF, monitoreo externo ni una
política de recuperación ante desastres.

## 1. DNS, SSH y firewall

### DNS antes del primer inicio

En el proveedor DNS creá o actualizá este registro:

| Tipo | Nombre | Valor |
|---|---|---|
| `A` | `crm` | `<IPV4_PUBLICA_DEL_SERVIDOR>` |

Desde una red externa, confirmá que la primera IP es la del servidor:

```bash
getent ahostsv4 crm.maiaconsultoresperu.com
```

Si hay un CDN o proxy, debe permitir el desafío HTTP de Let's Encrypt por el
puerto 80.

### Crear acceso administrativo por clave

Conectate inicialmente con el usuario entregado por el proveedor. En una
segunda terminal, creá el usuario de despliegue y agregá una clave pública bajo
tu control:

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

Abrí otra sesión y confirmá que podés entrar como `deploy` antes de endurecer
SSH. Luego desactivá root y contraseña:

```bash
sudo tee /etc/ssh/sshd_config.d/99-produccion.conf >/dev/null <<'EOF'
PermitRootLogin no
PasswordAuthentication no
KbdInteractiveAuthentication no
PubkeyAuthentication yes
EOF
sudo sshd -t && sudo systemctl reload ssh
```

Si usás un puerto SSH distinto de `22`, configurarlo, validarlo, permitirlo en
UFW y probarlo en una segunda sesión antes de cerrar la actual.

### Activar UFW

```bash
sudo apt update
sudo apt install -y ufw
sudo ufw allow OpenSSH
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable
sudo ufw status verbose
```

También permití TCP 80 y 443 en el firewall del proveedor. No abras 3306.

## 2. Instalar Docker Engine y Docker Compose

Usá el repositorio oficial de Docker, no el paquete `docker.io` de Ubuntu:

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

Cerrá sesión y volvé a entrar como `deploy` para aplicar el grupo. Luego:

```bash
docker --version
docker compose version
docker run --rm hello-world
```

> El grupo `docker` concede privilegios administrativos sobre el host. Limitálo
> a administradores de confianza.

## 3. Clonar y crear el entorno de producción

Creá el directorio de checkout correcto, cloná y seleccioná una revisión
aprobada:

```bash
sudo install -d -o deploy -g deploy /var/www/crm-maia-consultores
sudo -u deploy git clone https://github.com/soportemaiaconsultoresperu/crm-maiaconsultores.git /var/www/crm-maia-consultores
cd /var/www/crm-maia-consultores
sudo -u deploy git checkout <TAG_O_SHA_APROBADO>
```

Para un repositorio privado por SSH, configurá antes una clave de despliegue de
solo lectura para `deploy`. No guardes un token de Git en `.env.docker`.

### Crear `.env.docker` con secretos nuevos

Este bloque crea un archivo con permisos `600` y valores aleatorios locales.
Reemplazá `<CORREO_DE_CONTACTO_TLS>` y `<EMAIL_ADMIN_INICIAL>` antes de
continuar.

```bash
cd /var/www/crm-maia-consultores
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
APP_URL=https://crm.maiaconsultoresperu.com
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

Comprobá presencia, sin imprimir secretos:

```bash
grep -nE '^(APP_URL|CADDY_EMAIL|ADMIN_EMAIL)=' .env.docker
grep -qE '^APP_KEY="base64:.+"$' .env.docker && echo 'APP_KEY presente'
grep -qE '^MYSQL_PASSWORD=".+"$' .env.docker && echo 'MYSQL_PASSWORD presente'
grep -qE '^ADMIN_PASSWORD=".+"$' .env.docker && echo 'ADMIN_PASSWORD presente'
```

`ADMIN_NAME`, `ADMIN_EMAIL` y `ADMIN_PASSWORD` corresponden al administrador
de la **aplicación**, que el `DatabaseSeeder` normal crea o actualiza en la
tabla `users` con el rol `admin`; no son credenciales de MySQL. Guardá la
contraseña generada en un gestor de secretos antes de cerrar la sesión. El
seeder no carga datos de demostración.

## 4. Primer despliegue y verificación

Primero comprobá la configuración sin iniciar contenedores:

```bash
cd /var/www/crm-maia-consultores
docker compose --env-file .env.docker config --quiet
```

### Orden obligatorio del primer inicio

Iniciá y esperá la salud de MySQL. `--wait` no continúa hasta que el healthcheck
esté saludable:

```bash
docker compose --env-file .env.docker up --build --wait mysql
```

Ahora ejecutá solamente la inicialización en primer plano. **No presiones
`Ctrl+C`**: esperá a que el comando vuelva por sí solo tras las migraciones y el
seeder.

```bash
docker compose --env-file .env.docker up --build init
docker compose --env-file .env.docker ps -a
```

El estado de `init` debe ser `Exited (0)`. Si no lo es, no inicies los demás
servicios. Solo con ese resultado, iniciá los procesos persistentes:

```bash
docker compose --env-file .env.docker up -d app caddy queue scheduler
docker compose --env-file .env.docker ps -a
curl -I https://crm.maiaconsultoresperu.com/login
```

Resultado esperado: `mysql`, `app`, `caddy`, `queue` y `scheduler` están en
funcionamiento; `init` permanece como `Exited (0)`; y `curl` devuelve una
respuesta HTTPS (normalmente `200` o una redirección). Caddy solicita y renueva
el certificado automáticamente mientras DNS y 80/443 estén disponibles.

### Primer acceso

Abrí `https://crm.maiaconsultoresperu.com/login` e iniciá sesión con
`ADMIN_EMAIL` y `ADMIN_PASSWORD` de `.env.docker`. Cambiá la contraseña en la
interfaz administrativa y actualizá el gestor de secretos. No se crean usuarios
ni datos comerciales de demostración fuera del administrador inicial.

### Recuperación segura de un primer intento fallido

Si `init` falló en la **primera instalación**, corregí primero la causa en los
logs. Solo si confirmaste que no existe ningún dato real, ningún archivo cargado
y ningún certificado/estado que debas conservar, podés reiniciar desde cero:

```bash
docker compose --env-file .env.docker down -v --remove-orphans
```

Después repetí el orden obligatorio de este paso. **Nunca ejecutés ese comando
sobre un despliegue real ni después de cargar datos**: elimina volúmenes, incluidos
MySQL, archivos de la aplicación y estado de Caddy. En producción, investigá el
error, restaurá únicamente desde una copia verificada si es necesario y seguí el
procedimiento de recuperación aprobado.

## 5. Operación: estado y logs

Ejecutá desde `/var/www/crm-maia-consultores`:

```bash
# Estado, incluido init finalizado.
docker compose --env-file .env.docker ps -a

# Aplicación, cola y scheduler.
docker compose --env-file .env.docker logs -f --tail=100 app queue scheduler

# TLS, redirecciones y proxy.
docker compose --env-file .env.docker logs -f --tail=100 caddy

# Última inicialización.
docker compose --env-file .env.docker logs --no-color init
```

No uses `docker compose down -v` en producción: elimina los volúmenes y sus
datos.

## 6. Copias de seguridad

La instalación usa cuatro volúmenes nombrados: MySQL (`mysql-data`), archivos
de aplicación (`storage-data`) y estado/configuración de Caddy (`caddy-data`,
`caddy-config`). Identificá los nombres con prefijo de Compose:

```bash
docker volume ls --format '{{.Name}}' | grep -E '(mysql-data|storage-data|caddy-data|caddy-config)$'
```

Definí los nombres exactos devueltos. El ejemplo detiene brevemente el sitio
para copiar los volúmenes de forma coherente:

```bash
export MYSQL_VOLUME=<NOMBRE_REAL_DEL_VOLUMEN_MYSQL>
export STORAGE_VOLUME=<NOMBRE_REAL_DEL_VOLUMEN_STORAGE>
export CADDY_DATA_VOLUME=<NOMBRE_REAL_DEL_VOLUMEN_CADDY_DATA>
export CADDY_CONFIG_VOLUME=<NOMBRE_REAL_DEL_VOLUMEN_CADDY_CONFIG>
export BACKUP_DIR=/var/backups/crm-maia/$(date +%F-%H%M%S)

mkdir -p "$BACKUP_DIR"
docker compose --env-file .env.docker stop app queue scheduler caddy
for volume in "$MYSQL_VOLUME" "$STORAGE_VOLUME" "$CADDY_DATA_VOLUME" "$CADDY_CONFIG_VOLUME"; do
  docker run --rm -v "$volume":/source:ro -v "$BACKUP_DIR":/backup alpine \
    sh -c 'tar czf "/backup/'"$(basename "$volume")"'.tar.gz" -C /source .'
done
docker compose --env-file .env.docker start app queue scheduler caddy
sha256sum "$BACKUP_DIR"/*.tar.gz | tee "$BACKUP_DIR/SHA256SUMS"
```

Copiá el directorio a almacenamiento externo cifrado y probá restauraciones en
un servidor aislado. No restaures sobre producción activa sin un procedimiento
aprobado y una copia verificada.

## 7. Actualizar una revisión aprobada

Antes de actualizar, hacé y verificá una copia. Después seleccioná exactamente
la revisión aprobada, reconstruí `init` y esperá su finalización antes de
reiniciar los servicios:

```bash
cd /var/www/crm-maia-consultores
git fetch --tags origin
git checkout <NUEVO_TAG_O_SHA_APROBADO>
docker compose --env-file .env.docker config --quiet
docker compose --env-file .env.docker up --build --wait mysql
docker compose --env-file .env.docker up --build --force-recreate init
docker compose --env-file .env.docker ps -a
docker compose --env-file .env.docker up -d app caddy queue scheduler
curl -I https://crm.maiaconsultoresperu.com/login
```

Confirmá `init` como `Exited (0)` antes del último comando `up`. No ejecutes
`git pull` a ciegas. Si falla una migración, una reversión de código no revierte
la base: evaluá las migraciones y restaurá solo desde una copia probada.

## 8. Solución de problemas

| Síntoma | Comprobación | Acción segura |
|---|---|---|
| Caddy no obtiene TLS | `docker compose --env-file .env.docker logs --tail=200 caddy` | Confirmá DNS, acceso externo a 80/443 y `CADDY_EMAIL`; luego ejecutá `docker compose --env-file .env.docker up -d caddy`. |
| DNS no resuelve al host | `getent ahostsv4 crm.maiaconsultoresperu.com` desde red externa | Corregí el registro en el proveedor y esperá propagación. |
| `init` falla | `docker compose --env-file .env.docker logs --no-color init` | No fuerces `app`; corregí la causa. Usá `down -v --remove-orphans` exclusivamente en un primer intento sin datos reales. |
| MySQL no está saludable | logs de `mysql` y `docker compose --env-file .env.docker ps -a` | Revisá espacio y coherencia entre `MYSQL_*` y `DB_*`; no borres `mysql-data` en producción. |
| Falta `CADDY_EMAIL` | `grep -n '^CADDY_EMAIL=' .env.docker` | Usá `--env-file .env.docker` y definí un correo operativo. |
| El sitio devuelve 502 | estado y logs de `app`, `init`, `caddy` | Confirmá `init` en `Exited (0)` y `app` ejecutándose; corregí el error raíz. |

## 9. Lista final

- [ ] El `A` de `crm.maiaconsultoresperu.com` apunta a la IPv4 pública correcta.
- [ ] SSH usa claves, UFW está activo y TCP 80/443 es accesible desde Internet.
- [ ] Docker Engine y Docker Compose vienen del repositorio oficial de Docker.
- [ ] El checkout está en `/var/www/crm-maia-consultores` y usa una revisión aprobada.
- [ ] `.env.docker` tiene permisos `600`, correo TLS/admin real y secretos no vacíos.
- [ ] MySQL no publica ningún puerto del host.
- [ ] MySQL estuvo saludable antes de ejecutar `init`; `init` acabó en `Exited (0)`.
- [ ] `app`, `caddy`, `queue` y `scheduler` están en funcionamiento.
- [ ] `https://crm.maiaconsultoresperu.com/login` responde por HTTPS y el acceso inicial funciona.
- [ ] Existe una copia verificada de los cuatro volúmenes fuera del servidor.
- [ ] Las actualizaciones usan revisión aprobada, copia previa y verificación de `init`.

## Próximo paso

Documentá en el runbook quién conserva los secretos, la ubicación y retención de
las copias externas y el procedimiento de recuperación ya probado.
