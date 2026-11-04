# Delta for jerarquia-agencias

> Se habilita a la agencia a editar su propia ficha y a gestionar las taquillas de su local (matriz CRUD). El resto de la capability `jerarquia-agencias` permanece sin cambios.

## ADDED Requirements

### Requirement: Agencia edita su propia ficha

El rol `agencia` MUST poder actualizar su propia ficha de agencia (name, datos fiscales y contacto), pero MUST NOT modificar `code`, `grupo_id` ni `active`; tampoco crear, alternar estado ni eliminar agencias. Una agencia ajena MUST devolver 403.

#### Scenario: Agencia edita su ficha

- GIVEN un usuario rol `agencia` con `agencia_id`
- WHEN actualiza su propia agencia
- THEN persiste los cambios (200)

#### Scenario: Agencia reasigna su grupo

- GIVEN un usuario rol `agencia`
- WHEN intenta cambiar el `grupo_id` de su agencia a otro grupo
- THEN 403

#### Scenario: Agencia crea/elimina agencia

- GIVEN un usuario rol `agencia`
- WHEN intenta crear, alternar estado o eliminar una agencia
- THEN 403

### Requirement: Agencia gestiona taquillas de su local

El rol `agencia` MUST poder crear, actualizar, alternar y eliminar (soft delete) taquillas dentro de su propia agencia, derivando `agencia_id` y `grupo_id` de su local. Una taquilla de otra agencia MUST devolver 403.

#### Scenario: Agencia crea taquilla en su local

- GIVEN un usuario rol `agencia`
- WHEN crea una taquilla
- THEN la taquilla queda vinculada a su `agencia_id` y al grupo de su local

#### Scenario: Agencia gestiona taquilla ajena

- GIVEN un usuario rol `agencia` y una taquilla de otra agencia
- WHEN intenta ver, editar o eliminar esa taquilla
- THEN 403
