# customer-payments-invoices — Tarjeta de Pagos e Invoices en cliente (PRD / sdd-proposal)

> **Phase**: phase correction for existing sdd-proposal/sdd-spec/design artifacts only — no tasks, apply, or application code changes.
> **Artifact store**: OpenSpec.
> **Contexto de pantalla**: detalle de cliente `/customers/7`, junto a las tarjetas existentes **Datos del cliente**, **Contactos**, **Historial comercial**, **Actividades**, **Cotizaciones** y **Documentos**.
> **Decisiones ya tomadas**: primer slice = invoices + payments card; modalidad de pago vive a nivel cliente; estados de factura vienen de Catálogos; `Vencida` es automática y debe persistirse en BD (`status_id = Vencida`) para facturas cobrables activas vencidas; `Pagado` y `Nota de crédito` no generan alertas ni pasan automáticamente a `Vencida`; facturas retiradas/anuladas tampoco pasan automáticamente a `Vencida`; alertas van al Calendario global; registro manual primero; importe total únicamente; alerta en fecha de vencimiento para factura impaga; marcar como pagada sólo cambia estado por ahora.

---

## 1. Resumen ejecutivo

Se agregará una nueva tarjeta **Pagos** dentro del detalle de cliente para que el equipo pueda registrar y consultar manualmente facturas del cliente, su estado, vencimiento, importe total y modalidad de pago asociada al cliente. El primer slice busca dar visibilidad operativa inmediata sobre cuentas por cobrar sin construir todavía un módulo financiero completo: las facturas se cargan manualmente, los estados se administran desde **Catálogos**, toda factura cobrable activa genera una alerta en el **Calendario** global el día de su vencimiento y las facturas cobrables activas vencidas se actualizan automáticamente y de forma persistida a `Vencida` en la BD.

---

## 2. Problema de negocio

Hoy el detalle del cliente concentra información comercial, actividades, cotizaciones y documentos, pero no muestra de forma estructurada si el cliente tiene facturas pendientes, vencidas, en proceso, pagadas o con nota de crédito. Esto obliga al equipo a consultar información fuera del CRM, mantener controles manuales o explicar el estado financiero del cliente con datos incompletos.

La brecha genera:

- **Riesgo de seguimiento tardío**: una factura impaga puede vencer sin que el equipo comercial u operativo la vea en el calendario del CRM.
- **Fricción en atención al cliente**: antes de contactar al cliente, el usuario no puede confirmar rápidamente si existen facturas pendientes o vencidas desde la misma vista de relación comercial.
- **Datos dispersos**: la modalidad de pago y el estado de facturas quedan fuera del flujo normal del CRM, aunque impactan actividades, cobranzas, renovaciones y conversaciones comerciales.
- **Soporte operativo ad-hoc**: sin catálogo común de estados, cada usuario puede nombrar o interpretar el estado de pago de forma distinta.

---

## 3. Usuarios objetivo y situaciones

| Persona | Necesita | Momento de uso |
|---|---|---|
| **Comercial / ejecutivo de cuenta** | Ver si el cliente tiene facturas pendientes o vencidas antes de llamar, cotizar o hacer seguimiento. | Entra al detalle del cliente y revisa la tarjeta **Pagos** junto al historial comercial y actividades. |
| **Administración / cobranzas** | Registrar manualmente facturas, vencimientos, importe total y estado. | Recibe o emite una factura y la carga en el CRM para que el equipo tenga visibilidad. |
| **Supervisor / gerencia** | Entender rápidamente exposición de cobro por cliente y próximos vencimientos. | Revisa clientes críticos o el Calendario global con vencimientos impagos. |
| **Usuario operativo del Calendario** | Recibir señales de facturas impagas en la fecha de vencimiento. | Consulta Calendario y ve alertas vinculadas al cliente/factura. |

---

## 4. Resultado esperado de producto

Después del cambio, el detalle del cliente debe permitir:

1. Ver una tarjeta **Pagos** clara y consistente con el resto de tarjetas del cliente.
2. Consultar y registrar facturas manuales asociadas al cliente.
3. Guardar por factura al menos: identificador/número visible, fecha de vencimiento, importe total, estado de factura y notas mínimas si aplica.
4. Ver la modalidad de pago como atributo del cliente, no como campo repetido por factura.
5. Usar estados de factura definidos en **Catálogos**, con valores iniciales: `Pagado`, `Vencida`, `En proceso`, `Nota de crédito`.
6. Crear alertas en el **Calendario** global en la fecha de vencimiento de cada factura cobrable activa cuyo estado no sea `Pagado` ni `Nota de crédito`.
7. Marcar una factura como pagada cambiando únicamente el estado en esta primera versión, sin exigir fecha de pago, referencia, comprobante ni conciliación.

