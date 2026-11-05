# autorizacion-capability Specification

**Estado**: draft

## Purpose

Migra la autorización de recursos de `role:` a `permission:` en `routes/api.php` y en los controllers `apiResource` con CRUD mixto, cubre las rutas hoy sin permiso (`GET /juegos*`, `GET /resultados*`) y concede reportes/releases a taquilla. Preserva el acceso efectivo y el scoping de filas (#85).

## Requirements

### Requirement: Cero role: en recursos

Toda ruta de recurso MUST usar `permission:` (por grupo o por método). MUST quedar 0 rutas de recurso con `role:`. `role:` MAY quedar solo en login/activación/`verify.mac` (no autorización de recurso).

#### Scenario: Scan 0 role: en grupos de recurso

- GIVEN la migración aplicada
- WHEN un scan busca `role:` en `routes/api.php`
- THEN no queda `role:` en grupos de recurso (solo en login/activación/`verify.mac`)

### Requirement: Cobertura de rutas sin permiso

`GET /juegos*` (index/show/opciones/horarios/reglas) MUST cubrirse con `view_juegos`; `GET /resultados*` (index/show/apariciones) MUST cubrirse con `view_resultados`. Ninguna ruta autenticada de recurso MAY quedar sin `permission:`.

#### Scenario: Juegos legibles por cualquier autenticado

- GIVEN un usuario autenticado con `view_juegos`
- WHEN consulta `/juegos`, `/juegos/{juego}`, `/opciones`, `/horarios` o `/reglas`
- THEN accede; sin `view_juegos` recibe 403

#### Scenario: Resultados legibles por cualquier autenticado

- GIVEN un usuario autenticado con `view_resultados`
- WHEN consulta `/resultados`, `/resultados/{resultado}` o `/apariciones`
- THEN accede; sin `view_resultados` recibe 403

### Requirement: permission: por método en CRUD mixto

En los `apiResource` con CRUD mixto (usuarios, bancas, grupos, agencias, taquillas), el middleware MUST especificar `permission:` por método: view en index/show y manage (o create/edit/delete) en store/update/destroy.

#### Scenario: CRUD mixto con granularidad por método

- GIVEN el `apiResource` de grupos
- WHEN se definen sus rutas
- THEN index/show usan `permission:view_grupos` y store/update/destroy usan `permission:manage_grupos`

### Requirement: Preservación de acceso efectivo

Los roles que hoy pasan `role:` MUST seguir pasando el `permission:` correspondiente según la matriz; los que hoy reciben 403 MUST seguir recibiéndolo. Ninguna migración MAY cambiar quién entra o recibe 403, salvo las concesiones puntuales explícitas de BE-04.

#### Scenario: Rol que hoy pasa sigue pasando

- GIVEN un rol que hoy cruza `role:` en una ruta y tiene el permiso de matriz
- WHEN se migra a `permission:`
- THEN sigue accediendo (mismo status/body)

#### Scenario: Rol que hoy recibe 403 sigue recibiéndolo

- GIVEN un rol que hoy no cruza `role:` en una ruta
- WHEN se migra a `permission:`
- THEN sigue recibiendo 403

### Requirement: role: solo en autenticación

`role:` MUST NOT usarse como autorización de recurso. Su uso restante (login/activación/`verify.mac`) MUST documentarse explícitamente.

#### Scenario: role: residual documentado

- GIVEN la migración aplicada
- WHEN se inspecciona `routes/api.php`
- THEN todo `role:` restante corresponde a login/activación/`verify.mac`, documentado en `docs/dev/`

### Requirement: Taquilla reportes propios y releases

`taquilla` MUST poder consultar sus reportes (row scope ya acotado por #85) y descargar releases.

#### Scenario: taquilla descarga y reporta

- GIVEN un usuario `taquilla`
- WHEN accede a reportes propios o descarga un release
- THEN pasa por `view_reports` y `download_release`; las filas quedan acotadas por #85

### Requirement: Tests y scan de autorización

Un test/scan MUST verificar 0 `role:` en grupos de recurso y MUST prohibir permisos directos a usuarios. `composer test` y `pint --test` MUST quedar en verde.

#### Scenario: Suite y scan verdes

- GIVEN la migración y tests actualizados
- WHEN se ejecuta `composer test` y `pint --test`
- THEN pasan en verde y el scan de `role:`/permisos directos no reporta violaciones

### Requirement: Sin cambios de scoping ni negocio

El scoping de filas (#85) y la lógica de negocio MUST NOT cambiar. La migración solo reemplaza la capa de middleware de autorización de recurso.

#### Scenario: Alcance intacto

- GIVEN un endpoint migrado
- WHEN un rol consulta un listado o detalle
- THEN el scoping de filas (#85) y los mensajes de denegación permanecen idénticos
