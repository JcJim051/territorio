# TERR-ADR-0002 — Seguimiento durable de Google Calendar

- **Estado:** Accepted
- **Fecha:** 2026-08-03
- **Decisor:** Propietario técnico de Territorio
- **Aprobación:** 2026-08-03
- **Implementación:** Primer incremento autorizado

## Contexto y problema

Territorio publica reuniones aprobadas mediante outbox e importa Google
Calendar mediante jobs. La interfaz anterior solo distinguía conexión activa,
evento publicado, evento pendiente y último error. Una solicitud manual
confirmaba únicamente que el job había sido encolado y el navegador no volvía a
consultar su resultado.

Una autorización revocada podía conservar la conexión con estado `active`,
provocar reintentos inútiles y dejar la agenda mostrando un error anterior aun
después de una reconexión satisfactoria. Pulsaciones, webhooks y polling también
podían encolar sincronizaciones concurrentes para la misma conexión.

## Fuerzas y restricciones

- Mantener Google fuera de las transacciones HTTP.
- Preservar outbox, mappings y trabajos ya serializados.
- Mantener aislamiento por `campaign_id` y contexto autorizado en workers.
- No duplicar eventos ante reintentos o reconexiones.
- Ofrecer retroalimentación automática sin incorporar infraestructura en tiempo
  real adicional en este incremento.
- Conservar polling, webhooks y reconciliación semanal.

## Alternativas consideradas

### Recargar manualmente la agenda

No requiere persistencia nueva, pero mantiene estados engañosos y no permite
distinguir encolado, ejecución, éxito o agotamiento de reintentos.

### Consultar directamente el estado de la cola

Acopla la experiencia al driver de colas, no conserva resultados terminados y
no representa métricas funcionales de Google.

### Registrar ejecuciones durables por conexión

Permite deduplicar, auditar el resultado y consultar progreso con independencia
del driver. Requiere una tabla y disciplina en todos los disparadores.

### Introducir WebSockets inmediatamente

Reduce latencia visual, pero añade infraestructura y no resuelve por sí mismo
la trazabilidad. No se justifica para sincronizaciones de pocos segundos.

## Decisión

1. Registrar cada sincronización en `calendar_sync_runs`, siempre asociada a
   campaña y conexión.
2. Admitir una sola ejecución `queued` o `running` por conexión mediante una
   clave activa única y un bloqueo al crearla.
3. Centralizar manual, selección de calendario, webhook, polling y
   reconciliación en `CalendarSyncDispatcher`.
4. Mantener compatibles los jobs serializados anteriores haciendo opcional el
   identificador de ejecución.
5. Marcar la conexión `reconnect_required` cuando el refresh token sea inválido,
   borrar credenciales inutilizables y no reintentar ese error definitivo.
6. Mantener eventos outbox pendientes para que la selección posterior de un
   calendario los vuelva a procesar de forma idempotente.
7. Actualizar agenda y configuración mediante recarga parcial Inertia cada 2,5
   segundos mientras exista trabajo pendiente.
8. Considerar publicado un evento únicamente después de recibir ID y `etag` de
   Google; la aprobación local solo informa que la publicación está pendiente.

## Consecuencias positivas

- El usuario obtiene resultado final y cantidades procesadas.
- Solicitudes repetidas reutilizan la ejecución activa.
- Una revocación se convierte en una acción explícita de reconexión.
- La agenda abierta deja de conservar indefinidamente el estado anterior.
- La relación campaña-conexión se conserva en jobs y consultas.

## Consecuencias negativas

- Se añade persistencia operativa que deberá depurarse mediante una política de
  retención futura.
- El polling visual produce consultas breves mientras una ejecución está
  activa.
- Una reconciliación completa solicitada mientras otra ejecución está activa se
  deduplica; deberá reprogramarse si el producto exige garantía inmediata del
  recorrido completo.

## Riesgos y mitigaciones

- **Ejecución huérfana:** `failed()` libera la clave al agotar reintentos. Se
  recomienda añadir recuperación de ejecuciones de antigüedad anómala al
  incorporar monitoreo operativo.
- **Cruce de campañas:** todas las lecturas usan campaña y conexión; las pruebas
  verifican que la vista no exponga ejecuciones ajenas.
- **Mensajes con secretos:** solo se persisten códigos y mensajes seguros, no
  respuestas OAuth, access tokens ni refresh tokens.
- **Duplicados externos:** la publicación continúa usando
  `integration_mappings` y upsert idempotente.

## Reversión

La interfaz puede dejar de consultar `calendar_sync_runs` sin retirar la tabla.
Los disparadores pueden volver temporalmente al job directo porque
`syncRunId` es opcional. La migración se revierte eliminando únicamente el
historial de ejecuciones; no modifica conexiones, mappings, reuniones ni eventos
en Google. Los datos creados fuera de Territorio no se eliminan al revertir.

## Criterios de aceptación

- Dos solicitudes simultáneas para una conexión generan un job activo.
- El historial de una campaña nunca aparece en otra.
- Una conexión no lista termina la ejecución sin invocar Google.
- El estado cambia automáticamente de encolado a éxito o fallo.
- La UI ofrece reconectar ante autorización revocada.
- Una reunión sincronizada muestra el enlace confirmado por Google.
- Las pruebas de calendario y contexto multicampaña permanecen verdes.

## Criterios de revisión

Revisar esta decisión al incorporar WebSockets, varios calendarios por campaña,
calendarios de delegados, ejecución distribuida con Redis o garantías estrictas
de reconciliación completa.

## Decisiones relacionadas

- [TERR-ADR-0001](TERR-ADR-0001-alcance-autorizado-y-consistencia-multicampana.md)
  define el contexto autorizado que deben transportar los jobs.
