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
- Duplicar, reprogramar, papelera con restauración, eliminación definitiva y vaciado manual.
- Calendario de fechas importantes por proyecto y vista consolidada exclusiva del administrador.
- Exportación de la grilla a Excel y PDF (ver "Exportaciones").

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
calendario; el filtro por proyecto **solo acota** lo que el usuario ya puede ver. El índice de
proyectos muestra el enlace al calendario consolidado solo a quien puede verlo.

**Miembros: "todo en su proyecto".** Un usuario asignado a un proyecto, sin ser administrador, ve
el menú y el proyecto y, **dentro de ese proyecto**, crea y edita contenidos e ideas, adjunta
archivos y enlaces, escribe anotaciones del calendario, envía a la papelera, restaura, elimina
definitivamente y vacía la papelera. La pertenencia no otorga nada sobre proyectos donde no está
asignado. Siguen siendo de administración (permisos `content_projects.*`): catálogos, campos y
secciones personalizados, miembros, edición y archivo/reactivación del proyecto.

**Proyecto archivado = solo lectura.** Se puede ver y exportar. Toda escritura (contenidos, ideas,
adjuntos, anotaciones, papelera/restaurar/eliminar/vaciar, catálogos y campos) se rechaza hasta
que el proyecto se reactive. La vista consolidada del calendario sigue excluyendo los archivados.

## Reglas de negocio acordadas

| Tema | Regla |
|---|---|
| Papelera | Sin purga automática. Nada se borra solo, ni por tarea programada ni por comando. Los archivos se conservan hasta que alguien con acceso al proyecto elimina definitivamente o vacía la papelera. Contenidos e ideas se envían a la papelera desde su ficha; restaurar lleva a la ficha que corresponde (idea o contenido). |
| Eliminar definitivamente / Vaciar papelera | Son las **únicas** rutas del módulo que borran un archivo del disco. "Vaciar papelera" es una acción masiva por proyecto, con confirmación, disponible para administradores y miembros del proyecto, y rechazada en proyectos archivados. Los archivos se borran **después** de confirmar la transacción de base de datos, de modo que un fallo no deja filas apuntando a archivos ya borrados. |
| Duplicar | Copia textos, selecciones, campos personalizados y adjuntos, agrega " (copia)" al título, quita la fecha y vuelve el estado a Incompleto. El original no se modifica y los archivos copiados son independientes. Funciona aunque después se haya agregado un campo obligatorio que el original no tenía; cualquier error se muestra al usuario. |
| Reprogramar | Cambia fecha y hora; el contenido aparece en el mes correspondiente. |
| Edición simultánea | Cada guardado lleva un testigo de revisión. Si otra persona guardó antes, el segundo ve un aviso y **no se sobrescribe** nada. |
| Opciones retiradas | Se conservan en lo histórico y no pueden elegirse para contenidos o ideas nuevos. Al editar, el formulario muestra la opción guardada marcada "(Retirada)" y la mantiene seleccionada, así que editar otra cosa no la pierde. Retirar una opción reserva su identificador. |
| Pilar y tema | El selector de tema se filtra en el navegador según el pilar elegido; el servidor sigue validando que el tema pertenezca al pilar. |
| Horario | Se ingresa y se muestra en `America/Lima`; se guarda en UTC. La zona horaria global del CRM **no** se modifica. Las fechas se muestran como `d/m/Y H:i` (grilla, papelera, exportación y PDF). |
| Atrasado | Un contenido no publicado está **Atrasado** desde el instante peruano planificado. Una hora 00:00 explícita atrasa desde ese mismo instante (no hay gracia de "solo fecha"). Nunca si ya está Publicado; una idea sin fecha nunca está atrasada. |
| Validaciones | La fecha planificada debe estar entre los años 2000 y 2099. Los campos personalizados numéricos aceptan como máximo 12 dígitos enteros y 2 decimales (columna `decimal(14,2)`). Un mes de filtro inválido (p. ej. `2026-13`) nunca causa un error 500. |
| Ideas | La lista de ideas muestra primero las más recientes. La búsqueda trata `%` y `_` como texto literal. Las ideas tienen la misma protección de edición simultánea que los contenidos. |
| Adjuntos | Se agregan desde la **ficha** del contenido, después de guardarlo: al crear un contenido, el sistema lleva a su ficha con la sección de adjuntos visible. |

