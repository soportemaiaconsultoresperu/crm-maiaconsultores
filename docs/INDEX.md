# Índice de documentación

Tabla de contenidos de la documentación del proyecto CRM Maia Consultores. Cada archivo tiene un único tema y se referencia desde el [README](../README.md) y desde [AVANCE.md](AVANCE.md).

| Documento | Tema | Una línea |
|---|---|---|
| [ARQUITECTURA.md](ARQUITECTURA.md) | Arquitectura | Stack, capas, autorización, auditoría, scheduler y entornos del monolito Laravel. |
| [BASE_DATOS.md](BASE_DATOS.md) | Base de datos | Modelo relacional, 23 migraciones, estrategia de correlativos y de borrado lógico. |
| [REQUISITOS.md](REQUISITOS.md) | Requisitos | Matriz de RF y RNF con ID estable, estado y prueba asociada (verde = implementado). |
| [DECISIONES.md](DECISIONES.md) | Decisiones | ADRs (ADR-001 .. ADR-016) — las decisiones técnicas fundacionales del proyecto. |
| [SEGURIDAD.md](SEGURIDAD.md) | Seguridad | Autenticación, autorización, protecciones web, hallazgos corregidos en B04/B08. |
| [DESPLIEGUE_PRODUCCION_UBUNTU.md](DESPLIEGUE_PRODUCCION_UBUNTU.md) | Despliegue de producción | Guía segura y operativa para Ubuntu 24.04 con Docker, Caddy, TLS, copias de seguridad y actualizaciones. |
| [PRUEBAS.md](PRUEBAS.md) | Pruebas | Estado de la suite, archivos por módulo, reglas y criterio de "módulo terminado". |
| [AVANCE.md](AVANCE.md) | Avance | Bitácora por bloque (B00 .. B09) con evidencia real (comandos, números, hallazgos). |

## Cómo está organizado

- **Empezar por**: [README.md](../README.md) → [ARQUITECTURA.md](ARQUITECTURA.md) → [BASE_DATOS.md](BASE_DATOS.md).
- **Trazar un requisito**: buscalo en [REQUISITOS.md](REQUISITOS.md) → encontrar el ADR relacionado en [DECISIONES.md](DECISIONES.md) → ver cuándo se implementó en [AVANCE.md](AVANCE.md) → ver su prueba en [PRUEBAS.md](PRUEBAS.md).
- **Desplegar en producción**: seguí [DESPLIEGUE_PRODUCCION_UBUNTU.md](DESPLIEGUE_PRODUCCION_UBUNTU.md) para Ubuntu 24.04, Docker, Caddy, TLS y operación básica.
- **Auditoría de seguridad**: [SEGURIDAD.md](SEGURIDAD.md) lista los hallazgos detectados y corregidos, con el test de regresión que los protege.

## Estado del proyecto (resumen)

| Bloque | Estado | Detalle |
|---|---|---|
| B00 — Análisis y decisiones | ✅ | [AVANCE.md § B00](AVANCE.md) |
| B01 — Base técnica | ✅ | [AVANCE.md § B01](AVANCE.md) |
| B02 — Prospectos | ✅ | [AVANCE.md § B02](AVANCE.md) |
| B03 — Clientes y contactos | ✅ | [AVANCE.md § B03](AVANCE.md) |
| B04 — Oportunidades y embudo | ✅ | [AVANCE.md § B04](AVANCE.md) |
| B05 — Actividades y calendario | ✅ | [AVANCE.md § B05](AVANCE.md) |
| B06 — Productos y cotizaciones | ✅ | [AVANCE.md § B06](AVANCE.md) |
| B07 — Dashboard y reportes | ✅ | [AVANCE.md § B07](AVANCE.md) |
| B08 — Administración y configuración | ✅ | [AVANCE.md § B08](AVANCE.md) |
| B09 — Seguridad y estabilización | ✅ | [AVANCE.md § B09](AVANCE.md) |
| B12-UI — Editor de reglas del motor de automatizaciones | ✅ | [AVANCE.md § B12-UI](AVANCE.md) |

Suite de pruebas al cierre: ver "Estado del proyecto" abajo; baseline `php artisan test` cierra en verde con 0 failed.
