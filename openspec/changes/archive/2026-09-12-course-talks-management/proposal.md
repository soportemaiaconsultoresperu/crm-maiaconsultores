# course-talks-management — Gestión de cursos, charlas, certificados y comprobantes

> **Phase**: sdd-proposal.
> **Artifact store**: OpenSpec.
> **Change**: `course-talks-management`.
> **Fuente principal**: `exploration.md` y `client-assumption-questionnaire.md`.
> **Decisiones incorporadas**: modelo actividad/edición/clase; cursos con notas y charlas con asistencia; redondeo convencional a entero con `12.50 -> 13` aprobado; generación automática de certificados sólo tras pago completo y validaciones; tipos de documento aprobación/participación/charla; QR abre únicamente el PDF seguro; plantilla PDF configurable según diseño de referencia; registro de factura/boleta y regla de IGV 18%; entrega por email y WhatsApp manual asistido; historial de entregas y alertas.

---

## 1. Resumen ejecutivo

Se construirá un módulo unificado **Cursos y charlas** para administrar actividades reutilizables, sus ediciones, clases, participantes, asistencia, notas de cursos, resultados, certificados/documentos, comprobantes registrados externamente y seguimiento de entregas pendientes.

El primer slice debe resolver el control operativo completo desde la edición hasta el documento entregado: registrar participantes, calcular resultados de cursos, confirmar participación de charlas, generar automáticamente el documento correcto cuando se cumplan pago completo y validaciones, entregar por correo o WhatsApp asistido, conservar historial de envíos y mostrar alertas de documentos pendientes o vencidos.

---

## 2. Intento y problema de negocio

Hoy la gestión de cursos, charlas, alumnos, notas, certificados, comprobantes y envíos depende de controles dispersos o manuales. Esto dificulta saber quién está inscrito, quién aprobó, quién sólo participó, qué certificado corresponde, si el comprobante fue registrado, si el pago está completo y si el documento ya fue entregado.

El cambio busca reducir errores operativos, acelerar la emisión documental y dar trazabilidad a los documentos académicos y comerciales relacionados con cada edición.

---

## 3. Usuarios objetivo

| Usuario | Necesita |
|---|---|
| Administradores | Crear actividades, configurar ediciones, supervisar certificados, anular/regenerar documentos y controlar pendientes. |
| Personal académico/operativo | Registrar clases, asistencia, notas de cursos y resultados. |
| Administración/cobranzas | Registrar pagos, facturas/boletas emitidas externamente, IGV y documentos pendientes de envío. |
| Responsables de edición | Ver alertas, participantes pendientes, certificados generados/no enviados y comprobantes pendientes. |

---

## 4. Resultado esperado de producto

Después del cambio, el sistema debe permitir:

1. Administrar un módulo unificado **Cursos y charlas** con filtros por tipo.
2. Separar **actividad** reusable, **edición** concreta y **clase/sesión** individual.
3. Registrar participantes vinculados a contactos/clientes existentes o crear ficha mínima si no existen.
4. Registrar asistencia por clase, obligatoria para charlas y disponible para cursos.
5. Registrar notas simples por clase sólo para cursos.
6. Calcular promedio exacto, promedio redondeado y resultado final.
7. Generar automáticamente documentos cuando se cumplan pago completo, resultado/participación válida, datos obligatorios y validaciones de edición.
8. Generar PDFs con plantilla configurable basada en el diseño oficial de referencia.
9. Incluir QR único por documento que abre únicamente el PDF seguro y revocable.
10. Registrar facturas/boletas emitidas externamente, con subtotal, IGV 18% cuando corresponde y total.
11. Entregar certificados y comprobantes por email o WhatsApp manual asistido.
12. Mantener historial de entregas, última fecha, errores, reenvíos y alertas de pendientes.

---

## 5. Scope del primer slice

Incluye:

