# Cuestionario de validación: Cursos y charlas

> **Importante:** Las respuestas incluidas en este documento son supuestos de trabajo, no decisiones confirmadas por el cliente. Deben presentarse con la pregunta: **“¿Trabajamos así o qué cambiarías?”**

## 1. Módulo y estructura

### 1. ¿Un solo listado o dos secciones?

**Respuesta asumida:** Un único módulo llamado **Cursos y charlas**, con un campo `Tipo` para distinguirlos y filtros separados.

### 2. ¿Un curso o charla puede dictarse varias veces?

**Respuesta asumida:** Sí. Existiría una actividad base y diferentes **ediciones**. El nombre, código y temario pertenecerían a la actividad; las fechas, alumnos, precio y notas pertenecerían a cada edición.

### 3. ¿Qué identifica el código?

**Respuesta asumida:** El código identifica al curso o charla base, se asigna manualmente y no puede repetirse.

### 4. ¿Qué estados necesita una edición?

**Respuesta asumida:**

- Borrador.
- Programada.
- En curso.
- Finalizada.
- Cancelada.

### 5. ¿Quién administra el módulo?

**Respuesta asumida:** Administradores y personal autorizado. Los permisos para editar, calificar, emitir certificados y registrar facturas deberían estar separados.

---

## 2. Fechas, horarios y temario

### 6. ¿Puede haber varias clases o sesiones?

**Respuesta asumida:** Sí. Cada edición puede tener varias sesiones con fecha, horario, docente y tema.

### 7. ¿Qué significa “días”?

**Respuesta asumida:** Son las fechas concretas de las sesiones, no solamente texto libre o una cantidad de días.

### 8. ¿Qué modalidades existen?

**Respuesta asumida:**

- Presencial, con dirección.
- Virtual, con enlace de acceso.
- Híbrida, con dirección y enlace.

### 9. ¿Cómo se registra el temario?

**Respuesta asumida:** Como una lista ordenada de temas, permitiendo además adjuntar un documento.

### 10. ¿Cómo se determinan las horas académicas?

**Respuesta asumida:** Se ingresan manualmente como valor oficial. El sistema podría advertir si no coinciden con la duración de las sesiones.

---

## 3. Participantes e inscripciones

### 11. ¿Quién es el alumno dentro del CRM?

**Respuesta asumida:** Es un contacto o cliente existente. Si todavía no existe, se crea una ficha mínima de contacto.

### 12. ¿Qué datos son obligatorios?

**Respuesta asumida:**

- Nombres.
- Apellidos.
- Tipo y número de documento.
- Correo electrónico.
- Celular con código de país.

### 13. ¿Una empresa puede inscribir a varias personas?

**Respuesta asumida:** Sí. La empresa puede pagar una inscripción grupal, pero cada participante mantiene su propio registro académico y certificado.

### 14. ¿Qué estados tiene una inscripción?

**Respuesta asumida:**

- Inscrito.
- Confirmado.
- En curso.
- Completó.
- Retirado.
- No asistió.

### 15. ¿Se registra asistencia?

**Respuesta asumida:** Sí, por sesión. En la primera versión sería informativa y no afectaría automáticamente la aprobación hasta que el cliente defina un porcentaje mínimo.

---

## 4. Notas y resultado final

### 16. ¿Cursos y charlas llevan notas?

**Respuesta asumida:** Solamente los cursos. Las charlas registrarían participación o asistencia.

### 17. ¿Cuántas notas hay por clase?

**Respuesta asumida:** Una nota principal por clase, acompañada por una descripción, por ejemplo: `Evaluación de la clase 1`.

### 18. ¿Las notas tienen diferente peso?

**Respuesta asumida:** No en la primera versión. Todas las notas tendrían el mismo peso.

### 19. ¿Cuál es la escala de calificación?

**Respuesta asumida:** De 0 a 20, admitiendo hasta dos decimales.

### 20. ¿Cómo se aplica la nota mínima de 12.5?

**Respuesta asumida:** Se compara el promedio exacto:

- `12.50`: aprobado.
- `12.49`: no aprobado

**Corrección confirmada por el cliente:** el promedio debe redondearse al entero más cercano para determinar el resultado:

