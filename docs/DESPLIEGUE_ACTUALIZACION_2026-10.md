# Actualización de producción — octubre 2026 (PHP 8.3.36 + módulos nuevos)

Procedimiento específico para llevar a producción la versión que incluye el parche de seguridad de PHP y
los módulos nuevos. Complementa la guía general [DESPLIEGUE_PRODUCCION_UBUNTU.md](DESPLIEGUE_PRODUCCION_UBUNTU.md)
(§6 copias de seguridad, §7 actualización). Servidor: `/var/www/crm-maia-consultores`, dominio
`crm.maiaconsultoresperu.com`.

> **Urgencia:** PHP 8.3 anterior a 8.3.36 tiene dos vulnerabilidades críticas de ejecución remota de código
> sin autenticación (CVE-2026-109618 y CVE-2026-109619, "PHP2Shell", CVSS 9.2, publicadas el 21/10/2026,
> afectan PHP-FPM). La imagen ahora está fijada en `php:8.3.36-fpm-alpine`.
> Fuente: https://thephp.foundation/blog/2026/10/21/todays-php-security-release-and-how-were-strengthening-php-security/

---

## Qué cambia en esta versión

| Área | Cambio |
|---|---|
| Seguridad | PHP 8.3.36 (imagen Docker fijada). |
| Módulos nuevos | Actividades internas (con sincronización Google y recordatorios), Proyectos internos (fases, tareas, Gantt), Cronograma de contenidos completo. |
| Módulos corregidos | Soporte/tickets (alcance de datos, estados, KPI), Cursos y charlas (edición, documentos, envíos), roles y permisos en español. |
| Archivos | S3 automático si se configuran credenciales AWS; si no, disco local como hoy. |
| Permisos | Los seeders ya no pisan los ajustes de roles hechos desde la pantalla (registro "otorgar una sola vez"). |

### Migraciones (18)

`php artisan migrate` (lo ejecuta `init`) aplica **solo las pendientes**, en orden de fecha, y nunca repite las
ya aplicadas. Es normal que el servidor tenga migraciones sin ejecutar: esta actualización las corre todas.
Para ver cuántas faltan antes de empezar:

```bash
docker compose --env-file .env.docker exec app php artisan migrate:status | grep -c Pending
```

Solo agregan tablas o columnas, **excepto cuatro de Cursos que transforman datos existentes**. Si el módulo
de Cursos **nunca se instaló** en el servidor, estas cuatro corren sobre tablas recién creadas y vacías: no hay
datos que perder y el Paso 1 no aplica.

| Migración | Efecto | Reversible |
|---|---|---|
| `2026_09_13_000001_drop_reference_price_from_course_activities` | **Borra** la columna `course_activities.reference_price`. | No recupera los valores. |
| `2026_09_13_000002_move_talk_certificate_price_to_course_editions` | Copia el precio de certificado de cada charla a sus dictados y **borra** `course_activities.talk_certificate_price`. | No recupera la columna original. |
| `2026_09_14_000001_convert_course_edition_teachers_to_stable_identity` | Reconstruye `course_edition_teachers` (nueva clave primaria). | **No** (`down()` vacío). |
| `2026_09_14_000002_add_teacher_assignment_to_course_sessions` | Agrega docente a cada sesión y rellena los casos sin ambigüedad. | Sí. |

Las otras 14 (contenidos, actividades internas, proyectos internos, soporte, permisos) son aditivas.

### Variables de entorno

No hay variables **obligatorias** nuevas. Opcionales en `.env.docker`:

- `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET` (+ `AWS_URL`, `AWS_ENDPOINT`,
  `AWS_USE_PATH_STYLE_ENDPOINT`, `FILESYSTEM_TEMPORARY_URL_TTL`): solo si quieren usar S3. **Sin las tres
  principales, los archivos siguen en el disco del servidor.** Activar S3 no mueve los archivos existentes:
  ver [ALMACENAMIENTO.md](ALMACENAMIENTO.md).
- Siguen siendo necesarias las existentes, en especial `ADMIN_EMAIL` y `ADMIN_PASSWORD` (el seeder del
  administrador falla fuera de `local` si faltan).

### Tareas programadas

Nueva: `internal-activities:notify-upcoming` cada hora (recordatorios de actividades internas). Corre en el
contenedor `scheduler`, que ya existe; no hay que configurar nada.

---

## Paso 0 — Antes de empezar (en tu PC)

1. El código tiene que estar **mergeado en `main`** y aprobado (Pull Request).
2. Sobre el commit mergeado en `main` se crea y publica una **etiqueta de versión**, por ejemplo
   `v2026.10.22`. Ese es el valor que va en `<SHA_O_TAG_APROBADO>` (Paso 3). Volver atrás es hacer
   `git checkout` de la etiqueta anterior.
3. Elegí una ventana de mantenimiento: el sitio queda detenido unos minutos durante la copia y la migración.

## Paso 1 — Revisar datos que se van a transformar (servidor, solo lectura)

> **Si Cursos y charlas nunca se instaló en el servidor, saltá este paso.** (Los comandos dirían que la
> tabla `course_activities` no existe.)

