# Guía del CRM — Módulos y flujo de uso

> Documento de orientación para usuarios del CRM Maia Consultores.
> Última revisión: agosto 2026.

---

## 1. ¿Qué es este CRM?

Monolito modular construido sobre **Laravel 13 + MySQL 8**, con interfaz en español (**AdminLTE 4 + Bootstrap 5**), moneda **PEN** por defecto, zona horaria `America/Lima` y formato de fecha `dd/mm/yyyy`.

Cubre el ciclo comercial completo:

- Captura de **prospectos** desde múltiples orígenes.
- Conversión a **clientes** con contactos asociados.
- Gestión de **oportunidades** en un pipeline Kanban.
- Seguimiento mediante **actividades** y calendario.
- Emisión de **cotizaciones** con cálculo server-side y PDF.
- **Documentos** adjuntos polimórficos sobre cualquier entidad.
- **Dashboard** con 12 KPIs reales (sin datos simulados).
- **12 reportes** exportables a Excel.
- **Administración** granular: usuarios, equipos, roles, catálogos, auditoría.

Toda la autorización pasa por **62 permisos granulares** de Spatie y un `DataScopeService` que respeta el alcance **admin / supervisor / vendedor**. Un vendedor nunca ve registros ajenos — ni en la UI ni en las exportaciones.

---

## 2. Módulos disponibles

| Módulo | Función principal |
|---|---|
| **Prospectos (Leads)** | CRUD con detección de duplicados por documento, email o teléfono (normalizados), advertencia confirmada en servidor y auditada. Importación / exportación Excel. Asignación de responsable. Historial cronológico. |
| **Clientes y Contactos** | Alta de personas naturales y jurídicas. Conversión desde prospecto en una sola transacción. Hasta N contactos por cliente con un único principal activo garantizado transaccionalmente. Ficha 360° con oportunidades, cotizaciones, actividades y documentos. |
| **Oportunidades / Kanban** | Pipeline drag & drop (HTML5 vanilla con fallback sin JS). Totales por moneda y por columna. Cambio de etapa append-only. Marcada *ganada* exige monto final y fecha; *perdida* exige motivo. Asignación con notificación. |
| **Actividades y calendario** | 7 tipos (llamada, mensaje, reunión, correo, visita, tarea, nota). Cinco estados (`pending → in_process → completed` / `cancelled` / `overdue`). Scheduler diario marca vencidas y notifica próximos vencimientos. Cuatro vistas: mes, semana, día, lista. |
| **Productos** | Catálogo global con tipo (producto / servicio), moneda, impuesto predeterminado. Sin borrado físico, solo desactivación. Exportación Excel. |
| **Cotizaciones** | Numeración `COT-AAAA-NNNNN`. Cabecera + detalle con snapshot histórico de precio e impuesto. Cálculo server-side validado (`DECIMAL(14,2)`). PDF profesional vía Dompdf. Aceptación con confirmación explícita y cierre de oportunidad. Duplicación. |
| **Documentos** | Adjuntos polimórficos sobre prospecto, cliente, contacto, oportunidad, cotización y actividad. Disco privado configurable. Validación de extensión, MIME y tamaño. Descarga autorizada. URL temporal cuando el driver lo soporta. |
| **Dashboard** | 12 KPIs reales según alcance: prospectos nuevos, sin contactar, oportunidades abiertas, valor de embudo por moneda, ganadas / perdidas, actividades pendientes y vencidas, próximas reuniones, conversión por etapa, rendimiento por vendedor. |
| **Reportes** | 12 reportes con filtros por fecha, usuario, estado, etapa y moneda. Exportación Excel con endpoint `?export=xlsx`. Multimoneda sin consolidar (cada moneda se reporta por separado). |
| **Administración** | Usuarios (activar/desactivar, reset de password, último acceso). Equipos (definen el alcance de datos). Roles y permisos (Spatie, 62 granulares). Ocho catálogos configurables (solo desactivar, nunca borrar). Parámetros generales (`settings`). Visor de auditoría. |
| **Notificaciones** | Internas en base de datos con campana en navbar: asignación de oportunidad, cambio de etapa, asignación de actividad, próxima actividad y vencida. Arquitectura lista para agregar `mail` y `whatsapp`. |

---

## 3. Mapa de dependencias entre módulos

Antes de operar, conviene entender que los módulos **no son independientes**: hay un orden natural de carga.

```
Settings ─┬─► Catálogos ─┬─► Usuarios / Equipos / Roles
          │              │
          │              ├─► Productos ──────────────┐
          │              │                            │
          ▼              ▼                            ▼
        Prospectos ──► Clientes + Contactos ──► Oportunidades (Kanban)
              │                                  │
              └────► Actividades (transversal) ──┴──► Cotizaciones ──► PDF
                                                            │
                                                            ▼
                                                     Documentos (transversal)
                                                            │
                                                            ▼
                                                  Dashboard + Reportes
                                                            │
                                                            ▼
                                                 Notificaciones + Auditoría
```

### Lectura del mapa

- **Settings y catálogos** son prerrequisito de todo lo demás.
- **Productos** debe existir antes de poder cotizar.
- **Actividades** es transversal: se puede (y debe) usar en cualquier punto del embudo.
- **Prospecto → Cliente → Oportunidad → Cotización** es el flujo natural de una venta.
- **Documentos, Notificaciones y Auditoría** se usan transversalmente sobre cualquier entidad.

---

## 4. Orden recomendado de llenado

### Fase 1 — Andamiaje (una sola vez)

