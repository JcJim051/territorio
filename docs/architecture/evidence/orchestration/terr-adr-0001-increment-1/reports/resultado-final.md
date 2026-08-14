# Resultado final — primer incremento TERR-ADR-0001

- **Estado del incremento local:** implementado y verificado
- **Estado de release:** `NOT_READY`; `gate.release` cerrado
- **Produccion:** no desplegada ni consultada
- **Datos:** exclusivamente sinteticos en laboratorio desechable
- **Baseline inicial:** `d5452b0ad1295315920477f1cf1bc025be0a9e1d`

## Resultado

El alcance piloto de aislamiento multicampana fue implementado y verificado en
aplicacion y PostgreSQL 16.6. El primer ciclo de QA encontro el defecto QA-001
en la composicion HTTP con cola `sync`; GC-Backend lo corrigio dentro del mismo
alcance y GC-QA lo reverifico con regresion completa verde.

El cierre de esta mision no autoriza release. GC-DevOps mantiene condiciones
operativas pendientes para una futura activacion, que estaba expresamente fuera
del alcance.

## Garantias implementadas

### Contexto autorizado

- `DataScope` representa campana, organizacion y operacion global.
- En este incremento solo resolvers controlados emiten alcance de campana.
- `AuthorizationDecision` permanece separada del alcance de datos.
- El runner recibe un `AuthorizedExecutionContext`; IDs y `source` no conceden
  autoridad.
- Las unidades incompatibles fallan cerradas y el store se limpia en `finally`.
- La composicion legitima HTTP `membership → outbox_event` se permite solo para
  la misma campana, sin reemplazar al contexto propietario.

### Jobs piloto

- Sincronizacion, renovacion, rechazo y outbox transportan y verifican
  `campaign_id` mas referencia durable.
- Las discrepancias se rechazan antes de invocar Google.
- Un outbox inconsistente no se marca como publicado y registra fallo.
- `CurrentCampaign` deriva de la misma resolucion autorizada.

### PostgreSQL

Se incorporaron nueve indices y cinco FK compuestas `NOT VALID`:

1. membresia → rol;
2. asistencia → reunion;
3. asistencia → persona;
4. evento externo → conexion;
5. evento externo → reunion.

Se conservaron nulabilidad y acciones existentes:

- rol y reunion opcionales usan `SET NULL` selectivo sin nulificar
  `campaign_id`;
- asistencia y conexion conservan `CASCADE`;
- las FK simples siguen presentes durante el piloto.

## Evidencia de verificacion

| Verificacion | Resultado |
|---|---|
| PostgreSQL 16.6 `migrate:fresh` | PASS |
| Suite PostgreSQL | 2 pruebas, 20 aserciones, PASS |
| Cruces entre campanas | Cinco rechazos `23503` demostrados |
| Catalogo PostgreSQL | 9 indices `indisvalid=true`; 5 FK instaladas `NOT VALID` |
| QA-001 HTTP con cola `sync` | 1 prueba, 18 aserciones, PASS tras correccion |
| Composicion, aislamiento y limpieza | 8 pruebas, 27 aserciones, PASS |
| Suite PHP completa final | 61 pruebas, 530 aserciones, PASS |
| Build Vite/TypeScript | PASS; advertencia de chunks preexistente/no atribuible |
| Limpieza del laboratorio | PostgreSQL detenido y directorio temporal eliminado |

## Defecto encontrado y corregido

QA-001 demostraba que `ProcessCalendarOutbox` ejecutado con cola `sync` intentaba
abrir un segundo contexto dentro del contexto HTTP y respondia 500. La
correccion agrego una composicion limitada:

- propietario: decision por membresia activa;
- hijo: decision durable `outbox_event`;
- requisito: misma campana;
- rechazo: otra campana, otro propietario o decision incompatible.

No se modificaron migraciones ni expectativas existentes para corregirlo.

## Archivos principales

- Contexto tipado bajo `app/Support/Tenancy/`.
- Integracion HTTP en `CurrentCampaign`, middleware y provider.
- Jobs y servicios de Google Calendar endurecidos.
- Migraciones `2026_08_03_000100` y `2026_08_03_000200`.
- Pruebas `AuthorizedCampaignContextTest` y
  `Postgres/TenantIsolationPilotTest`.
- Configuracion aislada `phpunit.postgres.xml`.

## Reversion

- **Aplicacion:** detener productores y workers, drenar los jobs de la version
  propietaria y solo entonces cambiar el artefacto.
- **Constraints:** retirar explicitamente las cinco FK compuestas bajo gate
  propio; esto reabre el riesgo y no restaura datos.
- **Indices:** retirar los nueve indices concurrentemente despues de las FK.
- **Datos:** este incremento no migro ni remedio datos; no existe rollback de
  datos que ejecutar.
- **Efectos externos:** SQL no revierte Google Calendar ni outbox; deben
  reconciliarse antes de reanudar.

## Condiciones pendientes para release

Estas condiciones no impiden cerrar la implementacion local, pero mantienen
`gate.release` cerrado:

1. Runbook para transicion de jobs serializados entre versiones.
2. Pausa, drenaje, backlog cero y reinicio controlado de scheduler/workers.
3. `lock_timeout`, `statement_timeout`, monitoreo y recuperacion de indices
   concurrentes invalidos.
4. Preflight y `VALIDATE CONSTRAINT` uno por uno bajo gate operativo separado.
5. Observabilidad de contexto no autorizado, failed jobs, cola, outbox, sync,
   locks, `indisvalid` y `convalidated`.
6. Ensayo autorizado de activacion y rollback con volumen representativo.

## Decisiones no resueltas y preservadas

- Selector o politica para usuarios normales con varias membresias.
- Autoridad para emitir alcance organizacional o global.
- Propiedad y grants de recursos compartidos.
- Condiciones para activar RLS.

Ninguna fue inferida o codificada por este incremento.