1. **Módulo unificado**: listado y gestión de actividades de tipo `Curso` o `Charla`.
2. **Actividad base**: nombre, código único manual, temario base, horas académicas oficiales, datos referenciales y configuración de si una charla incluye certificado.
3. **Ediciones**: ejecución concreta con fechas, modalidad, precio, docentes, participantes, estado y configuración de entrega.
4. **Clases/sesiones**: fecha, horario, docente, tema y asistencia.
5. **Participantes e inscripciones**: datos mínimos de identificación, correo y celular; inscripción individual aunque una empresa pague de forma grupal.
6. **Cursos con notas**: una nota principal por clase, escala 0–20 con hasta dos decimales, pesos iguales en v1.
7. **Charlas con asistencia**: sin calificaciones; generan certificado de charla sólo si la charla está marcada con certificado y la participación está confirmada.
8. **Resultado académico**: promedio exacto conservado, redondeo convencional a entero para determinar aprobación y visualización del resultado con dos decimales.
9. **Documentos**: certificado de aprobación, constancia de participación y certificado de charla.
10. **Generación automática condicionada**: sólo después de pago completo, validaciones de datos, resultado académico o participación válida y validaciones propias de la edición.
11. **PDF configurable**: plantilla administrable basada en el diseño de referencia, con participante, curso/charla, modalidad, fechas, horas, firmas, código, QR y temario.
12. **QR seguro**: enlace único y revocable al PDF generado; no expone página pública con datos adicionales.
13. **Comprobantes**: registro y carga de factura/boleta emitida por sistema externo; el CRM no emite comprobantes tributarios.
14. **IGV 18%**: precio registrado sin IGV; cuando se solicita factura/boleta, se agrega 18% sobre actividad más certificado si corresponde, mostrando subtotal, impuesto y total en PEN.
15. **Entrega**: email con adjunto; WhatsApp manual asistido con mensaje preparado y enlace seguro, más confirmación manual `Marcar como enviado`.
16. **Historial y alertas**: estado enviado/pendiente, última fecha, canal, destinatario, responsable, resultado, errores, reenvíos y alertas por pendientes o vencidos.

---

## 6. Fuera de scope / no-goals

No se incluye en la primera versión:

- Generación tributaria automática de facturas o boletas.
- Integración con SUNAT, proveedor contable, bancos, pasarela de pagos o conciliación.
- Pagos parciales complejos, devoluciones, cuotas, becas complejas o conciliación contable.
- Envío automático de documentos por WhatsApp mediante API.
- Fórmulas ponderadas o múltiples esquemas de calificación.
- Portal de autoservicio del alumno, inscripción pública o pago en línea.
- Plataforma de contenidos, videoconferencia o automatización de asistencia.
- Migración masiva histórica sin revisión previa de calidad de archivos.
- Firma digital mediante proveedor externo.
- Página pública de validación con datos personales; el QR abre sólo el PDF seguro.

---

## 7. Áreas impactadas

| Área | Impacto esperado |
|---|---|
| Cursos y charlas | Nuevo módulo funcional unificado. |
| Contactos/clientes | Participantes se vinculan a fichas existentes o mínimas. |
| Documentos privados | PDFs generados, comprobantes cargados y versiones anuladas/corregidas. |
| Email | Envío de documentos como adjunto con registro de éxito/error. |
| WhatsApp | Flujo manual asistido con mensaje preparado, enlace seguro y confirmación manual. |
| Alertas/tablero | Pendientes y vencidos por certificado/comprobante. |
| Permisos | Separación de lectura, edición, calificación, emisión, anulación/regeneración y comprobantes. |
| Auditoría | Cambios relevantes en notas, resultados, generación, anulación, envíos y comprobantes. |

---

## 8. Reglas de negocio principales

1. Una **actividad** es un curso o charla reutilizable con código único.
2. Una **edición** es una ejecución concreta de una actividad y contiene fechas, modalidad, precio, docentes y participantes.
3. Una **clase** pertenece a una edición y contiene fecha, horario, tema, docente y asistencia.
4. Los cursos tienen notas; las charlas no tienen calificaciones.
5. Las notas de cursos usan escala 0–20, hasta dos decimales, y pesos iguales en v1.
6. El promedio exacto se conserva; para aprobar se redondea al entero más cercano con medio hacia arriba: `12.49 -> 12`, `12.50 -> 13`, `12.60 -> 13`.
7. Curso aprobado genera certificado de aprobación; curso no aprobado genera constancia de participación.
8. Charla con certificado genera certificado de charla para participantes con participación confirmada.
9. Ningún documento se genera automáticamente hasta que exista pago completo, datos obligatorios completos y validaciones de edición superadas.
10. El certificado anterior puede anularse al corregir/regenerar, conservando motivo y trazabilidad.
11. El QR es único, seguro y revocable; abre sólo el PDF generado.
12. El nombre del PDF sigue el patrón `Certificado_{participante}_{curso}_{rango-fechas}_{empresa}.pdf` cuando aplique.
13. El precio se registra sin IGV; al solicitar factura/boleta se suma 18% sobre actividad más certificado cuando corresponda.
14. Facturas/boletas se registran y adjuntan como documentos emitidos externamente; el sistema no las genera tributariamente.
15. Email confirma envío por resultado del sistema; WhatsApp manual queda pendiente hasta que el usuario confirme `Marcar como enviado`.

