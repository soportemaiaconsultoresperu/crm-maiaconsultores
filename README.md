# CRM Maia Consultores

CRM comercial para **Maia Consultores**, construido como monolito modular sobre **Laravel 13** y **MySQL 8**, con interfaz en **español** (AdminLTE 4 + Bootstrap 5), moneda PEN por defecto, zona horaria `America/Lima` y formato de fecha `dd/mm/yyyy`. Cubre el ciclo de venta completo: prospectos, clientes, oportunidades (Kanban), actividades, cotizaciones con PDF, productos, dashboard con KPIs, doce reportes exportables a Excel, administración de usuarios/equipos/roles/catálogos y un visor de auditoría. Toda la autorización pasa por 62 permisos granulares de Spatie y un `DataScopeService` que respeta el alcance admin / supervisor / vendedor, de modo que un vendedor nunca ve registros ajenos ni en la UI ni en las exportaciones.

## Requisitos

| Componente | Versión |
|---|---|
| PHP | ![PHP](https://img.shields.io/badge/PHP-8.3-777BB4) **8.3+** |
| Laravel | ![Laravel](https://img.shields.io/badge/Laravel-13.25-FF2D20) **13.25** (composer install resuelve la última 13.x) |
| MySQL | ![MySQL](https://img.shields.io/badge/MySQL-8.0%20%7C%208.4-4479A1) **8.0** (Docker) / **8.4** (dev Laragon) |
| Node | 20+ (para `npm install` / `npm run build`) |
| Composer | 2.x |

## Quickstart (dev local)

```bash
# 1. Clonar e instalar dependencias
git clone <repo> crm-maia-consultores && cd crm-maia-consultores
composer install
npm install

# 2. Variables de entorno (el admin nace de ADMIN_*)
cp .env.example .env
php artisan key:generate

# 3. Levantar la base (MySQL debe estar corriendo)
php artisan migrate --force
php artisan db:seed --force

# 4. Compilar assets y servir
npm run build              # o: npm run dev (Vite dev server)
php artisan serve          # http://127.0.0.1:8000
```

Cuenta admin inicial (desde `.env`):

| Campo | Origen |
|---|---|
| Email | `ADMIN_EMAIL` |
| Password | `ADMIN_PASSWORD` |
| Nombre | `ADMIN_NAME` |

El seeder falla rápido si `ADMIN_EMAIL` o `ADMIN_PASSWORD` están vacíos; no hay defaults en el repositorio.

## Módulos

| Módulo | Descripción |
|---|---|
| **Prospectos** | CRUD completo con detección de duplicados por documento, email o teléfono (normalizados), advertencia confirmada en servidor y auditada; importación / exportación Excel; asignación de responsable; historial cronológico; próxima acción derivada de la agenda (ADR-012). |
| **Clientes y contactos** | Alta de personas naturales y jurídicas; conversión desde prospecto en una sola transacción (ADR-001); hasta N contactos por cliente con **un único principal activo** garantizado transaccionalmente; ficha 360° con oportunidades, cotizaciones, actividades y documentos. |
| **Oportunidades / Kanban** | Pipeline drag & drop (HTML5 vanilla + fallback sin JS) con totales por moneda y por columna; cambio de etapa append-only; marcada ganada exige monto final y fecha; perdida exige motivo; asignación con notificación. |
| **Actividades y calendario** | 7 tipos (llamada, mensaje, reunión, correo, visita, tarea, nota); cinco estados (`pending → in_process → completed` / `cancelled` / `overdue`); scheduler diario marca vencidas y notifica próximos vencimientos; cuatro vistas de calendario (mes / semana / día / lista). |
| **Productos** | Catálogo global con tipo (producto / servicio), moneda, impuesto predeterminado; sin borrado físico, solo desactivación; exportación Excel. |
| **Cotizaciones** | Numeración `COT-AAAA-NNNNN`; cabecera + detalle con **snapshot histórico** de precio e impuesto (ADR-005); cálculo server-side validado (`DECIMAL(14,2)`); PDF profesional vía Dompdf; aceptación con confirmación explícita y cierre de oportunidad (ADR-007); duplicación. |
| **Documentos** | Adjuntos polimórficos sobre prospecto, cliente, contacto, oportunidad, cotización y actividad; disco privado configurable; validación de extensión, MIME y tamaño; descarga autorizada; URL temporal cuando el driver lo soporte. |
| **Dashboard** | 12 KPIs reales (sin datos simulados) según alcance: prospectos nuevos, sin contactar, oportunidades abiertas, valor de embudo por moneda, ganadas / perdidas, actividades pendientes y vencidas, próximas reuniones, conversión por etapa, rendimiento por vendedor. |
| **Reportes** | 12 reportes del prompt maestro (`prospectos-origen`, `valor-embudo`, `ventas-ganadas-perdidas`, etc.) con filtros por fecha, usuario, estado, etapa y moneda; exportación Excel endpoint `?export=xlsx`; **multimoneda sin consolidar** (ADR-004). |
| **Administración** | Usuarios (activar / desactivar, reset de password, último acceso), equipos (definición del alcance de datos), roles y permisos (Spatie, 62 granulares), ocho catálogos configurables (solo desactivar, nunca borrar), parámetros generales (`settings`) y visor de auditoría. |
| **Notificaciones** | Internas en base de datos (campana en navbar): asignación de oportunidad, cambio de etapa, asignación de actividad, próxima actividad y vencida. Arquitectura de canal lista para agregar `mail` y `whatsapp` driver. |

## Documentación

| Documento | Contenido |
|---|---|
| [docs/INDEX.md](docs/INDEX.md) | Tabla de contenidos de toda la documentación. |
| [docs/ARQUITECTURA.md](docs/ARQUITECTURA.md) | Stack, capas, autorización, auditoría, jobs / scheduler, entornos. |
| [docs/BASE_DATOS.md](docs/BASE_DATOS.md) | Modelo de datos, migraciones, estrategia de correlativos y borrado lógico. |
| [docs/REQUISITOS.md](docs/REQUISITOS.md) | Matriz de RF y RNF con ID estable, estado y prueba asociada. |
| [docs/DECISIONES.md](docs/DECISIONES.md) | ADRs (ADR-001 .. ADR-016) — decisiones técnicas inmutables. |
| [docs/SEGURIDAD.md](docs/SEGURIDAD.md) | Autenticación, autorización, protecciones web, hallazgos corregidos. |
| [docs/PRUEBAS.md](docs/PRUEBAS.md) | Estado de la suite, archivos, reglas y criterio de "módulo terminado". |
| [docs/AVANCE.md](docs/AVANCE.md) | Bitácora por bloque (B00 .. B09) con evidencia real. |

## Pruebas

```bash
php artisan test                                 # suite completa (364 tests, 1461 assertions)
php artisan test --filter QuotationMathTest      # un archivo
```

La suite usa SQLite en memoria (`phpunit.xml`); la aplicación corre sobre MySQL. Ningún módulo se considera terminado sin pruebas de sus reglas principales (ver `docs/PRUEBAS.md`).

## Convenciones del proyecto

- Validación, totales y reglas de negocio **siempre en el servidor** (Services + Form Requests).
- Cálculos monetarios en `DECIMAL(14,2)` — nunca `float`.
- **Nunca** borrado físico de entidades con historial comercial: solo `soft delete` o `is_active=false`.
- El código consulta **permisos**, nunca nombres de rol.
- Multi-moneda: se registra y se totaliza, **no** se consolida.
- Cada acción sensible queda en `activity_log` (Spatie) con old / new values y motivo.
- Docker de producción: Caddy termina HTTPS, PHP-FPM ejecuta Laravel y MySQL permanece dentro de la red de Compose.

## Despliegue con Docker (producción)

El despliegue publica exclusivamente Caddy en los puertos 80 y 443 para
`crm.maiaconsultores.com`. Caddy obtiene y renueva automáticamente los
certificados de Let's Encrypt; antes de iniciarlo, el DNS del dominio debe
resolver hacia el servidor y ambos puertos deben estar accesibles desde
Internet. Configurá `CADDY_EMAIL` en `.env.docker` con el correo de contacto
para el registro de Let's Encrypt. MySQL no publica ningún puerto del host.

```bash
# Crear el archivo local de secretos (no se versiona) y completarlo.
cp docker/env.docker.example .env.docker

# Construir y arrancar. --env-file hace que la interpolación de Compose y los
# contenedores usen el mismo archivo de producción.
docker compose --env-file .env.docker up --build -d
```

El servicio `init` espera a que MySQL esté sano y ejecuta una vez
`php artisan migrate --force` seguido de `php artisan db:seed --force`.
`app`, `queue` y `scheduler` no arrancan hasta que termine correctamente. El
`DatabaseSeeder` normal crea o actualiza el administrador de la **aplicación**
en la tabla `users` usando `ADMIN_NAME`, `ADMIN_EMAIL` y `ADMIN_PASSWORD`, y le
asigna el rol `admin`; esos valores no son el usuario de infraestructura MySQL.
No se generan datos de demostración durante el despliegue.

Para comprobar la configuración sin arrancar contenedores ni contactar
servicios externos:

```bash
docker compose --env-file docker/env.docker.example config --quiet
```
