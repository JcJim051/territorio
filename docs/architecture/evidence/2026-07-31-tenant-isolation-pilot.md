# Preflight piloto de aislamiento multicampaña — 2026-07-31

## Finalidad

Registrar la primera ejecución de solo lectura del preflight asociado a
`TERR-ADR-0001`, incluida la evidencia que limita su interpretación.

## Entorno

- Código base: commit `4c26206` más el cambio documental que acepta el ADR.
- Base: PostgreSQL `16.4`, imagen `postgis/postgis:16-3.4-alpine`.
- Datos: migraciones actuales y semillas ficticias de desarrollo.
- Servicios: PostgreSQL local aislado; ningún dato de producción.
- Script: [`database/preflight/tenant-isolation-pilot.sql`](../../../database/preflight/tenant-isolation-pilot.sql).

## Controles de seguridad

- Transacción `REPEATABLE READ READ ONLY`.
- `lock_timeout` de 5 segundos.
- `statement_timeout` de 60 segundos.
- Salida limitada a conteos agregados.
- Cierre mediante `ROLLBACK`.

## Resultado

| Comprobación | Violaciones |
|---|---:|
| `membership_role_campaign` | 0 |
| `attendance_meeting_campaign` | 0 |
| `attendance_person_campaign` | 0 |
| `external_event_connection_campaign` | 0 |
| `external_event_meeting_campaign` | 0 |

El script terminó correctamente y ejecutó `ROLLBACK`.

## Cobertura real del conjunto de datos

| Tabla | Filas |
|---|---:|
| `campaign_memberships` | 2 |
| `campaign_roles` | 2 |
| `attendances` | 0 |
| `meetings` | 2 |
| `persons` | 8 |
| `external_calendar_events` | 0 |
| `calendar_connections` | 0 |

## Interpretación

La ejecución valida que el preflight es compatible con el esquema PostgreSQL
actual y que no existen cruces membresía–rol en las semillas consultadas.

Los ceros de asistencia y calendario no demuestran integridad porque las tablas
hijas no contienen filas. Antes de proponer constraints debe crearse una prueba
PostgreSQL aislada que inserte relaciones válidas y compruebe que cada relación
cruzada propuesta sea rechazada.

Este resultado no certifica aislamiento integral ni representa datos de
producción.

## Hallazgos operativos del entorno

La preparación detectó dos incompatibilidades de Docker, sin corregir el
repositorio en esta fase:

1. La imagen instala dependencias Composer con `--no-dev`, pero copia la caché
   `bootstrap/cache/packages.php` del host, que puede referenciar
   `Laravel\Pail\PailServiceProvider` ausente en producción.
2. Un `DB_PASSWORD` vacío en `.env` llega vacío al contenedor de aplicación,
   mientras Compose usa el fallback público `change-me` al crear PostgreSQL.

Se regeneró la caché únicamente dentro de un contenedor desechable y se usaron
las credenciales locales declaradas por Compose. No se modificó el repositorio
para resolver estos hallazgos.

## Siguiente validación requerida

Diseñar una prueba PostgreSQL automatizada para las tres relaciones piloto y
corregir separadamente la reproducibilidad del entorno Docker antes de proponer
migraciones.