---

## 9. Riesgos y decisiones abiertas

- **Validación contable del IGV**: se confirmó la regla funcional de 18%, pero debe mantenerse visible como comportamiento configurable/validado por el responsable contable si cambian reglas tributarias.
- **Volumen e importación histórica**: no hay volumen confirmado de ediciones, participantes o certificados mensuales; la migración histórica debe tratarse como trabajo separado tras evaluar calidad de Excel u otras fuentes.
- **Permisos detallados**: está confirmado que deben separarse permisos, pero la matriz exacta de roles y nombres técnicos queda para spec/design.
- **Plazos de alerta**: un certificado o comprobante pendiente se considera vencido después de 1 día calendario; el plazo queda configurable.
- **Privacidad del PDF por QR**: el QR no muestra datos adicionales, pero el acceso seguro/revocable debe diseñarse para evitar exposición indebida del documento.

---

## 10. Rollback

Si el cambio debe revertirse:

1. Ocultar/deshabilitar el módulo **Cursos y charlas**.
2. Detener generación automática de certificados y comprobantes pendientes.
3. Detener envíos y alertas asociados al módulo.
4. Mantener datos ya creados en base si existen participantes, notas, documentos o comprobantes, salvo aprobación explícita de rollback destructivo.
5. Revocar enlaces QR/documentos generados si el módulo deja de estar disponible públicamente.
6. Preservar documentos cargados/generados como auditoría si ya fueron entregados a participantes o clientes.

---

## 11. Criterios de éxito

- **SC-1**: Se puede crear una actividad `Curso` o `Charla` con código único y temario base.
- **SC-2**: Se puede crear una edición con fechas, modalidad, precio, docentes, clases y participantes.
- **SC-3**: En cursos, las notas por clase calculan promedio exacto, promedio redondeado y resultado correcto con `12.50 -> 13` aprobado.
- **SC-4**: En charlas, la asistencia/participación confirmada habilita certificado de charla sólo si la charla incluye certificado.
- **SC-5**: El sistema genera automáticamente el tipo de documento correcto sólo después de pago completo y validaciones completas.
- **SC-6**: El PDF usa plantilla configurable basada en el diseño de referencia e incluye temario, firmas, datos principales, código y QR.
- **SC-7**: El QR abre únicamente el PDF seguro y puede revocarse/anularse.
- **SC-8**: Se pueden registrar factura/boleta emitida externamente, archivo adjunto, subtotal, IGV 18%, total y pagador.
- **SC-9**: Email registra éxito/error automáticamente; WhatsApp asistido requiere confirmación manual para dejar de estar pendiente.
- **SC-10**: El módulo muestra pendientes, vencidos, última fecha de envío e historial por certificado/comprobante.
- **SC-11**: Usuarios sin permisos adecuados no pueden calificar, emitir, anular, regenerar ni registrar comprobantes.
- **SC-12**: No se implementan generación tributaria, integración SUNAT, WhatsApp automático, portal de alumno ni pagos en línea en v1.

---

## 12. Decisiones operativas confirmadas

1. Un certificado o comprobante pendiente se considera vencido después de **1 día calendario**; el plazo será configurable.
2. Los permisos se separan para consulta, gestión, asistencia, calificaciones, emisión, anulación/regeneración, comprobantes, envíos y seguimiento.
3. El QR no expira automáticamente: permanece válido mientras el certificado esté vigente y se revoca al anularlo o reemplazarlo.
4. El enlace QR usa un token aleatorio y permite descargar el PDF sin iniciar sesión.
5. La importación histórica desde Excel queda fuera de la primera versión y se evaluará posteriormente mediante una carga controlada.

---

## Quick cross-reference

- **Change**: `openspec/changes/course-talks-management/`.
- **Artifact**: `openspec/changes/course-talks-management/proposal.md`.
- **Entradas leídas**: `exploration.md`, `client-assumption-questionnaire.md`.
- **Módulos relacionados**: Cursos/charlas, contactos/clientes, documentos, email, WhatsApp, alertas, permisos y auditoría.
