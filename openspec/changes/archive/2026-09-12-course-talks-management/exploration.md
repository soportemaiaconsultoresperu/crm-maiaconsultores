# Exploration: Courses and Talks Management

## Status

Exploration only. This artifact does not approve a proposal, specification, design, technology choice, dependency, or implementation.

## Original request interpreted as problem areas

The request appears to combine five related business capabilities:

1. A shared catalog and delivery record for courses and talks.
2. Enrollment or participation records for each person.
3. Class-level grades and a final participant result.
4. Certificate generation, qualification, delivery, and follow-up.
5. Pricing, optional invoice surcharge, invoice-document delivery, and pending alerts.

This interpretation is provisional until the client answers the discovery questions below.

## Confirmed from the client request

- Courses and talks must belong to the same business module.
- A course needs at least: name, course code, academic hours, modality, delivery date, syllabus, days, and price.
- A talk needs at least: name, code, academic hours, modality, delivery date, syllabus, whether it includes a certificate, and certificate cost.
- When an invoice is requested, the stated price is increased by 18%; otherwise the uploaded/base price is maintained.
- An uploaded invoice must be deliverable by email or WhatsApp.
- A participant with a final result of at least 12.5 is described as having passed; below that threshold, the requested outcome is participation only.
- Certificate information must include an issue date per participant.
- Grades must be recorded per class for each student and used to obtain an average.
- The system must indicate whether a certificate was sent.
- The system must surface pending certificate and pending invoice-delivery cases.
- Certificate delivery should support email and/or a WhatsApp-assisted flow.
- The last certificate-send date must be visible.
- Certificates must contain a QR code.
- Invoice status must distinguish sent from pending.

## Confirmed from repository evidence

These are existing capabilities that may be reusable later; no reuse decision is made in exploration:

- Customer invoices already have an invoice number, due date, total amount, status, notes, retirement fields, and audit fields (`database/migrations/2026_08_21_000001_create_customer_payment_invoice_foundation.php`).
- Private document metadata storage already exists for files attached polymorphically to business entities (`database/migrations/2026_08_17_174130_create_documents_table.php`).
- Email has an outbound queued-message pipeline and recipient persistence (`app/Services/Email/EmailService.php`).
- WhatsApp has conversations, pre-approved template messages, asynchronous dispatch, idempotency, and delivery states (`app/Services/WhatsApp/WhatsAppService.php`).
- Generic outbound delivery tracking exists with channel, recipient, status, attempts, retry time, errors, and related-entity reference (`database/migrations/2026_08_18_040010_create_outbound_deliveries_table.php`).
- No clear existing first-class Course, Talk, Enrollment, Grade, or Certificate domain entity was found in the indexed application files. This is evidence of a current gap, not proof that no related behavior exists elsewhere.

## Assumptions requiring validation

- “Same module” means one menu/workspace with shared behavior, not one undifferentiated record type.
- A course or talk may be delivered more than once; the catalog definition and each scheduled edition may therefore be different concepts.
- “Date when delivered” may represent one edition with one or multiple class dates.
- “Days” probably means the class schedule rather than a free-text duration.
- Participants may already exist as customers or contacts, but this has not been confirmed.
- The 12.5 threshold applies to courses; it is unclear whether talks are graded.
- “Participated” probably means a participation certificate rather than a failed-status certificate.
- The 18% increase is intended to represent IGV and applies once to the relevant charge.
- The uploaded invoice is an external document, while invoice status and delivery tracking are managed by this system.
- “Send through WhatsApp” may mean opening the WhatsApp chat with a prepared message/link, not necessarily sending the PDF automatically.
- The QR should let a third party verify certificate authenticity, but the destination and exposed data are unknown.
- “Last sent date” may coexist with a complete delivery history rather than replacing it.

## Missing decisions and client interview questions

### 1. Module and business object

1. Cuando decís que cursos y charlas deben ser “un mismo módulo”, ¿querés una sola pantalla/lista con un campo **tipo: curso o charla**, o dos secciones dentro del mismo menú?
2. ¿Un mismo curso o charla puede dictarse varias veces en fechas distintas? Si la respuesta es sí, ¿el nombre, código y temario pertenecen al curso base, mientras que fechas, precio, alumnos y notas pertenecen a cada edición?
3. ¿El código identifica al curso/charla en general o a cada edición dictada? ¿Quién lo asigna y puede repetirse?
4. ¿Qué estados necesita una edición: borrador, programada, en curso, finalizada, cancelada u otros?
5. ¿Quiénes van a usar y administrar este módulo? ¿Hay acciones que sólo ciertos roles puedan realizar?

