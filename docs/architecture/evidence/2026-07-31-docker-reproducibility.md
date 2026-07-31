# Validación de reproducibilidad Docker — 2026-07-31

## Finalidad

Registrar la corrección y validación de los defectos de entorno detectados
durante la Fase 0 de `TERR-ADR-0001`. Este documento prueba la capacidad de
crear un laboratorio PostgreSQL reproducible; no valida todavía las
restricciones multicampaña propuestas.

## Problemas corregidos

1. La imagen de producción podía copiar `bootstrap/cache/packages.php` y
   `services.php` generados en el host con proveedores de desarrollo ausentes
   en la imagen construida con Composer `--no-dev`.
2. Los servicios heredaban desde `.env` una configuración local potencialmente
   incompatible con la red de Compose, incluyendo SQLite, contraseña vacía y
   Redis en `127.0.0.1`.

## Decisión operativa

- Excluir `bootstrap/cache/*.php` del contexto de construcción.
- Regenerar el descubrimiento de paquetes dentro de la imagen después de
  instalar las dependencias de producción.
- Mantener `.env` como fuente de configuración general de Laravel, pero
  sobrescribir en Compose las direcciones y el driver propios de su red.
- Usar variables `DOCKER_DB_*` compartidas por aplicación, PostgreSQL, backup y
  Metabase para evitar colisiones con la configuración local `DB_*`.

Esta corrección no modifica reglas de negocio, esquema ni datos persistentes de
Territorio.

## Entorno de validación

- Proyecto Compose aislado: `territorio-repro-check`.
- Imagen de aplicación reconstruida con `--no-cache`.
- Base nueva y volumen desechable.
- PostgreSQL 16 mediante `postgis/postgis:16-3.4-alpine`.
- Redis 7 mediante `redis:7-alpine`.
- Migraciones y semillas ficticias actuales.

## Resultado

| Comprobación | Resultado |
|---|---|
| `docker compose config --quiet` | Correcto |
| Construcción limpia de la imagen | Correcta |
| Descubrimiento de paquetes de producción | Correcto |
| Migraciones sobre una base nueva | Correctas |
| Semillas ficticias | Correctas |
| Driver de base visto por Laravel | `pgsql` |
| Host de base visto por Laravel | `postgres` |
| Host Redis visto por Laravel | `redis` |
| Driver de colas | `redis` |
| Conexión `PING` a Redis | Correcta |
| Referencia residual a Laravel Pail | Ausente |

## Límites y observaciones

- La imagen PostGIS disponible se ejecutó mediante emulación `linux/amd64` en
  un equipo `arm64`. La validación funcional pasó, pero conviene fijar una
  estrategia de plataforma si este entorno se integra posteriormente en CI.
- Vite informó que algunos chunks superan 500 kB. Es deuda de rendimiento del
  frontend y no afecta la reproducibilidad validada aquí.
- No se ejecutaron aún casos de rechazo de relaciones entre campañas.
- No se usaron datos reales ni de producción.

## Siguiente validación

Construir fixtures PostgreSQL aislados para las tres relaciones piloto de
`TERR-ADR-0001` y demostrar primero el comportamiento actual y después el
rechazo de relaciones cruzadas mediante las constraints propuestas.