---

## 5. Scope del primer slice

Incluye:

1. **Nueva tarjeta Pagos en detalle de cliente**: visible en `/customers/{id}` cerca de las tarjetas existentes del perfil del cliente.
2. **Modalidad de pago a nivel cliente**: campo o presentación dentro de la tarjeta para indicar la modalidad vigente del cliente. La modalidad no se modela por factura en v1.
3. **Listado de facturas del cliente**: tabla/resumen dentro de la tarjeta con estado, vencimiento e importe total.
4. **Alta/edición manual de factura**: flujo simple para registrar o corregir facturas asociadas al cliente.
5. **Estados desde Catálogos**: la fuente de verdad para estados de factura es el módulo de Catálogos. La propuesta requiere sembrar/configurar inicialmente `Pagado`, `Vencida`, `En proceso`, `Nota de crédito`.
6. **Alertas de calendario**: cada factura cobrable activa con fecha de vencimiento debe aparecer en el Calendario global en esa fecha, excepto si está `Pagado`, `Nota de crédito` o retirada/anulada.
7. **Cambio de estado a Pagado**: en v1, marcar como pagada sólo cambia el estado de factura; no captura metadatos de pago.
8. **Importe total únicamente**: no se desglosan impuestos, subtotales, retenciones, descuentos, moneda múltiple ni líneas de factura en el primer slice.

---

## 6. Fuera de scope / no-goals

No se incluye en esta primera versión:

- Emisión automática de facturas desde cotizaciones, ventas o contratos.
- Integración contable, AFIP/SAT, pasarelas de pago, bancos, conciliación bancaria o importación masiva.
- Pagos parciales, múltiples pagos por factura, saldo restante, fecha de pago, referencia de pago o comprobante adjunto.
- Líneas de factura, impuestos, descuentos, retenciones o cálculo financiero detallado.
- Definir en proposal el mecanismo técnico exacto para ejecutar la persistencia automática de `Vencida`; design decidirá el mecanismo concreto, pero la regla de producto exige que `status_id` quede persistido como `Vencida` en la BD para facturas elegibles.
- Notificaciones por email/WhatsApp al cliente por vencimiento.
- Reportes financieros agregados o dashboard de cobranzas.
- Historial financiero completo si excede la tarjeta de detalle de cliente.
- Edición del catálogo de estados desde la tarjeta; eso permanece en Catálogos.

---

## 7. Áreas impactadas

| Área | Impacto esperado |
|---|---|
| **Detalle de cliente** | Nueva tarjeta **Pagos** con modalidad de pago y facturas. |
| **Modelo de cliente** | Persistencia/visualización de modalidad de pago a nivel cliente. |
| **Facturas de cliente** | Nueva entidad o almacenamiento para facturas manuales asociadas a cliente. |
| **Catálogos** | Nuevo catálogo/tipo de estados de factura o reutilización de estructura existente con valores iniciales definidos. |
| **Calendario** | Creación/visualización de alertas por vencimiento de facturas impagas. |
| **Permisos** | Reglas de lectura/escritura para ver y modificar pagos/facturas según roles existentes o nuevos permisos. |
| **Auditoría** | Cambios en facturas y estado de pago deberían quedar trazables si el proyecto ya audita cambios relevantes. |

---

## 8. Reglas de negocio

1. **La factura pertenece a un cliente**: toda factura registrada debe estar asociada a exactamente un cliente.
2. **La modalidad de pago pertenece al cliente**: el valor se consulta desde el cliente y aplica como contexto general; no bloquea que futuras versiones permitan excepciones por factura.
3. **Estados controlados por Catálogos**: no se aceptan estados libres escritos manualmente fuera del catálogo correspondiente.
4. **Valores iniciales requeridos**: `Pagado`, `Vencida`, `En proceso`, `Nota de crédito` deben existir al liberar la funcionalidad.
5. **Factura impaga/cobrable**: para el primer slice, una factura activa cuyo estado no sea `Pagado` ni `Nota de crédito` se considera cobrable y candidata a alerta de vencimiento.
6. **`Vencida` automática persistida**: cuando una factura cobrable activa supera su fecha de vencimiento, su estado actual no es `Pagado` ni `Nota de crédito`, y no está retirada/anulada, el sistema debe actualizar automáticamente su `status_id` persistido a `Vencida` en la BD. El mecanismo técnico exacto queda para design.
7. **Alerta por vencimiento**: se crea/expone en Calendario global el día de vencimiento de cada factura cobrable activa; al pasar el vencimiento, el comportamiento de alerta debe reflejar el estado `Vencida` persistido automáticamente.
8. **Marcar pagada**: sólo cambia estado a `Pagado`; no requiere fecha, referencia ni comprobante, y nunca debe auto-transicionar a `Vencida`.
9. **Importe total**: el monto capturado representa el total de la factura y no debe inferirse desde cotizaciones o documentos en v1.

