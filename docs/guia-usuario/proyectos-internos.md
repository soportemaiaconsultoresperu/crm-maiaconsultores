# Guía de usuario — Proyectos internos

Esta guía explica, paso a paso, cómo usar el módulo **Proyectos internos** del CRM Maia Consultores para planificar iniciativas de la organización (por ejemplo, el desarrollo de un software) en **fases** y **tareas**, y cómo seguir su **avance** en el resumen, en la línea de tiempo y en el calendario del CRM.

---

## Índice

1. [¿Qué es un proyecto interno?](#1-qué-es-un-proyecto-interno)
2. [Quién puede hacer qué](#2-quién-puede-hacer-qué)
3. [Dónde encontrar el módulo](#3-dónde-encontrar-el-módulo)
4. [Crear un proyecto interno](#4-crear-un-proyecto-interno)
5. [Equipo y audiencia: quién trabaja y quién lo ve](#5-equipo-y-audiencia-quién-trabaja-y-quién-lo-ve)
6. [Fases](#6-fases)
7. [Tareas](#7-tareas)
8. [Cómo se calcula el avance](#8-cómo-se-calcula-el-avance)
9. [Tareas atrasadas](#9-tareas-atrasadas)
10. [El resumen de proyectos (tablero)](#10-el-resumen-de-proyectos-tablero)
11. [El detalle de un proyecto](#11-el-detalle-de-un-proyecto)
12. [La línea de tiempo (Gantt)](#12-la-línea-de-tiempo-gantt)
13. [Los proyectos en el calendario del CRM](#13-los-proyectos-en-el-calendario-del-crm)
14. [Editar o eliminar un proyecto](#14-editar-o-eliminar-un-proyecto)
15. [Cómo usar los selectores de varias opciones](#15-cómo-usar-los-selectores-de-varias-opciones)
16. [Mensajes frecuentes](#16-mensajes-frecuentes)
17. [Preguntas frecuentes](#17-preguntas-frecuentes)
18. [Ejemplo práctico: desarrollo de un software](#18-ejemplo-práctico-desarrollo-de-un-software)
19. [Lo que todavía no hace](#19-lo-que-todavía-no-hace)

---

## 1. ¿Qué es un proyecto interno?

Un **proyecto interno** es una iniciativa de la organización que dura semanas o meses y que se planifica en partes: por ejemplo, el desarrollo de un nuevo sistema, el lanzamiento de un servicio o la renovación de la página web. Sirve para que el equipo que lo ejecuta registre **qué se va a hacer y cuándo**, y para que otras áreas (por ejemplo, **marketing** y **gerencia**) puedan **ver lo planificado y su avance** sin tener que preguntar.

Cada proyecto se organiza así:

```text
Proyecto  →  Fases (ordenadas)  →  Tareas
Ej.: Nuevo CRM → Análisis, Diseño, Desarrollo, Pruebas, Lanzamiento → "Diseñar pantalla de login", …
```

### ¿Proyecto interno o actividad interna?

| | Actividades internas | Proyectos internos |
|---|---|---|
| ¿Qué representa? | Un **evento** con fecha y hora (reunión, capacitación, cierre) | Un **trabajo planificado** en fases y tareas, con fechas de inicio y vencimiento |
| ¿Tiene estados y avance? | No | Sí: cada tarea tiene estado y el proyecto muestra un **% de avance** |
| ¿Quién la/lo ve? | La audiencia elegida | La audiencia elegida, más el equipo |
| ¿Quién la/lo modifica? | Quien la creó o un administrador | El **equipo** (fases y tareas); el **responsable** o un administrador (datos del proyecto) |
| ¿Dónde aparece en el calendario? | Capa **Interno** | Capa **Proyectos** (hitos y vencimientos de tareas) |

Regla práctica: si es algo que **ocurre** en un momento (una reunión), use **Actividades internas**. Si es algo que **se construye** en etapas y quiere medir cuánto falta, use **Proyectos internos**.

---

## 2. Quién puede hacer qué

En cada proyecto hay tres tipos de personas:

- **Responsable**: una sola persona a cargo del proyecto.
- **Equipo**: las personas que trabajan en él. El responsable **siempre** forma parte del equipo.
- **Audiencia**: quienes solo **ven** el proyecto y su avance (toda la organización, ciertos roles o personas específicas).

| Acción | ¿Quién puede? |
|---|---|
| Ver el resumen de proyectos | Cualquier usuario activo (solo ve los proyectos que tiene permitido ver) |
| Crear un proyecto | Cualquier usuario activo |
| Ver un proyecto, su detalle y su línea de tiempo | Su audiencia, su equipo, su responsable, quien lo creó y los administradores |
| Agregar, editar y eliminar **fases** | El equipo (incluido el responsable) y los administradores |
| Agregar, editar y eliminar **tareas** y cambiar su **estado** | El equipo (incluido el responsable) y los administradores |
| Editar los datos del proyecto, su equipo y su audiencia (**Editar proyecto**) | Solo el **responsable** o un **administrador** |
| **Eliminar** el proyecto | Solo el **responsable** o un **administrador** |

> **Importante:** si alguien abre el enlace de un proyecto que no tiene permitido ver, el sistema responde **"página no encontrada" (error 404)**, como si el proyecto no existiera. No es un fallo: así se protege la información de proyectos reservados.

> **Quien crea el proyecto** lo sigue viendo siempre, pero solo puede trabajar en él mientras pertenezca al equipo, y solo puede editar sus datos o eliminarlo si es el responsable.

---

## 3. Dónde encontrar el módulo

En el **menú lateral izquierdo**, entrada **Proyectos internos** (debajo de *Calendario* y *Actividades internas*). Al abrirla verá el **resumen** con una tarjeta por cada proyecto que puede ver.

Desde cada tarjeta puede ir a:

- **Ver**: el detalle del proyecto, con sus fases y tareas.
- **Línea de tiempo**: el diagrama de Gantt del proyecto.

Además, los hitos y vencimientos de tareas aparecen en el menú **Calendario** (ver [sección 13](#13-los-proyectos-en-el-calendario-del-crm)).

---

## 4. Crear un proyecto interno

1. Menú **Proyectos internos** → botón **Nuevo proyecto**.
2. Se abre el formulario **Nuevo proyecto interno**. Complete los campos:

| Campo | ¿Obligatorio? | Qué poner |
|---|---|---|
| **Nombre** | Sí | Nombre claro del proyecto. Ej.: *Desarrollo del nuevo CRM*. Máximo 150 caracteres. |
| **Código** | Sí | Identificador corto y **único**. Ej.: *CRM-2027*. Máximo 30 caracteres. Debe empezar con una letra o un número y solo puede tener letras, números, puntos (.), guiones (-) y guiones bajos (_). No admite espacios. Aparece en el calendario entre corchetes: *[CRM-2027]*. |
| **Descripción** | No | Objetivo, alcance, enlaces de referencia. Máximo 5000 caracteres. |
| **Color** | No | *Sin color*, uno de los 10 colores de la paleta (Azul, Celeste, Turquesa, Verde, Mostaza, Naranja, Rojo, Rosado, Morado, Pizarra) o *Personalizado* (elige cualquier color). Identifica al proyecto en el resumen, la línea de tiempo y el calendario. Si elige *Sin color*, se usa un gris neutro. |
| **Inicio planificado** | No | Fecha prevista de inicio del proyecto. |
| **Fin planificado** | No | Fecha prevista de cierre. No puede ser anterior al inicio planificado. |
| **Estado** | Sí | *Planificado* (por defecto), *En curso*, *En pausa*, *Terminado* o *Cancelado*. Es informativo: **no** cambia el % de avance (ver [sección 8](#8-cómo-se-calcula-el-avance)). |
| **Responsable** | No | Por defecto, *Yo (quien crea el proyecto)*. Puede elegir a otro usuario activo. El responsable edita el proyecto, su equipo y su audiencia. |
| **Equipo** | No | Las personas que crearán y actualizarán fases, tareas y estados. Usted (quien crea) y el responsable se agregan automáticamente. |
| **¿Quién lo ve?** | Sí | La audiencia: *Toda la organización*, *Roles* o *Personas específicas*. Ver [sección 5](#5-equipo-y-audiencia-quién-trabaja-y-quién-lo-ve). |
| **Roles** / **Personas** | Según la audiencia | Aparece uno u otro según lo que elija en *¿Quién lo ve?*. Debe marcar al menos uno. |
| **Crear fases sugeridas (Análisis, Diseño, Desarrollo, Pruebas, Lanzamiento)** | No | Casilla **marcada por defecto**. Si la deja marcada, el proyecto nace con esas cinco fases, en ese orden. Desmárquela si prefiere crear sus propias fases. Solo aparece al crear. |

3. Pulse **Crear proyecto**. El sistema lo lleva al detalle del proyecto y muestra el mensaje *"Proyecto interno creado."*.

Si quiere salir sin guardar, pulse **Cancelar**.

### Fases sugeridas

Las cinco fases sugeridas cubren el ciclo típico de un desarrollo de software:

| Fase | Para qué sirve |
|---|---|
| **Análisis** | Levantar requisitos, entrevistas, alcance. |
| **Diseño** | Pantallas, flujos, modelo de datos. |
| **Desarrollo** | Programación de las funcionalidades. |
| **Pruebas** | Revisión, corrección de errores, validación con usuarios. |
| **Lanzamiento** | Puesta en producción, capacitación, comunicación. |

Puede renombrarlas, reordenarlas, eliminar las que no use (si aún no tienen tareas) o agregar otras en cualquier momento (ver [sección 6](#6-fases)).

---

## 5. Equipo y audiencia: quién trabaja y quién lo ve

### 5.1 Equipo (quién trabaja)

El campo **Equipo** define quién puede **modificar el contenido** del proyecto: crear, editar y eliminar fases y tareas, y cambiar el estado de las tareas. El equipo **siempre ve** el proyecto, aunque no esté en la audiencia.

- El **responsable** siempre forma parte del equipo; el sistema lo agrega aunque no lo marque.
- Al **crear** el proyecto, quien lo crea también queda en el equipo. Más adelante el responsable puede quitarlo si lo desea.
- Solo se pueden **agregar** usuarios activos (como equipo, audiencia o responsable de una tarea). Si alguien que ya estaba asignado se desactiva, sigue en el proyecto y aparece en las listas como **"(inactivo)"**; al editar no se pierde. El mensaje *El usuario seleccionado no existe o está inactivo.* aparece si intenta agregar a un usuario inactivo nuevo.

### 5.2 Audiencia (quién lo ve)

El campo **¿Quién lo ve?** decide quién puede **ver** el proyecto (resumen, detalle, línea de tiempo y calendario) **sin modificarlo**:

| Opción | Quién lo ve |
|---|---|
| **Toda la organización** (por defecto) | Todos los usuarios activos. |
| **Roles** | Aparece el selector **Roles**. Lo ven los usuarios que tengan **alguno** de los roles marcados. Ideal para que, por ejemplo, *marketing* y *gerencia* sigan el avance. |
| **Personas específicas** | Aparece el selector **Personas**. Lo ven solo las personas marcadas. Útil para proyectos reservados. |

### Quién lo ve siempre, sin importar la audiencia

- **Quien lo creó.**
- **El responsable** y **todo el equipo.**
- **Los administradores.**

> La audiencia **solo ve**: en el detalle del proyecto, el estado de cada tarea aparece como una etiqueta y no hay botones para agregar, editar ni eliminar.

---

## 6. Fases

Las fases son las **etapas** del proyecto. Se muestran en el detalle, cada una en su propia tarjeta, en el orden definido.

### Agregar una fase
1. En el detalle del proyecto, pulse **Agregar fase**.
2. En la ventana **Agregar fase** complete:

| Campo | ¿Obligatorio? | Qué poner |
|---|---|---|
| **Nombre** | Sí | Ej.: *Capacitación*. Máximo 120 caracteres. |
| **Orden** | No | Número que define la posición (de menor a mayor). Si lo deja vacío (*Al final*), la fase se agrega después de la última. |
| **Inicio planificado** | No | Fecha prevista de inicio de la fase. |
| **Fin planificado** | No | Fecha prevista de fin. No puede ser anterior al inicio planificado. |

3. Pulse **Agregar fase**. Verá el mensaje *"Fase agregada."*.

### Renombrar o cambiar el orden
1. En la cabecera de la fase, pulse el botón del **lápiz** (Editar fase).
2. Cambie el **Nombre**, el **Orden** o las fechas. Si deja **Orden** vacío, la fase conserva su posición actual.
3. Pulse **Guardar fase**. Verá *"Fase actualizada."*.

**Cómo funciona el orden:** las fases se ordenan por el número de **Orden**, de menor a mayor. Las fases sugeridas nacen con 1, 2, 3, 4 y 5. Para mover una fase entre otras dos, asígnele un número intermedio o renumere las demás; el sistema **no** renumera automáticamente. Si dos fases tienen el mismo número, aparece primero la que se creó antes.

### Eliminar una fase
1. En la cabecera de la fase, pulse el botón de la **papelera**.
2. Confirme el mensaje *"¿Eliminar esta fase?"*.

**Regla:** una fase **que tiene tareas no se puede eliminar**. Si lo intenta, el sistema muestra:

> *"No se puede eliminar una fase con tareas. Mueva o elimine primero sus tareas."*

Para moverlas, edite cada tarea y cambie su campo **Fase** (ver [sección 7](#7-tareas)). Cuando la fase quede vacía, podrá eliminarla y verá *"Fase eliminada."*.

---

## 7. Tareas

Las tareas son el trabajo concreto dentro de cada fase. Son las que **mueven el avance** del proyecto.

### Agregar una tarea
1. En la cabecera de la fase, pulse **+ Tarea**.
2. Se abre la ventana **Agregar tarea a [nombre de la fase]**. Complete:

| Campo | ¿Obligatorio? | Qué poner |
|---|---|---|
| **Título** | Sí | Qué hay que hacer. Ej.: *Diseñar pantalla de inicio de sesión*. Máximo 200 caracteres. |
| **Fase** | Sí | Viene elegida la fase desde la que abrió la ventana; puede cambiarla. |
| **Descripción** | No | Detalle, criterios de aceptación, enlaces. Máximo 5000 caracteres. |
| **Responsable** | No | Quién la ejecuta. Por defecto, *Sin responsable*. |
| **Estado** | No | *Pendiente* (por defecto), *En curso*, *Bloqueada* o *Terminada*. |
| **Inicio** | Sí | Fecha en que empieza el trabajo. |
| **Vencimiento** | Sí | Fecha límite. No puede ser anterior al inicio. Puede ser el mismo día. |
| **Peso** | Sí | Número entero de 1 a 65535. Por defecto, **1**. *"Cuánto cuenta en el avance."* (ver más abajo). |
| **Es un hito** | No | Márquelo si la tarea es un **hito** (ver más abajo). |

3. Pulse **Agregar tarea**. Verá *"Tarea agregada."* y la página lo lleva a la fila de la tarea.

### El peso: cuánto cuenta cada tarea

No todas las tareas cuestan lo mismo. El **peso** indica cuánto aporta una tarea al avance cuando se termina. Una tarea de peso 3 cuenta el triple que una de peso 1.

**Ejemplo:** la fase *Desarrollo* tiene tres tareas:

| Tarea | Peso | Estado |
|---|---|---|
| Módulo de clientes | 5 | Terminada |
| Módulo de reportes | 3 | En curso |
| Ajustar textos | 2 | Pendiente |

Peso total = 5 + 3 + 2 = **10**. Peso terminado = **5**. Avance de la fase = 5 / 10 = **50 %**.
Si las tres tuvieran peso 1, el avance sería 1 / 3 = **33 %**.

Consejo: use una escala sencilla y estable, por ejemplo **1 = pequeña, 2 = mediana, 3 = grande**, o el número estimado de días de trabajo.

### Hitos

Un **hito** es una tarea que marca un momento importante: *Aprobación del diseño*, *Salida a producción*, *Presentación a gerencia*. Marcar **Es un hito** hace que la tarea:

- Se muestre con un **rombo** (◆) en el detalle y en la línea de tiempo.
- Aparezca en **Próximos hitos** del resumen mientras no esté terminada.
- Aparezca **siempre** en el calendario del CRM, incluso después de terminada.

Un hito cuenta para el avance igual que cualquier otra tarea, según su peso y su estado.

### Estados de una tarea

| Estado | Significado |
|---|---|
| **Pendiente** | Aún no empieza. |
| **En curso** | Se está trabajando. |
| **Bloqueada** | No puede avanzar (espera de información, aprobación, otra área). |
| **Terminada** | Completada. Es el **único** estado que suma al avance. |

### Cambiar el estado

Lo pueden hacer el **equipo** (incluido el responsable) y los **administradores**:

1. En la tabla de tareas del detalle, en la columna **Estado**, abra la lista desplegable de la tarea.
2. Elija el nuevo estado. Se guarda **automáticamente** al elegirlo y verá *"Estado de la tarea actualizado."*.

También puede cambiarlo desde la ventana de edición de la tarea.

Cada cambio de estado queda **registrado internamente** con quién lo hizo y cuándo, para que el avance sea auditable. (Hoy ese historial no se muestra en pantalla.)

Quienes solo forman parte de la **audiencia** ven el estado como una etiqueta de color y no pueden cambiarlo.

### Editar, mover o eliminar una tarea
- **Editar:** en la columna **Acción**, pulse el **lápiz** (ventana **Editar tarea**), cambie lo necesario y pulse **Guardar tarea**. Verá *"Tarea actualizada."*.
- **Mover a otra fase:** edite la tarea y cambie el campo **Fase**.
- **Eliminar:** pulse la **papelera** y confirme *"¿Eliminar esta tarea?"*. Verá *"Tarea eliminada."*. La tarea deja de contar para el avance.

> Si al guardar una fase o una tarea algún dato no es válido, la ventana se cierra y en la parte superior del detalle aparece el recuadro *"No se pudo guardar. Revise lo siguiente:"* con la lista de errores. Vuelva a abrir la ventana y corrija los datos (puede que deba escribirlos de nuevo).

---

## 8. Cómo se calcula el avance

El avance se calcula **solo a partir de las tareas en estado Terminada**, ponderadas por su peso:

```text
Avance = (suma del peso de las tareas Terminadas) ÷ (suma del peso de todas las tareas) × 100
```

- **Avance de una fase:** se calcula con las tareas de esa fase.
- **Avance general del proyecto:** se calcula con **todas** las tareas del proyecto (no es el promedio de las fases).
- **Redondeo hacia abajo:** el resultado se muestra como número entero **sin redondear hacia arriba**. Por ejemplo, 99,9 % se muestra como **99 %**. Así, el **100 %** solo aparece cuando todo está realmente terminado.
- **Sin tareas = 0 %:** una fase o un proyecto sin tareas muestra **0 %**.
- *Pendiente*, *En curso* y *Bloqueada* **no suman**: una tarea al 90 % de trabajo sigue contando 0 hasta que se marque *Terminada*.
- Las tareas eliminadas no cuentan.
- El **Estado del proyecto** (*Planificado*, *En curso*, *Terminado*…) es informativo y **no** afecta el porcentaje. Del mismo modo, llegar al 100 % **no** cambia el estado del proyecto a *Terminado*: hágalo usted desde **Editar proyecto**.

### Ejemplo completo

| Fase | Tareas (peso, estado) | Peso total | Peso terminado | Avance de la fase |
|---|---|---|---|---|
| Análisis | A (2, Terminada), B (1, Terminada) | 3 | 3 | **100 %** |
| Diseño | C (3, Terminada), D (3, En curso) | 6 | 3 | **50 %** |
| Desarrollo | E (5, Pendiente), F (5, Bloqueada), G (2, Pendiente) | 12 | 0 | **0 %** |
| Pruebas | (sin tareas) | 0 | 0 | **0 %** |

Avance general = (3 + 3 + 0) ÷ (3 + 6 + 12) × 100 = 6 ÷ 21 × 100 = 28,57… → **28 %**.

---

## 9. Tareas atrasadas

Una tarea está **Atrasada** cuando:

1. Su **fecha de vencimiento ya pasó** según el calendario de **Lima (Perú)**, y
2. **No** está *Terminada*.

Detalles:

- Una tarea que vence **hoy** todavía **no** está atrasada; pasa a estarlo a partir de mañana.
- *Atrasada* no es un estado que se elija: el sistema lo calcula solo. La tarea conserva su estado (*Pendiente*, *En curso* o *Bloqueada*) y además se marca como atrasada.
- Al marcarla *Terminada*, deja de estar atrasada.

Dónde se ve:

- **Detalle:** etiqueta roja **Atrasada** junto al título.
- **Resumen y detalle:** contador **Atrasadas**; los próximos hitos atrasados aparecen en **rojo**.
- **Línea de tiempo:** barra **roja** y etiqueta **Atrasada**.
- **Calendario:** borde rojo e ícono de advertencia (o la etiqueta **Atrasada** en la vista Lista).

---

## 10. El resumen de proyectos (tablero)

Menú **Proyectos internos**. Muestra una **tarjeta por proyecto**, solo de los que usted puede ver. Primero aparecen los proyectos activos y al final los *Terminados* y *Cancelados*; dentro de cada grupo, por fecha de inicio planificada y luego por nombre. Si hay muchos, se reparten en páginas de 12.

### Qué muestra cada tarjeta

- **Franja de color** del proyecto en la parte superior.
- **Nombre**, **código** y la etiqueta del **estado** del proyecto.
- **Responsable** (o *Sin responsable*) y **fechas planificadas** (inicio – fin; "—" si no hay).
- **Avance general**: porcentaje y barra con el color del proyecto.
- **Una barra por fase**, en orden, con su porcentaje.
- **Contadores**: *Total*, *Terminadas*, *En curso*, *Bloqueadas* y *Atrasadas*.
- **Próximos hitos**: hasta **3** hitos aún no terminados, del más próximo al más lejano, con su fecha. Los atrasados se muestran en **rojo**. Si no hay, dice *"Sin hitos pendientes."*.
- Botones **Línea de tiempo** y **Ver**.

### Filtrar por estado

Arriba a la izquierda, el selector **Estado** (*Todos*, *Planificado*, *En curso*, *En pausa*, *Terminado*, *Cancelado*) filtra las tarjetas apenas elige una opción.

Si no hay proyectos para mostrar, verá *"No hay proyectos internos."*.

---

## 11. El detalle de un proyecto

Desde el resumen, botón **Ver**. La página **Proyecto interno** muestra:

### Cabecera
- Botones **Proyectos internos** (volver al resumen) y **Línea de tiempo**.
- **Editar proyecto** y **Eliminar**: solo para el responsable o un administrador.
- Nombre, código, estado, **Avance general** con su barra y los contadores.
- **Responsable**, **Fechas planificadas**, **Equipo**, **Audiencia** (y, si corresponde, los roles o personas elegidos) y **Descripción**.

### Filtros de tareas

| Filtro | Opciones |
|---|---|
| **Fase** | *Todas* o una fase concreta (muestra solo esa fase). |
| **Estado** | *Todos*, *Pendiente*, *En curso*, *Bloqueada*, *Terminada*. |
| **Responsable** | *Todos* o una de las personas que tienen tareas asignadas en el proyecto. |

Elija los filtros y pulse **Filtrar**. Para volver a ver todo, pulse **Quitar filtros**. Si una fase no tiene tareas que cumplan los filtros, verá *"No hay tareas que coincidan con los filtros."*.

> Los filtros solo cambian **qué tareas se listan**. Las barras y porcentajes de avance siempre se calculan con **todas** las tareas.

### Fases y tareas
Cada fase aparece como una tarjeta con su nombre, sus fechas planificadas (si las tiene), su barra de avance, el porcentaje y el conteo *(terminadas/total tareas)*. Debajo, la tabla de tareas con las columnas **Tarea**, **Responsable**, **Inicio**, **Vencimiento**, **Estado**, **Peso** y, para el equipo, **Acción**.

- Los hitos llevan un **rombo** antes del título.
- Las tareas atrasadas llevan la etiqueta **Atrasada**.
- Si la descripción existe, se ve un extracto debajo del título.
- Una fase sin tareas muestra *"Esta fase aún no tiene tareas."*.
- Un proyecto sin fases muestra *"El proyecto aún no tiene fases. Agregue la primera con «Agregar fase»."* (al equipo) o *"El proyecto aún no tiene fases."* (a la audiencia).

---

## 12. La línea de tiempo (Gantt)

Botón **Línea de tiempo** (en la tarjeta del resumen o en el detalle). Abre la página **Línea de tiempo del proyecto**, que muestra el plan en el tiempo, día por día. La puede ver cualquier persona que pueda ver el proyecto.

### Cabecera
Nombre, código y estado del proyecto; **Avance** (por ejemplo, *"Avance: 28 % (3 de 7 tareas terminadas, 2 atrasadas)"*), el **Responsable** y el **Rango** de fechas mostrado. El botón **Volver al proyecto** regresa al detalle.

### Cómo leerla

- **Columna izquierda (fija):** el nombre de cada fase y, debajo, sus tareas con su responsable y su estado. Esta columna queda fija mientras se desplaza.
- **Encabezado superior:** primera fila con los **meses** (ej.: *oct 2026*) y segunda fila con el **lunes** de cada semana (ej.: *05/10*). Las líneas verticales finas marcan las semanas.
- **Filas de fase** (fondo gris claro): una barra resumen que va desde el inicio de su primera tarea hasta el vencimiento de su última tarea. La parte **oscura** de la barra representa el **% de avance** de la fase, y el porcentaje se muestra a la derecha. Si la fase no tiene tareas, la barra usa sus fechas planificadas (si las tiene). Junto al nombre se indica *"% · N tareas"* y, si hay, cuántas están atrasadas.
- **Filas de tarea:** una barra desde la fecha de **inicio** hasta la de **vencimiento**, con el título dentro.
- **Hitos:** en lugar de barra, un **rombo** en el día de su vencimiento.
- **Línea Hoy:** una línea vertical **roja** con la etiqueta **Hoy** marca el día actual (hora de Lima). Si hoy queda fuera del rango, se indica *"Hoy (dd/mm/aaaa) está fuera del rango del proyecto."*.

### Colores (leyenda)

| Color | Significado |
|---|---|
| **Verde** | Terminada |
| **Rojo** | Atrasada (vencida y no terminada) |
| **Amarillo** | Bloqueada |
| **Color del proyecto** | En curso |
| **Gris claro con borde** | Pendiente |
| **Rombo** | Hito (con el mismo color según su estado) |
| **Barra gris con parte oscura** | Fase (avance) |
| **Línea roja vertical** | Hoy |

Si una tarea está atrasada, el rojo tiene prioridad sobre *Bloqueada*, *En curso* o *Pendiente*.

### Rango y desplazamiento
- El rango abarca **todas las fechas** del proyecto, de sus fases y de sus tareas, ampliado a semanas completas (de lunes a domingo).
- Si el proyecto aún no tiene fechas, se muestra el **mes actual**.
- Si el plan dura más de **18 meses**, se muestran solo los primeros 18 y aparece el aviso *"El proyecto abarca más de 18 meses; se muestran solo los primeros 18 meses."*.
- Si el diagrama no entra en la pantalla, **desplácese horizontalmente** (barra de desplazamiento, rueda del mouse con Mayús o flechas del teclado tras hacer clic en el diagrama).

### Interacción
- Pase el mouse sobre una barra o rombo para ver su resumen (título, fechas, estado, hito y responsable).
- **Haga clic** en una barra, rombo o título de tarea para ir a esa tarea en el detalle del proyecto.

---

## 13. Los proyectos en el calendario del CRM

Menú **Calendario**. Los proyectos internos aparecen en el mismo calendario que las actividades comerciales y las actividades internas.

### Qué aparece
De los proyectos que usted puede ver, aparecen en el día de su **fecha de vencimiento**:

- **Todos los hitos**, estén o no terminados.
- **Las tareas que no están terminadas** (Pendiente, En curso o Bloqueada). Cuando una tarea normal se marca *Terminada*, desaparece del calendario.

Cada elemento se muestra como evento de **todo el día** (sin hora), solo en su día de vencimiento (no se dibuja desde el inicio hasta el vencimiento; para eso use la línea de tiempo).

### Cómo reconocerlos
- **Título:** *[CÓDIGO] Título de la tarea*. Los hitos empiezan con un rombo: *◆ [CRM-2027] Salida a producción*.
- **Etiqueta de tipo:** **Hito** o **Proyecto**.
- **Color:** fondo suave y borde izquierdo con el **color del proyecto**.
- **Atrasadas:** borde rojo e ícono de advertencia; en la vista **Lista**, etiqueta **Atrasada**.
- En la vista **Lista**: la columna *Sujeto* muestra el **nombre del proyecto**, el estado muestra el de la tarea (*Pendiente*, *En curso*, *Bloqueada*, *Terminada*) y el responsable es el de la tarea. En la vista **Día**, aparecen en el bloque *Todo el día*.
- Al hacer clic, se abre el **detalle del proyecto** en la fila de esa tarea.

### El filtro de capas

| Opción | Qué muestra |
|---|---|
| **Todo** (por defecto) | Actividades comerciales, vencimientos de facturas (si tiene permiso), actividades internas **y** proyectos |
| **Comercial** | Solo lo comercial |
| **Interno** | Solo las actividades internas |
| **Proyectos** | Solo los hitos y vencimientos de tareas de proyectos internos |

Elija la opción y pulse **Filtrar**. **Limpiar** vuelve a mostrar todo.

> **Nota sobre otros filtros:** los filtros *Responsable*, *Tipo*, *Sujeto* y *Estado* son propios de las actividades comerciales. Si usa alguno de ellos con la capa *Todo*, el calendario muestra solo actividades comerciales, porque las tareas de proyectos no tienen esos datos.

---

## 14. Editar o eliminar un proyecto

Solo el **responsable** del proyecto o un **administrador** ven los botones **Editar proyecto** y **Eliminar**.

### Editar
1. En el detalle, pulse **Editar proyecto**.
2. Se abre **Editar proyecto interno** con los mismos campos del alta (salvo *Crear fases sugeridas*): nombre, código, descripción, color, fechas, estado, responsable, equipo y audiencia.
3. Pulse **Guardar cambios**. Verá *"Proyecto interno actualizado."*.

Tenga en cuenta:
- Si cambia el **Responsable**, el nuevo pasa a formar parte del equipo y a ser quien puede editar el proyecto. El responsable anterior sigue en el equipo mientras usted no lo desmarque, pero **ya no** podrá editar los datos del proyecto ni eliminarlo.
- Si cambia la **audiencia**, el cambio de visibilidad es inmediato.

### Eliminar
1. En el detalle, pulse **Eliminar**.
2. Confirme el mensaje *"¿Eliminar este proyecto interno con sus fases y tareas?"*.

El proyecto desaparece del resumen, de la línea de tiempo y del calendario, y verá *"Proyecto interno eliminado."*. El sistema conserva un registro interno, pero el proyecto ya no se muestra en la aplicación.

---

## 15. Cómo usar los selectores de varias opciones

Los campos **Equipo**, **Roles** y **Personas** permiten elegir varias opciones. No hace falta usar Ctrl + clic:

1. Haga clic en el campo: se abre un panel con casillas.
2. Marque o desmarque las opciones con un clic.
3. Si hay 6 opciones o más, aparece un **buscador** arriba: escriba parte del nombre para filtrar.
4. **Todos** marca todas las opciones visibles; **Ninguno** las desmarca.
5. Lo elegido aparece como **etiquetas** dentro del campo. Para quitar una, pulse la **×** de la etiqueta.
6. Para cerrar el panel, haga clic afuera o pulse **Esc**.

Con teclado: **flecha abajo** abre el panel y recorre las opciones, **Enter** o **Espacio** marcan, **Esc** cierra.

---

## 16. Mensajes frecuentes

### Errores al guardar un proyecto

| Mensaje | Causa | Qué hacer |
|---|---|---|
| *El campo nombre es obligatorio.* | Falta el nombre | Escriba un nombre |
| *El campo código es obligatorio.* | Falta el código | Escriba un código |
| *Ya existe un proyecto con este código.* | Otro proyecto usa ese código | Elija un código distinto (ej.: agregue el año) |
| *El código solo puede tener letras, números, puntos, guiones y guiones bajos.* | El código tiene espacios, tildes u otros símbolos, o empieza con un símbolo | Use, por ejemplo, *CRM-2027* |
| *El campo código no debe tener más de 30 caracteres.* | Código demasiado largo | Acórtelo |
| *Elija un color válido.* | El color elegido no es válido | Elija un color de la paleta o *Sin color* |
| *La fecha de fin no puede ser anterior a la fecha de inicio.* | *Fin planificado* antes de *Inicio planificado* | Corrija las fechas |
| *Seleccione al menos un rol.* | Audiencia *Roles* sin ningún rol marcado | Marque al menos un rol |
| *Seleccione al menos una persona.* | Audiencia *Personas específicas* sin nadie marcado | Marque al menos una persona |

### Fases

| Mensaje | Causa | Qué hacer |
|---|---|---|
| *El campo nombre de la fase es obligatorio.* | Falta el nombre | Escriba un nombre |
| *La fecha de fin de la fase no puede ser anterior a su fecha de inicio.* | *Fin planificado* antes de *Inicio planificado* | Corrija las fechas |
| *No se puede eliminar una fase con tareas. Mueva o elimine primero sus tareas.* | La fase tiene tareas | Mueva las tareas a otra fase o elimínelas |

### Tareas

| Mensaje | Causa | Qué hacer |
|---|---|---|
| *El campo título es obligatorio.* | Falta el título | Escriba un título |
| *El campo fecha de inicio es obligatorio.* | Falta *Inicio* | Complete la fecha |
| *El campo fecha de vencimiento es obligatorio.* | Falta *Vencimiento* | Complete la fecha |
| *La fecha de vencimiento no puede ser anterior a la fecha de inicio.* | *Vencimiento* antes de *Inicio* | Corrija las fechas |
| *El campo peso debe ser al menos 1.* | Peso 0 o negativo | Use un número entero desde 1 |
| *El campo peso debe ser un número entero.* | Peso con decimales | Use un número entero |
| *La fase seleccionada no pertenece a este proyecto.* | La fase elegida ya no existe en el proyecto (por ejemplo, otra persona la eliminó) | Recargue la página y elija otra fase |

### Mensajes de confirmación

| Acción | Mensaje |
|---|---|
| Crear / editar / eliminar proyecto | *Proyecto interno creado.* / *Proyecto interno actualizado.* / *Proyecto interno eliminado.* |
| Agregar / editar / eliminar fase | *Fase agregada.* / *Fase actualizada.* / *Fase eliminada.* |
| Agregar / editar / eliminar tarea | *Tarea agregada.* / *Tarea actualizada.* / *Tarea eliminada.* |
| Cambiar estado de una tarea | *Estado de la tarea actualizado.* |

### Situaciones

| Situación | Causa | Qué hacer |
|---|---|---|
| Página no encontrada (404) al abrir un proyecto | No está en su audiencia ni en su equipo | Pida al responsable que lo agregue a la audiencia o al equipo |
| No aparecen los botones **Agregar fase**, **+ Tarea** ni los de editar | Usted es audiencia, no equipo | Pida al responsable que lo agregue al **Equipo** |
| No aparece el botón **Editar proyecto** | No es el responsable ni administrador | Pida el cambio al responsable o a un administrador |

---

## 17. Preguntas frecuentes

**¿Por qué el proyecto muestra 0 % si ya trabajamos mucho?**
Solo suman las tareas *Terminadas*. Si todo está *En curso*, el avance es 0 %. Divida el trabajo en tareas más pequeñas para que el avance refleje mejor el progreso.

**Terminamos todas las tareas, ¿el proyecto pasa a "Terminado"?**
No. El avance llega a 100 %, pero el estado del proyecto lo cambia el responsable desde **Editar proyecto**.

**¿Por qué marca 99 % y no 100 %?**
El porcentaje se redondea hacia abajo. Queda alguna tarea sin terminar; búsquela con el filtro **Estado**.

**Creé el proyecto y puse a otra persona como responsable; ahora no puedo editarlo.**
Es lo esperado: solo el responsable (o un administrador) edita los datos del proyecto. Usted sigue viéndolo y, si está en el equipo, puede trabajar en fases y tareas.

**¿Cómo hago para que gerencia vea el avance sin poder modificarlo?**
En **¿Quién lo ve?** elija *Roles* y marque el rol de gerencia (o *Personas específicas*). No los agregue al **Equipo**.

**¿Puedo cambiar el estado de una tarea que no es mía?**
Sí, si está en el equipo del proyecto. El estado lo puede cambiar cualquier integrante del equipo, no solo el responsable de la tarea. Cada cambio queda registrado con quién lo hizo.

**¿Por qué una tarea terminada ya no aparece en el calendario?**
El calendario muestra solo lo pendiente de vigilar: tareas no terminadas y todos los hitos.

**¿Por qué no veo los proyectos en el calendario?**
Verifique que la capa esté en *Todo* o *Proyectos*, y que no tenga activo un filtro de *Responsable*, *Tipo*, *Sujeto* o *Estado*.

**¿Las fechas de las tareas tienen que estar dentro de las fechas de la fase o del proyecto?**
No, el sistema no lo exige. La línea de tiempo se amplía para mostrar todas las fechas. Revise que el plan sea coherente.

**¿Una tarea que vence hoy figura como atrasada?**
No. Se considera atrasada desde el día siguiente a su vencimiento (hora de Lima), si no está terminada.

**¿Recibo notificaciones cuando me asignan una tarea?**
No. Este módulo no envía notificaciones (ver [sección 19](#19-lo-que-todavía-no-hace)). Revise el detalle del proyecto o el calendario.

---

## 18. Ejemplo práctico: desarrollo de un software

**Situación:** el equipo de sistemas desarrollará un nuevo portal de clientes. Marketing debe preparar el lanzamiento y gerencia quiere seguir el avance.

### Paso 1 — Crear el proyecto
- **Nombre:** Portal de clientes
- **Código:** PORTAL-2027
- **Color:** Azul
- **Inicio planificado:** 02/11/2026 — **Fin planificado:** 26/02/2027
- **Estado:** En curso
- **Responsable:** jefe de sistemas
- **Equipo:** los dos desarrolladores y la diseñadora
- **¿Quién lo ve?:** Roles → *marketing*, *gerencia* (use los nombres de rol que existan en su organización)
- **Crear fases sugeridas:** marcada

### Paso 2 — Ajustar fases
Las cinco fases sugeridas se crean solas. Edite cada una (lápiz) para poner su **Inicio planificado** y **Fin planificado**, por ejemplo *Análisis* 02/11–20/11.

### Paso 3 — Cargar tareas

| Fase | Tarea | Peso | Hito |
|---|---|---|---|
| Análisis | Entrevistas con usuarios | 2 | |
| Análisis | Documento de requisitos aprobado | 1 | ✔ |
| Diseño | Prototipo de pantallas | 3 | |
| Diseño | Aprobación del diseño por gerencia | 1 | ✔ |
| Desarrollo | Inicio de sesión y registro | 3 | |
| Desarrollo | Consulta de facturas | 5 | |
| Pruebas | Pruebas con usuarios piloto | 3 | |
| Lanzamiento | Salida a producción | 1 | ✔ |
| Lanzamiento | Campaña de comunicación (marketing) | 2 | |

### Paso 4 — Trabajo diario
El equipo cambia el estado de cada tarea en la columna **Estado** a medida que avanza (*En curso*, *Bloqueada*, *Terminada*).

### Resultado
- **Gerencia** abre **Proyectos internos**, ve la tarjeta *Portal de clientes* con el % general, la barra de cada fase, cuántas tareas están bloqueadas o atrasadas y los **próximos hitos**. Con **Línea de tiempo** ve si el plan va a tiempo respecto de la línea **Hoy**.
- **Marketing** ve en el **Calendario** (capa *Proyectos* o *Todo*) el hito *◆ [PORTAL-2027] Salida a producción* en su fecha, y planifica su campaña en función de él.
- Ninguno de los dos puede modificar el plan: solo lo ven.
- Si una tarea se bloquea esperando una aprobación, aparece en amarillo en la línea de tiempo y en el contador **Bloqueadas**; si además se pasa su vencimiento, aparece en rojo y suma a **Atrasadas**.

---

## 19. Lo que todavía no hace

Para evitar confusiones, estas funciones **no** están disponibles hoy:

- **Dependencias entre tareas:** no se puede indicar que una tarea empieza cuando termina otra; las fechas se gestionan a mano.
- **Comentarios y adjuntos en las tareas:** use la **Descripción** para notas y enlaces.
- **Notificaciones:** no se envían avisos (ni en la campana ni por correo) al asignar una tarea, al cambiar su estado ni cuando vence.
- **Tablero Kanban:** no hay vista de columnas por estado para arrastrar tareas; use la tabla del detalle y su filtro **Estado**.
- **Historial visible de estados:** los cambios de estado se registran internamente, pero no hay una pantalla para consultarlos.
- **Avance parcial de una tarea:** una tarea cuenta 0 o su peso completo; no admite porcentajes intermedios.
- **Sincronización con Google Calendar:** los hitos y tareas de proyectos solo se ven en el calendario del CRM.
- **Reordenar tareas dentro de una fase:** se muestran por fecha de inicio y, a igual fecha, por orden de creación; no se pueden arrastrar.
