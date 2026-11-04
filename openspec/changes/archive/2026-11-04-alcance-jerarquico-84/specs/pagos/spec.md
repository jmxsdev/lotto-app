# Delta for pagos

> Se añade la verificación de ownership del `egreso` para banca/grupo/master (caso 3). El resto de la capability `pagos` permanece sin cambios.

## ADDED Requirements

### Requirement: Egreso ownership por rol

Un `egreso` MUST registrarse únicamente para apuestas dentro del alcance jerárquico del actor: `taquilla` solo su taquilla, `grupo` solo su grupo, `banca` solo su banca, `master` solo sus bancas administradas, `super_master` cualquiera. La ruta `/pagos` no incluye el rol `agencia`; si se agrega en el futuro, su alcance MUST ser su local. Un intento cross-tenant MUST devolver 403.

#### Scenario: Banca paga apuesta ajena

- GIVEN una apuesta `ganadora` de una taquilla de otra banca
- WHEN un usuario rol `banca` registra su `egreso`
- THEN 403, sin `Pago` creado y sin cambio de estado

#### Scenario: Grupo paga apuesta ajena

- GIVEN una apuesta de una taquilla de otro grupo
- WHEN un usuario rol `grupo` registra su `egreso`
- THEN 403 y sin mutación

#### Scenario: Master paga apuesta de banca ajena

- GIVEN una apuesta de una banca que el master no administra
- WHEN un usuario rol `master` registra su `egreso`
- THEN 403 y sin mutación

#### Scenario: Banca paga apuesta propia

- GIVEN una apuesta `ganadora` de su propia banca
- WHEN un usuario rol `banca` registra su `egreso`
- THEN 201 con el `premio` del motor aplicado

#### Scenario: Super_master global

- GIVEN un `super_master`
- WHEN registra un `egreso` de cualquier apuesta `ganadora`
- THEN 201 (acceso global)