## Exportaciones

- Excel y PDF usan **exactamente** la misma consulta filtrada que la grilla: mismo proyecto,
  búsqueda, mes, estado y catálogos, mismo orden.
- Columnas: las de la grilla (Fecha `d/m/Y`, Día, Horario `H:i`, todas en hora de Perú), una
  columna **Atrasado** ("Atrasado" o vacío, con la misma regla del modelo que usa la grilla) y una
  columna por cada campo personalizado. Los campos personalizados **aparecen en la exportación
  aunque no sean columnas de la grilla**; esa es la única diferencia de columnas con la pantalla.
- Un campo personalizado **inactivo** que todavía guarda datos se exporta con el sufijo
  " (inactivo)" en el encabezado; uno inactivo sin datos no agrega columna.
- Toda celda y todo encabezado (los nombres de campos personalizados son texto del usuario) se
  neutraliza contra inyección de fórmulas: si empieza con `=`, `+`, `-`, `@`, tabulador o retorno
  de carro se le antepone un apóstrofo.
- Topes: **500 filas** para PDF y **5000** para Excel (`config/content.php`,
  `content.export.pdf_max_rows` / `excel_max_rows`, o las variables `CONTENT_EXPORT_PDF_MAX_ROWS`
  y `CONTENT_EXPORT_EXCEL_MAX_ROWS`). Si el filtro devuelve más filas, la exportación **no se
  genera** (nunca se trunca en silencio): el usuario vuelve a la grilla con los mismos filtros y un
  mensaje que le pide filtrar por mes u otro criterio.

## Adjuntos: límites y requisito de infraestructura

Límites de la aplicación:

- Imágenes: JPG, PNG y WEBP, hasta **10 MB** cada una.
- Videos: MP4 y MOV, hasta **200 MB** cada uno.
- Máximo **10 adjuntos** por contenido, contando los enlaces externos. El conteo y el orden se
  calculan bajo bloqueo de la fila del contenido, de modo que dos subidas simultáneas no superan el
  límite; si falla el registro en base de datos, el archivo ya guardado se elimina.
- Las descargas envían `X-Content-Type-Options: nosniff`.
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

Los archivos se guardan en el disco privado `docs`, bajo `content-attachments/{proyecto}/{contenido}/`,
y solo se descargan por una ruta autorizada. No están en ninguna carpeta pública.

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
   La conexión MySQL/MariaDB **no** fija la zona de la sesión: usa la del servidor
   (`@@global.time_zone`). Fijarla en `+00:00` sobre un servidor que no está en UTC desplazaría
   todas las columnas `TIMESTAMP` ya guardadas (comprobado en local: −5 h en Perú). Cambiarlo exige
   una migración de datos con ventana de mantenimiento y es una decisión aparte.
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
- Exportar a Excel y a PDF con un filtro puesto y confirmar que coinciden con lo que se ve, que
  las fechas salen `d/m/Y` y `H:i` de Perú y que la columna Atrasado coincide con la grilla.
- Con un miembro no administrador: crear, editar, adjuntar y enviar a la papelera dentro de su
  proyecto; archivar el proyecto y comprobar que todo queda en solo lectura.
- Eliminar un contenido, comprobar que aparece en la papelera, restaurarlo y verificar que
  conserva sus adjuntos.
- Comprobar que una fecha de julio planificada a las 23:00 en Perú se muestra como el día
  peruano correcto y no como el día siguiente.

## Estado de pruebas y limitaciones conocidas

- Módulo: `php artisan test tests/Feature/Content` → **229 pruebas, 2612 aserciones, todas verdes**
  (línea base previa a las correcciones de revisión de 2026-10; ver
  `odd/tasks/content-module-review-fixes.md` para el conteo actualizado).
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