```bash
cd /var/www/crm-maia-consultores
docker compose --env-file .env.docker exec mysql sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" -e "
  SELECT COUNT(*) AS cursos_con_precio_referencia FROM course_activities WHERE reference_price IS NOT NULL AND reference_price <> 0;
  SELECT COUNT(*) AS charlas_con_precio_certificado FROM course_activities WHERE talk_certificate_price IS NOT NULL AND talk_certificate_price <> 0;
  SELECT COUNT(*) AS docentes_asignados FROM course_edition_teachers;"'
```

- Si `cursos_con_precio_referencia` es mayor que 0, **exportá esos valores** antes de seguir (la columna se
  borra):

```bash
docker compose --env-file .env.docker exec mysql sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" -e "
  SELECT id, code, name, reference_price FROM course_activities WHERE reference_price IS NOT NULL;"' > ~/precios-referencia-cursos.tsv
```

> Si un comando dice que la columna no existe, esa migración ya se aplicó antes: no es un error.

## Paso 2 — Copia de seguridad completa (obligatoria)

Seguí la guía general **§6 Copias de seguridad** (detiene app/cola/scheduler/caddy, copia los cuatro
volúmenes y calcula SHA-256). Además, un volcado lógico de la base:

```bash
export BACKUP_DIR=/var/backups/crm-maia/$(date +%F-%H%M%S)-pre-octubre
mkdir -p "$BACKUP_DIR"
docker compose --env-file .env.docker exec -T mysql sh -c \
  'mysqldump --single-transaction --routines --triggers -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' \
  | gzip > "$BACKUP_DIR/mysql.sql.gz"
sha256sum "$BACKUP_DIR"/* | tee "$BACKUP_DIR/SHA256SUMS"
```

No sigas sin una copia verificada: tres migraciones de Cursos no se pueden revertir.

## Paso 3 — Traer la versión aprobada

```bash
cd /var/www/crm-maia-consultores
git fetch --tags origin
git checkout <SHA_O_TAG_APROBADO>      # por ejemplo: git checkout v2026.10.22
git log -1 --oneline
grep -n "FROM php" Dockerfile          # debe mostrar php:8.3.36-fpm-alpine
docker compose --env-file .env.docker config --quiet
```

## Paso 4 — Reconstruir con la imagen de PHP parcheada

`--pull` descarga la imagen base 8.3.36 y `--no-cache` evita reutilizar capas viejas con PHP vulnerable:

```bash
docker compose --env-file .env.docker build --pull --no-cache
```

## Paso 5 — Migrar y sembrar (contenedor `init`)

```bash
docker compose --env-file .env.docker stop app queue scheduler
docker compose --env-file .env.docker up --wait mysql
docker compose --env-file .env.docker up --force-recreate init
docker compose --env-file .env.docker ps -a        # init debe quedar "Exited (0)"
docker compose --env-file .env.docker logs --no-color init | tail -n 60
```

`init` ejecuta `php artisan migrate --force && php artisan db:seed --force`. Los seeders son idempotentes:
crean los permisos nuevos (cursos, contenidos, proyectos, soporte) y los otorgan **una sola vez** a los roles
del sistema.

> **Primer despliegue con el registro de permisos:** como el registro empieza vacío, esta vez los roles
> *supervisor* y *vendedor* reciben los permisos de fábrica que les falten (igual que hacía el CRM hasta
> ahora). Si en producción habían quitado alguno a propósito, revisalo en **Administración → Roles** después
> de este despliegue; desde el siguiente, esos ajustes ya se respetan.

**Si `init` termina con error:** no levantes los servicios. Revisá el log, corregí la causa y, si la base
quedó a medio migrar, restaurá desde la copia del Paso 2 antes de reintentar.

## Paso 6 — Levantar y verificar

```bash
docker compose --env-file .env.docker up -d app caddy queue scheduler
docker compose --env-file .env.docker exec app php -v           # PHP 8.3.36
docker compose --env-file .env.docker exec app php artisan migrate:status | tail -n 20
docker compose --env-file .env.docker exec scheduler php artisan schedule:list | grep internal-activities
curl -I https://crm.maiaconsultoresperu.com/login                 # HTTP/2 200
```

Prueba manual rápida (como administrador):

- [ ] Iniciar sesión.
- [ ] **Calendario**: abre y muestra el filtro de capas (Todo / Comercial / Interno / Proyectos).
- [ ] **Actividades internas** y **Proyectos internos** abren desde el menú.
- [ ] **Cursos y charlas**: abrir un dictado existente; docentes y sesiones se ven bien; "Ver PDF" de un
      documento existente descarga.
- [ ] **Soporte**: abrir un ticket; **Dashboard de soporte** en el menú.
- [ ] **Administración → Roles**: los permisos se ven en español.
- [ ] Subir un adjunto (por ejemplo en un ticket) y descargarlo.

## Paso 7 — Si algo sale mal (reversión)

1. `docker compose --env-file .env.docker stop app queue scheduler caddy`
2. Restaurá la base desde `mysql.sql.gz` (o los volúmenes de §6) del Paso 2.
3. `git checkout <SHA_ANTERIOR>` y `docker compose --env-file .env.docker build --pull --no-cache`.
   **Ojo:** el SHA anterior usa `php:8.3-fpm-alpine` sin fijar; al reconstruir con `--pull` obtiene la última
   8.3 publicada (ya parcheada), pero verificá `php -v` ≥ 8.3.36.
4. `docker compose --env-file .env.docker up -d`.

Volver solo el código **no** revierte las migraciones de Cursos: por eso la restauración de la base es
parte de la reversión.