---

## 9. Comportamiento de Calendario

- Las alertas deben aparecer en el módulo global **Calendario**, no únicamente dentro del cliente.
- Cada alerta debe vincularse claramente con el cliente y la factura para que el usuario pueda volver al detalle correspondiente.
- La fecha de la alerta es la fecha de vencimiento de la factura.
- Si la factura cambia a `Pagado` o `Nota de crédito`, la alerta pendiente debería dejar de presentarse como vencimiento activo o quedar marcada/resuelta según el patrón existente del Calendario.
- Si cambia la fecha de vencimiento, la alerta debe reflejar la nueva fecha.
- Si se elimina/anula una factura, la alerta asociada no debe quedar como tarea huérfana activa.

---

## 10. Permisos y auditoría

La propuesta no impone todavía nombres técnicos de permisos, pero el comportamiento de producto debe distinguir:

- **Lectura**: usuarios autorizados a ver el detalle del cliente deberían poder ver la tarjeta si su rol tiene acceso a información de pagos/facturas.
- **Escritura**: registrar, editar, eliminar/anular o cambiar estado de factura debe requerir permiso superior al de sólo lectura.
- **Catálogos**: administrar estados sigue bajo permisos del módulo Catálogos, no bajo la tarjeta Pagos.
- **Auditoría**: se recomienda auditar creación/edición/cambio de estado de factura, especialmente cambios hacia `Pagado`, `Vencida` o `Nota de crédito`; las transiciones automáticas a `Vencida` deberían quedar trazables como acción del sistema si el patrón del proyecto lo permite.
- **Datos sensibles**: aunque no se capturan referencias bancarias en v1, importes y estados de cobro pueden ser sensibles; si existen roles con visibilidad parcial del cliente, la tarjeta debe respetar esa segmentación.

---

## 11. Datos e implicaciones de persistencia

La implementación posterior deberá definir el modelo exacto, pero el PRD requiere persistir como mínimo:

- Cliente asociado.
- Número/identificador visible de factura.
- Fecha de vencimiento.
- Importe total.
- Estado desde Catálogos.
- Modalidad de pago a nivel cliente.
- Timestamps y usuario responsable cuando el patrón del proyecto lo permita.
- Relación o referencia necesaria para que Calendario vincule la alerta con la factura.

Implicaciones:

- Puede requerirse migración para facturas de cliente.
- Puede requerirse agregar campo de modalidad de pago al cliente o vincularlo a catálogo si ya existe una estructura adecuada.
- Debe evitarse duplicar estados como enum hardcodeado si Catálogos es la fuente de verdad.
- Debe definirse cómo evitar alertas duplicadas al editar y guardar la misma factura varias veces.

---

## 12. Edge cases

1. **Cliente sin facturas**: la tarjeta muestra estado vacío útil y CTA sólo si el usuario puede crear facturas.
2. **Cliente sin modalidad de pago definida**: mostrar valor vacío/pendiente sin bloquear la carga de facturas, salvo decisión futura contraria.
3. **Factura sin vencimiento**: si se permite, no debe crear alerta; si no se permite, el formulario debe validarlo.
4. **Estado `Nota de crédito`**: se considera no cobrable/no alertable en v1 y no debe auto-transicionar a `Vencida`.
5. **Factura vencida pero todavía en `En proceso`**: si está activa, es cobrable y superó su fecha de vencimiento, el sistema debe persistir automáticamente `status_id = Vencida` sin requerir acción manual.
6. **Cambio de estado a `Pagado` después del vencimiento**: la alerta debe resolverse/ocultarse como pendiente, manteniendo trazabilidad si existe auditoría, y la factura no debe volver automáticamente a `Vencida`.
7. **Catálogo incompleto**: si faltan los estados iniciales, la UI no debe permitir estados libres inconsistentes; debe orientar al admin a Catálogos.
8. **Permiso de sólo lectura**: el usuario puede ver la información permitida, pero no editar modalidad ni facturas.
9. **Importe cero o negativo**: requiere validación explícita en spec; por defecto, el primer slice debería aceptar sólo importes positivos, salvo nota de crédito si se modela distinto.
10. **Eliminación de cliente**: las facturas y alertas asociadas deben seguir la política existente del CRM para datos dependientes del cliente.

---

## 13. Riesgos y tradeoffs