- `12.49` → `12`: participación.
- `12.50` → `13`: aprobado.
- `12.60` → `13`: aprobado.

La regla técnica es redondeo convencional con medio hacia arriba (`12.50` sube a `13`). El promedio original y el resultado redondeado deben conservarse.

El resultado se muestra con dos decimales.

### 21. ¿Hay otras condiciones para aprobar?

**Respuesta asumida:** Inicialmente, sólo alcanzar un promedio mínimo de 12.5. La asistencia y el pago aparecerían como datos independientes.

### 22. ¿Qué recibe alguien con menos de 12.5?

**Respuesta asumida:** Una constancia o certificado de participación, no un certificado de aprobación.

---

## 5. Certificados

### 23. ¿Cursos y charlas usan el mismo formato?

**Respuesta asumida:** Se utiliza una plantilla común con variantes según la actividad y el resultado:

- Certificado de aprobación.
- Constancia de participación.
- Certificado de charla.

### 24. ¿Quién recibe certificado en una charla?

**Respuesta asumida:** Todos los participantes de una charla marcada como `Con certificado`, siempre que se confirme su participación.

### 25. ¿Cómo se define la fecha de emisión?

**Respuesta asumida:** Se registra automáticamente al generar el certificado, permitiendo una corrección manual autorizada.

### 26. ¿Puede corregirse un certificado?

**Respuesta asumida:** Sí. El certificado anterior queda anulado y se conserva el motivo de la corrección.

### 27. ¿Quién genera o autoriza los certificados?

**Respuesta confirmada:** Administradores y usuarios con permiso específico podrán supervisar, regenerar o anular certificados. La generación inicial será automática cuando se cumplan todas las condiciones definidas: monto total pagado, resultado académico o participación válida, datos obligatorios completos y demás validaciones de la edición. El sistema debe seleccionar el tipo de documento correspondiente: aprobación, participación o charla.

### 28. ¿Qué hace el código QR?

**Respuesta asumida:** Abre una página pública que permite comprobar si el certificado es auténtico y se encuentra vigente.

### 29. ¿Qué información muestra públicamente el QR?

**Respuesta asumida:**

- Nombre del participante.
- Curso o charla.
- Tipo de certificado.
- Fecha de emisión.
- Código del certificado.
- Estado: vigente o anulado.

**Corrección confirmada por el cliente:** el QR es único por certificado y abrirá un enlace seguro y revocable que mostrará únicamente el PDF generado. No expondrá datos adicionales en la página pública.

No mostraría documento de identidad, correo, teléfono ni calificaciones.

### 30. ¿Cómo se entrega el certificado?

**Respuesta confirmada:** Como PDF generado con una plantilla configurable por administradores, tomando como diseño base el archivo de referencia e incluyendo certificado principal, datos del participante, curso, modalidad, fechas, horas, firmas, código y sección de temario. Para correo se envía como archivo adjunto. Para WhatsApp se utilizará un enlace seguro o un archivo adjunto si el canal lo permite.

El nombre seguirá este patrón:

`Certificado_{participante}_{curso}_{rango-fechas}_{empresa}.pdf`

Ejemplo: `Certificado_Alvaro Segundo Alama Silva_Curso Avanzado de Saneamiento Ambiental_01-04.07.26_Maia Consultores.pdf`.

### 31. ¿Se guarda un historial de envíos?

**Respuesta asumida:** Sí. Se registra:

- Fecha y hora.
- Canal utilizado.
- Destinatario.
- Usuario responsable.
- Resultado del envío.
- Error, si ocurrió.
- Reenvíos.
- Última fecha de envío.

---

## 6. Precio, certificado y comprobantes

### 32. ¿Dónde se define el precio?

**Respuesta asumida:** La actividad tiene un precio referencial, pero cada edición conserva su propio precio.

### 33. ¿El certificado de una charla se cobra aparte?

**Respuesta asumida:** Sí. El costo se suma únicamente cuando el participante solicita certificado.

### 34. ¿El precio se maneja por persona o por edición?

**Respuesta asumida:** Se define por edición, pero el importe final queda registrado por inscripción para permitir excepciones o descuentos futuros.

