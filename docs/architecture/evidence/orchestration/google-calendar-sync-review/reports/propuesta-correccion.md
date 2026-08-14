# Propuesta consolidada — Corrección de Google Calendar

## Dictamen

El incremento nominal funciona, pero no está listo para aceptación ni release.
Arquitectura, Datos, Seguridad, Backend, QA y DevOps coinciden en cuatro
bloqueos críticos reproducibles. Las 64 pruebas existentes pasan porque no
cubren esas ventanas de fallo.

## Corrección mínima propuesta

1. Hacer compatibles los tres jobs con payloads anteriores. `campaignId`
   ausente se resuelve únicamente desde la conexión o revisión durable; una
   referencia incoherente falla cerrada antes de Google.
2. Particionar el procesamiento outbox por campaña. Un fallo de una campaña no
   detiene otras. `published_at` solo se establece después de un resultado
   tipado confirmado o un no-op terminal deliberado.
3. Usar ID externo determinista para eventos nuevos y adoptar de forma segura
   el evento ante un reintento, cerrando la ventana éxito remoto/fallo local.
4. Centralizar clasificación y sanitización de fallos Google. Solo códigos y
   mensajes permitidos llegan a UI, tablas, logs y failed jobs. Distinguir auth
   definitiva de cuota, red, permisos y 5xx.
5. Añadir migración aditiva con FK compuesta campaña-conexión, checks de ciclo
   de vida y campos de lease/heartbeat. PostgreSQL empieza con `NOT VALID` y un
   gate posterior valida datos históricos.
6. Implementar transiciones compare-and-set y recuperación programada de runs
   queued/running huérfanos. El lock de caché queda como optimización.
7. Derivar “sincronizado” y el enlace desde el mapping de la conexión activa;
   nunca desde un ID perteneciente al calendario anterior.
8. Alinear timeout, TTL y retry_after; aplicar migración antes de arrancar app,
   workers y scheduler; añadir métricas y runbook de rollback.
9. Corregir formato en los seis archivos reportados por Pint.

## Orden reversible

1. Preflight de datos y payloads de cola, sin mutar.
2. Migración aditiva compatible y constraints `NOT VALID`.
3. Código compatible hacia atrás para jobs y outbox.
4. Reinicio controlado de workers; scheduler al final.
5. Pruebas sintéticas, PostgreSQL, regresión, build y smoke local sin Google.
6. Observación antes de cualquier release.

La reversión detiene productores, drena o pone en cuarentena jobs, revierte la
aplicación y conserva columnas e historial. Constraints y reparaciones de datos
se revierten por operaciones separadas. No se eliminan eventos de Google.

## Pruebas obligatorias

- Tres fixtures de jobs legados.
- Dos campañas con fallo en A y publicación independiente en B.
- Conexión no lista conserva outbox pendiente.
- Éxito remoto, fallo DB y reintento producen un solo evento.
- Matriz sintética invalid_grant, 401, authError, cuota, 429, red y 5xx.
- Respuestas con secretos simulados nunca aparecen en persistencia o UI.
- Recuperación de queued/running huérfanos y job tardío no degrada terminal.
- PostgreSQL rechaza relación cruzada y estados inválidos.
- Cambio de calendario no muestra enlace anterior.
- Suite completa, Pint y build frontend.

## Gates

- `gate.material-action`: pendiente de aprobación humana para implementar.
- `gate.release`: cerrado; solo se reconsidera después de Backend y QA
  correctivos y nueva evaluación DevOps.
