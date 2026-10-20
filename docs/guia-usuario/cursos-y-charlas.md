# Guía de usuario — Cursos y charlas

Esta guía explica, paso a paso y sin tecnicismos, cómo usar el módulo **Cursos y charlas** del CRM Maia Consultores: desde registrar un curso o una charla, programar sus dictados, inscribir participantes y tomar asistencia, hasta emitir certificados con código QR, registrar comprobantes de pago y hacer seguimiento de las entregas pendientes.

---

## Índice

1. [Conceptos básicos](#1-conceptos-básicos)
2. [Quién puede hacer qué](#2-quién-puede-hacer-qué)
3. [Dónde encontrar el módulo](#3-dónde-encontrar-el-módulo)
4. [El recorrido completo, en resumen](#4-el-recorrido-completo-en-resumen)
5. [El catálogo de cursos y charlas](#5-el-catálogo-de-cursos-y-charlas)
6. [Crear un curso o una charla](#6-crear-un-curso-o-una-charla)
7. [Crear un dictado](#7-crear-un-dictado)
8. [La página del dictado y sus estados](#8-la-página-del-dictado-y-sus-estados)
9. [Docentes del dictado](#9-docentes-del-dictado)
10. [Sesiones del dictado](#10-sesiones-del-dictado)
11. [Inscribir participantes](#11-inscribir-participantes)
12. [Lista de participantes y estado de pago](#12-lista-de-participantes-y-estado-de-pago)
13. [Asistencia](#13-asistencia)
14. [Notas (solo cursos)](#14-notas-solo-cursos)
15. [Documentos académicos: certificados y constancias](#15-documentos-académicos-certificados-y-constancias)
16. [Entregar un documento académico](#16-entregar-un-documento-académico)
17. [Verificación pública por código QR](#17-verificación-pública-por-código-qr)
18. [Plantillas de certificados](#18-plantillas-de-certificados)
19. [Comprobantes (documentos comerciales)](#19-comprobantes-documentos-comerciales)
20. [Alertas de entrega](#20-alertas-de-entrega)
21. [Dónde se guardan los archivos](#21-dónde-se-guardan-los-archivos)
22. [Mensajes de error frecuentes](#22-mensajes-de-error-frecuentes)
23. [Preguntas frecuentes](#23-preguntas-frecuentes)
24. [Ejemplos prácticos](#24-ejemplos-prácticos)
25. [Lo que el módulo todavía no hace](#25-lo-que-el-módulo-todavía-no-hace)

---

## 1. Conceptos básicos

El módulo trabaja con cuatro ideas. Conviene tenerlas claras antes de empezar:

| Concepto | Qué es | Ejemplo |
|---|---|---|
| **Curso o charla** | La definición reutilizable de lo que se enseña: tipo, código, nombre, horas académicas y temario base. Se crea una sola vez. | *Curso de Excel avanzado*, 12 horas académicas. |
| **Dictado** | Cada vez que ese curso o charla se realiza: fechas, modalidad, precio, docentes y sesiones. Un mismo curso puede tener muchos dictados. | *Excel avanzado — dictado de marzo, virtual, S/ 350.* |
| **Sesión** | Cada clase de un dictado, con su fecha, horario, tema y docente. Sobre las sesiones se marca la asistencia y se ponen las notas. | *Clase 1: Tablas dinámicas, 05/03, 19:00–21:00.* |
| **Participante / matrícula** | La persona inscrita en un dictado. Cada inscripción guarda su estado de pago, su importe, su asistencia, sus notas y sus documentos. | *Ana Pérez, inscrita en el dictado de marzo.* |

### Diferencia entre curso y charla

| | Curso | Charla |
|---|---|---|
| ¿Tiene notas? | Sí, una nota de 0 a 20 por sesión | No |
| ¿Qué decide el documento que recibe el participante? | Su resultado final (promedio de notas) | Su asistencia (participación confirmada) |
| Documento que puede recibir | **Certificado de aprobación** (si aprueba) o **Constancia de participación** (si no llega a la nota mínima) | **Certificado de charla**, solo si la charla se creó como *Charla con certificado* |
| ¿Puede cobrarse un precio aparte por el certificado? | No | Sí, si es una charla con certificado |

### Los documentos que maneja el módulo

- **Documentos académicos**: certificados y constancias en PDF que genera el propio sistema, con un **código QR** de verificación.
- **Comprobantes**: facturas, boletas o recibos emitidos **fuera del CRM** (en su sistema de facturación). En el módulo se registran sus datos, se adjunta el archivo emitido y se envían al cliente.

---

## 2. Quién puede hacer qué

El acceso depende de **permisos**. Por defecto, el rol **administrador** tiene todos los permisos del módulo y el rol **supervisor** solo puede consultar. Un administrador puede crear roles a medida y asignarles cualquiera de estos permisos desde la administración de **Roles** (aparecen agrupados bajo *Course-talks*).

| Permiso (nombre en la pantalla de roles) | Qué habilita |
|---|---|
| `course-talks.view` | Ver el módulo: menú, catálogo, detalle de cursos y dictados, participantes, asistencia (solo lectura), documentos académicos (solo lectura), comprobantes (solo lectura) y alertas de entrega. |
| `course-talks.activities.manage` | Crear cursos y charlas. |
| `course-talks.editions.manage` | Crear dictados, cambiar su estado, administrar docentes y sesiones. |
| `course-talks.sessions.manage` | Administrar las sesiones de un dictado (también lo permite el permiso anterior). |
| `course-talks.participants.manage` | Inscribir participantes (individual o en grupo) y cambiar el estado de pago, incluida la exoneración. |
| `course-talks.attendance.manage` | Marcar y guardar la asistencia. |
| `course-talks.grades.manage` | Ver y cargar la matriz de notas. |
| `course-talks.documents.generate` | Generar documentos académicos. |
| `course-talks.documents.revoke` | Anular documentos académicos. Para **regenerar** se necesitan este permiso y el anterior. |
| `course-talks.commercial-documents.manage` | Registrar comprobantes y adjuntar su archivo. |
| `course-talks.documents.send` | Enviar documentos académicos y comprobantes por correo o WhatsApp, y descartar alertas de entrega. |
| `course-talks.templates.manage` | Administrar las plantillas de certificados. |
| `course-talks.audit.view` | Existe en la lista de permisos, pero hoy ninguna pantalla del módulo lo usa. |

Resumen por rol con la configuración inicial:

| Rol | Qué puede hacer |
|---|---|
| **Administrador** | Todo lo del módulo. |
| **Supervisor** | Solo consultar: ve catálogo, dictados, participantes, asistencia, documentos, comprobantes y alertas, pero no ve botones de acción. |
| **Otros roles** | No ven el módulo, salvo que un administrador les asigne permisos. |

> **Importante:** el sistema solo muestra los botones que usted puede usar. Si no ve un botón (por ejemplo **Notas** o **Generar documento**), es porque su rol no tiene el permiso correspondiente; no es un fallo.

---

## 3. Dónde encontrar el módulo

- **Menú lateral izquierdo → Cursos y charlas** (icono de birrete). Solo aparece si tiene el permiso de ver el módulo.
- **Panel principal (Dashboard)**: si tiene acceso al módulo, verá la tarjeta **Entregas de Cursos y charlas**, con el número de *Entregas pendientes* y *Entregas vencidas* y el botón **Ver alertas de entrega**.

---

## 4. El recorrido completo, en resumen

1. **Crear el curso o la charla** en el catálogo (una sola vez).
2. **Crear un dictado** con su modalidad, fechas y precio.
3. Cargar los **docentes** y las **sesiones** del dictado.
4. Pasar el dictado de *Borrador* a **Programada**.
5. **Inscribir participantes** (uno por uno o en grupo con un pagador).
6. Cuando empieza, pasar el dictado a **En curso**.
7. Marcar la **asistencia** en cada sesión y, si es un curso, cargar las **notas**.
8. Actualizar el **estado de pago** de cada participante.
9. Al terminar, pasar el dictado a **Finalizada**. Esto completa las validaciones del dictado.
10. Generar el **certificado o la constancia** de cada participante que cumpla las condiciones (el sistema intenta hacerlo solo; ver [sección 15](#15-documentos-académicos-certificados-y-constancias)).
11. **Entregar** el documento por correo o WhatsApp.
12. Registrar el **comprobante** de pago, adjuntar su archivo y enviarlo.
13. Revisar las **alertas de entrega** para que nada quede sin enviar.

---

## 5. El catálogo de cursos y charlas

Al entrar al módulo se abre la página **Cursos y charlas**, con la tabla **Catálogo de cursos y charlas**.

### Columnas
**Código**, **Tipo** (*Curso* o *Charla*), **Curso o charla** (nombre), **Horas** (horas académicas), **Dictados** (cantidad de dictados) y, a la derecha, los botones.

La lista se ordena por tipo y luego por nombre.

### Botones de la parte superior
- **Nuevo curso o charla**: si tiene permiso para crearlos.
- **Plantillas de certificados**: si tiene permiso para administrarlas ([sección 18](#18-plantillas-de-certificados)).
- **Alertas de entrega**: abre la pantalla de seguimiento de envíos ([sección 20](#20-alertas-de-entrega)).

### Filtro por tipo
Arriba de la tabla hay un selector **Tipo** con las opciones *Todos*, *Curso* y *Charla*. Elija una opción y pulse **Filtrar**. Mientras el filtro está activo se muestra la etiqueta *Filtro activo: …*. Para volver a ver todo, pulse **Ver todas**.

### El "dictado destacado" de cada fila
Para no tener que entrar al detalle cada vez, cada fila muestra un acceso directo al dictado con el que probablemente quiera trabajar, con una etiqueta que explica por qué se eligió:

| Etiqueta | Cuándo aparece |
|---|---|
| **Dictado en curso** | Hay un dictado en estado *En curso* (si hay varios, el que empezó más recientemente). |
| **Próximo dictado** | No hay ninguno en curso, pero hay uno *Programado* que empieza hoy o más adelante (el más cercano). |
| **Último dictado** | No se cumple nada de lo anterior: se muestra el más reciente, sin contar los cancelados. Los borradores solo aparecen aquí. |

Debajo de la etiqueta se ven el código y la fecha de inicio del dictado, y los botones **Docentes**, **Sesiones**, **Participantes**, **Inscribir participante**, **Asistencia**, **Notas**, **Documentos** y **Comprobantes** (cada uno solo si tiene el permiso correspondiente).

Si el curso no tiene dictados, o todos están cancelados, solo verá el botón **Ver detalle**.

---

## 6. Crear un curso o una charla

1. En el catálogo, pulse **Nuevo curso o charla**.
2. Complete el formulario:

| Campo | ¿Obligatorio? | Qué poner |
|---|---|---|
| **Tipo** | Sí | *Curso* o *Charla*. |
| **Código** | Sí | Un código corto y único entre todos los cursos y charlas, incluso los eliminados. Ej.: *EXC-AV*. Máximo 60 caracteres. |
| **Nombre** | Sí | El nombre tal como debe salir en el certificado. Máximo 255 caracteres. |
| **Horas académicas** | Sí | Número de horas (admite decimales, no negativo). **Se imprime en el certificado**, así que revíselo bien. |
| **Charla con certificado** | No | Solo aparece cuando el tipo es *Charla*. Márquelo si los asistentes recibirán un *Certificado de charla*. Si no lo marca, la charla no emite certificado. |
| **Activa** | No | Viene marcada. Hoy no cambia nada visible en el módulo. |
| **Temario base** | No | Lista de temas. Sirve como punto de partida para el temario de cada dictado. Los renglones vacíos no se guardan. |

3. Pulse **Crear curso o charla**. Volverá al catálogo con el mensaje *Curso o charla "[Nombre]" creado correctamente.*

Para salir sin guardar, pulse **Cancelar** o **Volver al catálogo**.

> **Ojo:** si cambia el tipo de *Charla* a *Curso*, la casilla *Charla con certificado* se oculta y se desmarca. Un curso nunca guarda esa opción.

### Ver el detalle
Desde el catálogo, **Ver detalle** abre la ficha con **Código**, **Horas académicas** y **Temario base**, y debajo la tabla **Dictados** (Código, Fechas, Modalidad, Estado, Precio), ordenada del dictado más reciente al más antiguo. Desde aquí se crea un dictado nuevo con **Nuevo dictado**.

---

## 7. Crear un dictado

1. Abra el detalle del curso o charla y pulse **Nuevo dictado**.
2. Complete el formulario **Nuevo dictado de [nombre]**:

| Campo | ¿Obligatorio? | Qué poner |
|---|---|---|
| **Código** | No | Código del dictado, único entre todos los dictados. Ej.: *EXC-AV-2026-03*. Si lo deja vacío, el dictado queda "sin código". |
| **Modalidad** | Sí | *Presencial*, *Virtual* o *Híbrida*. |
| **Precio** | Sí | Precio por participante. Puede ser 0 si es gratuito. No negativo. |
| **Precio del certificado** | No | Solo aparece en charlas con certificado. Se suma al precio en cada matrícula. Use 0 si el certificado no se cobra. |
| **Fecha de inicio** | No | Primer día del dictado. |
| **Fecha de fin** | No | Último día. Debe ser igual o posterior a la fecha de inicio. |
| **Dirección** | Según modalidad | Obligatoria para *Presencial* e *Híbrida*. |
| **Enlace de acceso virtual** | Según modalidad | Obligatorio para *Virtual* e *Híbrida*. |
| **Temario del dictado** | No | Llega precargado con el temario base del curso; puede ajustarlo para este dictado. Los renglones vacíos no se guardan. |

3. Pulse **Crear dictado**. Se abre la página del dictado con el mensaje *Dictado [código] creado correctamente.*

Todo dictado nuevo empieza en estado **Borrador** y con moneda **PEN** (soles).

> **Importante:** las fechas del dictado se imprimen en el certificado (*"realizado del 05 al 26 de marzo de 2026"*). Si las deja vacías, esa parte del certificado saldrá incompleta.

---

## 8. La página del dictado y sus estados

La página **Dictado [código]** es el centro de trabajo. Arriba tiene la barra de secciones:

**Docentes** · **Sesiones** · **Participantes** · **Inscribir participante** · **Asistencia** · **Notas** · **Documentos** · **Comprobantes**

y, a continuación, los botones para cambiar de estado. Debajo se muestra la ficha: **Código**, **Estado**, **Modalidad**, **Fechas**, **Dirección**, **Acceso virtual** (con el enlace *Abrir enlace*), **Temario del dictado**, **Precio**, **Precio del certificado** (solo charlas con certificado) y **Total por matrícula**.

### Estados del dictado

| Estado | Significado | Puede pasar a |
|---|---|---|
| **Borrador** | Recién creado, en preparación. | Programada, Cancelada |
| **Programada** | Publicado, con fecha. | En curso, Cancelada |
| **En curso** | Ya empezó. | Finalizada, Cancelada |
| **Finalizada** | Terminó. Estado final. | — |
| **Cancelada** | No se realizará. Estado final. | — |

Para cambiar el estado, pulse el botón con el nombre del estado al que quiere pasar (solo se muestran los pasos permitidos). Verá el mensaje *Estado del dictado actualizado a «[estado]».*

> **El paso a Finalizada es clave:** al finalizar un dictado, el sistema da por **completadas las validaciones del dictado**, que es una de las condiciones para emitir certificados. Mientras el dictado no esté *Finalizada*, ningún participante es elegible para recibir su documento.

> No hay vuelta atrás: un dictado finalizado o cancelado no puede reabrirse desde la pantalla.

---

## 9. Docentes del dictado

Pulse **Docentes** en la página del dictado. Arriba verá **Docentes del dictado** (los actuales) y debajo el formulario **Actualizar docentes**.

### Agregar un docente
1. En la fila **Nuevo**, escriba el **Nombre del docente** y, si quiere, su **Correo (opcional)**.
2. Pulse **Guardar docentes**.

Solo se agrega un docente por vez. Para agregar otro, repita el proceso.

### Corregir un docente
Cambie el nombre o el correo directamente en su fila y pulse **Guardar docentes**.

### Quitar un docente
Marque la casilla **Quitar** de su fila y pulse **Guardar docentes**. No se puede quitar a un docente que está asignado a alguna sesión: primero reasigne esas sesiones.

Al guardar verá *Docentes del dictado actualizados correctamente.*

---

## 10. Sesiones del dictado

Pulse **Sesiones** en la página del dictado. Verá **Sesiones del dictado** (la lista actual) y el formulario **Actualizar sesiones**.

| Campo | ¿Obligatorio? | Qué poner |
|---|---|---|
| **Fecha** | No | Día de la clase. |
| **Inicio** / **Fin** | No | Hora de inicio y de fin. |
| **Docente asignado** | No | Elija uno de los docentes del dictado, o *Sin docente asignado*. |
| **Nombre alternativo** | No | Úselo solo si el ponente no está en la lista de docentes. Si completa los dos, el certificado usa el de la lista. |
| **Tema** | Sí | Tema de la clase. |

### Agregar una sesión
Complete la fila **Nueva** (al menos el **Tema**) y pulse **Guardar sesiones**. Si deja el tema vacío y no completa nada más, no se agrega ninguna sesión.

### Modificar sesiones
Las sesiones se guardan **por posición**: la fila 1 actualiza la sesión 1, la fila 2 la sesión 2, y así sucesivamente. Cambie lo que necesite y pulse **Guardar sesiones**. Verá *Sesiones del dictado actualizadas correctamente.*

> Este formulario **no elimina** sesiones. Revise bien antes de agregar una.

### Por qué importan las sesiones
- Sin sesiones **no se puede marcar asistencia ni poner notas**.
- La **segunda página del certificado** imprime el temario a partir de las sesiones: *Clase N*, tema, ponente (el docente asignado, el nombre alternativo o, si no hay ninguno, *Maia Consultores*) y fecha. Si el dictado no tiene sesiones, la tabla del temario sale vacía; el *Temario del dictado* escrito a mano **no** se imprime en el certificado.

---

## 11. Inscribir participantes

Pulse **Inscribir participante** (desde la página del dictado, el catálogo o la lista de participantes). Se abre **Nueva inscripción**, que tiene dos formularios: uno para inscribir a una persona y otro para inscribir a un grupo con un mismo pagador.

### 11.1 Inscribir a una persona

En **Origen del participante** elija de dónde sale la persona. El formulario muestra solo los campos de la opción elegida:

| Opción | Cuándo usarla | Qué completar |
|---|---|---|
| **Contacto del CRM** | La persona ya existe como contacto en el CRM. | Elija el contacto en **Contacto del CRM**. |
| **Participante existente** | La persona ya participó en otro curso. | Elija su ficha en **Participante ya registrado**. |
| **Nuevo participante** | No está en el CRM ni en otros cursos. | Complete los datos de abajo. |

Datos de un **Nuevo participante** (todos obligatorios):

| Campo | Qué poner |
|---|---|
| **Nombres** / **Apellidos** | Tal como deben salir en el certificado. |
| **Tipo de documento** | Por ejemplo *DNI*, *CE* o *Pasaporte*. |
| **Número de documento** | Número del documento. Si ya existe un participante con el mismo tipo y número, el sistema reutiliza esa ficha en lugar de crear otra. |
| **Correo electrónico** | Se usará para enviarle el certificado. |
| **Celular** | **Con código de país**, empezando por **+**. Ej.: *+51 999 111 222*. |

Luego pulse uno de estos botones:

- **Inscribir participante**: inscribe y lo lleva a la lista de participantes.
- **Inscribir y agregar otro**: inscribe y vuelve a abrir el formulario vacío, para cargar varias personas seguidas.

En ambos casos verá *Participante inscrito correctamente.* Para evitar inscripciones duplicadas por doble clic, los botones se desactivan apenas pulsa uno.

> **Al inscribir un contacto del CRM:** el contacto debe tener correo y un teléfono que empiece con **+** (código de país). Si no, la inscripción se rechaza; corrija primero la ficha del contacto.

> Una misma persona no puede inscribirse dos veces en el mismo dictado.

### 11.2 Inscribir un grupo con pagador

Úselo cuando una empresa paga la inscripción de varias personas. Cada participante conserva su propia ficha académica, pero el pago se registra una sola vez con los datos del pagador.

**Datos del pagador:**

| Campo | ¿Obligatorio? | Qué poner |
|---|---|---|
| **Cliente pagador** | No | Elija el cliente del CRM, si existe (o *Sin cliente registrado*). |
| **Nombre o razón social del pagador** | Sí | Nombre de la empresa o persona que paga. |
| **Tipo de documento** / **Número de documento** | No | Ej.: *RUC* y su número. |
| **Notas** | No | Cualquier aclaración. Máximo 1000 caracteres. |

**Participantes del grupo:** se ofrecen tres filas. En cada una complete **Nombres**, **Apellidos**, **Tipo doc.**, **Número doc.**, **Correo** y **Celular** (con código de país). Las filas vacías se ignoran.

Pulse **Inscribir grupo**. Verá *Grupo de N participantes inscrito correctamente.*

> **Todo o nada:** si un solo participante del grupo tiene un error (por ejemplo, ya estaba inscrito), no se inscribe nadie del grupo. Corrija y vuelva a enviar.

> **¿Necesita más de tres personas?** Inscriba el grupo con las primeras tres; para el resto, hoy no hay forma de agregarlas al mismo grupo desde la pantalla (ver [sección 25](#25-lo-que-el-módulo-todavía-no-hace)).

> **El nombre del pagador sale en el certificado:** para los participantes de un grupo, el certificado dice *"Organizado por [nombre del pagador]"*; para los inscritos individualmente dice *"Organizado por Maia Consultores"* (salvo que la plantilla activa defina otra empresa).

---

## 12. Lista de participantes y estado de pago

Pulse **Participantes**. La tabla **Participantes inscritos** muestra:

| Columna | Contenido |
|---|---|
| **Participante** | Apellidos y nombres, documento y correo. |
| **Estado** | Estado de la inscripción (ver abajo). |
| **Estado de pago** | Pendiente, Parcial, Pagado, Exonerado o Reembolsado. |
| **Importe** | Total de la matrícula: precio del dictado + precio del certificado (si aplica) − descuento. |
| **Grupo pagador** | Nombre del pagador si se inscribió en grupo; *—* si no. |
| **Acciones** | Selector de estado de pago y botón **Actualizar pago**. |

### Estado de la inscripción
Toda inscripción nueva queda como **Inscrito**. La pantalla también conoce los estados *Confirmado*, *En curso*, *Completado*, *Retirado* y *No asistió*, pero hoy no hay ningún botón para cambiarlos.

### Cambiar el estado de pago
1. En la fila del participante, elija el nuevo estado.
2. Pulse **Actualizar pago**. Verá *Estado de pago actualizado correctamente.*

Cambios permitidos:

| Desde | Puede pasar a |
|---|---|
| **Pendiente** | Parcial, Pagado, Exonerado |
| **Parcial** | Pagado, Exonerado |
| **Pagado** | Reembolsado |
| **Exonerado** | — (final) |
| **Reembolsado** | — (final) |

> El pago importa para el certificado: solo los participantes en **Pagado** o **Exonerado** pueden recibir su documento académico.

---

## 13. Asistencia

Pulse **Asistencia**. Verá la **Matriz de asistencia**: una fila por participante y una columna por sesión (número, fecha y tema).

### Marcar la asistencia
1. En cada celda elija: **Presente**, **Ausente**, **Tardanza**, **Justificado** o **Sin marcar**.
2. Pulse **Guardar asistencia**. Verá *Asistencia actualizada correctamente.*

Quien no tiene permiso para marcar asistencia ve la matriz en modo lectura.

### Qué efecto tiene
- **En una charla**, la asistencia define la **participación**. Si el participante tiene al menos una sesión en *Presente*, *Tardanza* o *Justificado*, la columna **Participación** muestra *Participación confirmada*; si no, *Participación pendiente*. La participación confirmada, junto con el pago y las validaciones del dictado, habilita el certificado de la charla.
- **En un curso**, la asistencia es **informativa**: no cambia la nota ni el resultado final.

Si el dictado no tiene sesiones, verá el aviso *"Este dictado todavía no tiene sesiones registradas…"*: primero cargue las sesiones ([sección 10](#10-sesiones-del-dictado)).

---

## 14. Notas (solo cursos)

Pulse **Notas** (solo aparece si tiene permiso para cargar notas). Se abre **Notas del dictado** con la **Matriz de notas**.

### Cargar notas
1. En cada celda escriba una nota de **0 a 20**, con hasta **dos decimales** (ej.: *14*, *15.5*, *17.25*).
2. Pulse **Guardar notas**. Verá *Notas actualizadas correctamente.*

Las celdas que deja vacías no se guardan (si no completó ninguna, verá *No se enviaron notas para guardar.*).

### Cómo se calcula el resultado
- Todas las sesiones pesan lo mismo. El promedio se calcula **solo con las notas cargadas**.
- La columna **Resultado** muestra: *Promedio exacto* (4 decimales), *Promedio* (2 decimales), *Redondeado* (entero; ,5 sube) y el resultado final.
- Resultado final:
  - **Aprobado**: si el promedio redondeado es **13 o más** → recibirá *Certificado de aprobación*.
  - **Participación**: si es **menor que 13** → recibirá *Constancia de participación*.
  - **Pendiente**: todavía no tiene notas.

El resultado se recalcula cada vez que guarda.

> **En una charla** la pantalla de notas solo muestra la participación de cada persona: las charlas no usan notas.

> **Ojo con el promedio parcial:** como se promedian solo las notas cargadas, un participante con una sola nota ya tiene resultado. Cargue todas las notas antes de finalizar el dictado.

---

## 15. Documentos académicos: certificados y constancias

Pulse **Documentos** en la página del dictado. Se abre **Documentos académicos del dictado**, con la tabla **Documentos académicos por participante**.

| Columna | Contenido |
|---|---|
| **Participante** | Nombre y documento. |
| **Documento esperado** | El documento que le corresponde según sus datos actuales (o *Sin documento aplicable todavía*). |
| **Elegibilidad** | *Elegible* o *No elegible*, con la lista de **Condiciones pendientes**. |
| **Documentos registrados y acciones** | Cada documento emitido, su estado, su entrega, su historial y los botones de acción. |

### 15.1 Condiciones para recibir un documento

Un participante es **elegible** cuando se cumplen **todas** estas condiciones. Si falta alguna, aparece en la lista con este texto:

| Condición pendiente (texto en pantalla) | Qué falta | Cómo resolverlo |
|---|---|---|
| *Pago pendiente de completar* | El estado de pago no es *Pagado* ni *Exonerado*. | Actualice el pago ([sección 12](#12-lista-de-participantes-y-estado-de-pago)). |
| *Datos del participante incompletos* | Falta nombre, apellido, documento, correo o celular. | Complete la ficha del participante. |
| *Validaciones del dictado pendientes* | El dictado todavía no está *Finalizada*. | Finalice el dictado ([sección 8](#8-la-página-del-dictado-y-sus-estados)). |
| *Resultado académico o participación pendiente* | (Curso) No tiene notas cargadas. | Cargue las notas ([sección 14](#14-notas-solo-cursos)). |
| *Participación de la charla sin confirmar* | (Charla) No tiene asistencia válida, **o** la charla no se creó como *Charla con certificado*. | Marque la asistencia; si la charla no emite certificado, no hay documento. |
| *La matrícula está retirada o el participante no asistió* | La inscripción está en *Retirado* o *No asistió*. | No corresponde documento. |

### 15.2 Qué documento corresponde

| Situación | Documento | Código del documento |
|---|---|---|
| Curso, resultado **Aprobado** | Certificado de aprobación | Empieza con **CERT-APR-** |
| Curso, resultado **Participación** | Constancia de participación | Empieza con **CONST-PAR-** |
| Charla con certificado y participación confirmada | Certificado de charla | Empieza con **CERT-CHARLA-** |

### 15.3 Generación automática y manual

- **Automática:** cuando se completa la última condición que faltaba (por ejemplo, al marcar el pago como *Pagado* o al finalizar un curso), el sistema intenta generar el documento por su cuenta, en segundo plano. Puede tardar unos instantes; actualice la página para verlo.
- **Manual:** si el participante es elegible y no tiene documento vigente, aparece el botón **Generar documento**. Púlselo y verá *Documento académico generado correctamente.* Si todavía no es elegible, verá el aviso *La generación estará disponible cuando se cumplan las condiciones pendientes.*

> En las **charlas**, finalizar el dictado no dispara la generación automática por sí solo. Si la última condición que se cumplió fue la finalización, use **Generar documento**.

### 15.4 Qué contiene el certificado

El PDF tiene dos páginas en formato horizontal:

1. **Portada:** título del documento (en el certificado de aprobación se muestra *CERTIFICADO*), texto de introducción (*"Otorga el presente certificado a"*), nombre del participante, *"Por haber participado en el:"*, nombre del curso o charla, la frase *"Organizado por [empresa], desarrollado en modalidad [modalidad], realizado del [fechas], con una duración de [horas] horas académicas."*, lugar y fecha de emisión (*Lima, [fecha]*), el **código QR**, las **firmas** (dos) y el **Código** del documento.
2. **Temario:** una constancia con los mismos datos y la tabla **Clase / Tema / Ponente / Fecha**, armada con las sesiones del dictado.

Las firmas, el título, el texto de introducción y la empresa se pueden personalizar con [plantillas](#18-plantillas-de-certificados). Si no hay plantilla activa, las firmas por defecto son *Dirección Académica* y *Coordinación Académica*, ambas de *Maia Consultores*.

### 15.5 Estados de un documento académico

| Estado | Significado |
|---|---|
| **En generación** | Se está creando el PDF. |
| **Vigente** | Documento válido. Su QR funciona y se puede enviar. |
| **Reemplazado** | Se generó una versión corregida; este quedó sin efecto y su QR fue revocado. |
| **Anulado** | Se anuló con un motivo; su QR fue revocado. |
| **Fallido** | No se pudo guardar el archivo. Genere el documento nuevamente. |

Cada participante puede tener **un solo documento vigente** a la vez.

### 15.6 Regenerar (corregir) un documento

Úselo cuando hay que corregir un dato (por ejemplo, un nombre mal escrito ya corregido en la ficha).

1. En el documento vigente, escriba el **Motivo de la corrección**.
2. Pulse **Regenerar**.

Resultado: se emite un documento nuevo con otro código; el anterior pasa a **Reemplazado** y su QR deja de funcionar. Mensaje: *Documento regenerado correctamente: el anterior quedó reemplazado y su código QR fue revocado.*

Solo se puede regenerar un documento **vigente** y solo si el participante sigue siendo elegible.

### 15.7 Anular un documento

1. En el documento vigente, escriba el **Motivo de la anulación**.
2. Pulse **Anular**.

El documento pasa a **Anulado** y su QR deja de funcionar. Mensaje: *Documento anulado correctamente: su código QR quedó revocado.* El motivo queda visible como *Motivo registrado*.

Después de anular, si el participante sigue siendo elegible, puede volver a usar **Generar documento**.

---

## 16. Entregar un documento académico

Debajo de cada documento **vigente** (con su archivo disponible) aparecen las acciones de entrega. El estado de entrega del documento se muestra como *Entrega pendiente*, *Enviado*, *Entrega fallida* o *Descartado*, junto con *Último envío*.

### 16.1 Por correo
1. Revise el correo del destinatario (viene precargado con el del participante; puede cambiarlo).
2. Pulse **Enviar por correo**.

Verá *El documento quedó en cola de envío por correo. La entrega se registrará cuando el envío se confirme.* El correo se envía en segundo plano desde la cuenta de correo del sistema, con el asunto *Documento académico: [tipo] ([código])* y un **enlace de descarga seguro válido por 7 días**. Cuando el envío se confirma, el estado pasa a **Enviado**; si falla, a **Entrega fallida** con el error *No fue posible enviar el correo.*

### 16.2 Por WhatsApp (envío asistido)
El CRM no envía WhatsApp por su cuenta: le prepara el mensaje y usted lo envía.

1. Revise el teléfono (precargado con el celular del participante). Escríbalo con código de país, solo números si prefiere (ej.: *51999999999*).
2. Pulse **Abrir WhatsApp**. Se abre WhatsApp con el mensaje *"Hola, le escribimos de Maia Consultores. Puede descargar su documento académico aquí: [enlace]"*.
3. Envíe el mensaje desde WhatsApp.
4. Vuelva al CRM y pulse **Marcar como enviado**. Verá *Entrega por WhatsApp confirmada y registrada en el historial de entregas.*

Si abrió WhatsApp pero finalmente no envió el mensaje, pulse **Descartar intento** (*Intento descartado. No se envió nada y el documento queda como estaba.*).

Mientras haya intentos de WhatsApp sin confirmar, la parte superior de la página muestra un aviso con todos ellos y sus botones **Marcar como enviado** / **Descartar intento**.

> **Importante:** el enlace que viaja por WhatsApp vence **a la hora**. Envíe el mensaje apenas se abra WhatsApp. Si la persona lo abre tarde, envíele el documento por correo (enlace de 7 días) o repita el envío por WhatsApp.

### 16.3 Historial de entregas
Cada documento muestra su **Historial de entregas**: canal (*Correo* o *WhatsApp*), estado del intento (*En cola*, *Enviando*, *Enviado*, *Entregado*, *Intento fallido*, *Omitido* o *Pendiente de confirmar*), destinatario, número de intentos, fecha y, si hubo, el error.

---

## 17. Verificación pública por código QR

Cada documento académico lleva un código QR. Cualquier persona (por ejemplo, un empleador) puede escanearlo con el celular, **sin usuario del CRM**:

- Si el documento está **vigente**, se abre directamente el PDF original.
- Si fue **anulado** o **reemplazado**, o el archivo no está disponible, se muestra el texto *Documento no vigente o no disponible.*

Lo mismo ocurre con los enlaces de descarga enviados por correo o WhatsApp: dejan de funcionar si el documento deja de estar vigente o si el enlace venció.

> Por eso, cuando corrija un certificado, avise al participante: el QR del documento anterior ya no sirve, y debe usar el nuevo.

---

## 18. Plantillas de certificados

Desde el catálogo, pulse **Plantillas de certificados** (requiere permiso). Una plantilla define los textos y firmas que muestra un certificado generado.

### La lista
Columnas: **Plantilla**, **Alcance**, **Versión**, **Estado** (*Activa* / *Inactiva*), **Configuración aplicada** y los botones **Editar** y **Activar** / **Desactivar**.

### Crear o editar una plantilla
Pulse **Nueva plantilla** (o **Editar**) y complete:

| Campo | ¿Obligatorio? | Qué poner |
|---|---|---|
| **Nombre de la plantilla** | Sí | Nombre con el que la identificará. |
| **Alcance por tipo de documento** | Sí | *Certificado de aprobación*, *Constancia de participación*, *Certificado de charla* o *Todos los tipos*. |
| **Título** | No | Título del documento. Vacío = texto por defecto. |
| **Empresa** | No | Nombre que sale en *"Organizado por…"*. Si lo completa, reemplaza también al pagador del grupo. |
| **Texto de introducción** | No | Por defecto: *Otorga el presente certificado a*. |
| **Firmas** (dos filas) | No | **Nombre** y **Cargo** de cada firma. Máximo dos. Cada firma necesita ambos datos. Deje las dos filas vacías para usar las firmas por defecto. |

Pulse **Crear plantilla** (al crear) o **Guardar cambios** (al editar). Cada cambio de configuración aumenta la **versión** de la plantilla (*Plantilla "[nombre]" actualizada en la versión N.*).

### Reglas de uso
- Solo puede haber **una plantilla activa por alcance**. Al activar una, las demás del mismo alcance quedan inactivas.
- Una plantilla **nueva queda activa** apenas se crea.
- Para un tipo de documento, el sistema usa la plantilla activa de ese tipo; si no hay, la activa de *Todos los tipos*; si tampoco hay, el diseño por defecto.
- Cambiar una plantilla **no modifica** los documentos ya emitidos. Para aplicar los cambios a un documento existente, regenérelo.

---

## 19. Comprobantes (documentos comerciales)

Pulse **Comprobantes** en la página del dictado. Aquí se registran las **facturas, boletas o recibos** emitidos fuera del CRM, para tener el respaldo y enviarlos al cliente.

### 19.1 La tabla de comprobantes
Columnas: **Comprobante** (tipo y observaciones), **Pagador**, **Serie y número**, **Emisión**, **Moneda**, **Subtotal**, **Tasa IGV**, **IGV**, **Total**, **Estado**, **Entrega** y **Adjunto privado**. Debajo de cada comprobante está su **Historial de entregas** y sus acciones de envío.

### 19.2 Registrar un comprobante por matrícula
En la sección **Registrar comprobante por matrícula** hay una tarjeta por participante, con un **desglose calculado por el sistema** para cada tipo de comprobante (Subtotal, Tasa IGV, IGV, Total):

- **Factura** y **Boleta**: llevan IGV del **18 %** sobre el subtotal.
- **Recibo**: sin IGV.
- El subtotal es el precio del dictado + el precio del certificado − el descuento de esa matrícula.

Complete el formulario:

| Campo | ¿Obligatorio? | Qué poner |
|---|---|---|
| **Tipo de comprobante** | Sí | Factura (por defecto), Boleta o Recibo. |
| **Pagador (nombre o razón social)** | Sí | A nombre de quién se emitió. |
| **Tipo de documento** / **Número de documento** | No | Ej.: *RUC* / *DNI* y su número. |
| **Serie** / **Número** | No | Serie y número del comprobante emitido. Ej.: *F001* / *000123*. |
| **Fecha de emisión** | No | Fecha del comprobante. Se usa para calcular si su entrega está vencida. |
| **Moneda** | No | Por defecto *PEN*. Tres letras. |
| **Observaciones** | No | Cualquier aclaración. |

Pulse **Registrar comprobante**. Verá *Comprobante registrado. Adjunte el archivo emitido para completarlo.* Los montos no se escriben a mano: siempre los calcula el sistema.

### 19.3 Registrar un comprobante por grupo
Para los grupos con pagador, use la sección **Registrar comprobante por grupo**. El desglose suma todas las matrículas del grupo (sin contar las retiradas o que no asistieron). El pagador viene precargado con el del grupo. Pulse **Registrar comprobante del grupo** (*Comprobante del grupo registrado. Adjunte el archivo emitido para completarlo.*).

No se puede registrar un comprobante de grupo con total cero.

### 19.4 Adjuntar el archivo emitido
En la columna **Adjunto privado** de un comprobante *Sin adjunto*:
1. Elija el archivo (PDF, Word, Excel, imagen JPG/PNG o texto, dentro del tamaño máximo configurado para documentos).
2. Pulse **Adjuntar archivo**. Verá *Archivo del comprobante adjuntado correctamente.*

### 19.5 Estados del comprobante

| Estado | Significado |
|---|---|
| **Pendiente de archivo** | Registrado sin archivo. Todavía no se puede enviar. |
| **Registrado** | Ya tiene su archivo. Se puede enviar. |

(La pantalla también conoce los estados *Enviado* y *Descartado*, pero hoy ninguna acción los asigna.)

### 19.6 Enviar un comprobante
Funciona igual que con los documentos académicos ([sección 16](#16-entregar-un-documento-académico)): **Enviar por correo** o **Abrir WhatsApp** → **Marcar como enviado** / **Descartar intento**. El destinatario se precarga con el correo y celular del participante o, en comprobantes de grupo, con los del cliente pagador. El estado de entrega se muestra como *Entrega pendiente*, *Enviado*, *Entrega fallida* o *Descartado*.

> **Aviso:** en la versión actual, el envío de comprobantes **por correo** depende de una cuenta de correo vinculada y puede terminar en *Entrega fallida* con el error *No fue posible enviar el correo.* Si le ocurre, envíe el comprobante por WhatsApp y avise al administrador.

---

## 20. Alertas de entrega

Desde el catálogo (**Alertas de entrega**) o desde el panel principal (**Ver alertas de entrega**). Aquí se reúnen, en una sola lista, todos los documentos académicos **vigentes** y comprobantes **registrados** cuya entrega sigue **pendiente** o **fallida**.

### Indicadores
- **Entregas pendientes**: total de documentos sin entregar.
- **Entregas vencidas**: los que superaron el plazo de entrega.
- **Resultado del filtro**: cantidad que coincide con los filtros aplicados.

### Plazo de entrega
El plazo es de **1 día** desde la fecha de emisión (o, si el comprobante no tiene fecha de emisión, desde que se registró). Una entrega se marca **Vencida** a partir del segundo día después de la emisión; mientras tanto se marca **En plazo**. Ejemplo: un documento emitido el día 18 sigue *En plazo* el 19 y aparece *Vencida* desde el 20.

### Filtros
**Tipo** (curso/charla), **Dictado**, **Participante**, **Responsable**, **Tipo de documento**, **Estado de entrega**, **Canal del último intento**, **Emitido desde** y **Emitido hasta**. Pulse **Filtrar**; **Limpiar** quita todos los filtros. Si un filtro llega con un valor no válido, la pantalla lo avisa y no muestra resultados hasta que lo corrija.

### Qué muestra cada fila
- **Documento**: *Documento académico* o *Comprobante*, su código o número y su tipo.
- **Curso o charla y dictado**.
- **Participante y responsable** (hoy siempre *Sin responsable*: ver [sección 25](#25-lo-que-el-módulo-todavía-no-hace)).
- **Estado de entrega**, con *Vencida* o *En plazo*, la fecha de emisión y si el archivo está disponible (*Archivo disponible: la entrega puede intentarse.* o *No entregable: falta el archivo privado…*).
- **Último intento**: canal, cantidad de intentos, fecha, estado y último error.

### Descartar una alerta
Cuando un documento no necesita entregarse (por ejemplo, el participante ya lo recibió en mano), escriba el **Motivo (obligatorio)** y pulse **Descartar**. Mensaje: *Alerta de entrega descartada. El documento sigue vigente.* El documento pasa a estado de entrega *Descartado* y sale de la lista, pero **no se anula**. Una entrega ya enviada no se puede descartar.

Para enviar el documento, vaya a la pantalla **Documentos** o **Comprobantes** del dictado.

---

## 21. Dónde se guardan los archivos

Los certificados en PDF y los archivos de comprobantes se guardan como **archivos privados**: nadie puede abrirlos sin pasar por el CRM, por el QR o por un enlace seguro con vencimiento.

Según la configuración del servidor, se almacenan en el propio servidor o en la nube (AWS S3). **Para usted no cambia nada**: los enlaces, el QR y los envíos funcionan igual en ambos casos.

---

## 22. Mensajes de error frecuentes

Algunos mensajes de validación muestran el nombre interno del campo (en inglés o sin traducir). La tabla indica a qué corresponden.

| Mensaje / situación | Causa | Qué hacer |
|---|---|---|
| *El campo type / code / name es obligatorio.* | Falta Tipo, Código o Nombre del curso. | Complete el campo. |
| *El campo official academic hours es obligatorio.* / *Las horas académicas son obligatorias.* | Faltan las horas académicas. | Escriba las horas. |
| *Course activity code … is already in use.* | El código del curso ya existe. | Use otro código. |
| *Course edition code … is already in use.* | El código del dictado ya existe. | Use otro código o déjelo vacío. |
| *Presential editions require an address.* | Modalidad presencial sin dirección. | Complete **Dirección**. |
| *Virtual editions require an access URL.* | Modalidad virtual sin enlace. | Complete **Enlace de acceso virtual**. |
| *Hybrid editions require both address and access URL.* | Híbrida sin dirección o sin enlace. | Complete ambos. |
| *El campo ends on debe ser una fecha posterior o igual a starts on.* | La fecha de fin es anterior a la de inicio. | Corrija las fechas. |
| *El campo price amount es obligatorio.* / *El precio del dictado es obligatorio.* | Falta el precio. | Escriba el precio (puede ser 0). |
| *El nombre del docente es obligatorio.* | Una fila de docente quedó sin nombre. | Escriba el nombre o marque **Quitar**. |
| *No se puede quitar el docente porque está asignado a una o más sesiones…* | El docente tiene sesiones. | Reasigne esas sesiones y vuelva a intentar. |
| Error de *topic* obligatorio en sesiones | Una sesión (o la fila *Nueva* con datos) no tiene tema. | Escriba el **Tema**. |
| *Participants require names, document type and number, email, and a mobile number with country code.* | Faltan datos del participante o el celular no empieza con **+**. Ocurre también al inscribir un contacto sin correo o con teléfono sin código de país. | Complete los datos; escriba el celular como *+51…*. |
| *This participant is already enrolled in the selected edition.* | La persona ya está inscrita en este dictado. | No hace falta inscribirla de nuevo. |
| *Payment transition from … to … is not permitted.* | Cambio de pago no permitido (por ejemplo, de *Pendiente* a *Reembolsado*). | Siga la tabla de la [sección 12](#12-lista-de-participantes-y-estado-de-pago). |
| *Invalid grade […].* | Nota fuera de 0–20 o con más de dos decimales, o con coma en lugar de punto. | Escriba la nota con punto decimal, ej. *15.5*. |
| *Talk editions do not accept grades.* | Se intentó poner notas a una charla. | Las charlas no llevan notas. |
| *No se pudo guardar la asistencia / las notas: una de las sesiones o participantes ya no está disponible…* | La página estaba desactualizada. | Recargue la página e intente de nuevo. |
| *La matrícula todavía no es elegible para generar documento académico.* | Falta alguna condición. | Revise **Condiciones pendientes**. |
| *La matrícula ya cuenta con un documento académico vigente…* | Ya tiene un documento vigente. | Use **Regenerar** con un motivo. |
| *No se pudo regenerar el documento: solo un documento vigente puede regenerarse.* | El documento ya estaba anulado o reemplazado. | Trabaje sobre el documento vigente. |
| *Solo un documento vigente puede anularse…* | Se intentó anular un documento no vigente. | Nada que hacer: ya no está vigente. |
| *Solo un documento vigente con su archivo privado disponible puede entregarse.* | El documento no está vigente, falta su archivo **o el teléfono de WhatsApp no es válido** (debe tener entre 8 y 15 dígitos). | Revise el estado y el número de teléfono. |
| *No se pudo confirmar el envío: el teléfono no coincide con el handoff de WhatsApp registrado.* | Se cambió el número al confirmar. | Confirme con el mismo número con el que abrió WhatsApp. |
| *Solo un comprobante registrado con su archivo privado disponible puede entregarse.* | El comprobante no tiene archivo. | Adjunte el archivo primero. |
| *El grupo no tiene matrículas facturables…* / *El subtotal del grupo es cero…* | El grupo no tiene montos a facturar. | Revise las matrículas del grupo. |
| *Descartar la alerta requiere un motivo.* | Se pulsó **Descartar** sin motivo. | Escriba el motivo. |
| *Una entrega enviada no se puede descartar.* | El documento ya figura como enviado. | Nada que hacer. |
| *Documento no vigente o no disponible.* (al abrir un QR o enlace) | El documento fue anulado o reemplazado, o el enlace venció. | Envíe el documento vigente nuevamente. |
| No aparece un botón (Notas, Generar documento, Enviar…) | Su rol no tiene ese permiso. | Pida el permiso a un administrador. |

---

## 23. Preguntas frecuentes

**¿Por qué un participante aparece como *No elegible* si ya pagó y aprobó?**
Lo más habitual es que el dictado todavía no esté en **Finalizada**. Revise también que su ficha tenga todos los datos (incluido el celular).

**Finalicé el dictado y no se generaron los certificados. ¿Qué hago?**
La generación automática corre en segundo plano y puede tardar; actualice la página. Si sigue sin aparecer (por ejemplo, en charlas), use **Generar documento** en cada participante elegible.

**Me equivoqué en el nombre del participante y ya emití el certificado.**
Corrija la ficha y use **Regenerar** con el motivo. El certificado anterior queda reemplazado y su QR deja de funcionar. Envíe el nuevo.

**¿Puedo cambiar las firmas de los certificados?**
Sí, con una plantilla ([sección 18](#18-plantillas-de-certificados)). Afecta solo a los documentos que se generen desde ese momento.

**¿El temario del certificado sale del temario que escribí en el dictado?**
No. Sale de las **sesiones** del dictado. Cargue las sesiones con su tema, docente y fecha antes de generar.

**¿Por qué el certificado dice "Organizado por" el nombre de una empresa?**
Porque el participante se inscribió en un grupo y ese es el nombre del pagador. Si quiere que siempre diga lo mismo, complete **Empresa** en la plantilla activa.

**¿Una charla sin certificado puede emitir algún documento?**
No. Solo las charlas creadas con *Charla con certificado* emiten *Certificado de charla*.

**¿El participante tiene que tener usuario para descargar su certificado?**
No. Recibe un enlace seguro (por correo o WhatsApp) y el certificado también puede abrirse escaneando el QR.

**El enlace que mandé por WhatsApp no funciona.**
Ese enlace vence a la hora. Envíe el documento por correo (enlace de 7 días) o repita el envío por WhatsApp.

**¿Puedo registrar dos comprobantes para la misma matrícula?**
Sí. Cada envío del formulario registra un comprobante nuevo (por ejemplo, un pago en cuotas). Tenga cuidado de no registrar dos veces el mismo.

**¿Cómo veo o descargo el PDF de un certificado desde el CRM?**
Hoy la pantalla **Documentos** no tiene un botón para verlo. Puede enviárselo a usted mismo por correo o escanear el QR.

**¿Qué pasa si descarto una alerta?**
Solo se cierra el seguimiento de la entrega. El documento sigue vigente y puede enviarse más adelante desde su pantalla.

---

## 24. Ejemplos prácticos

### Curso virtual con participantes individuales
1. **Catálogo → Nuevo curso o charla**: Tipo *Curso*, Código *EXC-AV*, Nombre *Excel avanzado*, Horas académicas *12*, temario base con cuatro temas.
2. **Ver detalle → Nuevo dictado**: Código *EXC-AV-2026-03*, Modalidad *Virtual*, Precio *350*, fechas 05/03 al 26/03, Enlace de acceso virtual con el link de Meet.
3. **Docentes**: agregue a la docente. **Sesiones**: cargue cuatro sesiones con fecha, horario, docente y tema.
4. Pulse **Programada**.
5. **Inscribir participante** → *Nuevo participante* → datos con celular *+51 9…* → **Inscribir y agregar otro** para cada inscrito.
6. Al empezar, pulse **En curso**. Después de cada clase, marque la **Asistencia** y cargue las **Notas**.
7. En **Participantes**, pase a *Pagado* a quienes pagaron.
8. Al terminar, pulse **Finalizada**.
9. En **Documentos**: quienes promedian 13 o más reciben *Certificado de aprobación*; el resto, *Constancia de participación*. Si alguno no se generó solo, pulse **Generar documento**.
10. **Enviar por correo** a cada participante. Revise **Alertas de entrega** al día siguiente.

### Charla gratuita con certificado pagado
1. Cree la charla marcando **Charla con certificado** (Horas académicas *2*).
2. Nuevo dictado: Modalidad *Presencial*, Dirección del auditorio, Precio *0*, **Precio del certificado** *30*. El *Total por matrícula* será 30.
3. Cargue una sesión con el tema y el ponente.
4. Inscriba a los asistentes. Durante el evento marque **Presente** en la asistencia: la columna **Participación** pasa a *Participación confirmada*.
5. Para quienes pagaron el certificado, cambie el pago a **Pagado**; para invitados especiales, a **Exonerado**. Quienes no pagan quedan en *Pendiente* y no reciben certificado.
6. Finalice el dictado y use **Generar documento** para cada participante elegible (*Certificado de charla*).
7. Entréguelos por **Abrir WhatsApp** → enviar → **Marcar como enviado**.

### Empresa que inscribe a su equipo
1. En **Nueva inscripción → Inscripción de grupo con pagador**: Cliente pagador *(el cliente del CRM)*, Nombre o razón social *Constructora Andina S.A.C.*, Tipo de documento *RUC* y número.
2. Complete una fila por persona y pulse **Inscribir grupo**.
3. En **Comprobantes → Registrar comprobante por grupo**, elija *Factura*, revise el desglose (subtotal + IGV 18 %), complete serie, número y fecha de emisión, y pulse **Registrar comprobante del grupo**.
4. Adjunte el PDF de la factura con **Adjuntar archivo** y envíelo al cliente.
5. Los certificados de estas personas dirán *"Organizado por Constructora Andina S.A.C."* (salvo que la plantilla activa defina otra empresa).

---

## 25. Lo que el módulo todavía no hace

- **Editar o eliminar** un curso, una charla o un dictado desde la pantalla. Revise bien los datos antes de guardar (sobre todo horas académicas, nombre y fechas, que salen en el certificado).
- **Asignar un responsable** al dictado: el formulario no tiene ese campo, por eso las alertas muestran *Sin responsable* y el filtro *Responsable* no encuentra resultados.
- **Eliminar sesiones** ya cargadas: el formulario solo agrega y modifica.
- **Cambiar el estado de la inscripción** (*Confirmado*, *Retirado*, *No asistió*, etc.): toda inscripción queda como *Inscrito*.
- **Anular o quitar una inscripción**, ni **aplicar descuentos** desde la pantalla (el descuento siempre es 0).
- **Agregar participantes a un grupo ya inscrito**, ni inscribir más de tres personas por vez en el formulario de grupo.
- **Borrar una nota** ya cargada: dejar la celda vacía no la elimina.
- **Reabrir** un dictado finalizado o cancelado.
- **Ver o descargar** el PDF de un certificado o comprobante desde las pantallas del módulo.
- **Reemplazar el archivo** de un comprobante que ya tiene adjunto, ni **anular** un comprobante.
- **Enviar WhatsApp automáticamente**: el envío siempre lo hace una persona desde WhatsApp.
- Usar la casilla **Activa** del curso o charla para algo visible: hoy no tiene efecto.
- Usar el permiso `course-talks.audit.view`: no hay pantalla de auditoría propia del módulo.
- Imprimir en el certificado el **Temario del dictado** escrito a mano: solo se imprimen las sesiones.
