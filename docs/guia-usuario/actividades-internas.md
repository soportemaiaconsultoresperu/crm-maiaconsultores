# Guía de usuario — Actividades internas

Esta guía explica, paso a paso, cómo usar el módulo **Actividades internas** del CRM Maia Consultores para planificar reuniones, capacitaciones, eventos y cualquier otra actividad de la organización, y cómo verlas en el calendario junto al resto del trabajo.

---

## Índice

1. [¿Qué es una actividad interna?](#1-qué-es-una-actividad-interna)
2. [Quién puede hacer qué](#2-quién-puede-hacer-qué)
3. [Dónde encontrar el módulo](#3-dónde-encontrar-el-módulo)
4. [Crear una actividad interna](#4-crear-una-actividad-interna)
5. [Elegir quién la ve (audiencia)](#5-elegir-quién-la-ve-audiencia)
6. [Responsable y participantes](#6-responsable-y-participantes)
7. [Actividades que se repiten](#7-actividades-que-se-repiten)
8. [Ver el detalle de una actividad](#8-ver-el-detalle-de-una-actividad)
9. [Editar o eliminar una actividad](#9-editar-o-eliminar-una-actividad)
10. [El listado de actividades internas](#10-el-listado-de-actividades-internas)
11. [Las actividades internas en el calendario](#11-las-actividades-internas-en-el-calendario)
12. [Notificaciones y recordatorios](#12-notificaciones-y-recordatorios)
13. [Sincronización con Google Calendar](#13-sincronización-con-google-calendar)
14. [Cómo usar los selectores de varias opciones](#14-cómo-usar-los-selectores-de-varias-opciones)
15. [Mensajes de error frecuentes](#15-mensajes-de-error-frecuentes)
16. [Preguntas frecuentes](#16-preguntas-frecuentes)
17. [Ejemplos prácticos](#17-ejemplos-prácticos)

---

## 1. ¿Qué es una actividad interna?

Una **actividad interna** es cualquier evento de la organización que **no está ligado a un cliente, prospecto u oportunidad**. Por ejemplo:

- Reunión general de equipo.
- Capacitación sobre un nuevo procedimiento.
- Cierre mensual de contabilidad.
- Mantenimiento de equipos o de oficina.
- Celebraciones, aniversarios o fechas importantes.

Se diferencian de las **Actividades** comerciales (llamadas, visitas, reuniones con clientes):

| | Actividades (comerciales) | Actividades internas |
|---|---|---|
| ¿Está ligada a un cliente/prospecto/oportunidad? | Sí, siempre | No |
| ¿Quién la ve? | Su responsable (y supervisores/administradores según su alcance) | La **audiencia** que elija quien la crea |
| ¿Tiene estados (pendiente, completada…)? | Sí | No: es un evento programado |
| ¿Dónde aparece? | Menú **Actividades** y **Calendario** | Menú **Actividades internas** y **Calendario** (capa *Interno*) |

El objetivo es que **todo el personal** pueda ver en un mismo calendario lo que ocurre en la organización, para coordinarse y evitar choques de agenda.

---

## 2. Quién puede hacer qué

| Acción | ¿Quién puede? |
|---|---|
| Ver el listado y el calendario | Cualquier usuario activo (solo ve las actividades de su audiencia) |
| Crear una actividad interna | Cualquier usuario activo |
| Editar o eliminar una actividad | Solo **quien la creó** o un **administrador** |
| Ver una actividad concreta | Quien la creó, sus participantes, su audiencia y los administradores |

> **Importante:** si alguien intenta abrir el enlace de una actividad que no le corresponde, el sistema le muestra un aviso de acceso denegado (error 403). No es un fallo: es la protección de la audiencia funcionando.

---

## 3. Dónde encontrar el módulo

En el **menú lateral izquierdo** hay dos entradas relacionadas:

- **Calendario**: muestra todas las actividades (comerciales e internas) en vista de mes, semana, día o lista.
- **Actividades internas**: listado propio del módulo, con los botones para crear y consultar.

---

## 4. Crear una actividad interna

Hay tres caminos para empezar:

1. Menú **Actividades internas** → botón **Nueva actividad interna**.
2. Menú **Calendario** → botón **+ Actividad interna** (en la barra de filtros). La fecha que estés mirando en el calendario se precarga como inicio, a las 09:00.
3. Desde el listado, botón **Nueva actividad interna**.

Se abre el formulario **Nueva actividad interna**. Estos son sus campos:

| Campo | ¿Obligatorio? | Qué poner |
|---|---|---|
| **Título** | Sí | Nombre corto y claro. Ej.: *Capacitación del equipo*, *Reunión general*. Máximo 150 caracteres. |
| **Lugar** | No | Sala, dirección o enlace de videollamada. Máximo 150 caracteres. |
| **Inicio** | Sí | Fecha y hora en que empieza. |
| **Fin** | No | Fecha y hora en que termina. Debe ser igual o posterior al inicio. |
| **Repetición** | No | *No se repite* (por defecto), *Diaria*, *Semanal* o *Mensual*. Ver [sección 7](#7-actividades-que-se-repiten). |
| **Repetir hasta** | Solo si se repite | Último día en que puede ocurrir una repetición. Máximo un año desde el inicio. |
| **Descripción** | No | Agenda, objetivos, materiales necesarios, etc. |
| **¿Quién la ve?** | Sí | La audiencia. Ver [sección 5](#5-elegir-quién-la-ve-audiencia). |
| **Roles** / **Personas** | Según la audiencia | Aparece uno u otro según lo que elijas en *¿Quién la ve?*. |
| **Responsable** | No | Una persona a cargo de la actividad. |
| **Participantes** | No | Las personas que asisten o colaboran. |

Cuando termines, pulsá **Crear actividad**. El sistema te lleva al detalle de la actividad recién creada y muestra el mensaje *"Actividad interna creada."*.

Si querés salir sin guardar, pulsá **Cancelar**.

---

## 5. Elegir quién la ve (audiencia)

El campo **¿Quién la ve?** decide qué usuarios pueden ver la actividad en el listado, en el calendario y por enlace directo. Tiene tres opciones:

### 5.1 Toda la organización
Todos los usuarios activos la ven. Es la opción indicada para reuniones generales, feriados, eventos de empresa, etc.

### 5.2 Roles
Aparece el selector **Roles**. Elegí uno o varios roles (por ejemplo, *supervisor* y *vendedor*). La ven todos los usuarios que tengan **alguno** de esos roles.

Usalo para actividades de un área: *capacitación para vendedores*, *reunión de supervisores*.

### 5.3 Personas específicas
Aparece el selector **Personas**. Elegí uno por uno a los usuarios que deben verla.

Usalo para reuniones reducidas o temas reservados.

### Quién la ve siempre, sin importar la audiencia
- **Quien la creó.**
- **El responsable y los participantes.** Aunque no estén dentro de la audiencia elegida, siempre la ven (el formulario lo recuerda debajo del campo *Participantes*).
- **Los administradores.**

> **Consejo:** si elegís *Roles* o *Personas* y no marcás ninguno, el sistema no deja guardar y te pide completar ese campo.

---

## 6. Responsable y participantes

- **Responsable**: una sola persona que organiza o responde por la actividad. Es opcional (*Sin responsable*). Si elegís un responsable, se agrega automáticamente como participante.
- **Participantes**: todas las personas involucradas. Podés elegir varias.

Ser participante tiene tres efectos:

1. La persona **siempre ve** la actividad.
2. Recibe **notificaciones** cuando la agregan, cuando la actividad cambia y antes de que empiece ([sección 12](#12-notificaciones-y-recordatorios)).
3. Si tiene Google Calendar conectado, la actividad **aparece en su calendario de Google** ([sección 13](#13-sincronización-con-google-calendar)).

En el calendario, el nombre que aparece en la columna *Responsable* de una actividad interna es el de su responsable.

---

## 7. Actividades que se repiten

Para actividades periódicas (una reunión todos los lunes, un cierre cada fin de mes) no hace falta crear una por una.

1. En **Repetición** elegí:
   - **Diaria**: se repite todos los días a la misma hora.
   - **Semanal**: se repite cada semana, el mismo día y hora que el inicio.
   - **Mensual**: se repite cada mes, el mismo día y hora que el inicio.
2. En **Repetir hasta** indicá el último día en que puede ocurrir.

Reglas:

- **Repetir hasta** es obligatorio cuando hay repetición.
- Debe ser posterior al inicio.
- Como máximo puede ser **un año** después del inicio. Para algo que dura más, creá otra serie al terminar la primera.
- **Mensual en días 29, 30 o 31**: en los meses que no tienen ese día, la repetición cae en el **último día del mes**. Ej.: una actividad del 31 de octubre se repite el 30 de noviembre, el 31 de diciembre, el 31 de enero, etc.

Cómo se ve:

- En el **calendario**, cada repetición aparece en su día como un evento propio.
- En el **detalle**, el campo *Repetición* muestra, por ejemplo, *"Semanal hasta el 30/12/2026"*.
- Todas las repeticiones son **la misma actividad**: si la editás, el cambio se aplica a toda la serie.

> **Para cambiar solo una fecha de la serie:** hoy no se puede modificar una única repetición. Lo recomendable es acortar la serie (cambiando *Repetir hasta*) y crear aparte la actividad excepcional.

---

## 8. Ver el detalle de una actividad

Desde el listado (botón **Ver**) o haciendo clic en el evento en el calendario se abre la página **Actividad interna**, con:

- **Inicio** y **Fin**.
- **Repetición** (o *No se repite*).
- **Lugar**.
- **Audiencia**: el tipo y, si corresponde, los roles o personas elegidos.
- **Responsable** y **Participantes**.
- **Creada por**.
- **Descripción**.

Botones disponibles:

- **Ver en calendario**: abre el calendario en la fecha de la actividad.
- **Editar** y **Eliminar**: solo aparecen si sos quien la creó o administrador.

---

## 9. Editar o eliminar una actividad

### Editar
1. Abrí el detalle y pulsá **Editar**.
2. Cambiá lo necesario. Todos los campos del alta se pueden modificar, incluida la audiencia y la repetición.
3. Pulsá **Guardar cambios**.

Qué pasa al guardar:
- Los participantes que **ya estaban** reciben un aviso de que la actividad fue modificada.
- Los participantes **nuevos** reciben un aviso de que fueron agregados.
- Si la sincronización con Google está activa, el evento se actualiza en los calendarios de Google, y se quita del calendario de quien dejó de ser participante.

### Eliminar
1. Abrí el detalle y pulsá **Eliminar**.
2. Confirmá en el mensaje *"¿Eliminar esta actividad interna?"*.

La actividad desaparece del listado y del calendario, y se borra de los calendarios de Google sincronizados. El sistema conserva un registro interno de la eliminación, pero la actividad ya no se muestra en la aplicación.

---

## 10. El listado de actividades internas

Menú **Actividades internas**. Tiene dos pestañas:

- **Próximas**: actividades que empiezan hoy o más adelante, de la más cercana a la más lejana.
- **Pasadas**: actividades anteriores a hoy, de la más reciente a la más antigua.

Columnas: **Fecha y hora**, **Título**, **Lugar**, **Audiencia**, **Participantes** (cantidad) y **Acción** (botón *Ver*).

Solo aparecen las actividades que tenés permitido ver. Si hay muchas, se reparten en páginas de 20.

> En el listado, una actividad que se repite aparece **una sola vez**, con la fecha de su primer inicio. Para ver cada repetición, usá el calendario.

---

## 11. Las actividades internas en el calendario

Menú **Calendario**. Las actividades internas se muestran **en el mismo calendario** que las actividades comerciales, para ver toda la agenda en un solo lugar.

### Cómo reconocerlas
- Tienen un **borde violeta** y fondo lila claro.
- Su etiqueta de tipo dice **Interno**.
- En la vista **Lista**, el estado aparece como **Interna** y la columna *Sujeto* muestra el lugar.

### El filtro de capas
En la barra de filtros hay un selector de capa:

| Opción | Qué muestra |
|---|---|
| **Todo** (por defecto) | Actividades comerciales, vencimientos de facturas (si tenés permiso) y actividades internas |
| **Comercial** | Solo lo comercial; oculta las internas |
| **Interno** | Solo las actividades internas |

Elegí la opción y pulsá **Filtrar**. **Limpiar** vuelve a mostrar todo.

> **Nota sobre otros filtros:** los filtros *Responsable*, *Tipo*, *Sujeto* y *Estado* son propios de las actividades comerciales. Si usás alguno de ellos, el calendario muestra solo actividades comerciales, porque las internas no tienen esos datos.

### Vistas
Las pestañas **Mes**, **Semana**, **Día** y **Lista** funcionan igual para ambos tipos. En la vista mensual, si un día tiene más de tres eventos, aparece *"+N más"*; cambiá a vista **Día** o **Lista** para verlos todos.

Al hacer clic en un evento interno se abre su detalle.

---

## 12. Notificaciones y recordatorios

Las notificaciones aparecen en la **campana** de la barra superior y en el menú de notificaciones. Las reciben **solo los participantes** (incluido el responsable) que estén activos. Quien hace el cambio no se notifica a sí mismo.

| Cuándo | Mensaje que se recibe |
|---|---|
| Te agregan como participante (al crear o al editar) | *"[Nombre] lo agregó a la actividad interna "[Título]" ([fecha y hora])."* |
| Se modifica una actividad en la que ya participabas | *"[Nombre] modificó la actividad interna "[Título]" ([fecha y hora])."* |
| Faltan 24 horas o menos para que empiece | *"Recordatorio: la actividad interna "[Título]" empieza el [fecha y hora]."* |

Sobre los recordatorios:
- El sistema revisa **cada hora** las actividades que empiezan en las próximas 24 horas.
- Cada participante recibe **un solo recordatorio por cada repetición**: si la actividad es diaria, recibe uno por día, no varios.
- Las personas **audiencia** que no son participantes no reciben avisos; solo la ven en el calendario.

---

## 13. Sincronización con Google Calendar

Si conectás tu cuenta de Google, las actividades internas en las que participás (o que creaste) se copian automáticamente a tu Google Calendar.

### Cómo activarla
1. Abrí **Integraciones** (en el menú lateral o en el menú de tu usuario, arriba a la derecha).
2. Pulsá **Conectar Google Calendar** y aceptá los permisos que pide Google.

### Qué se sincroniza
- Se crea el evento en el calendario de Google del **creador** y de cada **participante** con Google conectado.
- Las actividades que se repiten se crean como **un único evento repetido** en Google, con la misma regla y fecha de fin.
- Al **editar** la actividad, el evento se actualiza (no se duplica).
- Al **quitar a un participante**, el evento se borra de su calendario.
- Al **eliminar** la actividad, el evento se borra de todos los calendarios.

### Lo que tenés que saber
- La sincronización es **en un solo sentido**: del CRM hacia Google. Si cambiás el evento en Google, el CRM no se entera y, en la siguiente modificación desde el CRM, se vuelve a imponer la versión del CRM. **Hacé siempre los cambios en el CRM.**
- La copia a Google puede tardar unos instantes, porque se procesa en segundo plano.
- Quien no tiene Google conectado igual ve todo en el CRM; simplemente no recibe la copia en Google.
- Si cambiás tu calendario predeterminado de Google después de que se creó un evento, al quitarte de esa actividad el evento podría quedar en el calendario anterior. En ese caso, borralo a mano desde Google.

---

## 14. Cómo usar los selectores de varias opciones

Los campos **Roles**, **Personas** y **Participantes** permiten elegir varias opciones. No hace falta usar Ctrl + clic:

1. Hacé clic en el campo: se abre un panel con casillas.
2. Marcá o desmarcá las opciones con un clic.
3. Si hay 6 opciones o más, aparece un **buscador** arriba: escribí parte del nombre para filtrar.
4. **Todos** marca todas las opciones visibles; **Ninguno** las desmarca.
5. Lo elegido aparece como **etiquetas** dentro del campo. Para quitar una, pulsá la **×** de la etiqueta.
6. Para cerrar el panel, hacé clic afuera o pulsá **Esc**.

Con teclado: **flecha abajo** abre el panel y recorre las opciones, **Enter** o **Espacio** marcan, **Esc** cierra.

---

## 15. Mensajes de error frecuentes

| Mensaje / situación | Causa | Qué hacer |
|---|---|---|
| *El campo título es obligatorio.* | Falta el título | Escribí un título |
| *El campo inicio es obligatorio.* | Falta la fecha/hora de inicio | Completá *Inicio* |
| *El campo fin debe ser una fecha posterior o igual a inicio.* | El fin es anterior al inicio | Corregí la hora de fin |
| *El campo roles es obligatorio…* | Elegiste audiencia *Roles* sin marcar ninguno | Marcá al menos un rol |
| *El campo personas es obligatorio…* | Elegiste *Personas específicas* sin marcar a nadie | Marcá al menos una persona |
| *El campo repetir hasta es obligatorio…* | Elegiste una repetición sin fecha de fin | Completá *Repetir hasta* |
| *La repetición puede durar como máximo un año.* | *Repetir hasta* supera un año desde el inicio | Acortá la serie |
| Página de acceso denegado (403) al abrir una actividad | No estás en su audiencia ni participás | Pedí a quien la creó que te agregue |
| No aparece el botón **Editar** | No sos quien la creó ni administrador | Pedí el cambio a quien la creó o a un administrador |

---

## 16. Preguntas frecuentes

**¿Por qué un compañero no ve la actividad que creé?**
Revisá la audiencia: si elegiste *Roles*, puede que su rol no esté incluido; si elegiste *Personas*, puede que no lo hayas marcado. Agregarlo como participante también le da acceso.

**¿Puedo invitar a alguien sin que la vea todo su rol?**
Sí: dejá la audiencia en *Personas específicas* o agregalo solo como participante.

**¿Las actividades internas tienen estados como "completada"?**
No. Son eventos programados. Si una se cancela, eliminala; si cambia, editala (los participantes reciben el aviso).

**¿Puedo cambiar solo una repetición de una serie?**
No. Acortá la serie con *Repetir hasta* y creá la excepción como actividad aparte.

**¿Por qué no veo las actividades internas en el calendario?**
Verificá que el filtro de capa esté en *Todo* o *Interno*, y que no tengas activo un filtro de *Responsable*, *Tipo*, *Sujeto* o *Estado* (esos filtros ocultan las internas).

**Cambié el evento en Google Calendar y en el CRM sigue igual.**
Es lo esperado: la sincronización va del CRM hacia Google. Hacé el cambio en el CRM.

**¿Recibo recordatorio si solo estoy en la audiencia?**
No. Los avisos son solo para participantes y responsable.

**Me fui de vacaciones y desactivaron mi usuario, ¿recibo avisos?**
No. Los usuarios inactivos no reciben notificaciones.

---

## 17. Ejemplos prácticos

### Reunión general de los lunes
- **Título:** Reunión general semanal
- **Lugar:** Sala principal / enlace de Meet
- **Inicio:** próximo lunes 09:00 — **Fin:** 10:00
- **Repetición:** Semanal — **Repetir hasta:** 31/12 del año
- **¿Quién la ve?:** Toda la organización
- **Responsable:** gerente general
- **Participantes:** jefes de área

Resultado: todos la ven cada lunes en el calendario; los jefes de área reciben recordatorio el domingo y la tienen en su Google Calendar.

### Capacitación para el equipo comercial
- **Título:** Capacitación en el nuevo CRM
- **Inicio:** fecha y hora de la sesión — **Fin:** hora de cierre
- **¿Quién la ve?:** Roles → *vendedor*, *supervisor*
- **Participantes:** el capacitador

Resultado: solo vendedores y supervisores la ven; el capacitador recibe los avisos.

### Cierre contable mensual
- **Título:** Cierre contable
- **Inicio:** 31/10 a las 15:00
- **Repetición:** Mensual — **Repetir hasta:** un año después
- **¿Quién la ve?:** Personas específicas → equipo de contabilidad
- **Responsable:** jefe de contabilidad

Resultado: aparece el último día de cada mes (30/11, 31/12, 31/01…) solo para el equipo de contabilidad.