### 35. ¿Sobre qué se aplica el 18%?

**Respuesta asumida:** El 18% representa el IGV y se aplica al total de los conceptos cobrados: actividad más certificado, cuando corresponda.

> Este supuesto debe ser validado por el cliente o su responsable contable.

### 36. ¿Se muestra el impuesto por separado?

**Respuesta asumida:** Sí. Se muestran:

- Subtotal.
- IGV del 18%.
- Total.

La moneda asumida es el sol peruano (`PEN`).

### 37. ¿Se permiten descuentos o pagos parciales?

**Respuesta asumida:** Los descuentos simples podrían registrarse por inscripción. Los pagos parciales, devoluciones y becas complejas quedarían fuera de la primera versión.

### 38. ¿El sistema genera la factura o sólo la registra?

**Respuesta asumida:** No genera comprobantes tributarios. Permite registrar y subir una factura o boleta emitida por otro sistema.

### 39. ¿Qué datos se guardan junto al comprobante?

**Respuesta asumida:**

- Tipo de comprobante.
- Serie.
- Número.
- Fecha de emisión.
- Subtotal.
- Impuesto.
- Total.
- Moneda.
- Empresa o persona pagadora.
- RUC o documento.
- Archivo adjunto.
- Observaciones.

### 40. ¿A quién pertenece la factura?

**Respuesta asumida:** Pertenece a una inscripción o compra. Una factura puede cubrir a varios participantes cuando una empresa realiza una inscripción grupal.

### 41. ¿Cuándo se considera enviada?

**Respuesta asumida:**

- Por correo: cuando el sistema registra un envío exitoso.
- Por WhatsApp manual: cuando el usuario selecciona `Marcar como enviada`.

---

## 7. Correo, WhatsApp y alertas

### 42. ¿Se puede enviar por ambos canales?

**Respuesta asumida:** Sí. El usuario puede elegir correo, WhatsApp o ambos en cada envío.

### 43. ¿WhatsApp será manual o automático?

**Respuesta asumida:** En la primera versión, el sistema abre el chat de WhatsApp con un mensaje preparado y un enlace al documento. No se asume envío automático.

### 44. ¿Cómo se confirma un envío manual por WhatsApp?

**Respuesta asumida:** Después de abrir WhatsApp, el usuario debe presionar `Marcar como enviado`. Mientras no lo confirme, el documento continúa pendiente.

### 45. ¿Se pueden modificar los destinatarios?

**Respuesta asumida:** Sí. El sistema propone el correo o celular de la ficha del participante, pero el usuario puede corregirlo antes del envío. El destinatario utilizado queda registrado.

### 46. ¿Qué sucede si falla un envío?

**Respuesta asumida:** El documento continúa pendiente y se muestra el error. En la primera versión, el usuario decide cuándo volver a intentarlo.

### 47. ¿Dónde aparecen las alertas?

**Respuesta asumida:** En el tablero principal y dentro del módulo de cursos y charlas, mediante contadores y listas filtrables.

### 48. ¿Cuándo se considera atrasado un documento?

**Respuesta asumida:** Cuando supera una fecha límite configurable. Si no existe una fecha límite, aparece como pendiente pero no como vencido.

### 49. ¿Quién recibe y cierra las alertas?

**Respuesta asumida:** El responsable de la edición y los administradores. Una alerta sólo puede cerrarse al enviar el documento o descartarlo indicando un motivo.

### 50. ¿Qué filtros se necesitan?

**Respuesta asumida:**

- Tipo de actividad.
- Edición.
- Participante.
- Responsable.
- Estado.
- Canal.
- Fecha.
- Tipo de documento pendiente.

---

## 8. Datos existentes y prioridad

### 51. ¿Hay información histórica?

**Respuesta asumida:** Los datos actuales probablemente están en archivos Excel. La primera versión podría considerar una importación controlada después de revisar la calidad de esos archivos.

### 52. ¿Qué ejemplos debería entregar el cliente?

**Respuesta asumida:**

- Un curso real.
- Una charla real.
- Una planilla de alumnos y notas.
- Un certificado de aprobación.
- Una constancia de participación.
- Un certificado de charla.
- Una factura o boleta.

