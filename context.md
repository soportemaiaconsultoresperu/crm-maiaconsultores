# Code Context

## Files Retrieved

1. `resources/views/admin/automations/index.blade.php` (1-164) — listado, estados, acciones y terminología visible.
2. `resources/views/admin/automations/create.blade.php` (1-17) — introducción al formulario.
3. `resources/views/livewire/admin/automations/rule-form.blade.php` (1-145) — formulario principal de reglas.
4. `resources/views/livewire/admin/automations/condition-group-editor.blade.php` (1-69) — editor de condiciones y operadores.
5. `resources/views/livewire/admin/automations/action-editor.blade.php` (1-20) — host de widgets por tipo de acción.
6. `resources/views/admin/automations/show.blade.php` (1-160) — resumen de regla, acciones y simulación/historial.
7. `app/Livewire/Admin/Automations/RuleForm.php` (1-220) — estado, defaults y operaciones add/remove/reorder.
8. `tests/Feature/Admin/Automations/AdminAutomationUsabilityTest.php` (1-150) — contratos de copy/acciones actualmente testeados.

## Key Code

- El flujo funcional ya es: evento (`trigger_event`) → grupos de condiciones (`groups`, operador AND/OR) → acciones (`actions`, widgets tipados); el formulario POST/PUT estándar persiste la estructura y Livewire sólo mantiene estado inline (`RuleForm.php:20-105`).
- El formulario todavía expone lenguaje técnico: `Trigger`, `Live/Test`, nombres FQCN (`class_basename($fqcn)`), `Campo`, `Operador`, `Tipo`, `Valor`, `Recipient strategy`, y tipos raw (`add_tag`, `send_email`, etc.) (`rule-form.blade.php:31-125`, `condition-group-editor.blade.php:15-62`).
- Existen avisos de implementación (“pendiente B14”, “no la consideres lista para ejecución real”) directamente al usuario final (`rule-form.blade.php:113-117`; también `show.blade.php` en el bloque de acciones).
- La pantalla de índice mezcla “Activas”, “Papelera”, “Modo”, “Ejecuciones” y acciones CRUD; “Ver historial” es el CTA principal de cada regla (`index.blade.php:21-145`).
- Los tests fijan sólo copy estructural/CRUD y nombres HTML, no etiquetas UX; cualquier cambio de texto debe preservar esos nombres/acciones (`AdminAutomationUsabilityTest.php:20-110`).

## Architecture

`admin/automations/create|edit` embeben `livewire:admin.automations.rule-form`; RuleForm repite grupos y acciones, delegando condiciones a `ConditionGroupEditor` y payloads a `ActionEditor`, que monta widgets en `app/Livewire/Admin/Automations/ActionWidgets`. Index/show son vistas Blade server-rendered para administración, historial y simulación. La mejora puede ser casi enteramente de presentación/copy, sin cambiar contratos Livewire, names ni rutas.

## Smallest safe edit plan

1. **Primero `resources/views/livewire/admin/automations/rule-form.blade.php`**: reordenar visualmente y agregar una guía visible con el patrón “**CUANDO** ocurre / **SI** cumple / **ENTONCES** hacer”. Cambiar `Trigger`→`CUANDO ocurre`, `Modo`→`Entorno de prueba`, `Live`→`Producción`, `Test`→`Prueba`, `Condiciones`→`SI cumple`, `Acciones`→`ENTONCES hacer`; mantener los `name=`, wire-models y valores internos intactos. Explicar “Orden” como “Prioridad (si varias reglas coinciden)” y “Activa” como “Regla habilitada”.
2. **`condition-group-editor.blade.php`**: reemplazar AND/OR por “Todas (Y)” / “Al menos una (O)” con ayuda corta; `Campo`→“Qué dato revisar”, `Operador`→“Cómo compararlo”, `Tipo`→“Tipo de dato”, `Valor`→“Valor a buscar”. Reemplazar valores técnicos visibles de operadores/value types por labels amigables mediante mapa de presentación (sin alterar value enviado).
3. **Action UI (`rule-form.blade.php` + widgets en `resources/views/livewire/admin/automations/widgets/**`)**: mostrar tipo con labels orientados a resultado (“Agregar etiqueta”, “Enviar correo”, “Cambiar etapa”, etc.) y reemplazar `Recipient strategy` por “A quién”; agregar ayuda contextual “Qué pasa después”. Mantener type slugs y payload field names; ocultar notas internas B14 y usar “Disponible próximamente”/deshabilitado sólo donde realmente corresponda.
4. **`resources/views/admin/automations/create.blade.php` y `edit.blade.php`**: sustituir la descripción técnica por una frase de onboarding que repita la receta y explique que primero se prueba y luego se activa.
5. **`resources/views/admin/automations/index.blade.php` y `show.blade.php`**: traducir “Trigger”→“Se inicia cuando”, “Modo”→“Entorno”, “Ejecuciones”→“Veces ejecutada”, “Simulación disponible”→“Podés probarla sin afectar datos”; en show, presentar resumen en receta (CUANDO/SI/ENTONCES) antes del historial.
6. Tests: conservar assertions existentes; sumar sólo pruebas de copy accesible en `AdminAutomationUsabilityTest.php` y tests Livewire correspondientes si se agrega la guía/labels. No tocar clases PHP, rutas ni persistencia salvo que el mapa de labels requiera un Presenter dedicado.

## UX copy recommendation (recipe)

- **CUANDO ocurre:** “Elegí qué evento inicia esta automatización (por ejemplo, cuando se crea un contacto).”
- **SI cumple:** “Agregá condiciones para decidir cuándo aplica. ‘Todas (Y)’ exige que se cumplan todas; ‘Al menos una (O)’ permite cualquiera.”
- **ENTONCES hacer:** “Elegí una o más acciones. Se ejecutarán en el orden mostrado.”
- Empty state: “Sin condiciones todavía. Agregá al menos una para definir cuándo aplica.”
- Save CTA: “Guardar automatización”; test CTA: “Probar con un ejemplo”; activation help: “Las reglas en Producción se ejecutan automáticamente; usá Prueba para verificar.”

## Risks / constraints

- No modificar slugs internos, FQCN, `name` attributes, wire bindings ni payload keys: tests y controller dependen de ellos.
- No presentar `webhook`/WhatsApp como producción si siguen bloqueados; usar explicación no técnica y estado claro.
- La recomendación es read-only mapping; no se hicieron ediciones ni se ejecutaron tests.

## Start Here

Abrir `resources/views/livewire/admin/automations/rule-form.blade.php`: concentra la mayor parte de la fricción terminológica y conecta directamente las tres etapas de la receta.
