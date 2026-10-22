# Guía de usuario — Soporte técnico

Esta guía explica, paso a paso, cómo usar el módulo **Soporte** del CRM Maia Consultores para registrar los pedidos de ayuda de los clientes como **tickets**, atenderlos (asignar, programar, poner en espera, resolver, cerrar o reabrir), controlar los puntos pendientes con **observaciones** y medir los tiempos de atención en el **Dashboard de soporte**.

---

## Índice

1. [¿Qué es el módulo de soporte?](#1-qué-es-el-módulo-de-soporte)
2. [Tipos de ticket y listas del módulo](#2-tipos-de-ticket-y-listas-del-módulo)
3. [Quién puede hacer qué](#3-quién-puede-hacer-qué)
4. [Dónde encontrar el módulo](#4-dónde-encontrar-el-módulo)
5. [Crear un ticket](#5-crear-un-ticket)
6. [Estados del ticket (ciclo de vida)](#6-estados-del-ticket-ciclo-de-vida)
7. [La ficha del ticket](#7-la-ficha-del-ticket)
8. [Asignar y reasignar](#8-asignar-y-reasignar)
9. [Iniciar la atención](#9-iniciar-la-atención)
10. [Poner en espera y reanudar](#10-poner-en-espera-y-reanudar)
11. [Programar y reprogramar la atención](#11-programar-y-reprogramar-la-atención)
12. [Incidentes](#12-incidentes)
13. [Observaciones](#13-observaciones)
14. [Resolver](#14-resolver)
15. [Cerrar](#15-cerrar)
16. [Reabrir](#16-reabrir)
17. [Cancelar](#17-cancelar)
18. [Cambiar la prioridad](#18-cambiar-la-prioridad)
19. [Editar el ticket](#19-editar-el-ticket)
20. [Historial y registro de acciones](#20-historial-y-registro-de-acciones)
21. [Notas internas y respuestas al cliente](#21-notas-internas-y-respuestas-al-cliente)
22. [Documentos](#22-documentos)
23. [La lista de tickets: búsqueda y Mis tickets](#23-la-lista-de-tickets-búsqueda-y-mis-tickets)
24. [El Dashboard de soporte](#24-el-dashboard-de-soporte)
25. [Exportar a CSV](#25-exportar-a-csv)
26. [Mensajes frecuentes](#26-mensajes-frecuentes)
27. [Preguntas frecuentes](#27-preguntas-frecuentes)
28. [Ejemplo práctico: un error en la facturación de un cliente](#28-ejemplo-práctico-un-error-en-la-facturación-de-un-cliente)
29. [Lo que todavía no hace](#29-lo-que-todavía-no-hace)

---

## 1. ¿Qué es el módulo de soporte?

Un **ticket de soporte** es el registro de un pedido de ayuda de un **cliente**: una capacitación, una reunión, una consulta funcional, una configuración, una queja o un error del sistema. El ticket acompaña todo el caso: quién lo pidió, quién lo atiende, cuándo se programó, qué se hizo, cómo se resolvió y si el cliente lo validó.

Cada ticket:

- Recibe un **código** automático con el formato **SUP-AAAA-NNNNN** (por ejemplo, *SUP-2026-00001*).
- Pertenece a un **cliente** y tiene un **contacto solicitante** de ese cliente.
- Tiene un **responsable** (la persona que lo atiende) y, opcionalmente, un **equipo**.
- Avanza por **estados** (*Nuevo*, *Asignado*, *En atención*, *Resuelto*, *Cerrado*…); en cada estado la ficha muestra **solo las acciones válidas**.
- Puede tener **observaciones**: puntos concretos que hay que levantar y validar antes de cerrar.
- Guarda los **tiempos** de cada etapa para calcular indicadores (primera respuesta, resolución, tiempo efectivo, reaperturas).

### Solicitud, incidente y observación

| Concepto | Qué es | Dónde se ve |
|---|---|---|
| **Solicitud** | El ticket completo: cualquier pedido del cliente (capacitación, reunión, ayuda, configuración…). | La ficha del ticket. |
| **Incidente** | Un ticket del tipo **Incidente o error**. Además de los datos comunes, permite registrar datos técnicos (sistema, resultado obtenido, solución técnica). | Tarjeta **Incidente** de la ficha (ver [sección 12](#12-incidentes)). |
| **Observación** | Un punto específico **dentro** de un ticket que hay que atender: *"El reporte no muestra el IGV"*, *"Falta capacitar al área de caja"*. Tiene su propio estado. | Tarjeta **Observaciones** de la ficha (ver [sección 13](#13-observaciones)). |

---

## 2. Tipos de ticket y listas del módulo

Las listas desplegables del módulo vienen cargadas con estos valores (configuración inicial del CRM):

| Lista | Valores |
|---|---|
| **Tipo** | Reunión, Capacitación, Ayuda funcional, Asesoría, Configuración, **Incidente o error**, Queja, Levantamiento de observaciones, Otro |
| **Categoría** | General, Funcional, Técnico, Capacitación, Configuración |
| **Canal** (por dónde llegó el pedido) | Registro interno, Llamada, WhatsApp, Correo, Reunión, Presencial, Otro |
| **Prioridad** | Baja, Media, Alta, Crítica |
| **Estado** | Nuevo, Asignado, Programado, En atención, En espera del cliente, En espera interna, Resuelto, Cerrado, Cancelado, Reabierto |

- Solo el tipo **Incidente o error** muestra la tarjeta **Incidente** en la ficha.
- Los estados no se eligen en un desplegable: cambian con los botones de acción (ver [sección 6](#6-estados-del-ticket-ciclo-de-vida)).
- Hoy no hay una pantalla para agregar o cambiar estos valores (ver [sección 29](#29-lo-que-todavía-no-hace)).

---

## 3. Quién puede hacer qué

Lo que cada persona ve y puede hacer depende de los **permisos de su rol** (menú **Administración → Roles**, módulo **Soporte**; ver la guía *Roles y permisos*). Los nombres de esta tabla son los que aparecen en esa pantalla.

### 3.1 ¿Qué tickets puede ver?

Se elige **una sola** opción:

| Opción en Roles | Qué tickets ve |
|---|---|
| **Ver solo sus tickets** | Los tickets de los que es **responsable**, más los que **creó y aún no tienen responsable**. |
| **Ver los tickets de su equipo** | Los tickets de los **equipos activos que supervisa** (asignados a ese equipo o a cualquiera de sus miembros), además de los suyos y los que creó sin responsable. |
| **Ver todos los tickets** | Todos los tickets de la empresa. |

> **Importante:** si abre el enlace de un ticket que está fuera de lo que puede ver, el sistema responde con un error de acceso. Así se protege la información de otros clientes y equipos.

> Si usted crea un ticket con la opción *Ver solo sus tickets* y luego se asigna a **otra persona**, **dejará de verlo** (ya no es suyo ni está sin responsable).

### 3.2 Acciones

| Permiso (nombre en Roles) | Qué permite | Supervisor* | Vendedor* |
|---|---|:---:|:---:|
| **Ver reportes de soporte** | Ver el **Dashboard de soporte** y **Exportar CSV** | ✔ | |
| **Crear tickets** | Botón **Nuevo ticket** | ✔ | ✔ |
| **Editar tickets** | **Editar ticket** y guardar el detalle de **Incidente** | ✔ | ✔ |
| **Registrar avances** | Agregar **notas internas** y **respuestas al cliente** | ✔ | ✔ |
| **Cambiar prioridad** | Tarjeta **Prioridad** | ✔ | |
| **Asignar tickets** | Asignar el **primer** responsable | ✔ | |
| **Reasignar tickets** | Cambiar el responsable de un ticket ya asignado | ✔ | |
| **Programar atención** | Tarjeta **Programación** y registrar participantes de una sesión | ✔ | |
| **Reprogramar atención** | Botón **Reprogramar** de una actividad | ✔ | |
| **Iniciar atención** | **Iniciar atención**, **Poner en espera** y **Reanudar atención** | ✔ | |
| **Registrar observaciones** | Agregar observaciones | ✔ | |
| **Levantar observaciones** | Botones **Iniciar** y **Levantar** de una observación | ✔ | |
| **Validar observaciones** | Botones **Validar** y **No aplica** | ✔ | |
| **Rechazar observaciones** | Botones **Rechazar** y **Reabrir** | ✔ | |
| **Resolver tickets** | Botón **Resolver** | ✔ | |
| **Cerrar tickets** | Botón **Cerrar** | ✔ | |
| **Cerrar tickets con observaciones pendientes** (marcado como sensible) | Casilla **Cerrar con observaciones pendientes** | ✔ | |
| **Reabrir tickets** | Botón **Reabrir** | ✔ | |
| **Anular tickets** | Botón **Cancelar ticket** | ✔ | |
| **Gestionar catálogos de soporte** | *Reservado: todavía sin pantalla.* | | |

\* Configuración inicial del CRM: el **supervisor** tiene además *Ver los tickets de su equipo*; el **vendedor**, *Ver solo sus tickets*. El **administrador** tiene acceso total y ve todos los tickets. Un administrador puede cambiar estos permisos en **Roles**.

> Todas las acciones requieren, además del permiso, que usted **pueda ver** el ticket según la sección 3.1.

---

## 4. Dónde encontrar el módulo

En el **menú lateral izquierdo** hay dos entradas:

- **Soporte**: la lista de tickets (página **Soporte técnico**). Aparece si tiene alguna de las opciones de *¿Qué tickets puede ver?*.
- **Dashboard de soporte**: indicadores y exportación. Aparece solo si tiene el permiso *Ver reportes de soporte*.

Desde la lista, el botón **Ver** de cada fila abre la **ficha del ticket**.

---

## 5. Crear un ticket

1. Menú **Soporte** → botón **Nuevo ticket**.
2. Se abre el formulario **Nuevo ticket de soporte**. Complete los campos:

| Campo | ¿Obligatorio? | Qué poner |
|---|---|---|
| **Título** | Sí | Resumen corto del pedido. Ej.: *No se emite la factura electrónica*. Máximo 200 caracteres. |
| **Prioridad** | Sí | *Baja*, *Media*, *Alta* o *Crítica*. Se puede cambiar después (ver [sección 18](#18-cambiar-la-prioridad)). |
| **Cliente** | Sí | El cliente que pide ayuda. Se muestra como *código — nombre comercial* (o razón social). |
| **Contacto solicitante** | Sí | La persona del cliente que hizo el pedido. Primero elija el **Cliente**: la lista solo muestra los contactos de ese cliente (mientras no elija cliente, dice *Seleccione primero un cliente*). |
| **Tipo** | Sí | Ver [sección 2](#2-tipos-de-ticket-y-listas-del-módulo). Elija **Incidente o error** si es una falla del sistema. |
| **Categoría** | Sí | General, Funcional, Técnico, Capacitación o Configuración. |
| **Canal** | Sí | Por dónde llegó el pedido: Registro interno, Llamada, WhatsApp, Correo, Reunión, Presencial u Otro. |
| **Equipo** | No | El equipo que se encargará del caso, o **Sin equipo**. Solo aparecen equipos **activos**: todos si usted ve todos los tickets; si no, los equipos que usted supervisa o a los que pertenece. |
| **Impacto** | No | A quién o a qué afecta. Ej.: *Detiene la facturación de toda la empresa*. Máximo 100 caracteres. |
| **Descripción** | Sí | Qué pide el cliente, con el detalle necesario para atenderlo. |
| **Observaciones generales** | No | Comentarios generales del caso. |

3. Pulse **Guardar ticket**. El sistema lo lleva a la ficha del ticket y muestra *"Ticket de soporte creado."*.

Si quiere salir sin guardar, pulse **Cancelar**.

Tenga en cuenta:

- El ticket nace en estado **Nuevo** y **sin responsable**. El responsable se asigna después desde la ficha (ver [sección 8](#8-asignar-y-reasignar)).
- **Impacto** y **Observaciones generales** también se pueden completar o corregir después con **Editar ticket** (ver [sección 19](#19-editar-el-ticket)).
- El **equipo** también se puede cambiar al asignar o reasignar el responsable.
- Si falta un dato o no es válido, el mensaje aparece debajo del campo con su nombre en español (por ejemplo, *El campo cliente es obligatorio.*).
- El **Cliente** y el **Contacto solicitante** no se pueden cambiar después.

---

## 6. Estados del ticket (ciclo de vida)

### 6.1 Los estados

| Estado | Significado |
|---|---|
| **Nuevo** | Recién registrado, sin responsable. |
| **Asignado** | Ya tiene responsable; aún no se empieza a atender. |
| **Programado** | Tiene una sesión o atención agendada. |
| **En atención** | Alguien lo está atendiendo. En la ficha aparece el botón deshabilitado **Atención en marcha**. |
| **En espera del cliente** | Se espera algo del cliente (información, acceso, una prueba). **Pausa el tiempo efectivo.** |
| **En espera interna** | Se espera algo de un área interna. **No pausa** el tiempo efectivo. |
| **Resuelto** | Se registró la solución. Falta cerrarlo (o reabrirlo si el cliente indica que sigue fallando). |
| **Cerrado** | Caso terminado. Solo se puede **reabrir**. |
| **Cancelado** | El pedido no procede. **No admite ninguna acción** y no se puede reabrir. |
| **Reabierto** | Se volvió a abrir después de resuelto o cerrado; empieza un **nuevo ciclo de atención**. |

### 6.2 Cambios de estado permitidos

| Desde | Puede pasar a |
|---|---|
| **Nuevo** | Asignado, Cancelado (y directamente a *En atención* o *Programado* asignándose usted mismo; ver secciones 9 y 11) |
| **Asignado** | Programado, En atención, En espera del cliente, En espera interna, Cancelado |
| **Programado** | En atención, En espera del cliente, En espera interna, Cancelado |
| **En atención** | En espera del cliente, En espera interna, Resuelto, Cancelado |
| **En espera del cliente** | En atención, Programado, Cancelado |
| **En espera interna** | En atención, Programado, Cancelado |
| **Resuelto** | Cerrado, Reabierto |
| **Cerrado** | Reabierto |
| **Reabierto** | Asignado, Programado, En atención, Cancelado |
| **Cancelado** | — (ninguno) |

Cualquier otro cambio es rechazado con el mensaje *"Transición de soporte no permitida."*. Por ejemplo, **no** se puede resolver un ticket que no está *En atención*: primero hay que iniciar la atención.

### 6.3 Qué acciones aparecen en cada estado

La tarjeta **Acciones** de la ficha muestra solo los botones válidos para el estado actual (y para los que usted tiene permiso):

| Estado | Tarjeta **Acciones** | Tarjeta **Responsable** | Tarjeta **Programación** |
|---|---|---|---|
| **Nuevo** | Iniciar atención · Cancelar ticket | Asignar | Programar |
| **Asignado** | Iniciar atención · Poner en espera · Cancelar ticket | Reasignar | Programar |
| **Programado** | Iniciar atención · Poner en espera · Cancelar ticket | Reasignar | Programar |
| **En atención** | *Atención en marcha* (deshabilitado) · Poner en espera · Resolver · Cancelar ticket | Reasignar | Programar |
| **En espera del cliente** / **En espera interna** | Reanudar atención · Cancelar ticket | Reasignar | Programar |
| **Resuelto** | Cerrar · Reabrir | *No admite cambios de responsable* | Solo consulta (no se programa ni se reprograma) |
| **Cerrado** | Reabrir | *No admite cambios de responsable* | Solo consulta |
| **Cancelado** | *No hay acciones disponibles para este ticket.* | *No admite cambios de responsable* | Solo consulta |
| **Reabierto** | Iniciar atención · Cancelar ticket | Reasignar | Programar |

Además:

- **Editar ticket**, la tarjeta **Prioridad**, agregar observaciones, cambiar el estado de las observaciones, el formulario de **Incidente**, **Reprogramar** y registrar **participantes** están disponibles en todos los estados **excepto Cerrado y Cancelado**. El sistema también lo comprueba al guardar: si el ticket se cerró o canceló mientras usted tenía la página abierta, verá un mensaje de error (por ejemplo, *"No se puede reprogramar la atención de un ticket cerrado o cancelado."*) y no se guarda nada.
- Las **notas internas**, las **respuestas al cliente** y los **documentos** se pueden agregar en cualquier estado.
- Si usted no tiene ninguno de los permisos de acción (*Iniciar atención*, *Resolver tickets*, *Cerrar tickets*, *Reabrir tickets*, *Anular tickets*), la tarjeta dice *"No hay acciones disponibles para este ticket."*.

**Errores junto a cada formulario:** si a un formulario de la ficha le falta un dato (por ejemplo, el motivo de cierre), el mensaje aparece **debajo de ese mismo formulario** y lo que escribió se conserva; los demás formularios no muestran el error.

> **Doble clic sin duplicados:** si pulsa dos veces el mismo botón, o dos personas hacen la misma acción a la vez, el cambio se registra **una sola vez**. Si el estado ya cambió y la acción ya no es válida, verá un mensaje de error en lugar de un fallo.

---

## 7. La ficha del ticket

Desde la lista, botón **Ver**. La página **Ticket SUP-…** tiene:

### Cabecera
- *Código · Tipo*, el **título**, la etiqueta de **estado**, **Prioridad: …**, el **responsable** (o *Sin asignar*) y el **cliente**.
- Botones **Editar ticket** (si tiene permiso y el ticket no está cerrado ni cancelado) y **Volver** (a la lista).

### Columna izquierda
- **Detalle**: Cliente, Contacto solicitante (con su correo), Categoría / canal, Responsable, Equipo (o *Sin equipo*), Descripción y, si existen, Impacto y Observaciones generales.
- **Registro de acciones**: los textos de solución, cierre, reapertura y cancelación (ver [sección 20](#20-historial-y-registro-de-acciones)).
- **Documentos** del ticket (ver [sección 22](#22-documentos)).
- **Actualizaciones**: notas internas y respuestas al cliente (ver [sección 21](#21-notas-internas-y-respuestas-al-cliente)).
- **Observaciones** (ver [sección 13](#13-observaciones)).
- **Historial**: asignaciones, reprogramaciones y estados (ver [sección 20](#20-historial-y-registro-de-acciones)).

### Columna derecha
- **Responsable**: asignar o reasignar.
- **Acciones**: iniciar, poner en espera, reanudar, resolver, cerrar, reabrir, cancelar.
- **Prioridad**: cambiar la prioridad.
- **Programación**: programar una atención y ver las actividades programadas.
- **Incidente**: solo en tickets del tipo *Incidente o error*.
- **Asistencia: …**: una tarjeta por cada sesión programada, para registrar participantes.

Todas las acciones piden confirmación en una ventana antes de ejecutarse.

---

## 8. Asignar y reasignar

En la tarjeta **Responsable**:

1. Elija el responsable en **Seleccione responsable** (solo aparecen usuarios **activos**).
2. Elija el equipo o deje **Sin equipo**.
3. Escriba el motivo:
   - Primera asignación: **Motivo de asignación (opcional)**.
   - Si el ticket ya tiene responsable: **Motivo de reasignación (obligatorio)**.
4. Pulse **Asignar** (o **Reasignar**) y confirme. Verá *"Responsable asignado."* o *"Responsable reasignado."*.

Reglas:

| Situación | Qué pasa |
|---|---|
| Ticket **Nuevo** o **Reabierto** | Además de guardar el responsable, el ticket pasa a **Asignado**. |
| Ticket **Asignado, Programado, En atención** o **en espera** | Cambia el responsable (y el equipo) pero el ticket **conserva su estado**. |
| Ticket **Resuelto, Cerrado** o **Cancelado** | No se puede: la tarjeta dice *"El ticket … no admite cambios de responsable."*. |
| Primera asignación | Requiere el permiso *Asignar tickets*. |
| Ticket que ya tiene responsable (incluido un *Reabierto*) | Requiere el permiso *Reasignar tickets* y el motivo es obligatorio. |
| Responsable inactivo | Se rechaza con *"El responsable debe ser un usuario activo."*. |
| Equipo inactivo o inexistente | Se rechaza con *"El equipo seleccionado no existe o no está activo."*. La lista solo ofrece equipos activos. |

- El **equipo** se guarda tal como lo deje en el formulario: si reasigna y deja **Sin equipo**, el ticket queda sin equipo.
- Cada asignación queda en **Historial → Asignaciones** con fecha, responsable anterior → nuevo, cambio de equipo, quién la hizo y el motivo.
- Si no tiene permiso, la tarjeta dice *"No tiene permiso para asignar este ticket."*.

---

## 9. Iniciar la atención

En la tarjeta **Acciones**, pulse **Iniciar atención** y confirme. El ticket pasa a **En atención** y verá *"Atención iniciada."*.

- Está disponible en **Nuevo**, **Asignado**, **Programado** y **Reabierto** (permiso *Iniciar atención*).
- Si el ticket está **Nuevo y sin responsable**, el sistema lo **asigna primero a usted** (con el motivo *"Inicio de atención por el responsable"*) y luego inicia la atención. Para esta autoasignación basta el permiso *Iniciar atención* (no se necesita *Asignar tickets*). Las dos cosas se hacen **juntas**: si el inicio no se puede completar, tampoco queda la asignación y el ticket sigue **Nuevo**, sin responsable.
- La primera vez que un ticket entra en atención se guarda la fecha de **inicio del trabajo**.
- Cuando el ticket ya está en atención, el botón aparece deshabilitado como **Atención en marcha**. Si de todos modos se envía el inicio (por ejemplo, desde otra pestaña), verá el aviso informativo *"La atención ya estaba en marcha; no se realizó ningún cambio."*.

---

## 10. Poner en espera y reanudar

### Poner en espera
Disponible en **Asignado**, **Programado** y **En atención** (permiso *Iniciar atención*).

1. En la tarjeta **Acciones**, elija el tipo de espera:
   - **En espera del cliente**: falta algo del cliente.
   - **En espera interna**: falta algo de un área interna.
2. Opcional: escriba el **Motivo de la espera (opcional)**.
3. Pulse **Poner en espera** y confirme. Verá *"Ticket en espera del cliente."* o *"Ticket en espera interna."*.

| Tipo de espera | ¿Pausa el tiempo efectivo? |
|---|---|
| **En espera del cliente** | **Sí.** Ese tiempo no se cuenta en los indicadores de *tiempo efectivo*. En **Historial → Estados** el periodo lleva la etiqueta *pausa el tiempo efectivo*. |
| **En espera interna** | **No.** La demora es responsabilidad de la organización y se sigue contando. |

### Reanudar
Mientras el ticket está en espera, la tarjeta muestra **Reanudar atención**. Al pulsarlo, el ticket vuelve a **En atención** y verá *"Atención reanudada."*.

Desde una espera también puede **Programar** una atención (el ticket pasa a *Programado*) o **Cancelar ticket**. No se puede pasar directamente de una espera a la otra: reanude primero.

---

## 11. Programar y reprogramar la atención

### Programar
En la tarjeta **Programación** (permiso *Programar atención*):

| Campo | ¿Obligatorio? | Qué poner |
|---|---|---|
| **Tipo de actividad** | Sí | Uno de los tipos de actividad activos del CRM (por ejemplo, reunión o llamada). |
| **Título** | Sí | Ej.: *Capacitación módulo de caja*. Máximo 255 caracteres. |
| **Fecha y hora** | Sí | Cuándo será la atención. |
| **Modalidad** | Sí | *Virtual*, *Presencial*, *Teléfono* o *No aplica*. |

Pulse **Programar** y confirme. Verá *"Atención programada."*.

Qué ocurre:

- Se crea una **actividad del CRM** vinculada al ticket (el sujeto de la actividad es el código del ticket). Su responsable es el responsable del ticket o, si no tiene, usted.
- Se crea una **sesión** con la modalidad elegida, que aparece en una tarjeta **Asistencia: …**.
- El estado cambia según desde dónde programe:

| Estado actual | Estado después de programar |
|---|---|
| Nuevo (sin responsable) | Se lo asigna a usted y pasa a **Programado** (basta el permiso *Programar atención*; si la programación falla, tampoco queda la asignación) |
| Asignado, En espera del cliente, En espera interna, Reabierto | **Programado** |
| Programado, En atención | **No cambia** (se agrega otra actividad) |
| Resuelto, Cerrado, Cancelado | No se puede programar ni reprogramar (la tarjeta solo lista las actividades) |

Debajo del formulario se listan las actividades del ticket con su fecha. Si no hay, dice *"Sin actividades programadas."*. Las actividades completadas o canceladas muestran la etiqueta **Completada** o **Cancelada**.

### Reprogramar
Bajo cada actividad que no está completada ni cancelada, mientras el ticket no esté **Resuelto**, **Cerrado** ni **Cancelado** (permiso *Reprogramar atención*):

1. Elija la nueva fecha y hora.
2. Escriba el **Motivo** (obligatorio).
3. Pulse **Reprogramar** y confirme. Verá *"Atención reprogramada."*.

Cada reprogramación queda en **Historial → Reprogramaciones**: fecha del cambio, actividad, fecha anterior → nueva, quién la hizo y el motivo.

### Participantes de la sesión
Cada sesión tiene una tarjeta **Asistencia: …** con el tema de la sesión o, si no tiene tema, su modalidad (*Virtual*, *Presencial*, *Teléfono* o *No aplica*). En ella (permiso *Programar atención*; no disponible en tickets cerrados o cancelados):

1. Escriba el **Nombre** (obligatorio) y, si lo tiene, el **Email**.
2. Deje marcada la casilla **asistió** si la persona asistió (viene marcada) o desmárquela.
3. Pulse **Guardar**. Verá *"Participante registrado."*.

La lista muestra cada participante con **✓** (asistió) o **—** (no asistió). La tarjeta también tiene su propio panel de **Documentos** (por ejemplo, para la lista de asistencia firmada).

---

## 12. Incidentes

Si el ticket es del tipo **Incidente o error**, la ficha muestra la tarjeta **Incidente** para registrar los datos técnicos (permiso *Editar tickets*; no disponible en tickets cerrados o cancelados):

| Campo | Qué poner |
|---|---|
| **Sistema** | Sistema o producto afectado. |
| **Resultado obtenido** | Qué ocurre (el error que ve el cliente). |
| **Solución técnica** | Qué se corrigió. |

Pulse **Guardar incidente** y confirme. Verá *"Detalle de incidente guardado."*. Puede volver a guardar las veces que necesite: se actualiza el mismo detalle. Los datos guardados se muestran debajo del formulario, junto con un panel de **Documentos** del incidente (por ejemplo, capturas de pantalla).

---

## 13. Observaciones

Una **observación** es un punto concreto del ticket que debe atenderse y validarse. Sirve, por ejemplo, para la lista de pendientes que deja el cliente después de una capacitación o de una entrega.

### 13.1 Agregar una observación
En la tarjeta **Observaciones** (permiso *Registrar observaciones*; no disponible en tickets cerrados o cancelados):

| Campo | ¿Obligatorio? | Qué poner |
|---|---|---|
| **Nueva observación** | Sí | El punto a atender. Máximo 255 caracteres. |
| **Detalle (opcional)** | No | Más información. |
| **Responsable** | No | Quién debe atenderla, o *Sin responsable*. |
| **Fecha límite** | No | Hasta cuándo debe atenderse. |

Pulse **Agregar** y confirme. Verá *"Observación creada."*. La observación nace en estado **Pendiente**.

Cada observación muestra su título, su estado, el detalle y, si existen, *Responsable*, *Vence* y el último *Motivo*. La cabecera de la tarjeta indica cuántas hay **sin finalizar**. Si no hay ninguna, dice *"No hay observaciones registradas."*.

### 13.2 Estados de una observación

| Estado | Significado |
|---|---|
| **Pendiente** | Registrada, aún no se trabaja. |
| **En proceso** | Se está trabajando. |
| **Levantada** | Quien la atendió indica que quedó resuelta; falta validarla. |
| **Validada** | Se confirmó que quedó resuelta. **Finalizada.** |
| **Rechazada** | Se revisó lo levantado y **no** quedó resuelta; vuelve a trabajo. |
| **Reabierta** | Estaba validada, no aplicaba o fue rechazada, y se vuelve a abrir. |
| **No aplica** | Se descarta porque no corresponde. **Finalizada.** |

### 13.3 Botones y cambios permitidos

Junto a cada observación aparecen solo los botones válidos para su estado y para sus permisos:

| Estado actual | Botones disponibles (→ nuevo estado) |
|---|---|
| **Pendiente** | Iniciar (→ En proceso) · Levantar · No aplica |
| **En proceso** | Levantar · No aplica |
| **Levantada** | Validar · Rechazar |
| **Rechazada** | Iniciar (→ En proceso) · Levantar · Reabrir |
| **Reabierta** | Iniciar (→ En proceso) · Levantar · No aplica |
| **Validada** | Reabrir |
| **No aplica** | Reabrir |

| Botón | Permiso necesario | ¿Pide motivo? |
|---|---|---|
| **Iniciar**, **Levantar** | *Levantar observaciones* | No |
| **Validar** | *Validar observaciones* | No |
| **No aplica** | *Validar observaciones* | **Sí** |
| **Rechazar**, **Reabrir** | *Rechazar observaciones* | **Sí** |

Cuando el botón pide motivo, aparece un campo **Motivo (…)** a su lado; complételo antes de pulsar. Tras confirmar verá *"Observación actualizada."*.

- Reabrir una observación validada **borra su validación** (vuelve a requerir validarse).
- Cada cambio se registra internamente con quién lo hizo, cuándo y el motivo (hoy ese historial no se muestra en pantalla).
- En tickets **cerrados** o **cancelados** no aparecen los botones.
- Cada observación tiene su propio panel de **Documentos** (evidencias).

### 13.4 Cómo afectan al ticket

| Para… | Lo impiden las observaciones en estado |
|---|---|
| **Resolver** el ticket | Pendiente, En proceso, Rechazada o Reabierta. (Una observación **Levantada** no impide resolver.) |
| **Cerrar** el ticket | Cualquiera que no esté **Validada** ni **No aplica** (es decir: Pendiente, En proceso, Levantada, Rechazada o Reabierta), salvo que use *Cerrar con observaciones pendientes* (ver [sección 15](#15-cerrar)). |

---

## 14. Resolver

Disponible solo cuando el ticket está **En atención** (permiso *Resolver tickets*).

1. En la tarjeta **Acciones**, escriba el **Resumen de solución (obligatorio)**: qué se hizo para resolver el pedido.
2. Pulse **Resolver** y confirme. Verá *"Ticket resuelto."*.

Reglas:

- Si hay observaciones **por atender** (Pendiente, En proceso, Rechazada o Reabierta), la tarjeta lo advierte (*"Hay N observación(es) por atender: levántelas o márquelas como «No aplica» antes de resolver."*) y el sistema rechaza la resolución con *"No se puede resolver con observaciones pendientes: levántelas o márquelas como no aplicables."*.
- Si la resolución es rechazada, **no se guarda nada**, tampoco el resumen escrito.
- La fecha de resolución y el resumen quedan en **Registro de acciones** (*Resumen de solución — Resuelto el …*) y en el **ciclo de atención** actual. Si el ticket se reabre y se vuelve a resolver, los tiempos del primer ciclo **se conservan**.

---

## 15. Cerrar

Disponible solo cuando el ticket está **Resuelto** (permiso *Cerrar tickets*).

1. En la tarjeta **Acciones**, escriba la **Validación o motivo de cierre** (obligatorio). Ej.: *El cliente confirmó por correo que ya emite facturas*.
2. Marque **Cliente validó la solución** si el cliente confirmó que quedó resuelto.
3. Si hay observaciones sin finalizar y usted tiene el permiso *Cerrar tickets con observaciones pendientes*, aparece la casilla **Cerrar con observaciones pendientes (N)**. Márquela solo si decide cerrar de todos modos.
4. Pulse **Cerrar** y confirme. Verá *"Ticket cerrado."*.

Reglas:

- Sin la casilla de excepción, el cierre se rechaza si hay observaciones que no estén **Validadas** ni en **No aplica**: *"No se puede cerrar con observaciones pendientes."*.
- Si no tiene el permiso de excepción, en lugar de la casilla verá *"Hay N observación(es) sin validar; deben finalizarse antes de cerrar."*.
- El texto de cierre aparece en **Registro de acciones → Validación de cierre** con *Cerrado el …* y, si marcó la casilla, *· validado por el cliente*.
- Un ticket cerrado ya no se puede editar, ni cambiar su prioridad, ni sus observaciones. Solo se puede **Reabrir**.

---

## 16. Reabrir

Disponible cuando el ticket está **Resuelto** o **Cerrado** (permiso *Reabrir tickets*).

1. Escriba el **Motivo de reapertura (qué sigue fallando)** (obligatorio).
2. Pulse **Reabrir** y confirme. Verá *"Ticket reabierto."*.

Qué ocurre:

- El ticket pasa a **Reabierto** y comienza un **nuevo ciclo de atención**. Los tiempos del ciclo anterior (resolución, cierre, validación) se conservan en ese ciclo para los indicadores.
- Las fechas de **resolución**, **cierre** y **validación del cliente** del ticket se **vacían**: mientras esté reabierto no cuenta como *resuelto* ni *cerrado* en el dashboard. Cuando se vuelva a resolver, el ticket toma la fecha de esa última resolución. Los textos de solución y cierre anteriores siguen visibles en **Registro de acciones**.
- El motivo aparece en **Registro de acciones → Motivo de reapertura**.
- El ticket **conserva su responsable**. Desde *Reabierto* puede **Iniciar atención**, **Programar**, **Reasignar** (con motivo; el ticket pasa a *Asignado*) o **Cancelar ticket**.
- El ticket cuenta como **reabierto** en el indicador *Reabiertos* del dashboard.

---

## 17. Cancelar

Disponible en **Nuevo, Asignado, Programado, En atención, En espera del cliente, En espera interna** y **Reabierto** (permiso *Anular tickets*). **No** se puede cancelar un ticket **Resuelto** ni **Cerrado**.

1. Escriba el **Motivo de cancelación** (obligatorio).
2. Pulse **Cancelar ticket** y confirme el aviso *"Esta acción cancelará el ticket y no podrá reabrirse."*.

Verá *"Ticket cancelado."*. El motivo queda en **Registro de acciones → Motivo de cancelación**. Un ticket cancelado no admite ninguna otra acción de estado.

---

## 18. Cambiar la prioridad

En la tarjeta **Prioridad** (permiso *Cambiar prioridad*; no disponible en tickets cerrados o cancelados):

1. Elija la nueva prioridad.
2. Opcional: escriba el **Motivo del cambio (opcional)**.
3. Pulse **Cambiar prioridad** y confirme. Verá *"Prioridad actualizada."*.

El cambio (prioridad anterior, nueva y motivo) se guarda en el registro de auditoría del sistema; hoy no se muestra en el **Historial** de la ficha.

---

## 19. Editar el ticket

Botón **Editar ticket** en la cabecera de la ficha (permiso *Editar tickets*; no disponible en tickets cerrados o cancelados).

La página **Editar ticket SUP-…** permite cambiar solo los datos descriptivos:

| Campo | ¿Obligatorio? | Qué poner |
|---|---|---|
| **Título** | Sí | Máximo 200 caracteres. |
| **Tipo** | Sí | Ver [sección 2](#2-tipos-de-ticket-y-listas-del-módulo). |
| **Categoría** | Sí | |
| **Canal** | Sí | |
| **Descripción** | Sí | |
| **Impacto** | No | A quién o a qué afecta. Ej.: *Toda el área de ventas*. Máximo 100 caracteres. |
| **Observaciones generales** | No | Comentarios generales del caso. |

Pulse **Guardar cambios**. Verá *"Ticket actualizado."*. Con **Volver** regresa a la ficha sin guardar.

Como indica la propia página: *"Cliente, contacto, prioridad, responsable y estado se gestionan desde la ficha del ticket."*

---

## 20. Historial y registro de acciones

### Registro de acciones
Muestra el último texto ingresado en cada acción importante:

| Rótulo | Cuándo aparece |
|---|---|
| **Resumen de solución** | Al resolver (con *Resuelto el …*). |
| **Validación de cierre** | Al cerrar (con *Cerrado el …* y, si corresponde, *· validado por el cliente*). |
| **Motivo de reapertura** | Al reabrir. |
| **Motivo de cancelación** | Al cancelar. |

Si todavía no hay ninguno, dice *"Cuando resuelva, cierre, reabra o cancele el ticket, el texto ingresado quedará visible aquí."*. Si el ticket se reabre, los textos de solución y cierre anteriores siguen visibles hasta que se reemplacen en el siguiente ciclo.

### Historial
Tres apartados:

| Apartado | Qué muestra | Si está vacío |
|---|---|---|
| **Asignaciones** | Fecha, responsable anterior → nuevo, cambio de equipo, *por* quién y *Motivo*. | *Sin asignaciones registradas.* |
| **Reprogramaciones** | Fecha, actividad, fecha anterior → nueva, *por* quién y *Motivo*. | *Sin reprogramaciones.* |
| **Estados** | Cada estado por el que pasó el ticket, con su inicio – fin (o *en curso*). Las esperas del cliente llevan la etiqueta *pausa el tiempo efectivo*. | *Sin cambios de estado registrados.* |

---

## 21. Notas internas y respuestas al cliente

En la tarjeta **Actualizaciones** (permiso *Registrar avances*):

| Tipo | Para qué | Botón | Mensaje |
|---|---|---|---|
| **Nota interna** | Avances, hallazgos o coordinaciones del equipo. | **Agregar nota** | *Nota interna agregada.* |
| **Respuesta al cliente** | Dejar constancia de lo que se respondió al cliente. | **Registrar respuesta** | *Respuesta al cliente registrada.* |

- La **primera respuesta al cliente** marca la fecha de **primera respuesta** del ticket, que se usa en el indicador *Prom. primera respuesta*. Las notas internas no cuentan.
- El CRM **no envía** la respuesta al cliente: solo la registra. Envíela usted por el canal habitual (correo, WhatsApp…).
- La lista muestra cada actualización con su tipo, autor y texto. Si no hay, dice *"Sin actualizaciones."*.

---

## 22. Documentos

La ficha tiene paneles **Documentos** en varios lugares:

| Panel | Dónde |
|---|---|
| Documentos del **ticket** | Columna izquierda, debajo de *Registro de acciones*. |
| Documentos de cada **observación** | Debajo de cada observación. |
| Documentos del **incidente** | En la tarjeta *Incidente*, después de guardar el detalle. |
| Documentos de cada **sesión** | En cada tarjeta *Asistencia: …*. |

- El panel indica el tamaño máximo y las extensiones permitidas.
- Para **subir** se necesita el permiso *Subir documentos* y poder ver el ticket. La regla es la misma en los cuatro paneles: si usted puede ver el ticket, puede ver y usar los paneles de sus observaciones, incidente y sesiones. Al subir verá *Documento "nombre" subido correctamente.* Si intenta subir a un ticket que no puede ver, el sistema responde con un error de acceso.
- Para **descargar** se necesita el permiso *Descargar documentos* y poder ver el ticket.
- **Eliminar**: quien subió el documento (con el permiso *Eliminar documentos*) o quien tiene *Ver todos los documentos*.
- Si no hay archivos, dice *"Sin documentos adjuntos."*.

Los documentos de una **observación**, del **incidente** y de las **sesiones** se descargan con la misma regla que los del ticket: quien puede ver el ticket y tiene el permiso de descargar documentos.

---

## 23. La lista de tickets: búsqueda y Mis tickets

Menú **Soporte**. La página **Soporte técnico** muestra la tabla **Tickets de soporte**, solo con los tickets que usted puede ver, del más reciente al más antiguo, en páginas de 25 (o el tamaño configurado en el CRM).

### Columnas
**Código**, **Título**, **Cliente**, **Estado**, **Prioridad**, **Responsable** (o *Sin asignar*), **Actualizado** y el botón **Ver**.

### Filtros

| Filtro | Qué hace |
|---|---|
| **Buscar** | Busca el texto en el **código** del ticket, el **título**, y el **código**, la **razón social** o el **nombre comercial** del cliente. Basta con una parte. Ej.: *00012*, *factura*, *Comercial Lima*. |
| **Estado** | *Todos* o un estado. |
| **Prioridad** | *Todas* o una prioridad. |
| **Mis tickets** | Muestra solo los tickets de los que **usted es el responsable**. |

Pulse **Filtrar**. **Limpiar** quita todos los filtros.

Si no hay resultados, verá *"No hay tickets que coincidan con la búsqueda."* (con filtros) o *"No hay tickets de soporte."* (sin filtros).

---

## 24. El Dashboard de soporte

Menú **Dashboard de soporte** (permiso *Ver reportes de soporte*).

> Los indicadores se calculan **solo con los tickets que usted puede ver** (sección 3.1). Un supervisor ve los de sus equipos; quien tiene *Ver todos los tickets* ve los de toda la empresa.

### 24.1 Filtros

| Filtro | Qué hace |
|---|---|
| **Desde** / **Hasta** | Tickets **creados** entre esas fechas. *Hasta* no puede ser anterior a *Desde*. |
| **Cliente** | Se muestra el código del cliente. |
| **Responsable**, **Equipo** | |
| **Tipo**, **Categoría**, **Prioridad**, **Estado** | |
| **Modalidad** | Tickets que tienen al menos una sesión con esa modalidad (*Virtual*, *Presencial*, *Teléfono*, *No aplica*). |

Si usted no ve todos los tickets, las listas **Cliente**, **Responsable** y **Equipo** solo ofrecen los que aparecen en sus tickets.

Pulse **Filtrar**. **Limpiar** quita los filtros. **Exportar CSV** descarga los tickets con los mismos filtros (ver [sección 25](#25-exportar-a-csv)).

Si no hay tickets para los filtros, verá *"No hay tickets para los filtros seleccionados."* y *"Ajuste los filtros o registre tickets para ver indicadores."*.

### 24.2 Indicadores

| Indicador | Cómo se calcula (en palabras) |
|---|---|
| **Tickets recibidos** | Cantidad de tickets que cumplen los filtros. |
| **Prom. primera respuesta** | Para los tickets con al menos una *Respuesta al cliente*: promedio del tiempo desde la **creación** hasta la **primera respuesta**. |
| **Prom. resolución** | Para los tickets que tienen fecha de resolución: promedio del tiempo desde la **creación** hasta la **resolución** (si se resolvió más de una vez, la última). Un ticket **reabierto** que todavía no se vuelve a resolver no tiene fecha de resolución y no entra en este promedio (su ciclo anterior sí cuenta en los indicadores por ciclo). |
| **Prom. tiempo efectivo** | Lo mismo que *Prom. resolución*, pero **restando el tiempo en *En espera del cliente***. La espera interna sí se cuenta. |
| **Prom. cierre** | Para los tickets cerrados: promedio desde la **creación** hasta el **cierre**. Al reabrir un ticket cerrado, deja de contar como cerrado hasta que se vuelva a cerrar. |
| **Prom. por ciclo de atención** | Para cada ciclo de atención resuelto (el primero y cada reapertura): promedio desde el **inicio del ciclo** hasta su **resolución**. Los ciclos terminados por cancelación no cuentan. |
| **Prom. efectivo por ciclo** | Lo mismo por ciclo, **restando la espera del cliente** dentro del ciclo. |
| **Reabiertos** | **Tickets con más de un ciclo** ÷ **tickets resueltos alguna vez** × 100, con 2 decimales. Debajo se indica *"N de M tickets resueltos alguna vez."*. Nunca supera el 100 %. |
| **Resueltos dentro del objetivo** | Muestra *"Sin tiempos objetivo configurados"*: los tiempos objetivo por prioridad todavía no se pueden configurar. |

Cómo leer los tiempos:

- Menos de una hora: *N min*. Una hora o más: *H h M min*.
- Si no hay tickets para calcular un promedio, se muestra *Sin datos*.
- Los tiempos son **corridos** (incluyen noches y fines de semana).
- El **primer ciclo** empieza con el primer cambio de estado del ticket (normalmente la asignación); cada reapertura inicia un ciclo nuevo.

### 24.3 Tablas
- **Por estado**, **Por prioridad**, **Por tipo**, **Por responsable** (los tickets sin responsable aparecen como *Sin asignar*): cantidad de tickets, de mayor a menor.
- **Observaciones**: **Pendientes** (suma Pendiente, En proceso, Rechazada y Reabierta), **Levantadas** y **Validadas**. Las de *No aplica* no se cuentan.

---

## 25. Exportar a CSV

En el dashboard, pulse **Exportar CSV**. Se descarga el archivo **reporte-soporte.csv** con los tickets que usted puede ver y que cumplen los filtros aplicados.

| Columna | Contenido |
|---|---|
| **Código** | Código del ticket. |
| **Fecha de creación** | Formato *AAAA-MM-DD HH:MM:SS*. |
| **Cliente** | Código del cliente. |
| **Responsable** | Nombre (vacío si no tiene). |
| **Tipo**, **Categoría**, **Prioridad**, **Estado** | Nombres en español. |
| **Modalidad** | Modalidad de la primera sesión programada (*Virtual*, *Presencial*, *Teléfono*, *No aplica*), o vacío. |

El archivo usa codificación UTF-8 y columnas separadas por comas, para que las tildes se vean bien al abrirlo.

Por seguridad, si un valor empieza con `=`, `+`, `-`, `@`, un tabulador o un retorno de carro, el archivo lo antepone con un apóstrofo (`'`). Así la hoja de cálculo lo muestra como texto y nunca lo ejecuta como fórmula.

---

## 26. Mensajes frecuentes

### Al crear o editar un ticket

| Mensaje | Causa | Qué hacer |
|---|---|---|
| *El campo cliente es obligatorio.* (y otros *El campo … es obligatorio.*) | Falta un dato obligatorio al crear | Complete el campo indicado |
| *El contacto solicitante no pertenece al cliente seleccionado.* | El contacto elegido es de otro cliente | Elija primero el cliente y luego uno de sus contactos |
| *El equipo seleccionado no existe o no está activo.* | El equipo fue desactivado | Elija otro equipo o deje *Sin equipo* |
| *El título es obligatorio.* / *La descripción es obligatoria.* | Falta el dato (al editar) | Complételo |
| *El título no debe superar los 200 caracteres.* | Título demasiado largo | Acórtelo |
| *El impacto no debe superar los 100 caracteres.* | Impacto demasiado largo | Acórtelo |
| *Seleccione el tipo de ticket.* / *Seleccione la categoría.* / *Seleccione el canal de ingreso.* | Falta elegir una opción (al editar) | Elija una opción |
| *El tipo de ticket no está activo.* / *La categoría de soporte no está activa.* / *El canal de ingreso no está activo.* | La opción fue desactivada | Elija otra opción |
| *No se puede editar un ticket cerrado o cancelado.* | El ticket está cerrado o cancelado | Si debe corregirlo, reábralo (solo si está cerrado) |

### Al cambiar el estado o asignar

| Mensaje | Causa | Qué hacer |
|---|---|---|
| *Transición de soporte no permitida.* | La acción no es válida para el estado actual (por ejemplo, otra persona ya cambió el estado) | Recargue la ficha y use las acciones que aparecen |
| *No se puede resolver con observaciones pendientes: levántelas o márquelas como no aplicables.* | Hay observaciones Pendientes, En proceso, Rechazadas o Reabiertas | Atiéndalas (sección 13) |
| *No se puede cerrar con observaciones pendientes.* | Hay observaciones que no están Validadas ni en No aplica | Finalícelas o, si tiene el permiso, marque *Cerrar con observaciones pendientes* |
| *No tiene permiso para cerrar con observaciones pendientes.* | Marcó la excepción sin tener el permiso | Pida a quien tenga el permiso que cierre el ticket |
| *El ticket no admite cancelación desde su estado actual.* | El ticket está Resuelto o Cerrado | Ciérrelo o reábralo según corresponda |
| *No se puede asignar un ticket resuelto, cerrado o cancelado.* | El ticket ya no está abierto | Reábralo primero (si está resuelto o cerrado) |
| *El motivo de reasignación es obligatorio.* | Reasignó sin motivo | Escriba el motivo |
| *El responsable debe ser un usuario activo.* | El usuario elegido está inactivo | Elija otro usuario |
| *El equipo seleccionado no existe o no está activo.* | El equipo elegido fue desactivado | Elija otro equipo o deje *Sin equipo* |
| *El ticket no está en espera.* | Pulsó *Reanudar* en un ticket que ya no está en espera | Recargue la ficha |
| *La atención ya estaba en marcha; no se realizó ningún cambio.* (aviso informativo) | Se envió *Iniciar atención* en un ticket que ya está En atención | Ninguna; el ticket ya está en atención |
| *Seleccione el tipo de espera: cliente o interna.* | No eligió el tipo de espera | Elija *En espera del cliente* o *En espera interna* |
| *Seleccione una prioridad.* / *La prioridad de soporte no está activa.* | Falta la prioridad o fue desactivada | Elija otra |
| *No se puede cambiar la prioridad de un ticket cerrado o cancelado.* | El ticket está cerrado o cancelado | — |
| *Fecha, tipo de actividad y modalidad válida son obligatorios.* | Faltan datos al programar | Complete todos los campos |
| *La reprogramación requiere motivo y actividad no terminal.* | Falta el motivo o la actividad ya está completada o cancelada | Escriba el motivo; para una actividad terminada, programe una nueva |
| *No se puede reprogramar la atención de un ticket cerrado o cancelado.* | El ticket se cerró o canceló | Si está cerrado y sigue el problema, reábralo |
| *No se puede modificar el incidente de un ticket cerrado o cancelado.* | El ticket se cerró o canceló | Ídem |
| *No se pueden registrar participantes en un ticket cerrado o cancelado.* | El ticket se cerró o canceló | Ídem |
| *El campo … es obligatorio.* debajo de un formulario de la ficha | Falta un dato en ese formulario (por ejemplo, el motivo de cancelación) | Complete el dato en el mismo formulario y vuelva a enviarlo |

### Observaciones

| Mensaje | Causa | Qué hacer |
|---|---|---|
| *Transición de observación no permitida: de «…» a «…».* | El cambio no es válido para el estado actual de la observación | Recargue la ficha y use los botones que aparecen |
| *Este cambio exige motivo.* | Pulsó *Rechazar*, *Reabrir* o *No aplica* sin motivo | Escriba el motivo |
| *No se pueden crear observaciones en un ticket cerrado o cancelado.* | El ticket se cerró o canceló | Si está cerrado y falta algo, reábralo |
| *Estado de observación inválido.* | El estado pedido no existe | Recargue la ficha |

### Mensajes de confirmación

| Acción | Mensaje |
|---|---|
| Crear / editar ticket | *Ticket de soporte creado.* / *Ticket actualizado.* |
| Asignar / reasignar | *Responsable asignado.* / *Responsable reasignado.* |
| Iniciar / reanudar atención | *Atención iniciada.* / *Atención reanudada.* |
| Poner en espera | *Ticket en espera del cliente.* / *Ticket en espera interna.* |
| Programar / reprogramar | *Atención programada.* / *Atención reprogramada.* |
| Resolver / cerrar / reabrir / cancelar | *Ticket resuelto.* / *Ticket cerrado.* / *Ticket reabierto.* / *Ticket cancelado.* |
| Cambiar prioridad | *Prioridad actualizada.* |
| Nota / respuesta | *Nota interna agregada.* / *Respuesta al cliente registrada.* |
| Observación | *Observación creada.* / *Observación actualizada.* |
| Incidente / participante | *Detalle de incidente guardado.* / *Participante registrado.* |

### Situaciones

| Situación | Causa | Qué hacer |
|---|---|---|
| Error de acceso al abrir un ticket | El ticket está fuera de lo que usted puede ver | Pida que se lo asignen o que ajusten su rol |
| No aparece el menú **Soporte** | Su rol no tiene ninguna opción de *¿Qué tickets puede ver?* | Pida el permiso a un administrador |
| No aparece **Dashboard de soporte** | Le falta *Ver reportes de soporte* | Pida el permiso a un administrador |
| No aparece **Resolver** | El ticket no está *En atención*, o le falta *Resolver tickets* | Inicie la atención o pida el permiso |
| Dejé de ver un ticket que yo creé | Se asignó a otra persona y usted tiene *Ver solo sus tickets* | Pida acceso al responsable o al supervisor |

---

## 27. Preguntas frecuentes

**¿Por qué no aparece el botón Resolver?**
Solo se puede resolver un ticket **En atención**. Si está *Asignado*, *Programado* o en espera, primero pulse **Iniciar atención** o **Reanudar atención**.

**¿Cuál es la diferencia entre resolver y cerrar?**
**Resolver** registra la solución. **Cerrar** confirma que el caso terminó (idealmente, con la validación del cliente). Entre ambos, el cliente puede indicar que sigue fallando y usted puede **Reabrir**.

**¿Qué espera debo usar?**
*En espera del cliente* si falta algo del cliente: ese tiempo no se cuenta como tiempo efectivo. *En espera interna* si falta algo de su organización: ese tiempo sí se cuenta.

**¿Puedo cerrar un ticket con observaciones sin validar?**
Solo si tiene el permiso *Cerrar tickets con observaciones pendientes* y marca la casilla **Cerrar con observaciones pendientes**. Queda registrado que se cerró con esa excepción.

**¿Por qué pude resolver con una observación Levantada pero no puedo cerrar?**
Para resolver basta con que las observaciones estén levantadas (o no apliquen). Para cerrar, deben estar **Validadas** (o en *No aplica*).

**Reabrí un ticket, ¿se pierden los tiempos de la primera atención?**
No. Cada reapertura inicia un nuevo ciclo y los tiempos del ciclo anterior se conservan en los indicadores por ciclo. Lo que sí cambia es el ticket: mientras está reabierto no cuenta como resuelto ni cerrado.

**¿Puedo reabrir un ticket cancelado?**
No. Un ticket cancelado no admite acciones. Si el pedido vuelve a ser válido, cree un ticket nuevo.

**¿El cliente recibe un correo cuando registro una respuesta?**
No. El CRM solo registra la respuesta; envíela usted por el canal habitual.

**¿Puedo cambiar el cliente de un ticket?**
No. Si se registró con el cliente equivocado, cancele el ticket con ese motivo y cree uno nuevo.

**¿Por qué el dashboard muestra menos tickets que mi compañero?**
El dashboard solo usa los tickets que cada persona puede ver según su rol.

**¿Por qué la hora de un ticket no coincide con la hora de Lima?**
Las fechas automáticas (creación, resolución, cierre, cambios de estado) se guardan en la hora del servidor (UTC), que va 5 horas adelantada respecto de Lima, y se muestran tal como se guardan. Las fechas que usted ingresa (programación) se muestran tal como las escribió. Ver [sección 29](#29-lo-que-todavía-no-hace).

---

## 28. Ejemplo práctico: un error en la facturación de un cliente

**Situación:** el contacto de *Comercial Lima* llama porque el sistema no emite facturas electrónicas desde ayer.

### Paso 1 — Registrar el ticket
Menú **Soporte** → **Nuevo ticket**:
- **Título:** No se emiten facturas electrónicas
- **Prioridad:** Alta
- **Cliente:** Comercial Lima — **Contacto solicitante:** la jefa de facturación
- **Tipo:** Incidente o error — **Categoría:** Técnico — **Canal:** Llamada
- **Descripción:** Desde ayer, al emitir una factura aparece un error de envío.

El ticket nace como **SUP-2026-000NN**, estado **Nuevo**.

### Paso 2 — Asignar
El supervisor, en la tarjeta **Responsable**, elige al técnico y el equipo de soporte → **Asignar**. Estado: **Asignado**.

### Paso 3 — Atender
El técnico pulsa **Iniciar atención** (estado **En atención**) y registra una **Respuesta al cliente**: *"Estamos revisando el caso"* (cuenta como primera respuesta). En la tarjeta **Incidente** anota el *Sistema* y el *Resultado obtenido*.

### Paso 4 — Esperar al cliente
Necesita que el cliente renueve un certificado. Elige **En espera del cliente** → **Poner en espera**. Ese tiempo no se cuenta como tiempo efectivo. Cuando el cliente avisa, pulsa **Reanudar atención**.

### Paso 5 — Resolver
Corrige la configuración, completa *Solución técnica* en **Incidente** y, en **Acciones**, escribe el **Resumen de solución** → **Resolver**. Estado: **Resuelto**.

### Paso 6 — Observación del cliente
El cliente indica que el PDF no muestra el logo. El técnico agrega la observación *"Logo no aparece en el PDF"*, la corrige y pulsa **Levantar**. El supervisor la revisa y pulsa **Validar**.

### Paso 7 — Cerrar
Con la observación validada, el supervisor escribe *"Cliente confirmó por llamada que emite facturas"*, marca **Cliente validó la solución** → **Cerrar**. Estado: **Cerrado**.

### Paso 8 — Si vuelve a fallar
Si a la semana el error reaparece, el supervisor pulsa **Reabrir** con el motivo. El ticket inicia un nuevo ciclo; en el dashboard cuenta en **Reabiertos** y el tiempo del primer ciclo se conserva.

---

## 29. Lo que todavía no hace

Para evitar confusiones, estas funciones **no** están disponibles hoy:

- **Administrar las listas del módulo:** no hay pantalla para crear o modificar tipos, categorías, canales, prioridades ni estados. El permiso *Gestionar catálogos de soporte* está reservado para esa pantalla futura.
- **Tiempos objetivo por prioridad:** no se pueden configurar; por eso el indicador *Resueltos dentro del objetivo* muestra *"Sin tiempos objetivo configurados"*.
- **Notificaciones:** no se envían avisos (ni en la campana ni por correo) al crear, asignar, programar, resolver o cerrar un ticket.
- **Conversión a hora de Lima:** las fechas automáticas del ticket se muestran en la hora del servidor (UTC) y las fechas programadas tal como se ingresaron. La conversión requiere un ajuste conjunto con Actividades y Calendario.
- **Portal o envío al cliente:** las *respuestas al cliente* solo se registran en el CRM.
- **Cambiar el cliente o el contacto solicitante** de un ticket ya creado.
- **Equipo, impacto y observaciones generales al crear:** el equipo se elige al asignar; el impacto y las observaciones generales, desde **Editar ticket**.
- **Todos los datos técnicos del incidente en pantalla:** la tarjeta *Incidente* solo muestra *Sistema*, *Resultado obtenido* y *Solución técnica*.
- **Tema, objetivo y agenda de la sesión:** el formulario de programación no los pide; por eso la tarjeta de asistencia se titula con la modalidad.
- **Editar o eliminar** observaciones, participantes o el propio ticket.
- **Ver el historial de una observación, de los cambios de prioridad o los motivos de espera:** se guardan internamente, pero no se muestran en la ficha.
- **Fechas en la lista de actualizaciones:** las notas y respuestas no muestran su fecha.
- **Tiempos en horario laboral:** los indicadores cuentan tiempo corrido, sin descontar noches, fines de semana ni feriados.
