# Módulo de contenidos (Cronograma de contenidos)

Módulo independiente para planificar y publicar contenidos de redes sociales de cualquier
proyecto. Reemplaza la idea de tres soluciones a medida: existe **un módulo con proyectos
dinámicos**, y Maia Consultores, Maia Plagas y Maia Residuos se cargan como datos iniciales.

## Alcance

- Proyectos de contenido configurables: crear, editar, archivar, reactivar y asignar miembros.
- Catálogos por proyecto: pilares, temas (dependientes del pilar), objetivos, formatos y canales.
  Las opciones se crean, renombran, ordenan y **retiran**; nunca se borran, para no romper
  referencias históricas.
- Campos y secciones personalizados por proyecto: texto, texto largo, fecha, número, selección
  simple y múltiple. Crear un campo **no requiere código ni columnas nuevas** en la base.
- Contenidos con los campos de la especificación, cuatro estados, multiselección, búsqueda,
  filtros por mes, estado, pilar, tema, formato y canal, resaltado de publicaciones atrasadas y
  horario de Perú.
- Ideas sin fecha, en su propia sección, con conversión atómica a contenido.
- Adjuntos: imágenes y videos, o enlaces externos a imágenes y videos.
- Duplicar, reprogramar, papelera con restauración y vaciado manual.
- Calendario de fechas importantes por proyecto y vista consolidada exclusiva del administrador.
- Exportación de la grilla a Excel y PDF.

Fuera de alcance: publicación automática en redes sociales, métricas y APIs de las plataformas.
La publicación es manual.

## Acceso

Permisos del módulo (`content_projects.*`): `view`, `create`, `update`, `archive`,
`assign_members` y `view_all`.

- `admin`: todos.
- `supervisor`: solo `view`.
- El resto de los roles no accede hasta que se los asigne a un proyecto.

Un proyecto es accesible únicamente para los usuarios asignados. La verificación es del lado del
servidor, no solo visual: un proyecto ajeno responde **404** aunque la petición lleve datos
inválidos o venga del navegador, y también en enlaces directos, descargas y exportaciones.
`view_all` habilita la lectura transversal y es lo que sostiene la vista consolidada del
calendario; el filtro por proyecto **solo acota** lo que el usuario ya puede ver.

## Reglas de negocio acordadas

| Tema | Regla |
|---|---|
| Papelera | Sin purga automática. Nada se borra solo, ni por tarea programada ni por comando. Los archivos se conservan hasta que el administrador vacía la papelera explícitamente. |
| Vaciar papelera | Es la **única** ruta del módulo que borra un archivo del disco. Requiere el permiso `archive` y confirmación. |
| Duplicar | Copia textos, selecciones, campos personalizados y adjuntos, quita la fecha y vuelve el estado a Incompleto. El original no se modifica y los archivos copiados son independientes. |
| Reprogramar | Cambia fecha y hora; el contenido aparece en el mes correspondiente. |
| Edición simultánea | Cada guardado lleva un testigo de revisión. Si otra persona guardó antes, el segundo ve un aviso y **no se sobrescribe** nada. |
| Opciones retiradas | Se conservan en lo histórico y no pueden elegirse de nuevo. Retirar una opción reserva su identificador. |
| Horario | Se ingresa y se muestra en `America/Lima`; se guarda en UTC. La zona horaria global del CRM **no** se modifica. |
| Publicación atrasada | Con hora definida, atrasa desde ese instante peruano. Con solo fecha, atrasa recién cuando termina ese día peruano. Nunca si ya está Publicado. |

## Adjuntos: límites y requisito de infraestructura

Límites de la aplicación:

- Imágenes: JPG, PNG y WEBP, hasta **10 MB** cada una.
- Videos: MP4 y MOV, hasta **200 MB** cada uno.
- Máximo **10 adjuntos** por contenido, contando los enlaces externos.
- Un enlace acepta cualquier URL `http` o `https` válida; si apunta a una imagen o video se
  etiqueta como tal, y si no, queda como enlace que se abre fuera del CRM.
- Se valida el **contenido real** del archivo, no la extensión ni el tipo declarado por el
  navegador, de modo que un archivo renombrado no pasa la validación.

> **Requisito obligatorio antes de publicar.** Producción **no acepta estos tamaños hoy**. Hay que
> ajustar dos capas y recargar los servicios:
>
> | Capa | Dónde | Hoy | Necesario |
> |---|---|---|---|
> | nginx | `docker/nginx/default.conf`, `client_max_body_size` | `20M` | `200M` |
> | PHP-FPM | `Dockerfile` (`php:8.3-fpm-alpine`), sin ajustes | `upload_max_filesize=2M`, `post_max_size=8M` | `200M` y `210M` |
>
> Mientras esto no se haga, **cualquier subida mayor a 2 MB falla en producción**, incluso una
> imagen de 10 MB, y nginx corta con 413 antes de llegar a PHP. En local (Laragon, 2 GB) el
> problema no se reproduce, así que **no valides este punto solo en local**.

