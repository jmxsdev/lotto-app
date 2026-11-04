# Alcance jerárquico

> Cómo se decide **qué filas ve y qué puede tocar** cada usuario (issue #85).
> Complementa a [`arquitectura-backend.md`](arquitectura-backend.md) (mapa del
> backend) y a [`estructura.md`](estructura.md) (mapa del monorepo).

## 1. Regla única

`App\Support\Scope\AlcanceService` es la **única implementación** del recorrido
jerárquico `taquilla → grupo → banca` y `taquilla → agencia → grupo → banca`.
Los controllers y las policies delegan en él; ninguno reimplementa la jerarquía
ni compara roles para decidir qué filas devuelve un listado.

La capa de bajo nivel sigue viviendo en `App\Models\User`:

| Helper | Qué devuelve |
|---|---|
| `masterBancaIds()` | IDs de las bancas cuyo `master_id` es el actor |
| `masterBancaScope($column)` | Closure para queries con columna directa de banca (`banca_id`, o `id` en `Bancas`) |
| `masterBancaChainScope()` | Closure para queries que cuelgan de `taquilla.grupo.banca` (apuestas, tickets, cierres) |
| `masterBancaGroupScope($relation)` | Closure para queries cuyo primer eslabón es el grupo (taquillas, agencias) |
| `masterCanAccessBanca($id)` | ¿El master administra esa banca? |

## 2. Modelo de roles y columnas

| Rol | Ancla | Dónde vive |
|---|---|---|
| `super_master` | ninguna | rol de spatie (`model_has_roles`); alcance global |
| `master` | `bancas.master_id` | relación 1→N: un master administra varias bancas |
| `banca` | `users.banca_id` | |
| `grupo` | `users.grupo_id` | `grupos.banca_id` |
| `agencia` | `users.agencia_id` | `agencias.grupo_id` |
| `taquilla` | `users.taquilla_id` | `taquillas.grupo_id` + `taquillas.agencia_id` (nullable) |

Notas del modelo:

- Los roles **no** viven en `users.role`: esa columna se eliminó y la autoridad
  es spatie (`HasRoles`, guard `api`). Un usuario puede tener varios roles.
- `users.created_by` es **metadata de fila** (quién creó al usuario), no una
  capability. Solo `applyToUsuarios` la usa para ampliar la visibilidad del
  creador; nunca autoriza acciones.
- La cadena es `super_master ⊃ master ⊃ banca ⊃ grupo ⊃ agencia ⊃ taquilla`.
  `agencia` cuelga de `grupo`; `taquilla` cuelga de `grupo` y, cuando existe,
  de `agencia`.
- Con multi-rol decide la **precedencia del contrato**, no `primaryRole()`.

## 3. Precedencias por contexto

Cada constante de `AlcanceService` fija el orden de evaluación; **el primer
match gana**. El orden es parte del contrato observable de cada endpoint y no
se debe "simplificar" a un orden único.

| Constante | Orden | Consumidores | Cuándo usarla |
|---|---|---|---|
| `APUESTAS` | `taquilla → agencia → grupo → banca → master → super_master` | `applyToApuestas`: `ApuestaController`, `EstadisticaController`, `ReporteController` | listados de apuestas; `super_master` sin filtro |
| `TICKETS` | `taquilla → grupo → banca → master` | `applyToTickets`: `TicketController` | listados y ganadores de tickets; `agencia` y `super_master` quedan sin filtro (el fail-closed vive en `autorizarScope`) |
| `POLICY` | `super_master → taquilla → agencia → grupo → banca → master` | `ApuestaPolicy` (`view`, `motivoNoAnulable`) | decisión sobre una apuesta concreta |
| `ENTIDADES` | `super_master → master → banca → grupo → agencia → taquilla` | `applyToEntidades`, `applyToUsuarios`, `applyToCierres`, `alcanceDeEntidad` (banca/grupo/agencia/taquilla/limite/cierre) | listados y CRUD de entidades, usuarios y cierres |
| `CIERRE_ITEM` | `taquilla → super_master → master → agencia → banca → grupo` | `puedeOperarTaquilla`, `TaquillaPolicy::operar` | operar una taquilla concreta (cierre de caja); la rama `agencia` acepta agencias del mismo grupo |
| `LIMITES` | `super_master → master → banca → agencia → grupo` | `applyToEntidades('limite')`, `alcanceDeEntidad('limite')`, `puedeGestionar('limite')` | límites de juego |
| `JUEGO_VIS` | `banca → master → grupo → agencia → super_master` | `entidadesVisibles` (consumido por `JuegoController`) | matriz de visibilidad de Juego; la escalera vive en el servicio y **no** se reutiliza `applyToEntidades` |

Cómo elegir el contexto:

1. **Listado de filas** → `applyToApuestas`, `applyToTickets`, `applyToCierres`,
   `applyToUsuarios` o `applyToEntidades` según el recurso.
2. **Un modelo concreto** → `alcanceDeEntidad($actor, $entidad, $modelo)` para
   leer y `puedeGestionar(...)` para escribir/borrar.
3. **Operación sobre una taquilla** → `puedeOperarTaquilla(...)` (regla de
   cierre), no `puedeGestionar('taquilla')`.
4. **Ownership por apuesta** → la policy (`POLICY`).
5. Si el contrato real difiere de la constante, **agregá una constante nueva y
   documentala**; no reutilices una cuyo orden cambie el resultado.

Advertencia: `ENTIDADES`, `CIERRE_ITEM` y `JUEGO_VIS` producen resultados
distintos para el mismo rol. Por ejemplo, con `agencia` + `tipo=grupo`,
`JUEGO_VIS` devuelve "su grupo" mientras `ENTIDADES` denegaría; con `agencia`
y una taquilla de otra agencia del mismo grupo, `puedeOperarTaquilla` acepta y
`puedeGestionar('taquilla')` rechaza.

`idsPorTipo` (expansión de alcance de Juego) conserva el orden de ramas
original del controller, que **no** coincide con `JUEGO_VIS`: evalúa
`banca|agencia` antes que `master`, y en el tipo grupo `grupo|agencia` cae en
`[$actor->grupo_id]`. Está en el servicio y no debe "simplificarse" a otra
constante.

## 4. Reglas clave

- **Master sin bancas ve NADA, nunca global.** Los helpers de `User` devuelven
  `whereRaw('1=0')` cuando `masterBancaIds()` está vacío. No agregues un
  "fallback global" para masters sin bancas.
- **`created_by` es metadata, no capability.** Sirve para que un usuario vea a
  quien creó; no lo uses para autorizar acciones ni para filtrar entidades.
- **`super_master` es global** únicamente en los contextos donde la constante
  lo incluye. En `TICKETS` y `JUEGO_VIS` la rama significa "sin filtro", no un
  chequeo explícito.
- **Fail-closed.** Un rol fuera del contrato termina en `denegar()` (403).
  Si falta el vínculo (banca/grupo/agencia/taquilla) se usa la constante
  `MENSAJE_SIN_*` correspondiente; si el rol no está soportado, el mensaje lo
  aporta el controller.
- **Multi-rol.** Decide el primer rol presente en el orden de la constante. No
  uses `primaryRole()` para filas: cambia el SQL y el status observado.
- **Una sola definición de `masterBancaIds()`.** Vive en `User`; el único
  consumidor externo es `AlcanceService`. Los controllers no la llaman.

## 5. Cómo migrar un endpoint nuevo

1. Identificá el contexto (tabla de §3) y la constante que corresponde.
2. **Listados:** reemplazá la escalera `rol → columna` por el `applyTo*`
   correspondiente. No escribas `hasRole`/`hasAnyRole` para filtrar filas.

   ```php
   // NO
   if ($user->hasRole('banca')) {
       $query->where('banca_id', $user->banca_id);
   } elseif ($user->hasRole('grupo')) {
       $query->where('grupo_id', $user->grupo_id);
   } // ...

   // SÍ
   $query = $this->alcance->applyToEntidades(
       $query,
       $user,
       'taquilla',
       'No tienes permiso para ver taquillas.',
   );
   ```

3. **Detalle y CRUD:** `alcanceDeEntidad` para lectura y `puedeGestionar` para
   escritura. La policy delega en una línea; no reimplementa la matriz.
4. **Denegación:** `$this->alcance->denegar($mensaje)` con el **texto exacto**
   del endpoint original.
5. **Contrato:** preservá status, body y mensaje. Los tests de scope existentes
   deben pasar sin modificarse.
6. **Verificación:** corré el test de scope del recurso y
   `php artisan test --filter=ArquitecturaAlcanceTest`. No agregues llamadas a
   `masterBancaIds()` fuera de `User`/`AlcanceService`.

## 6. Denegación

- `AlcanceService::denegar($mensaje)` es el mecanismo único **de la capa de
  alcance** para decidir filas o acceso: `abort(403, $mensaje)`.
- La **autorización de acción puntual** MAY conservar su `abort(403, $mensaje)`
  local, con status y body idénticos; esas ramas no deciden qué filas se ven
  (REQ-5).
- Las policies devuelven `bool`. **No** se usan para emitir mensajes propios:
  `bootstrap/app.php` normaliza `AuthorizationException` a `No autorizado.`, así
  que una denegación de filas con mensaje custom se hace con `denegar()`.
- Excepciones de paridad exacta de body/status:
  - `CierreController` captura el 403 del servicio y responde
    `response()->json(['message' => ...], 403)`, porque `abort()` agrega
    metadata de depuración con `APP_DEBUG=true`.
  - `TicketController::show` responde
    `response()->json(['message' => 'No autorizado.'], 403)` para conservar el
    body previo; el chequeo de alcance sigue siendo fail-closed.

## 7. Excepciones deliberadas

Estas reglas **no** se delegan en `AlcanceService` a propósito. Si las movés,
cambiás el contrato observable:

- **`CierreController::assertTaquillaEnAlcance` y `resolveTaquillaParaCierre`.**
  Son autorización de acción puntual. El contrato de cierre exige que la
  agencia sea **dueña** de la taquilla; `puedeOperarTaquilla` acepta agencias
  del mismo grupo y `puedeGestionar('taquilla')` usa `ENTIDADES` (perdería la
  precedencia taquilla-first). Los `abort` locales se conservan.
- **`puedeGestionarTaquilla` vs `puedeOperarTaquilla`.**
  `puedeGestionarTaquilla` es estricto: la agencia solo gestiona su propia
  taquilla. `puedeOperarTaquilla` (regla `CIERRE_ITEM`) permite operar
  taquillas del mismo grupo. Usá el primero para escritura y el segundo solo
  para cierre/operación.
- **`AgenciaController::update`.** La rama `hasRole('agencia')` protege la
  ficha propia y sus campos inmutables; es autorización de acción, no filtrado
  de filas.
- **`TaquillaController::authorizeGrupoAccess` / `authorizeAgenciaAccess`.**
  Chequeos de acción puntual sobre la relación padre de una taquilla; no
  deciden listados.
- **`AuthController`.** Fuera del alcance del refactor: no filtra filas.

## 8. Verificación

| Comando | Qué prueba |
|---|---|
| `php artisan test --filter=ArquitecturaAlcanceTest` | Scan estático: `AlcanceService` final, sin `masterBancaIds` en controllers, una definición en `User` y un único consumidor externo |
| `php artisan test --filter=AlcanceServiceScopeTest` | Paridad del servicio con dos árboles |
| `php artisan test --filter=AlcanceJuegoTest` | `entidadesVisibles` (`JUEGO_VIS`), `idsPorTipo` y `bancasParaComisiones` con dos árboles |
| `php artisan test --filter=AlcancePoliciesTest` | Matriz rol × entidad de las policies |

`ArquitecturaAlcanceTest` es conservador a propósito: verifica archivos y
propiedades estructurales, no SQL. Si falla, alguien volvió a dispersar la
jerarquía fuera de `app/Models/User.php` y `app/Support/Scope/AlcanceService.php`.