Los ejemplos deberían estar anonimizados cuando contengan información personal.

### 53. ¿Qué volumen de información se manejará?

**Respuesta asumida:** No se debería asumir una cifra. El cliente debe informar:

- Ediciones por mes.
- Participantes por edición.
- Certificados emitidos por mes.
- Comprobantes enviados por mes.

### 54. ¿Cuál es el principal problema operativo?

**Respuesta asumida:** Controlar desde un solo lugar a los participantes, sus resultados, los certificados y los comprobantes pendientes de envío.

### 55. ¿Qué haría útil la primera versión?

**Respuesta asumida:** Poder:

1. Registrar una edición.
2. Inscribir participantes.
3. Registrar clases y notas.
4. Calcular el resultado final.
5. Generar certificados con QR.
6. Registrar comprobantes.
7. Controlar si certificados y comprobantes fueron enviados.

---

## Primera versión asumida

La primera versión incluiría:

- Un módulo unificado de cursos y charlas.
- Actividades reutilizables y ediciones por fecha.
- Sesiones, participantes y asistencia básica.
- Notas simples para cursos.
- Promedio automático y aprobación desde 12.5.
- Certificados de aprobación o participación.
- Certificados con QR público de validación.
- Precio por edición y cargo adicional por certificado.
- Cálculo explícito del 18% cuando se solicita comprobante.
- Carga manual de factura o boleta.
- Envío de documentos por correo electrónico.
- Apertura de WhatsApp con un mensaje preparado.
- Confirmación manual del envío realizado por WhatsApp.
- Historial y última fecha de envío.
- Alertas de certificados y comprobantes pendientes.

## Fuera de la primera versión

- Generación tributaria automática de facturas o boletas.
- Integración directa con SUNAT o un proveedor contable.
- Pasarela de pagos.
- Cuotas, devoluciones o conciliación contable.
- Envío automático de documentos por WhatsApp.
- Fórmulas complejas o ponderadas de calificación.
- Portal de autoservicio para estudiantes.
- Inscripción pública y pago en línea.
- Plataforma de clases o contenidos educativos.
- Integraciones con videoconferencia.
- Migración masiva sin revisar previamente los datos históricos.
- Firma digital mediante proveedores externos.

## Decisiones críticas confirmadas por el cliente

Las siguientes decisiones quedan incorporadas al alcance funcional:

1. El promedio se redondea al entero más cercano; `12.50` redondea a `13` y aprueba.
2. Los certificados se generan automáticamente cuando pago, resultado y datos obligatorios están completos.
3. Una persona que no alcanza el resultado aprobatorio recibe constancia de participación.
4. El QR es individual, valida vigencia y permite visualizar o descargar el PDF generado.
5. El PDF debe reproducir el diseño oficial de referencia, incluida la sección de temario.
6. El nombre del archivo debe seguir el patrón definido en la pregunta 30.

## Decisiones adicionales confirmadas por el cliente

También quedan aceptados los siguientes supuestos funcionales:

1. **Actividad:** curso o charla reutilizable, con nombre, código y temario base.
2. **Edición:** ejecución concreta de una actividad, con fechas, modalidad, precio, docentes y participantes.
3. **Clase:** sesión individual de una edición, con fecha, horario, tema, docente y asistencia.
4. Las charlas no tendrán calificaciones; registrarán inscripción, confirmación y asistencia.
5. Una charla marcada como `Con certificado` generará certificado para quienes tengan participación confirmada.
6. El precio se registrará sin IGV. Cuando se solicite factura o boleta, se agregará el 18% al total de la actividad más el certificado, cuando corresponda.
7. El sistema permitirá registrar facturas y boletas, pero no generará comprobantes tributarios; se cargará el archivo emitido externamente.
8. WhatsApp será manual asistido: el sistema preparará el mensaje, abrirá el chat con un enlace seguro y el usuario confirmará `Marcar como enviado`.
9. El envío por correo registrará éxito o error automáticamente; cada canal conservará su historial y última fecha de envío.

> El alcance funcional principal ya fue validado. El siguiente paso es transformar estas decisiones en la especificación formal y luego en diseño técnico y tareas de implementación.
