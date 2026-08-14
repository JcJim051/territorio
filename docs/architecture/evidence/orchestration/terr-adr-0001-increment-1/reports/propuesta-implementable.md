# Propuesta implementable — primer incremento de TERR-ADR-0001

- **Estado:** pendiente de gate material humano
- **Baseline revisado:** `d5452b0ad1295315920477f1cf1bc025be0a9e1d`
- **Clasificacion:** interna
- **Efectos realizados:** documentacion y trazabilidad; ningun cambio en codigo, esquema o datos

## Alcance aprobado que se propone implementar

El incremento se limita a las tres relaciones piloto de `TERR-ADR-0001` y al
soporte minimo de aplicacion necesario para que las mismas garantias existan en
HTTP y trabajos asincronos:

1. Membresia de campana y rol de campana.
2. Asistencia, reunion y persona.
3. Evento externo, conexion de calendario y reunion.
4. Contexto autorizado tipado para las unidades de trabajo que operan esas
   relaciones.
5. Endurecimiento de los jobs de Google Calendar incluidos en el piloto.

Permanecen fuera de alcance RLS, recursos compartidos, otras relaciones,
despliegue, produccion y la decision de si un usuario normal puede cambiar entre
varias campanas.

## Cambios necesarios

### 1. Contexto de ejecucion autorizado

Crear tipos separados para:

- `DataScope`, con variantes representables de campana, organizacion y operacion
  global;
- `AuthorizationDecision`;
- `AuthorizedExecutionContext`, que compone alcance y decision;
- un store y un runner que activen una sola unidad de trabajo y limpien el
  contexto en `finally`;
- resolvers controlados desde membresia activa o referencias durables.

En este incremento solo se emitira `CampaignScope`. Organizacion y operacion
global seran representables, pero ningun resolver podra concederlas. El runner
aceptara un contexto ya autorizado; ni un `campaign_id` ni una cadena `source`
constituiran evidencia de autoridad. `CurrentCampaign` se conservara como
fachada compatible, derivada de la misma resolucion.

### 2. Jobs y calendario

- Transportar `campaign_id` junto con el ID durable en sincronizacion,
  renovacion y rechazo.
- Resolver modelos por `campaign_id + id`.
- Comprobar en outbox la igualdad entre campana del evento, payload, reunion y
  agregado antes de llamar a Google o marcar publicacion.
- Procesar cada evento de outbox en un contexto individual con limpieza
  garantizada.
- Mantener los filtros manuales existentes durante la transicion.
- Registrar discrepancias de forma auditable sin secretos ni payload sensible.

### 3. Integridad PostgreSQL

Crear cuatro indices unicos de soporte en:

- `campaign_roles (campaign_id, id)`;
- `meetings (campaign_id, id)`;
- `persons (campaign_id, id)`;
- `calendar_connections (campaign_id, id)`.

Crear cinco indices hijos y cinco FK compuestas `NOT VALID`:

| Hija | Columnas | Padre | Eliminacion preservada |
|---|---|---|---|
| `campaign_memberships` | `(campaign_id, campaign_role_id)` | `campaign_roles (campaign_id, id)` | `SET NULL (campaign_role_id)` |
| `attendances` | `(campaign_id, meeting_id)` | `meetings (campaign_id, id)` | `CASCADE` |
| `attendances` | `(campaign_id, person_id)` | `persons (campaign_id, id)` | `CASCADE` |
| `external_calendar_events` | `(campaign_id, calendar_connection_id)` | `calendar_connections (campaign_id, id)` | `CASCADE` |
| `external_calendar_events` | `(campaign_id, meeting_id)` | `meetings (campaign_id, id)` | `SET NULL (meeting_id)` |

Las dos acciones `SET NULL` deben indicar solamente la columna referenciada
opcional; `campaign_id` permanece obligatorio. Se conserva `MATCH SIMPLE`,
`ON UPDATE NO ACTION`, la nulabilidad actual y las FK simples durante el piloto.

## Migraciones propuestas

1. Migracion no transaccional para indices padres e hijos concurrentes, con
   verificacion de `pg_index.indisvalid`.
2. Migracion para agregar una a una las cinco FK compuestas `NOT VALID`.
3. Paso posterior y separado para `VALIDATE CONSTRAINT`, solo despues de
   preflight limpio y pruebas de comportamiento. No se mezclara la creacion y la
   validacion en un unico gate operativo.

`NOT VALID` no significa desactivada: desde su creacion cada FK rechaza filas
nuevas incompatibles. Los cruces historicos, si aparecen, detienen la validacion
y requieren una tarea de remediacion distinta y aprobada.

## Plan incremental y reversible

1. Backend incorpora primero pruebas PostgreSQL que reproduzcan el estado actual
   y los casos esperados.
2. Backend crea el contexto tipado en modo observacion, sin global scopes ni RLS.
3. Backend deriva `CurrentCampaign` del resolver controlado.
4. Backend endurece payloads, lookups y validaciones de los jobs piloto.
5. En laboratorio sintetico se repite el preflight.
6. Se crean y verifican indices concurrentes.
7. Se agregan las FK `NOT VALID` una a una.
8. QA demuestra inserciones validas, rechazo de cruces y semantica de borrado en
   PostgreSQL 16.
9. Se validan las constraints por separado en el laboratorio.
10. QA ejecuta regresion PHP y compilacion TypeScript.
11. DevOps evalua preparacion, orden de activacion y reversion. No despliega.

