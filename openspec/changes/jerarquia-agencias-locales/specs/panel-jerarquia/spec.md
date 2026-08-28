# panel-jerarquia Specification

**Estado**: draft

## Purpose

Navegación y labels del panel para la jerarquía de 6 niveles: renames (Agencias→Taquillas para la máquina; Agencias=local), sidebar, creación de taquillas por agencia y login/payload con `agencia_id`.

## Requirements

### Requirement: Renames y labels de navegación

El panel MUST renombrar la sección de máquinas de "Agencias" a "Taquillas" (`/taquillas`) y usar "Agencias" para los locales (`/agencias`). `ROLE_LABELS` MUST mapear `agencia:'Agencia'` y `taquilla:'Taquilla'`.

#### Scenario: Navegación

- GIVEN el sidebar con rol con acceso
- WHEN se muestra la navegación
- THEN "Taquillas" apunta a `/taquillas` y "Agencias" a `/agencias`

#### Scenario: Labels de rol

- GIVEN un label de rol
- WHEN se renderiza
- THEN agencia→"Agencia" y taquilla→"Taquilla"

### Requirement: Sidebar por rol

El sidebar MUST mostrar Taquillas y Agencias (locals) según el rol; la agencia ve sus taquillas y reportes, no bancas/grupos/tasas.

#### Scenario: Sidebar de agencia

- GIVEN un usuario agencia
- WHEN ve el sidebar
- THEN ve taquillas/cuadre/reportes y no bancas/grupos/tasas

#### Scenario: Sidebar de master

- GIVEN un master
- WHEN ve el sidebar
- THEN ve solo sus bancas y descendientes

### Requirement: Login y payload con agencia_id

El login del panel MUST incluir `agencia` en los roles permitidos, y el payload de usuario MUST exponer `agencia_id`.

#### Scenario: Agencia aceptada

- GIVEN el login del panel
- WHEN un rol agencia se autentica
- THEN es aceptado

#### Scenario: Payload con agencia_id

- GIVEN la respuesta de login o `GET /user`
- WHEN se consulta
- THEN incluye `agencia_id`

### Requirement: Creación de taquillas por agencia

Una agencia MUST poder crear taquillas solo en su local; `agencia_id` y `grupo_id` se derivan de la agencia (no editables).

#### Scenario: Creación en su local

- GIVEN una agencia autenticada
- WHEN crea una taquilla
- THEN se asigna a su `agencia_id` y grupo derivado

#### Scenario: Intento en otro local

- GIVEN una agencia que intenta crear en otro local
- WHEN lo hace
- THEN se rechaza

#### Scenario: Usuario creado

- GIVEN la creación de una taquilla por agencia
- WHEN se crea el usuario taquilla
- THEN incluye `agencia_id`

### Requirement: Selectores y formularios

Los formularios de usuarios/taquillas/reportes/cuadre/límites MUST reflejar local vs máquina (select agencia, opción agencia=local, taquilla=máquina, dashboard con stat locales+taquillas).

#### Scenario: Select de agencia

- GIVEN el formulario de usuarios
- WHEN se asigna entidad
- THEN permite seleccionar agencia

#### Scenario: Niveles en reportes/cuadre

- GIVEN reportes/ventas y cuadre
- WHEN se elige nivel
- THEN "Agencia"=local y "Taquilla"=máquina

### Requirement: Actualización de tests de terminología

`TerminologiaTest` y los tests de login X-Panel MUST actualizarse a la nueva semántica.

#### Scenario: Terminología verde

- GIVEN la suite actualizada
- WHEN se ejecuta
- THEN `TerminologiaTest` refleja Agencia=local y Taquilla=máquina
