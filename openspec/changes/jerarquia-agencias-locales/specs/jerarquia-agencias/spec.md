# jerarquia-agencias Specification

**Estado**: draft

## Purpose

Define la entidad **agencia** (local físico) como nivel intermedio entre grupo y taquilla en la jerarquía de 6 niveles. La agencia es solo identidad (no configura límites/monedas/vigencia/tiempo), y cubre rol, cadena de activación y backfill idempotente.

## Requirements

### Requirement: Entidad agencia (local físico)

El sistema MUST persistir un local físico (`agencia`) con `code` único, `name`, pertenencia a un grupo (`grupo_id` NOT NULL), `active` (default true), creador, datos fiscales nullable y softDeletes. La agencia SHALL NOT configurar monedas/vigencia/tiempo/límites en esta iteración (passthrough).

#### Scenario: Creación de agencia

- GIVEN un grupo activo
- WHEN se crea una agencia con `code` y `name`
- THEN se persiste activa y vinculada al grupo

#### Scenario: Código duplicado

- GIVEN una agencia con un `code` existente
- WHEN se intenta crear otra agencia con el mismo `code`
- THEN se rechaza (código único)

#### Scenario: Sin configuración propia

- GIVEN una agencia
- WHEN se consulta su configuración
- THEN no expone límites/monedas/vigencia/tiempo propios (hereda del grupo/banca)

### Requirement: Asociación taquilla y usuario a agencia

El sistema MUST permitir `taquillas.agencia_id` y `users.agencia_id` (nullable). Eliminar una agencia MUST NOT eliminar sus taquillas ni usuarios (`set null`). `taquillas.grupo_id` MUST conservarse.

#### Scenario: Vinculación de taquilla

- GIVEN una taquilla
- WHEN se le asigna una agencia
- THEN `agencia_id` referencia el local

#### Scenario: Borrado sin cascada destructiva

- GIVEN una agencia con taquillas y usuarios vinculados
- WHEN se elimina la agencia
- THEN taquillas y usuarios conservan `agencia_id = null`

#### Scenario: Conservación de grupo_id

- GIVEN una taquilla con `grupo_id`
- WHEN se introduce el nivel agencia
- THEN `taquillas.grupo_id` se conserva sin migrar

### Requirement: Rol agencia en ambas fuentes

El sistema MUST registrar el rol `agencia` en AMBAS fuentes: columna `users.role` y rol Spatie, con permisos `view_taquillas, manage_taquillas, view_apuestas, create_apuesta, delete_apuesta, create_pago, view_pagos, view_reports, create_cierre, view_cierre`.

#### Scenario: Doble registro

- GIVEN un usuario rol agencia
- WHEN se consulta su rol
- THEN `users.role = 'agencia'` Y posee el rol Spatie con los permisos listados

#### Scenario: Fuente faltante

- GIVEN un usuario con solo una de las dos fuentes configurada
- WHEN accede a un recurso protegido
- THEN el acceso falla (ambas fuentes son obligatorias)

### Requirement: Cadena de activación con agencia

La cadena de activación efectiva MUST ser `taquilla → agencia → grupo → banca`. Desactivar una agencia MUST pausar sus taquillas.

#### Scenario: Cadena completa activa

- GIVEN agencia y grupo activos
- WHEN se evalúa el estado de una taquilla
- THEN la taquilla está activa

#### Scenario: Local desactivado

- GIVEN una agencia desactivada
- WHEN se evalúa una taquilla de esa agencia
- THEN la taquilla está inactiva (pausada)

#### Scenario: Grupo inactivo

- GIVEN una agencia activa con grupo desactivado
- WHEN se evalúa la cadena
- THEN la taquilla está inactiva

### Requirement: Backfill idempotente

Un comando (no seeder) MUST crear 1 agencia por grupo (name `"{grupo} - Local"`), asignar `agencia_id` a taquillas y usuarios rol taquilla, ser idempotente y re-ejecutable con log.

#### Scenario: Primera ejecución

- GIVEN grupos sin agencia
- WHEN se ejecuta el backfill
- THEN crea 1 local por grupo y vincula taquillas y usuarios rol taquilla

#### Scenario: Re-ejecución

- GIVEN el backfill ya ejecutado
- WHEN se re-ejecuta
- THEN no crea agencias duplicadas

#### Scenario: Ejecución en producción

- GIVEN un entorno de producción
- WHEN se ejecuta el backfill
- THEN requiere `--force` y registra un log revisable

### Requirement: Actualización de tests agencia≡taquilla

El sistema MUST actualizar en el mismo cambio los tests existentes que fijan `agencia`≡`taquilla`, y añadir cobertura de alcance agencia, master-scope y cadena con agencia.

#### Scenario: Suite verde

- GIVEN la suite actualizada
- WHEN se ejecuta `composer test`
- THEN la suite pasa en verde

#### Scenario: Cobertura nueva

- GIVEN los nuevos tests
- WHEN se ejecutan
- THEN cubren alcance agencia, master-scope y cadena de activación con agencia