Los archivos se guardan en el disco privado `docs`, bajo `content-attachments/{proyecto}/{contenido}/`:
en local quedan en `storage/app/private/docs/…` y con AWS en el bucket, bajo el prefijo
`private/docs/…`. Se descargan solo por una ruta autorizada —la aplicación decide el acceso y
recién entonces entrega el archivo, con una URL temporal firmada si el disco es remoto— y no
están en ninguna carpeta pública.

## Puesta en producción

1. **Respaldo verificado** de base de datos y almacenamiento antes de empezar.
2. Confirmar el commit aprobado y desplegarlo.
3. Correr `php artisan migrate --force`. Las migraciones del módulo son **aditivas**: crean
   `content_projects`, `content_project_user`, los catálogos, los campos personalizados, los
   contenidos, los valores, los adjuntos y las anotaciones del calendario. No alteran tablas de
   otros módulos, salvo `content_items` (columna `revision`), que es tabla propia del módulo.
4. Correr `php artisan db:seed --class=ContentPermissionsSeeder` y
   `php artisan db:seed --class=ContentCatalogSeeder`. Ambos son idempotentes: repetirlos no
   duplica nada ni borra opciones agregadas por el equipo.
5. Aplicar el ajuste de nginx y PHP descrito arriba y recargar los servicios.
6. Verificación posterior (ver siguiente sección).

Reversión: `down()` de cada migración del módulo elimina solo sus propias tablas. Ante un problema,
revertir las migraciones del módulo no afecta a los demás módulos, pero **borra los contenidos
cargados**.

## Verificación posterior a la publicación

- Entrar con un usuario asignado y ver el menú "Cronograma de contenidos".
- Abrir la grilla de un proyecto: se ven los contenidos del proyecto y ninguno de otro.
- Con un usuario **no** asignado, abrir la URL de otro proyecto: debe dar **404**.
- Subir una imagen de más de 2 MB: **debe funcionar**; si falla con 413, el ajuste de nginx no se
  aplicó.
- Subir un archivo renombrado como `.jpg` que no sea imagen: debe rechazarse.
- Crear una idea sin fecha, convertirla y comprobar que aparece una sola vez en la grilla.
- Guardar un mismo contenido desde dos pestañas: la segunda debe avisar y no sobrescribir.
- Cargar una fecha importante en el calendario del proyecto; con el administrador, verla en la
  vista consolidada y comprobar que el filtro por proyecto acota el resultado.
- Exportar a Excel y a PDF con un filtro puesto y confirmar que coinciden con lo que se ve.
- Eliminar un contenido, comprobar que aparece en la papelera, restaurarlo y verificar que
  conserva sus adjuntos.
- Comprobar que una fecha de julio planificada a las 23:00 en Perú se muestra como el día
  peruano correcto y no como el día siguiente.

## Estado de pruebas y limitaciones conocidas

- Módulo: `php artisan test tests/Feature/Content` → **229 pruebas, 2612 aserciones, todas verdes**.
- Suite completa: 1672 pruebas, 1657 correctas. Hay **15 fallos preexistentes** (13 fallos, 2
  errores, 2 marcadas como riesgosas) que se reproducen igual en la base `b7d0468`, es decir,
  **no los causa este módulo**. Entre ellos, `AdminHttpTest::test_settings_update_round_trip_through_admin_form`.
- Las pruebas corren sobre SQLite en memoria. El comportamiento específico de MySQL
  (colación en búsquedas, índices únicos, bloqueos bajo concurrencia real) **no está verificado**.
- La aceptación visual en navegador real y el comportamiento de JavaScript **queda pendiente**:
  las pruebas son HTTP, no de interfaz.
- La verificación independiente de la fase de catálogos se hizo al final y pasó; la de las fases
  anteriores al módulo de contenidos se realizó por tarea.

## Estructura

| Ruta | Contenido |
|---|---|
| `app/Models/Content/` | Modelos del módulo |
| `app/Services/Content/` | Reglas de negocio, alcance, valores, adjuntos, calendario, exportación |
| `app/Policies/Content/` | Autorización del proyecto de contenido |
| `app/Http/Controllers/Content/` | Controladores |
| `app/Enums/Content/` | Estados, tipos de campo y distinción idea/contenido |
| `app/Support/Content/PeruTime.php` | Conversión `America/Lima` ⇄ UTC |
| `resources/views/content/` | Vistas del módulo |
| `database/migrations/2026_09_15_0000*` | Migraciones aditivas del módulo |
| `tests/Feature/Content/` | Pruebas del módulo |