- **Mecanismo de `Vencida` automática persistida**: la regla de producto exige persistir `status_id = Vencida` en BD para facturas elegibles; design debe elegir un mecanismo confiable e idempotente que no duplique alertas ni oculte escrituras en GETs puros.
- **Sobrecarga del Calendario**: si se crean muchas facturas, el calendario puede llenarse de alertas de cobranza. La primera versión debe mostrar suficiente contexto para que no se confundan con actividades comerciales.
- **Permisos demasiado abiertos**: exponer importes a todos los usuarios que ven clientes puede ser sensible.
- **Acoplamiento con Catálogos**: si Catálogos no soporta tipos/valores necesarios, el slice puede necesitar trabajo previo de catalogación.
- **Expectativa de sistema financiero**: al mostrar “Pagos”, los usuarios podrían esperar conciliación, pagos parciales o comprobantes. El copy y límites de v1 deben ser claros.
- **Duplicación de alertas**: sin regla de idempotencia por factura/vencimiento, editar facturas podría crear eventos repetidos en Calendario.

---

## 14. Rollback

Si el cambio debe revertirse:

1. Ocultar/remover la tarjeta **Pagos** del detalle de cliente.
2. Deshabilitar rutas/acciones de alta o edición de facturas si se introducen en la implementación posterior.
3. Detener la creación/visualización de alertas de calendario ligadas a facturas.
4. Mantener los datos migrados en base si ya existen facturas, salvo que una migración de rollback segura sea explícitamente aprobada.
5. Los valores agregados a Catálogos pueden permanecer si no rompen otros flujos; eliminarlos sólo si no fueron usados.

El rollback funcional debe poder volver a la experiencia anterior del cliente sin afectar las tarjetas existentes: Datos del cliente, Contactos, Historial comercial, Actividades, Cotizaciones y Documentos.

---

## 15. Criterios de éxito

- **SC-1**: En `/customers/{id}` aparece la tarjeta **Pagos** sin degradar las tarjetas existentes.
- **SC-2**: Un usuario autorizado puede registrar manualmente una factura con vencimiento, importe total y estado de catálogo.
- **SC-3**: La modalidad de pago se guarda/visualiza a nivel cliente.
- **SC-4**: Los estados disponibles provienen de Catálogos e incluyen `Pagado`, `Vencida`, `En proceso`, `Nota de crédito`.
- **SC-5**: Una factura cobrable activa con vencimiento aparece en el Calendario global en la fecha correspondiente y, al quedar vencida, persiste automáticamente su estado como `Vencida` en la BD.
- **SC-6**: Al cambiar una factura a `Pagado` o `Nota de crédito`, deja de tratarse como alerta activa de impago y no auto-transiciona a `Vencida`.
- **SC-7**: Usuarios sin permiso de escritura no pueden crear/editar facturas ni cambiar estados.
- **SC-8**: Cliente sin facturas muestra un empty state claro.
- **SC-9**: No se implementan pagos parciales, conciliación, referencias de pago ni emisión automática en v1.

---

## 16. Proposal question round / supuestos para revisión

Como la fase corre en modo interactivo y ya existen decisiones de producto tomadas, esta propuesta no bloquea el avance, pero deja una ronda mínima de preguntas para validar antes de spec/design:

1. **Modalidad de pago**: ¿debe ser texto libre del cliente o también debe venir de Catálogos?
2. **Visibilidad**: ¿todos los usuarios que ven el cliente pueden ver importes, o la tarjeta Pagos requiere permisos específicos de lectura?
3. **Eliminación vs anulación**: ¿una factura cargada por error se puede eliminar físicamente, o sólo anular/cambiar estado para conservar trazabilidad?

**Decisiones confirmadas en este proposal**: `Vencida` es automática y persistida para facturas cobrables activas con fecha de vencimiento pasada; `Pagado` y `Nota de crédito` son estados no alertables y no deben auto-transicionar a `Vencida`; facturas retiradas/anuladas tampoco auto-transicionan; el mecanismo técnico de esa automaticidad persistida se definirá en design.

**Supuestos usados en este proposal**: los importes son sensibles pero visibles a roles autorizados; la modalidad de pago vive en cliente y puede implementarse sin pagos parciales; el calendario debe evitar alertas duplicadas por la misma factura.

---

## 17. Preguntas abiertas

- Confirmar si modalidad de pago es catálogo o campo simple.
- Confirmar matriz de permisos para lectura/escritura de información financiera.
- Confirmar política de eliminación/anulación y auditoría obligatoria.

---

## Quick cross-reference

- **Change**: `openspec/changes/customer-payments-invoices/`.
- **Artifact**: `openspec/changes/customer-payments-invoices/proposal.md`.
- **Pantalla objetivo**: `/customers/{id}` detalle de cliente.
- **Módulos relacionados**: Clientes, Catálogos, Calendario, permisos/auditoría.
