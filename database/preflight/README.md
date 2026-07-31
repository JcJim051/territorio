# Preflight de base de datos

## Finalidad

Este directorio contiene consultas diagnósticas de solo lectura que deben
ejecutarse antes de proponer restricciones o remediaciones de datos.

`tenant-isolation-pilot.sql` verifica únicamente las tres relaciones piloto de
`TERR-ADR-0001`. Un resultado sin violaciones no certifica el aislamiento
integral de Territorio.

## Ejecución

Ejecutar con un usuario PostgreSQL de solo lectura sobre una copia o entorno
autorizado:

```bash
psql "$DATABASE_URL" -v ON_ERROR_STOP=1 \
  -f database/preflight/tenant-isolation-pilot.sql
```

El script abre una transacción `READ ONLY`, devuelve conteos agregados y termina
con `ROLLBACK`. No imprime datos personales ni intenta corregir registros.

## Interpretación

- `violations = 0`: no se observaron cruces para esa comprobación en el
  snapshot consultado.
- `violations > 0`: detener cualquier constraint y abrir una revisión de datos.
- Error de ejecución: el preflight no es válido; no interpretar como cero.

Conservar fecha, entorno, versión de esquema y salida agregada como evidencia de
cada ejecución. No versionar credenciales ni resultados con datos sensibles.

La primera ejecución está registrada en
[`docs/architecture/evidence/2026-07-31-tenant-isolation-pilot.md`](../../docs/architecture/evidence/2026-07-31-tenant-isolation-pilot.md).
