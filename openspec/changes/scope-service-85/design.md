# Design: AlcanceService + policies (issue #85)

## Enfoque

Refactor aditivo: `AlcanceService` centraliza el recorrido `rol → columna`; las policies delegan. Controllers conservan status/body/mensaje: scope vía `Builder` y `denegar()` → `abort(403,$msg)`. Sin DTOs, enums, global scopes ni rutas nuevas.

## Decisiones

| # | Decisión | Alternativas | Razón |
|---|---|---|---|
| 1 | `Builder`→`Builder`; `Model` para entidades | DTO `Alcance`; closures | Menos indirección, mismo SQL |
| 2 | 6 policies + 2 existentes; una línea por método | Auto-discovery | No resuelve `CierreCaja`→`CierrePolicy` |
| 3 | `denegar()` = `abort(403,$msg)`; policies `bool` | `Response::deny()` | `bootstrap/app.php` normaliza `AuthorizationException` a `No autorizado.` |
| 4 | Orden por contrato | Orden único; `primaryRole()` | Preserva SQL y multi-rol |
| 5 | Servicio llama `masterBancaIds()`; `User` la define | Mover SQL | PR 5 elimina closures |
| 6 | Paridad observable (IDs/status/body) | Snapshots SQL | SQL-string es frágil |
| 7 | Excluir `AuthController`; `PagoController` no cambia | Migrar los 14 | `Auth` no filtra filas; `Pago` hereda `ApuestaPolicy` |

### Precedencia (primer match gana)

```php
APUESTAS    = taquilla>agencia>grupo>banca>master>super_master  // Apuesta/Estadística/Reporte
TICKETS     = taquilla>grupo>banca>master                       // TicketController
POLICY      = super_master>taquilla>agencia>grupo>banca>master  // ApuestaPolicy
ENTIDADES   = super_master>master>banca>grupo>agencia>taquilla  // entidades/usuarios/cierre-lista
CIERRE_ITEM = taquilla>super_master>master>agencia>banca>grupo
LIMITES     = super_master>master>banca>agencia>grupo
JUEGO_VIS   = banca>master>grupo>agencia>super_master
```

## API (`app/Support/Scope/AlcanceService.php`)

```php
final class AlcanceService
{
    public function applyToApuestas(Builder $q, User $actor): Builder;
    public function applyToTickets(Builder $q, User $actor): Builder;
    public function applyToCierres(Builder $q, User $actor, string $mensaje): Builder;
    public function applyToUsuarios(Builder $q, User $actor, string $mensaje): Builder;
    public function applyToEntidades(Builder $q, User $actor, string $entidad, string $mensaje): Builder;
    public function entidadesVisibles(User $actor, string $tipo, ?array $raiz = null): Collection;
    public function alcanceDeEntidad(User $actor, string $entidad, Model $modelo): bool;
    public function puedeGestionar(User $actor, string $entidad, Model $modelo): bool;
    public function puedeOperarTaquilla(User $actor, Taquilla $taquilla): bool;
    public function bancasDeMaster(User $actor): Collection;
    public function denegar(string $mensaje);
}
```

`$entidad` acepta `banca|grupo|agencia|taquilla|limite` (+`apuesta|ticket|cierre` en `alcanceDeEntidad`/`puedeGestionar`). Los mensajes de vínculo faltante (`No tienes una banca/grupo/agencia/taquilla asociada.`) son constantes; el de rol no soportado lo aporta el controller.

## Flujo

```
Request → role middleware → Controller
  ├─ applyTo* / Policy → AlcanceService → SQL o decisión
  └─ false → denegar(msg) → 403 {"message": msg}
```

## Archivos

| Archivo | Acción | Cambio |
|---|---|---|
| `app/Support/Scope/AlcanceService.php` | Crear | Única jerarquía |
| `app/Policies/{Banca,Grupo,Agencia,Taquilla,Ticket,Cierre}Policy.php` | Crear | Delegación |
| `app/Policies/{Apuesta,User}Policy.php` | Modificar | → servicio |
| `app/Providers/AppServiceProvider.php` | Modificar | 6 `Gate::policy()` |
| 12 controllers de `Api/` | Modificar | Escaleras → `applyTo*`/policy |
| `app/Models/User.php` | Modificar (PR 5) | Solo `masterBancaIds()` |
| `tests/Feature/{AlcanceServiceScope,AlcancePolicies,ArquitecturaAlcance}Test.php` | Crear | Paridad, matriz, grep |
| `docs/dev/alcance-jerarquico.md` + `arquitectura-backend.md` | Crear/Modificar | Modelo y prohibiciones |

## Oleadas y PRs (≤800; objetivo ≤400)

| PR | Contenido | Líneas |
|---|---|---|
| 0a | Servicio + test de paridad | ~330 |
| 0b | 6 policies + proveedor + tests | ~330 |
| 1a | Estadística + Reporte | ~90 |
| 1b | Apuesta + `ApuestaPolicy` | ~80 |
| 1c | Ticket | ~60 |
| 2a | Banca + Agencia | ~130 |
| 2b | Grupo | ~80 |
| 2c | Taquilla | ~160 |
| 3a | User | ~90 |
| 3b | Comisión | ~20 |
| 4a | Cierre | ~160 |
| 4b | Juego (límites + visibilidad) | ~260 |
| 5 | Docs + arquitectura + limpieza | ~200 |

Base `feature/alcance-scope-service-85`; slices locales encadenados; rollback `git revert`; 0a/0b aditivos.

## Tests

| Capa | Qué | Cómo |
|---|---|---|
| Baseline | 13 tests de scope intactos | `composer test` (usuario) |
| Servicio | IDs por rol/método | Dos árboles; IDs |
| Policies | 6 roles × 6 entidades | Tabla de verdad |
| Arquitectura | `Api/` sin helpers | Scan estático |
| E2E | Endpoints migrados | Tests existentes |

## Riesgos

| Riesgo | Mitigación |
|---|---|
| `AuthorizationException` normalizada | `denegar()` con `abort`; policies `bool` solo ahí |
| Multi-rol con órdenes distintos | Constantes por contrato; no `primaryRole()` en filas |
| `JuegoController` 1 291 líneas | Solo migran límites/visibilidad; guards 422 quedan |
| Reglas ocultas: agencia→grupo, cierre ítem taquilla-first | `puedeOperarTaquilla`/`entidadesVisibles` conservan la variante |
| `grep -c "masterBancaIds()"` ambiguo | Verificar `grep -rn -- "->masterBancaIds()" app/` = 1; ajustar spec |
| 0a/0b sin consumidores | Aceptado; tests directos |

## CI/CD

CI solo en `main` (`ci-cd.yml`); la feature no corre Actions. El usuario corre `composer test` y `pint --test` por unidad. Sin migraciones: rollback = revertir imagen (`IMAGE_TAG`).

## Threat Matrix

N/A — sin routing, shell, subprocesos ni integración de procesos.

## Open Questions

- [ ] Criterio `grep -c "masterBancaIds()"`.
- [ ] ¿Publicar slices al cerrar #85 + BE-04?
