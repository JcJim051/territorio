# Decisiones de arquitectura de Territorio

## Finalidad

Este directorio conserva las decisiones arquitectónicas de Territorio y el
contexto necesario para entender por qué se adoptaron. Un ADR registra una
decisión; no sustituye migraciones, pruebas ni procedimientos operativos.

## Estados

- `Proposed`: requiere decisión humana.
- `Accepted`: gobierna la implementación.
- `Rejected`: fue evaluado y descartado.
- `Superseded`: una decisión posterior lo reemplazó.
- `Deprecated`: se conserva, pero ya no debe aplicarse.

## Convenciones

- Cada ADR aborda una decisión material.
- Los identificadores siguen el formato `TERR-ADR-####` y no se reutilizan.
- Una decisión aceptada no se reescribe para ocultar cambios de dirección.
- El código, SQL y pruebas ejecutables viven fuera del ADR y se enlazan como
  evidencia.

## Índice

| ADR | Título | Estado |
|---|---|---|
| [TERR-ADR-0001](TERR-ADR-0001-alcance-autorizado-y-consistencia-multicampana.md) | Alcance autorizado y consistencia multicampaña | Proposed |