## Reversion

- **Aplicacion:** desactivar el enforcement piloto o restaurar la version
  anterior, siempre que sea compatible con las FK activas. La limpieza en
  `finally` no debe omitirse.
- **Constraints:** retirar explicitamente las cinco FK compuestas; despues,
  retirar indices concurrentemente si ya no son necesarios. Las FK simples
  originales permanecen.
- **Datos:** este incremento no remedia datos. Eliminar una constraint no
  restaura valores. Cualquier reparacion futura exige diario antes/despues y su
  propia operacion inversa o restauracion.
- **Orden critico:** si una version anterior puede volver a escribir cruces, las
  FK compuestas deben retirarse antes del rollback de aplicacion.

## Pruebas requeridas

### PostgreSQL 16 con datos sinteticos

- Insercion valida y rechazo `23503` de los cinco cruces.
- Rol nullable y reunion nullable permitidos.
- Borrar rol conserva membresia y nulifica solo `campaign_role_id`.
- Borrar reunion o persona elimina asistencia.
- Borrar conexion elimina evento externo.
- Borrar reunion conserva evento externo y nulifica solo `meeting_id`.
- Una FK `NOT VALID` acepta la inconsistencia historica de fixture, rechaza una
  nueva y no valida hasta remediar el fixture.
- Estado `convalidated = true` tras cada validacion.
- Varias membresias de un mismo usuario siguen siendo representables.

### PHP

- Resolver HTTP rechaza membresia inactiva, campana inactiva y discrepancias.
- Ningun ID o texto `source` concede autoridad.
- Un contexto ausente, de otra campana o anidado de forma incompatible falla
  cerrado.
- El runner limpia contexto en exito y excepcion, incluidos jobs A→B consecutivos.
- Jobs rechazan envelope, payload, conexion, review, evento o reunion de campanas
  distintas sin invocar Google.
- Outbox inconsistente no se marca como publicado.
- Regresion de aislamiento, usuarios y Google Calendar.

### Frontend

No se propone cambio funcional de interfaz. Se ejecutara la compilacion
TypeScript como regresion.

## Riesgos y condiciones de bloqueo

| Riesgo | Prioridad | Respuesta |
|---|---:|---|
| Escritores incompatibles cuando se agrega `NOT VALID` | Alta | Adaptar y probar antes de la FK |
| Contexto filtrado entre jobs persistentes | Alta | Store por unidad y `finally` obligatorio |
| ID o `source` tratados como autorizacion | Alta | Resolver solo evidencia durable |
| `SET NULL` intenta nulificar `campaign_id` | Alta | Lista selectiva de columna y prueba PG16 |
| Preflight con tablas vacias se interpreta como prueba | Alta | Fixtures sinteticos no vacios |
| Indice concurrente queda invalido | Media | Comprobar `indisvalid` antes de continuar |
| `VALIDATE` genera carga o bloqueo | Media | Ejecutar por constraint y observar |
| SQLite produce confianza falsa | Media | Suite PostgreSQL obligatoria |

El gate queda bloqueado si el DDL no se demuestra en PostgreSQL 16, si los jobs
siguen recibiendo solo IDs, si el runner no limpia en `finally`, si aparece una
violacion sin plan separado, si se infiere la politica de usuarios multicampana
o si se intenta desplegar en produccion.

Las probabilidades del modelo de amenazas son estimaciones cualitativas basadas
en alcanzabilidad, barreras independientes, uso de IDs sin campana y cobertura
de pruebas; no son frecuencias observadas.

## Archivos posiblemente afectados

Existentes:

- `app/Support/CurrentCampaign.php`
- `app/Http/Middleware/ResolveCurrentCampaign.php`
- `app/Jobs/ProcessCalendarOutbox.php`
- `app/Jobs/SyncGoogleCalendarConnection.php`
- `app/Jobs/RenewGoogleCalendarWatch.php`
- `app/Jobs/ApplyCalendarReviewRejection.php`
- `app/Services/GoogleCalendarSync.php`
- `app/Services/GoogleCalendarPublisher.php`
- `app/Http/Controllers/GoogleCalendarConnectionController.php`
- `app/Http/Controllers/GoogleCalendarWebhookController.php`
- `app/Http/Controllers/CalendarReviewController.php`
- `routes/console.php`
- `tests/Feature/CampaignIsolationTest.php`
- `tests/Feature/GoogleCalendarIntegrationTest.php`

Nuevos, con nombres ajustables por GC-Backend sin cambiar el contrato:

- tipos, decision, contexto, store, runner y resolver bajo
  `app/Support/Tenancy/`;
- migraciones separadas de indices, FK `NOT VALID` y validacion;
- pruebas unitarias del runner;
- pruebas funcionales de contexto y jobs;
- suite PostgreSQL del piloto y su configuracion aislada.

## Resultados y decisiones pendientes

Arquitectura, Datos y Seguridad coinciden en la correccion minima. No existe un
desacuerdo material. Datos no pudo ejecutar el DDL en PostgreSQL 16 durante la
revision; por ello la ejecucion sintetica es un criterio obligatorio de QA y no
se presenta la propuesta como ya verificada.

Siguen pendientes y no se infieren:

- si usuarios normales pueden cambiar entre multiples membresias;
- quien puede emitir alcance organizacional o global;
- propiedad y grants de recursos compartidos;
- condiciones para activar RLS.
