# Almacenamiento de archivos: local o AWS S3

Guía para operadores de dónde viven los archivos de usuario del CRM y cómo pasar de
almacenamiento local a AWS S3 sin tocar código. El interruptor implementa la decisión
[ADR-011](DECISIONES.md#adr-011--documentos-en-disco-privado) y quedó operativo; ver también
[ADR-017](DECISIONES.md#adr-017--almacenamiento-aws-s3-implementado-referencia-a-adr-011).

> **Alcance:** esta guía cubre solo la configuración del almacenamiento. El despliegue general,
> TLS, base de datos y contenedores siguen en
> [DESPLIEGUE_PRODUCCION_UBUNTU.md](DESPLIEGUE_PRODUCCION_UBUNTU.md).

## 1. El interruptor: tres variables, sin cambios de código

Todo el CRM usa AWS S3 solo cuando las **tres** variables tienen un valor no vacío:

| Variable | Uso |
|---|---|
| `AWS_BUCKET` | Nombre del bucket. |
| `AWS_ACCESS_KEY_ID` | Clave de acceso. |
| `AWS_SECRET_ACCESS_KEY` | Secreto de acceso. |

- Con las tres presentes, los discos `local`, `docs` y `public` se respaldan en S3.
- Si falta **una cualquiera** (vacía, ausente o solo con espacios), los tres discos mantienen su
  definición local y el CRM se comporta como siempre.
- No hay una bandera aparte ni un cambio de código: configurar o borrar las tres variables del
  entorno es todo el interruptor.
- Variables opcionales: `AWS_DEFAULT_REGION`, `AWS_ENDPOINT`, `AWS_URL`,
  `AWS_USE_PATH_STYLE_ENDPOINT` y `FILESYSTEM_TEMPORARY_URL_TTL`.
- La decisión se resuelve una sola vez en `config/filesystems.php` a partir de
  `App\Support\Storage\StorageMode`, de modo que "dos de tres" nunca activa S3.

Después de tocar las variables, recargá la configuración de la aplicación y reiniciá `app`,
`queue` y `scheduler` para que todos los procesos lean el mismo modo.

## 2. Distribución en el bucket

Cada disco conserva su nombre y su raíz relativa, así que una ruta guardada en la base de datos
significa lo mismo en local y en AWS:

| Disco | Raíz local | Prefijo en el bucket | Visibilidad |
|---|---|---|---|
| `local` | `storage/app/private` | `private/` | privada |
| `docs` | `storage/app/private/docs` | `private/docs/` | privada |
| `public` | `storage/app/public` | `public/` | pública |

Ejemplo: un adjunto guardado como `content-attachments/12/34/<uuid>.jpg` en el disco `docs` vive en
`storage/app/private/docs/content-attachments/12/34/<uuid>.jpg` en local y en
`s3://<bucket>/private/docs/content-attachments/12/34/<uuid>.jpg` en AWS. El valor guardado en la
base (el `path`) no cambia entre modos.

## 3. Archivos privados: URL temporal firmada

Ningún archivo privado tiene URL pública. La descarga sigue pasando por la autorización de la
aplicación: cuando el usuario tiene permiso, el controlador responde con una redirección a una
**URL temporal firmada** del disco en la nube; el archivo lo sirve S3 directamente y no atraviesa
el servidor de la aplicación.

- La autorización se decide **antes** de emitir cualquier URL: nunca se firma un archivo que el
  usuario no puede leer (ADR-011).
- Si el disco es local o no sabe firmar, el archivo se transmite por la aplicación como hasta
  ahora.
- La vigencia de la URL se controla con `FILESYSTEM_TEMPORARY_URL_TTL` (segundos; por defecto
  `300`). Conviene mantenerla corta.

## 4. Disco público

El disco `public` es para archivos genuinamente públicos (assets publicables). Con AWS se sirve por
**URL directa** del bucket o del CDN configurado en `AWS_URL`; sin `AWS_URL` se usa la forma
estándar `https://<bucket>.s3.<region>.amazonaws.com`. No pasa por la autorización de la aplicación
porque su contenido no es privado.

En modo local el disco `public` vive en `storage/app/public` y su URL es `<APP_URL>/storage`.

## 5. Consecuencias en el despliegue

- **Los archivos de usuario salen del volumen `storage-data`.** Ese volumen (montado en
  `storage/app`) solo contiene archivos de usuario cuando el modo es local. Con AWS, el respaldo de
  volúmenes ya **no** cubre los archivos cargados: el bucket necesita su **propia** política de
  versionado y respaldo, independiente del respaldo de MySQL.
- **Habilitá el versionado del bucket** y definí retención/expiración de versiones y una copia
  externa (replicación o export programado). Sin versionado, un borrado o una sobrescritura en el
  bucket no se puede recuperar.
- **El alias `/storage` del servidor web y el volumen del contenedor solo importan en modo local.**
  El alias (`docker/nginx/default.conf`, `alias /var/www/html/storage/app/public/`) sirve los
  archivos del disco `public` local; con AWS el disco público se sirve directo desde el bucket y el
  alias queda sin uso. El volumen `storage-data` deja de tener archivos de usuario.
- **No uses `docker compose down -v` en producción.** En modo local borra los archivos de usuario;
  en modo AWS esos archivos ya no están en el volumen, pero igual elimina base de datos y estado.

## 6. Lo que sigue en disco local aunque AWS esté activo

Estos archivos **no** son archivos de usuario y no pasan a S3:

- **Fuentes del despliegue y su caché.** Las fuentes Poppins viven en `storage/fonts` y están
  horneadas en la imagen; dompdf las usa para los PDF y el certificado (`fontDir` y `fontCache`
  apuntan ahí), y `storage/fonts` no está dentro del volumen `storage-data`.
- **Imágenes horneadas en la imagen** que el certificado y los PDF necesitan, por ejemplo el logo
  en `public/images/logo-maia.png`. Se distribuyen con la imagen, no con el disco de usuario.

Si se migrara a contenedores sin estado, estas piezas deben seguir incluidas en la imagen.

## 7. Archivos temporales de Excel (import/export)

Las importaciones y exportaciones de planillas corren **de forma síncrona** en la petición HTTP
(`Excel::import` / `Excel::download`), no en un worker de cola. El archivo temporal que usa
`maatwebsite/excel` se escribe en `storage/framework/cache/laravel-excel` y lo consume el **mismo**
proceso que atiende la petición, así que con varios contenedores no hay traspaso entre procesos y
el archivo es visible para quien sirve la descarga.

`config/excel.php` deja `remote_disk` en `null`, que solo tiene efecto si algún día una importación
o exportación se mueve a la cola. En ese escenario, con más de un contenedor y sin un disco
compartido, el worker y el proceso que sirve la descarga podrían no ver el archivo temporal. No se
cambia esa configuración en este trabajo.

## 8. Verificación

- [ ] Con las tres variables vacías, subir y descargar un adjunto: se guarda y se lee en local.
- [ ] Con las tres variables presentes, repetir: el objeto aparece en el bucket bajo el prefijo
  esperado y la descarga redirige a una URL temporal firmada.
- [ ] Confirmar que un archivo privado no tiene URL pública y que un usuario sin permiso no recibe
  la URL.
- [ ] Confirmar que el bucket tiene versionado y una política de respaldo probada.