1. **Settings / Parámetros generales** — los 13 campos de la tabla `settings` agrupados en `general` (4), `quotations` (1) y `sequences` (8). La zona horaria y los datos de la empresa **no** están acá: ver `checklist-implementacion.md` § "Lo que no está en Settings".
2. **Catálogos** — revisar los que vienen pre-cargados del seeder:
   - Monedas (PEN, USD, EUR).
   - Impuestos (Gravado IGV 18%, Exonerado, Inafecto, Gratuito).
   - Estados de prospecto (Nuevo → Contactado → Calificado → Convertido / Perdido).
   - Orígenes de prospecto (Web, Referido, Campaña, Llamada, Feria, Redes sociales, Otro).
   - Tipos de actividad (Llamada, WhatsApp, Correo, Reunión, Visita, Tarea, Nota).
   - Etapas del pipeline (Nueva oportunidad → Ganada / Perdida).
   - Motivos de pérdida (Precio, Competencia, Sin respuesta, Sin presupuesto).
   - Categorías de producto.
3. **Equipos** — definir los equipos comerciales (son el "alcance" de datos para los vendedores).
4. **Usuarios** — crear usuarios y asignarles equipo + rol.
5. **Roles y permisos** — usar los 62 permisos ya armados; solo asignar los que correspondan a cada rol.

> **Por qué primero esto:** los catálogos son el vocabulario del sistema. Si no existen las etapas, no podés mover una oportunidad. Si no hay equipos, no podés asignar un vendedor con alcance correcto.

### Fase 2 — Operación diaria (orden del embudo real)

1. **Productos** — cargar el catálogo (productos/servicios) antes de cotizar.
2. **Prospectos** — primer punto de contacto con el mercado. Asignar responsable y origen desde el primer momento (afecta reportes).
3. **Actividades** — en paralelo a todo lo demás. Cada llamada, reunión o WhatsApp es una actividad con fecha y estado. Es el pulmón del CRM.
4. **Clientes** — nacen cuando convertís un prospecto. La conversión crea cliente + contacto en una sola transacción.
5. **Contactos** — agregar hasta N contactos por cliente, marcando uno como principal activo (único garantizado por la base).
6. **Oportunidades** — sobre el cliente ya creado. Recorren el Kanban: Nueva → Contacto → Reunión → Propuesta → Negociación → Ganada / Perdida. Ganada exige monto y fecha; perdida exige motivo.
7. **Cotizaciones** — cuando la oportunidad está avanzada (típicamente desde "Propuesta enviada"). Adjuntar ítems del catálogo de Productos con precio e impuesto. Numeración automática `COT-AAAA-NNNNN`. PDF generado. Al aceptarla, cierra la oportunidad.

### Fase 3 — Análisis (cuando ya hay volumen)

1. **Dashboard** — revisar los 12 KPIs según el alcance.
2. **Reportes** — usar los 12 reportes, filtrar por fecha, usuario, estado, etapa y moneda. Exportar a Excel cuando haga falta.

### Fase 4 — Soporte transversal

1. **Documentos** — cargar contratos, propuestas firmadas, hojas técnicas sobre cualquier entidad.
2. **Visor de auditoría** — quién hizo qué y cuándo (toda acción sensible queda logueada).
3. **Notificaciones** — la campana en el navbar muestra asignaciones, cambios de etapa, actividades próximas y vencidas.

---

## 5. Resumen ejecutivo del orden

```
Fase 1  Settings → Catálogos → Equipos → Usuarios → Roles
Fase 2  Productos → Prospectos → Actividades
                       ↓ (Convertir)
                  Clientes → Contactos → Oportunidades → Cotizaciones
Fase 3  Dashboard → Reportes
Fase 4  Documentos / Auditoría / Notificaciones (transversal)
```

---

## 6. Buenas prácticas operativas

- **Nunca cargues un cliente directo**: usá siempre la conversión desde prospecto. Garantiza integridad y queda auditado.
- **Actividades primero, datos después**: si cargás prospectos sin actividades, perdés la trazabilidad de seguimiento y no aparecen en el calendario.
- **El Kanban refleja la realidad, no al revés**: si una oportunidad está en "Propuesta enviada", asegurate de tener al menos una cotización emitida.
- **Los catálogos no se borran, solo se desactivan**: preservan el histórico. Es por diseño.
- **Asigná responsable desde el inicio**: tanto en prospectos como en oportunidades. Sin responsable asignado, no aparecen en el dashboard del vendedor.
- **El motivo de pérdida es obligatorio**: cuando marqué una oportunidad como perdida, te pide motivo. Esto alimenta los reportes de causa de pérdida.

---

## 7. Documentación técnica relacionada

Si necesitás profundizar en la arquitectura, el modelo de datos o las decisiones técnicas, consultá:

- `docs/INDEX.md` — tabla de contenidos de toda la documentación.
- `docs/ARQUITECTURA.md` — stack, capas, autorización, auditoría, jobs / scheduler, entornos.
- `docs/BASE_DATOS.md` — modelo de datos, migraciones, estrategia de correlativos y borrado lógico.
- `docs/REQUISITOS.md` — matriz de RF y RNF con ID estable y prueba asociada.
- `docs/DECISIONES.md` — ADRs (ADR-001 a ADR-016) — decisiones técnicas inmutables.
- `docs/SEGURIDAD.md` — autenticación, autorización, protecciones web, hallazgos corregidos.
- `docs/PRUEBAS.md` — estado de la suite, archivos, reglas y criterio de "módulo terminado".
- `docs/AVANCE.md` — bitácora por bloque (B00 a B09) con evidencia real.