### 2. Dates, schedule, modality, and syllabus

1. ¿Una edición puede tener varias clases o sesiones? Para cada clase, ¿qué necesitás registrar: fecha, hora de inicio, hora de fin, docente y tema?
2. En “días”, ¿querés fechas concretas, días de la semana, cantidad de días o un texto libre?
3. ¿Cuáles son las modalidades válidas: presencial, virtual, híbrida? ¿Se necesita guardar dirección o enlace de acceso?
4. ¿El temario es texto libre, un archivo adjunto o una lista de temas por clase?
5. ¿Las horas académicas se ingresan manualmente o deben calcularse desde las sesiones?

### 3. Participants and enrollment

1. ¿Quién es el participante dentro del CRM: un cliente, un contacto, ambos, o una ficha nueva de alumno?
2. ¿Qué datos mínimos necesitás del alumno para inscribirlo y emitirle documentos: nombres, documento, correo, celular u otros?
3. ¿Una inscripción puede cubrir a varias personas pagadas por una empresa, o cada alumno compra individualmente?
4. ¿Necesitás estados de inscripción o asistencia, por ejemplo inscrito, confirmado, retirado, completó o no asistió?
5. ¿La asistencia por clase debe registrarse y afecta la aprobación o el certificado?

### 4. Grades and result rules

1. ¿Los cursos y las charlas llevan notas, o solamente los cursos?
2. ¿Cuántas notas puede tener una clase y qué conceptos representan: práctica, examen, asistencia, participación u otros?
3. ¿Todas las notas tienen el mismo peso o necesitás porcentajes/ponderaciones configurables?
4. ¿La escala siempre es de 0 a 20? ¿Se permiten decimales y cómo se redondea el promedio final?
5. Para aprobar con 12.5, ¿se compara el promedio exacto o un promedio previamente redondeado? Por ejemplo, ¿12.49 desaprueba y 12.50 aprueba?
6. ¿Además de la nota mínima existen otras condiciones, como porcentaje mínimo de asistencia, pagos completos o entrega de trabajos?
7. Cuando alguien obtiene menos de 12.5, ¿recibe un certificado/constancia de participación o sólo queda registrado como participante sin certificado?

### 5. Certificates

1. ¿Curso y charla usan el mismo formato de certificado? ¿Hay formatos distintos para aprobación y participación?
2. En las charlas “con certificado”, ¿todos los participantes lo reciben o deben cumplir alguna condición?
3. ¿La fecha de emisión se carga manualmente, se genera al crear el certificado o se define en lote para toda la edición?
4. ¿Un certificado puede corregirse y volver a emitirse? Si sucede, ¿debe conservarse el anterior como anulado y guardar el motivo?
5. ¿Quién autoriza o genera los certificados y se necesita una aprobación previa al envío?
6. ¿Qué debe mostrar el QR y quién puede consultarlo: una página pública de validación, una descarga del certificado o sólo un código de verificación?
7. En la validación QR, ¿qué datos personales está permitido mostrar públicamente?
8. ¿El certificado se envía como PDF adjunto, como enlace de descarga o de ambas formas?
9. ¿Necesitás guardar sólo “enviado/no enviado” y la última fecha, o también el historial completo de intentos, canales, destinatarios, errores y reenvíos?

### 6. Price, certificate charge, and invoice

1. ¿El precio se define por curso/charla base o puede cambiar en cada edición?
2. Para las charlas, ¿el costo del certificado está incluido en el precio o se suma únicamente cuando el participante lo solicita?
3. ¿El precio y el costo del certificado se registran por participante, por inscripción o para toda la edición?
4. Confirmemos la regla del 18%: ¿es IGV y debe aplicarse sobre curso/charla más certificado, o sólo sobre el precio principal?
5. ¿El sistema debe mostrar precio base, impuesto y total por separado? ¿Qué moneda o monedas se usan?
6. ¿Puede haber descuentos, becas, pagos parciales, devoluciones o precios diferentes entre alumnos?
7. ¿“Factura” incluye únicamente factura tributaria o también boleta/recibo? ¿El sistema la genera o sólo permite subir un archivo emitido por otro sistema?
8. ¿Qué datos deben registrarse junto al archivo: serie/número, fecha de emisión, monto, empresa/RUC y observaciones?
9. ¿La factura pertenece a una inscripción, a un alumno, a una empresa pagadora o puede cubrir varias inscripciones?
10. ¿Cuándo pasa a estado “enviada”: al intentar enviarla, cuando el proveedor confirma el envío o mediante confirmación manual?

### 7. Email, WhatsApp, and alerts

