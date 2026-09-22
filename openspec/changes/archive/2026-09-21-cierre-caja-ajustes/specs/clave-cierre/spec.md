# clave-cierre Specification

**Estado**: draft

## Purpose

Define la clave de cierre de caja por usuario: almacenamiento hasheado (bcrypt) que nunca se serializa ni se guarda en claro, formato de PIN numérico, endpoint self-service de configuración (`GET`/`PUT /api/v1/usuarios/clave-cierre`) y validación contra la cadena jerárquica por encima de la taquilla. La clave habilita el re-cierre del día (capability `cierre-caja`).

## Requirements

### Requirement: Almacenamiento hasheado de la clave de cierre

El sistema MUST persistir `users.clave_cierre` como hash bcrypt (nullable) y MUST NOT almacenar la clave en texto plano. `clave_cierre` MUST incluirse en `$hidden` del modelo `User` para que el hash nunca se serialice, y MUST NOT devolverse en ninguna respuesta.

#### Scenario: Configuración guarda hash

- GIVEN un usuario elegible que configura su clave
- WHEN guarda la clave
- THEN se persiste un hash bcrypt y nunca el texto plano

#### Scenario: Hash no serializado

- GIVEN un usuario con clave configurada
- WHEN se serializa el usuario en cualquier respuesta
- THEN `clave_cierre` queda oculto (ausente del payload)

### Requirement: Formato de clave (PIN numérico)

La clave MUST ser un PIN numérico de 4 a 8 dígitos, con longitud por defecto de 6 (Q5 — pendiente de confirmación de negocio). Un formato inválido MUST responder 422.

#### Scenario: PIN válido de 6 dígitos

- GIVEN una clave de 6 dígitos numéricos
- WHEN se configura
- THEN se acepta

#### Scenario: Formato inválido

- GIVEN una clave con menos de 4 dígitos o no numérica
- WHEN se configura
- THEN responde 422 con errores de validación

### Requirement: Endpoint self-service de configuración

`GET /api/v1/usuarios/clave-cierre` MUST devolver solo `{clave_configurada: bool}` del usuario autenticado. `PUT /api/v1/usuarios/clave-cierre` MUST operar sobre `$request->user()` (self-service: cada usuario cambia SOLO su propia clave) y MUST aceptar `clave_actual` + `clave_nueva`. Si ya existe una clave configurada, el cambio MUST verificar `clave_actual` con `Hash::check` antes de persistir la nueva (Q1); si `clave_actual` no coincide, MUST responder 422 y la clave no cambia. En la primera configuración (sin clave previa) se configura directamente con `clave_nueva`. El endpoint SHALL estar restringido a roles `super_master|master|banca`; otros roles MUST recibir 403.

#### Scenario: Primera configuración

- GIVEN un usuario elegible sin clave configurada
- WHEN hace `PUT` con `clave_nueva` (sin `clave_actual`)
- THEN configura la clave

#### Scenario: Cambio exige clave actual

- GIVEN un usuario con clave configurada
- WHEN hace `PUT` con `clave_actual` correcta y `clave_nueva` nueva
- THEN actualiza la clave

#### Scenario: Clave actual incorrecta

- GIVEN un usuario con clave configurada
- WHEN hace `PUT` con `clave_actual` incorrecta
- THEN responde 422 y la clave no cambia

#### Scenario: Estado booleano

- GIVEN un usuario elegible
- WHEN hace `GET /api/v1/usuarios/clave-cierre`
- THEN recibe `{clave_configurada: true|false}` (nunca el hash)

#### Scenario: Rol no elegible

- GIVEN un usuario con rol fuera de `super_master|master|banca`
- WHEN accede a `GET`/`PUT /api/v1/usuarios/clave-cierre`
- THEN recibe 403

### Requirement: Validación contra la cadena jerárquica de la taquilla

`validarClaveCierre($taquillaId, $clave)` MUST validar la clave contra CUALQUIER usuario elegible de la cadena por encima de la taquilla: (1) usuarios `role=banca` con `banca_id` = banca del grupo de la taquilla; (2) el usuario `role=master` con `id` = `Banca.master_id` de esa banca; (3) todos los `role=super_master`. La clave se valida SIEMPRE contra la cadena de ESA taquilla, incluso si un administrador ejecuta el cierre (Q6). Si NINGÚN candidato tiene clave configurada, la validación MUST fallar (422) con mensaje claro (Q2). La validación MUST usar `Hash::check`; la clave MUST NOT persistirse ni exponerse.

#### Scenario: Clave de la banca de la taquilla

- GIVEN la banca del grupo de la taquilla tiene clave configurada
- WHEN se valida su clave
- THEN la validación es correcta

#### Scenario: Clave del master de la banca

- GIVEN el master de esa banca tiene clave configurada
- WHEN se valida su clave
- THEN la validación es correcta

#### Scenario: Clave de super_master

- GIVEN un `super_master` tiene clave configurada
- WHEN se valida su clave
- THEN la validación es correcta

#### Scenario: Sin candidato con clave

- GIVEN ningún usuario de la cadena tiene clave configurada
- WHEN se valida una clave cualquiera
- THEN falla (422 en el re-cierre)

#### Scenario: Clave incorrecta

- GIVEN candidatos con clave configurada
- WHEN se valida una clave que no coincide con ninguna
- THEN falla (422 en el re-cierre)
