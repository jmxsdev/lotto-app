# Fase 1 — Vocabulario compartido

**Estado:** cerrada. Código, tests dirigidos y suite completa verdes.
**Fecha:** 2026-11-26

## Objetivo

Extraer el vocabulario de roles, niveles, capacidades y perfil de producto, y
reemplazar las duplicaciones principales sin cambiar comportamiento.

## Creados (15)

### Kernel

```
backend/packages/kernel/src/Identidad/Domain/Rol.php
backend/packages/kernel/src/Identidad/Domain/Nivel.php
backend/packages/kernel/src/Jerarquia/Domain/CatalogoNiveles.php
backend/packages/kernel/src/Autorizacion/Domain/Capacidad.php
backend/packages/kernel/src/Autorizacion/Domain/CatalogoCapacidades.php
backend/packages/kernel/src/Autorizacion/Domain/MatrizRolCapacidad.php
backend/packages/kernel/src/Autorizacion/Domain/PerfilProducto.php
backend/packages/kernel/phpunit.xml
```

### Perfil del producto

```
backend/app/Producto/PerfilLoteria.php
```

### Tests

```
backend/packages/kernel/tests/Domain/NivelTest.php
backend/packages/kernel/tests/Domain/RolTest.php
backend/packages/kernel/tests/Domain/CatalogoNivelesTest.php
backend/packages/kernel/tests/Domain/CatalogoCapacidadesTest.php
backend/packages/kernel/tests/Domain/MatrizRolCapacidadTest.php
backend/tests/Unit/Producto/PerfilLoteriaTest.php
```

## Modificados (8)

| Archivo | Cambio |
|---|---|
| `backend/database/seeders/RolesAndPermissionsSeeder.php` | `PERMISSIONS`/`MATRIX` son alias de `PerfilLoteria`; import agregado |
| `backend/app/Models/User.php` | Se retiró `ROLE_PRIORITY`; `primaryRole()` recorre `Rol::cases()` |
| `backend/app/Policies/UserPolicy.php` | Se retiró `RANKS`; `roleRank()` usa `Nivel::jerarquia()` |
| `backend/app/Support/Scope/AlcanceService.php` | Constantes de precedencia usan `Rol::…->value` |
| `backend/app/Http/Controllers/Api/AuthController.php` | `hasAnyRole` usa `Rol` |
| `backend/app/Http/Controllers/Api/JuegoController.php` | 5 arrays de `hasAnyRole` usan `Rol` |
| `backend/app/Http/Controllers/Api/TicketController.php` | 1 array de `hasAnyRole` usa `Rol` |
| `backend/app/Policies/ApuestaPolicy.php` | 1 array de `hasAnyRole` usa `Rol` |

## Eliminados

**Ningún archivo.** Se retiraron las constantes privadas `User::ROLE_PRIORITY`
y `UserPolicy::RANKS`, pero dentro de sus archivos.

## Decisión clave: rango vs jerarquía

`Nivel::rango()` expresa profundidad (super_master 0 → taquilla 5).
`UserPolicy` necesita "mayor número = mayor jerarquía", así que se agregó
`Nivel::jerarquia()` como inverso (5 → 0). Usar `rango()` directo habría
invertido la autorización y permitido escaladas.

## Tests

| Suite | Tests | Assertions | Tiempo |
|---|---:|---:|---:|
| Kernel (`phpunit.xml`) | 22 | 61 | 6 ms |
| `PerfilLoteria` | 4 | 119 | 6 ms |
| Dirigidos de app | 128 | 630 | 193 s |
| Suite completa final | 1.870 | 11.918 | 1.323 s (22m03s) |

Los tests dirigidos incluyen `PermissionCatalogTest`, `GestionUsuariosTest`,
`AlcanceJerarquicoUsuariosTest`, `AlcanceServiceScopeTest`,
`AlcanceEntidadesTest`, `RoleAuthorizationTest`, `DivergenciaRolTest`,
`UsuarioRolApiTest`, `GestionEntidadesApiTest`, `SuperBancaScopeTest` y los
12 contract tests.

## Revisión de vocabulario (2026-11-27)

- `Nivel::esEntidad()` eliminado: era tautológico (siempre `true`). El
  invariante Model B se prueba con `tablaEntidad()` y la unicidad de tablas.
- `CatalogoNiveles::porCodigo()` valida contra `$this->niveles` y lanza
  `InvalidArgumentException` si el nivel no pertenece al catálogo.
- `Nivel::hijos()` eliminado; `CatalogoNiveles::hijosDe()` es la única fuente
  del árbol. `Nivel` queda como metadata del nodo.
- `CatalogoNiveles implements Countable`; `count($catalogo)` funciona.
- Tests: kernel 22/22, 52 assertions; `PerfilLoteria` 4/4, 119 assertions.

## Cierre

- Suite completa final: **1.870 tests, 1.870 passed, 0 skips, 11.918 assertions, 22m03s**, exit 0.
- Log: `/tmp/opencode/phase1-full.log`.
- Opcional/futuro: strings de rol en `CierreService`, `BancaController` y
  `AgenciaController` (`role('master')`, etc.) que aún no usan `Rol`.
- Siguiente fase: entidades raíz `super_masters` + `masters` (Model B).
