# TERR-ADR-0001 — Alcance autorizado y consistencia multicampaña

- **Estado:** Accepted
- **Fecha:** 2026-07-31
- **Decisor:** Propietario técnico de Territorio
- **Aprobación:** 2026-07-31
- **Implementación:** Fase 0 autorizada; aplicación y migraciones no autorizadas

## Contexto

Territorio mantiene varias campañas en una base PostgreSQL y un esquema
compartidos. La mayoría de las entidades operativas contiene `campaign_id`; el
middleware HTTP establece una membresía activa y los controladores normalmente
filtran por campaña.

Las claves foráneas actuales comprueban la existencia de cada registro, pero no
garantizan que dos registros relacionados pertenezcan a la misma campaña. Los
jobs y las integraciones operan fuera del middleware HTTP. También existen
recursos de organización compartidos y operaciones globales legítimas.

El esquema permite varias membresías por usuario. No existe una decisión de
producto que determine si los usuarios normales podrán cambiar entre campañas.

## Problema

Impedir lecturas, escrituras y relaciones entre campañas sin:

- depender de que cada consulta recuerde aplicar `campaign_id`;
- confundir un identificador o una etiqueta de origen con autorización;
- representar operaciones organizacionales o globales como bypasses;
- separar prematuramente cada campaña en otro esquema o base de datos.

## Fuerzas y restricciones

- Mantener el monolito Laravel y PostgreSQL 16.
- Proteger datos personales, políticos y documentales sensibles.
- Conservar despliegues incrementales y reversibles.
- Soportar HTTP, jobs, webhooks, integraciones y tareas administrativas.
- Mantener recursos exclusivos y compartidos sin otorgar acceso implícito a
  todas las campañas de una organización.
- No activar RLS sin validar workers persistentes, pooling y operaciones
  globales.

## Alternativas consideradas

### Mantener filtros manuales

Preserva compatibilidad, pero una nueva consulta sin filtro puede producir una
fuga. No proporciona defensa relacional.

### Aplicar únicamente global scopes de Eloquent

Protege parte de las consultas ORM, pero no SQL directo, integraciones,
analítica ni relaciones incoherentes almacenadas.

### Usar un contexto limitado a campaña

Mejora el comportamiento tenant-aware, pero fuerza recursos organizacionales y
administración global dentro de excepciones informales.

### Usar alcance tipado autorizado y restricciones PostgreSQL

Representa explícitamente campaña, organización u operación global; separa la
emisión de autoridad de la activación temporal y añade una segunda barrera de
integridad en la base de datos.

### Añadir PostgreSQL RLS

Ofrece una barrera adicional, pero aumenta el riesgo operativo mientras no se
haya demostrado limpieza de contexto en conexiones y workers persistentes.

### Separar esquema o base por campaña

Maximiza aislamiento físico, pero multiplica migraciones, respaldos, conexiones
y analítica. Es desproporcionado para la etapa actual.

## Decisión

Adoptar una base y un esquema compartidos con estas reglas:

1. Representar el alcance de datos mediante variantes tipadas de campaña,
   organización y operación global explícita.
2. Mantener conceptualmente separadas la partición de datos (`DataScope`) y la
   autorización de la acción (`AuthorizationDecision`), aunque ambas formen
   parte del contexto de ejecución.
3. Permitir que solo resolvers controlados emitan un contexto desde evidencia
   verificable: membresía, token, credencial, conexión o evento durable.
4. Limitar el runner a activar y limpiar un contexto ya autorizado; nunca debe
   conceder autoridad desde IDs o cadenas descriptivas.
5. Hacer fallar cerradas las operaciones tenant-aware sin alcance.
6. Transportar y comprobar campaña y referencia durable en jobs.
7. Agregar restricciones compuestas gradualmente para garantizar pertenencia
   común en PostgreSQL.
8. Mantener los filtros manuales durante la transición.
9. Mantener RLS fuera de esta primera decisión de implementación.

## Piloto autorizado por fases

Antes de extender el patrón, validarlo sobre tres relaciones representativas:

- membresía → rol;
- asistencia → reunión y persona;
- evento externo → conexión y reunión.

La Fase 0 autoriza ejecutar el
[preflight de aislamiento](../../../database/preflight/tenant-isolation-pilot.sql)
en un PostgreSQL controlado. Las pruebas de rechazo y las migraciones requieren
una autorización posterior basada en sus resultados.

## Decisiones explícitamente pendientes

- **Usuarios multicampaña:** una cuenta por campaña o selector restringido a
  membresías.
- **Alcance organizacional:** quién puede emitirlo y para qué operaciones.
- **Recursos compartidos:** propiedad organizacional o campaña propietaria con
  grants.
- **RLS:** condiciones y resultados del piloto requerido para activarlo.

Ninguna de estas políticas debe inferirse durante la implementación del piloto.

## Despliegue y reversión

El orden propuesto es:

1. Ejecutar preflight de solo lectura.
2. Adaptar y probar escrituras compatibles.
3. Activar observación del contexto tipado.
4. Remediar datos mediante un diario auditable cuando sea necesario.
5. Crear índices concurrentes fuera de transacciones.
6. Agregar FK `NOT VALID`, que desde ese momento protege escrituras nuevas.
7. Validar cada constraint.
8. Activar lecturas fail-closed por módulo.

Las feature flags solo revierten comportamiento de aplicación. Las constraints
se retiran explícitamente y las reparaciones de datos requieren su propia
operación inversa o restauración; retirar una FK no restaura datos anteriores.

## Consecuencias positivas

- Reduce fugas causadas por consultas nuevas sin alcance.
- PostgreSQL rechaza relaciones cruzadas aunque falle la aplicación.
- Jobs e integraciones reciben contexto verificable.
- Las operaciones organizacionales y globales dejan de ser bypasses implícitos.
- Permite avanzar por módulos sin introducir microservicios.

## Consecuencias negativas

- Añade tipos, resolvers, índices y pruebas PostgreSQL.
- Los flujos públicos necesitan un bootstrap controlado para localizar su grant.
- Parte de las migraciones requerirá SQL específico de PostgreSQL.
- La reversión exige coordinar aplicación, constraints y datos.
- SQLite seguirá siendo útil para pruebas rápidas, pero no demostrará la
  integridad objetivo.

## Riesgos

- Confundir `DataScope` con permisos funcionales.
- Permitir que servicios arbitrarios construyan grants.
- Conservar contexto entre jobs por no limpiarlo en `finally`.
- Activar constraints antes de que todas las escrituras sean compatibles.
- Convertir el alcance global en una credencial reutilizable.
- Aplicar reglas pendientes de producto como si fueran invariantes aprobadas.

## Criterios de aceptación

- El preflight piloto es reproducible y no modifica datos.
- Ningún contexto se construye desde un ID o texto sin evidencia autorizada.
- Cada unidad de trabajo tiene un solo alcance efectivo.
- Las tres relaciones piloto rechazan cruces en PostgreSQL 16.
- Se conserva la nulabilidad y el comportamiento de eliminación existente.
- Los jobs piloto rechazan discrepancias entre campaña y agregado.
- Las decisiones pendientes permanecen fuera de la implementación.
- Las pruebas PHP, TypeScript y PostgreSQL aplicables quedan registradas.

## Criterios de revisión

Revisar esta decisión al definir usuarios multicampaña, administración de
organización, propiedad de recursos compartidos, acceso analítico, RLS o un
segundo servicio con acceso a los mismos datos.

## Decisiones relacionadas

Todavía no existen otros ADR de Territorio. Las decisiones pendientes deberán
registrarse por separado cuando exista suficiente contexto para resolverlas.