1. Para certificados y facturas, ¿el usuario elige email o WhatsApp en cada envío, o ambos pueden enviarse simultáneamente?
2. En WhatsApp, ¿querés sólo abrir el chat con un mensaje preparado para que una persona adjunte/envíe el documento, o envío automático desde el sistema?
3. Si sólo se abre el chat, ¿cómo confirmará el usuario que realmente envió el archivo: botón manual “Marcar enviado” o alguna otra evidencia?
4. ¿El email y teléfono destinatarios se toman de la ficha del alumno o pueden cambiarse antes de cada envío?
5. Si el envío falla, ¿debe seguir pendiente, reintentarse automáticamente o requerir intervención manual?
6. ¿Dónde deben aparecer las alertas: tablero, listado del módulo, notificación interna, email al responsable u otro lugar?
7. ¿Desde qué momento se considera atrasado un certificado o una factura pendiente? ¿Debe existir una fecha límite configurable?
8. ¿Quién recibe y quién puede cerrar las alertas? ¿Cerrar significa enviado, descartado o ambas opciones con motivo?
9. ¿Necesitás filtros o reportes por edición, alumno, documento pendiente, canal, fecha y responsable?

### 8. Existing data and operations

1. ¿Actualmente estos datos viven en Excel, documentos u otro sistema? ¿Qué información histórica debería incorporarse en una primera carga?
2. ¿Podés compartir ejemplos reales anonimizados de un curso, una charla, una planilla de notas, ambos tipos de certificado y una factura?
3. ¿Cuál es el volumen aproximado: ediciones por mes, alumnos por edición y certificados/facturas enviados por mes?
4. ¿Qué problema operativo querés resolver primero: ordenar cursos y alumnos, calcular resultados, emitir certificados, controlar envíos o facturación?
5. ¿Qué resultado concreto haría que la primera versión sea útil para el cliente aunque todavía no tenga todas las automatizaciones?

## Plausible first-version boundary

This is a discovery hypothesis, not an approved scope.

- One shared module listing course/talk editions with an explicit type.
- Basic edition data: name, code, academic hours, modality, dates/schedule, syllabus, and base price.
- Talk-specific certificate availability and certificate charge.
- Participant enrollment using an existing CRM person record if the client confirms the mapping.
- Class/session records and simple per-class grades.
- Final average with one confirmed threshold rule and a visible passed/participated outcome.
- Certificate record per participant with issue date, status, last-send date, and generated document reference.
- QR-based certificate verification with a minimal privacy-safe public result, subject to client confirmation.
- Optional invoice request with explicit base amount, 18% increment, and final amount.
- Uploaded invoice document with pending/sent status and last-send date.
- Manual user-triggered email delivery and WhatsApp chat handoff, with explicit confirmation where delivery cannot be observed automatically.
- A focused pending-work list for certificates and invoices.

## Keep out for now

Unless the client identifies one as essential to the first useful release:

- Automatic tax-document generation or direct integration with a tax authority/accounting provider.
- Payment gateway, collections, refunds, installments, or accounting reconciliation.
- Fully automatic WhatsApp document delivery before the intended delivery model and consent rules are confirmed.
- Complex weighted-grade formulas, multiple grading schemes, or academic transcript management.
- Student self-service portal, public enrollment, online checkout, or learning-management features.
- Attendance automation, videoconference integration, classroom access, or content delivery.
- Bulk historical-data migration before source quality and ownership are assessed.
- Advanced analytics beyond operational pending lists and essential filters.
- Certificate-signature infrastructure or third-party digital-signature integration.
- Technology, package, provider, or architecture decisions.

## Main risks before proposal

- The request mixes catalog, scheduling, academic records, document generation, tax pricing, delivery, and alerting; treating it as one undivided feature would create scope and review risk.
- The meaning of “course”, “edition”, “class”, “participant”, and “customer” is not yet agreed.
- The approval rule is incomplete without scale, rounding, weighting, attendance, and talk-specific behavior.
- The 18% rule may have tax/legal implications and must be expressed by the client/accounting owner, not inferred by implementation.
- WhatsApp chat handoff cannot prove actual delivery unless the workflow includes explicit confirmation; automatic API delivery has different consent and template constraints.
- QR verification creates privacy and certificate-revocation decisions.
- Existing invoice and messaging capabilities overlap with the request, but extending them should be decided only after the business boundaries are confirmed.

## Recommended next action

Take the interview questions to the client. On return, update this exploration, resolve contradictions, and obtain explicit approval before starting the SDD proposal phase. Do not proceed to specification or design from this artifact alone.
