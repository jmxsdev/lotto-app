# Exploración: kernel-identidad-jerarquia

**Estado:** borrador
**Fecha:** 2026-11-24
**Cambio:** `kernel-identidad-jerarquia`

> **Nota de lectura (2026-11-24).** Este documento es la instantánea de la
> exploración. El modelo objetivo vigente es el **Modelo B**, descrito en
> `design.md`: los seis niveles son entidades y cada una tiene exactamente un
> operador activo. Donde este documento describa el estado actual (por ejemplo,
> que `master` es un usuario), es historia, no destino.
>
> **Administración de sistema (2026-11-24).** Se agrega un principal
> `system_admin` fuera del árbol, con alcance global auditado, más un rol de BD
> `readonly_analytics` de solo lectura. El super_master sigue viendo solo su
> árbol; el system_admin es el único actor global.

## 1. Objetivo

Extraer un kernel de dominio sin framework para **identidad, jerarquía y autorización** desde el monolito Laravel actual, de modo que:

1. La jerarquía de seis niveles (`super_master > master > banca > grupo > agencia > taquilla`) y sus reglas de alcance tengan una sola implementación testeable.
2. Los invariantes de dominio sean artefactos explícitos y ejecutables, en lugar de estar repartidos entre controladores, políticas, servicios y seeders.
3. Un segundo producto PHP/Laravel (apuestas deportivas) pueda consumir el mismo kernel como paquete Composer y aportar su propia matriz de capacidades.
4. El contrato HTTP se mantenga compatible: `panel/` y el cliente de escritorio `taquilla/` deben seguir funcionando sin un release.

Fuera del alcance de este cambio: la matemática de comisiones/distribución (diferida a un cambio posterior), pagos, tickets, juegos y reportes.

## 2. Restricciones duras

| Restricción | Fuente |
|---|---|
| Las respuestas, rutas, payloads y códigos de error de la API no deben cambiar | Decisión de producto, esta sesión |
| La suite existente (~214 tests) debe seguir verde en cada etapa | `backend/tests/` |
| Spatie sigue siendo la única fuente de roles; `users.role` ya fue eliminada | `openspec/specs/autorizacion-rol-unico/spec.md` |
| `AlcanceService` sigue siendo `final` y es el único recorrido de jerarquía hasta que se reemplace | `tests/Feature/ArquitecturaAlcanceTest.php:48-94` |
| El kernel no debe importar Laravel (`Illuminate\*`, `App\*`) | Test de frontera nuevo |
| El paquete objetivo es PHP 8.3+, Composer, sin dependencia de framework | `backend/composer.json` |

## 3. Decisiones de producto tomadas

| Decisión | Elección |
|---|---|
| Objetivo de reutilización | PHP/Laravel → el kernel se entrega como paquete Composer |
| Alcance del primer cambio | Solo identidad + jerarquía + permisos |
| Vocabulario de niveles | Los mismos seis niveles para todos los productos; las capacidades cambian por producto |
| Compatibilidad de API | Compatible para panel/taquilla; un cambio de comportamiento deliberado (inmutabilidad del rol, ver abajo) |
| Modelo de despliegue | Despliegues separados por producto; sin dimensión de tenant/producto en los agregados |
| Operadores por entidad | Exactamente un operador **activo** por entidad; invariante duro, respaldado por la base |
| Mutabilidad del rol | Inmutable una vez creado. Reenviar el mismo rol es idempotente; un rol distinto se rechaza. Sin flujo de promoción. |
| Reglas de alcance | Idénticas en todos los productos; solo varía la matriz de capacidades |
| Cardinalidad de super_master | **Varios super_masters, cada uno dueño de un árbol aislado.** Requiere un vínculo estructural `master → super_master` y elimina el bypass global. |
| Administración de sistema | Principal `system_admin` fuera del árbol + rol de BD `readonly_analytics` de solo lectura; es el único actor global. |

### Inmutabilidad del rol: contradicción verificada y resolución

La regla de negocio "si eres taquilla, sigues siendo taquilla" contradice la implementación actual:

- `PUT /api/v1/users/{id}` acepta `role` (`UserController.php:168`), llama a `syncRoles` (`:253`) y emite `user.role_changed` (`AuditAction.php:28`, `AuditoriaRolTest.php:42`).
- El panel siempre envía `role` en la edición, incluso cuando no cambió (`panel/src/pages/usuarios.astro:171`, `panel/src/pages/bancas/detalle.astro:597`, `grupos/detalle.astro:676`, `taquillas/detalle.astro:611`).

Resolución para este cambio:

1. Mismo rol en el payload = no-op (mantiene funcionando las versiones actuales del panel).
2. Rol distinto = `422` con un código de error de dominio.
3. El formulario de edición del panel deja de ofrecer el selector de rol; el rol solo se elige al crear.
4. `user.role_changed` y `AuditoriaRolTest` se retiran o se reemplazan por el test de inmutabilidad.

### Un operador por entidad: brecha verificada

- No existe índice único sobre `users.banca_id/grupo_id/agencia_id/taquilla_id`.
- Los datos locales de desarrollo son 1:1 (1 banca, 1 grupo, 1 agencia, 3 taquillas con 3 usuarios), pero eso es un seed, no evidencia de producción.
- MySQL 8 no tiene índices parciales. La garantía requiere una columna generada + índice único por nivel, o una tabla `vinculos_operador` con una clave única `(entidad_tipo, entidad_id)`.
- Bloqueador de despliegue: antes de aplicar la restricción hay que auditar duplicados activos en producción.

### Super master como raíz: consecuencias de la decisión

> Esta tabla refleja el análisis previo al Modelo B. El destino vigente está en
> `design.md`: `masters.super_master_id` y `bancas.master_id` → `masters.id`.

| Área | Cambio |
|---|---|
| Esquema | Nuevo `users.super_master_id` nullable (obligatorio para `master` tras el backfill; nulo para el resto). `bancas.master_id` ya vincula master → bancas. |
| Backfill | No hace falta: el sistema está en desarrollo. Se reconstruye con `migrate:fresh` + seed; el vínculo puede ser obligatorio desde el primer día. |
| Alcance | `Alcance::Global` desaparece. Cada actor tiene raíces; las raíces de un super_master son las bancas de sus masters. El acceso entre árboles está prohibido, incluso entre super_masters. |
| Bypass de capacidades | `super_master` conserva el bypass, pero **acotado a su propio árbol**. Ya no es un override global. |
| Provisioning | Crear un super_master no es CRUD normal de usuarios; es una operación de instalación/provisioning. Se mueve a un caso de uso y comando dedicados. |
| Tests | Todo test que asuma un super_master global debe reescribirse para construir árboles por super_master y verificar aislamiento. Es un cambio de comportamiento, no un refactor. |
| Despliegue | Etapa separada y reversible, con su propio PR. La restricción y el cambio de alcance llegan después de la auditoría, no con la primera extracción. |

### La creación de super_master es solo por provisioning (decisión 2026-11-24)

El endpoint HTTP **no se borra**: `POST /users` sigue creando todos los demás niveles. Lo que se deshabilita es el *caso* `super_master`.

| Capa | Cambio |
|---|---|
| `POST /users` | Quitar `super_master` del enum de roles de `Rule::in([...])` → `422`. Agregar un código de error de dominio (`super_master_requires_provisioning`). |
| `PUT /users/{id}` | Mantener `super_master` en el enum solo por idempotencia. Enviar un rol distinto se rechaza por la regla de inmutabilidad. |
| `UserPolicy::canCreate` | Quitar el atajo "un super_master puede crear otro super_master". Un super_master sigue creando `master` y hacia abajo, pero solo dentro de su árbol. |
| Panel | `assignableRoles()` empieza en `master` para un super_master; el campo de rol es de solo lectura en edición. Las filas de super_master existentes siguen mostrando su rol actual. |
| Provisioning | Nuevo comando Artisan (nombre de trabajo `super-master:provision`) respaldado por un caso de uso `CrearSuperMaster`: email único, política de password, sin vínculo, sin padre, activo, auditoría `super_master.provisioned` con `actor_type=system`, confirmación obligatoria en producción. |
| Datos existentes | Sin cambios. Los super_masters ya provisionados siguen funcionando; la API deja de crear nuevos. |

## 4. Mapa del estado actual (condensado)

### 4.1 Autenticación

- `POST /api/v1/login` → `App\Http\Controllers\Api\AuthController::login` (`AuthController.php:20-137`). Una `DB::transaction` que envuelve: verificación de credenciales, chequeo de activo, chequeo de rol, política de dispositivo de taquilla (MAC + fingerprint + `X-Device-Fingerprint`), activación efectiva de la cadena, gating por `X-Panel`, emisión de token, proyección de permisos y auditoría.
- Token: personal access token de Sanctum, nombre `taquilla-token` para todos los roles (`AuthController.php:117`). Expiración 1440 min (`config/sanctum.php:53`).
- Logout revoca solo el token actual (`AuthController.php:146-151`). No hay revoke-all ni refresh.
- Verificación de dispositivo por request: `App\Http\Middleware\VerifyMac` (`:18-94`), no-op para roles distintos de taquilla, revalida la cadena y escribe `last_connection_at` en cada request.
- Protección de rutas: `permission:` en todas, `auth:sanctum` + `verify.mac` para todo excepto `/user`, `/logout` y `clave-cierre` (`routes/api.php:55-335`).
- Alias muerto: `role_or_permission` registrado pero nunca usado (`bootstrap/app.php:41`).

### 4.2 Usuario y jerarquía

- `App\Models\User` desnormaliza la cadena en `banca_id`, `grupo_id`, `agencia_id`, `taquilla_id` más `created_by` (`User.php:43-54`).
- La jerarquía es un **árbol de seis niveles ordenados**: `super_master → master → banca → grupo → agencia → taquilla`. Cada nodo tiene un padre y N hijos; un master posee N bancas. `Agencia hasMany Taquilla` (`Agencia.php:32-35`), `Taquilla belongsTo Agencia` (`Taquilla.php:36-39`), y la cadena de activación es `taquilla → agencia → grupo → banca` (`openspec/specs/jerarquia-agencias/spec.md`).
- **En la implementación actual, el árbol empieza en `master`, no en `super_master`.** No hay `super_master_id` ni columna de padre para los usuarios raíz: `bancas.master_id` es el único vínculo estructural de raíz, y `users.created_by` es metadata de auditoría (INV-I7). `UserPolicy::canCreate` permite que un super_master cree otro super_master (`:82-88`), así que varios super_masters conviven con un bypass global y sin aislamiento entre ellos.
- La persistencia *parece* un DAG porque `taquillas.grupo_id` se conserva como atajo desnormalizado ("Conservación de grupo_id" en la misma spec). Es una proyección para queries, **no un vínculo de padre**. `AlcanceService` mezcla ambas lecturas: `$taquilla->grupo?->banca_id` (`:455`) se salta la agencia, mientras que `$taquilla->agencia_id` (`:471`) la usa. Esa inconsistencia es una fuga del modelo, no una regla de dominio.
- Regla de negocio: una taquilla siempre tiene agencia, y una agencia siempre tiene grupo. La columna nullable existe solo por razones legacy/de backfill.
- `bancas.master_id` vincula a un master de super-banca con sus bancas (`Banca.php:13-16`).
- Vocabulario de niveles duplicado en ≥10 lugares: `User::ROLE_PRIORITY` (`:21-28`), `UserPolicy::RANKS` (`:22-29`), ocho constantes de precedencia en `AlcanceService` (`:37-139`), `RolesAndPermissionsSeeder::MATRIX` (`:31-147`) y 12 arrays literales de `hasAnyRole([...])` repartidos por los controladores.

### 4.3 Autorización

- 36 permisos definidos en código en `RolesAndPermissionsSeeder::PERMISSIONS` (`:31-66`); conteos de la matriz: master 35, banca 24, grupo 21, agencia 17, taquilla 12, super_master 0.
- La matriz de capacidades **no es nueva**: la introdujo el cambio archivado `2026-11-05-be04-permisos` (specs `catalogo-permisos`, `autorizacion-capability`), precedido por `2026-11-03-autorizacion-rol-unico`. El kernel debe preservar la matriz exacta de 36 permisos, no rediseñarla.
- Bypass de `super_master` vía `Gate::before`, restringido a abilities del catálogo (`AppServiceProvider.php:64-74`).
- Ocho políticas. Las de entidad son envoltorios finos sobre `AlcanceService`; `UserPolicy` tiene lógica propia de rango/subárbol (`UserPolicy.php:31-296`).
- Conviven mecanismos en paralelo: `permission:` en la ruta + `hasAnyRole` en el controlador + políticas + llamadas directas a `AlcanceService` + queries de Spatie `role()` dentro de reglas de negocio (`CierreService.php:282-293`, `BancaController.php:75`, `AgenciaController.php:308`).
- Deriva de spec: `comisiones/spec.md:39` dice que `manage_comisiones` pertenece a master, pero el seeder solo le da `view_comisiones`.

### 4.4 Alcance

- `App\Support\Scope\AlcanceService`: 1.363 líneas, `final`, 59 call sites.
- Ocho contratos de precedencia, gana el primero: `APUESTAS`, `TICKETS`, `POLICY`, `ENTIDADES`, `CIERRE_ITEM`, `LIMITES`, `JUEGO_VIS`, `AUDITORIA` (`:37-139`).
- Mezcla tres preocupaciones: política de roles, construcción de queries Eloquent y denegación HTTP (`abort(403)` en `:695-698`).
- Excepción fail-open documentada: `applyToTickets` devuelve una query sin filtrar para roles fuera de `TICKETS` (`:205`) y `ticketVisible` devuelve `true` (`:1116-1118`).
- Visibilidad y gestión divergen: `agenciaVisible` es verdadero para el local propio (`:959-962`) y `puedeGestionarAgencia` también (`:1245-1246`), pero `AgenciaController::authorizeGestion` bloquea por completo el rol agencia (`:389-393`).
- Resolución de cadena reimplementada en ≥8 módulos: helpers de master en `User` (`:181-245`), `AlcanceService`, `UserPolicy` (`:235-296`), `AuditScopeResolver` (`:36-76`), `ActivacionEfectivaService` (`:62-83`), `ApuestaService` (`:120-229`), `ComisionService` (`:158-177`), `JuegoLimiteService` (`:232-259`).

### 4.5 Distribución de comisiones (solo contexto, fuera de alcance)

- `ComisionService` (584 líneas) implementa la cascada de tasas taquilla → grupo → banca → default global, topes por tipo (animalitos 16, tripletas 25, otros 100) y el clamp de lectura `liquidable = min(efectiva, 100 − Σ tasas propias de ancestros, tope)` (`:118-151`).
- Dos implementaciones paralelas, individual y bulk, que deben mantenerse sincronizadas (`:118-151` vs `:347-508`).
- No hay validación de escritura de que la cadena sume ≤100; el invariante emerge del clamp de lectura.
- `juego_limites.participacion` está **dormida**: solo CRUD y validación de rango; ningún cálculo la lee.
- El cambio activo `comision-cierre-cadena` (issue #65) documenta un bug de sobreconteo en `CierreService::comisionCadena` (`:430-445`) y sigue en pre-propuesta. Su `explore.md` referencia métodos de ledger eliminados y debe re-derivarse.
- **El diseño de reparto sí existe ahora**: `docs/dev/diseno-reparto-participaciones.md` (1.161 líneas, sin OpenSpec). Sustituye la comisión sobre ventas por un reparto sobre margen, con tabla nueva `repartos` y `puntos_base`. Este cambio no lo implementa; el módulo `Distribucion` se diseñará contra ese documento.

### 4.6 Tests que ya congelan invariantes

- `ArquitecturaAlcanceTest`, `RoutePermissionCoverageTest`, `PermissionCatalogTest`: invariantes de arquitectura y catálogo.
- `AlcanceServiceScopeTest`, `AlcanceEntidadesTest`, `AlcanceJuegoTest`, `AlcancePoliciesTest`, `SuperBancaScopeTest`, `AgenciaMasterScopeTest`: comportamiento de alcance.
- `GestionUsuariosTest`, `AlcanceJerarquicoUsuariosTest`, `CreatedByTest`, `RoleAuthorizationTest`, `DivergenciaRolTest`, `AgenciaScopeTest`: identidad y jerarquía.
- Brechas: no hay test de cierre con varias taquillas, ni de throttle/expiración/revoke-all del login, ni de suma de cadena al escribir.

### 4.7 Auditoría

- El módulo vive en `app/Audit`: `AuditAction`, `AuditEventData`, `AuditRecorder`, `AuditScopeResolver`, `AuditRedactor`, `AuditChainHasher`, `AuditQueryService`.
- Tablas `audit_events` y `audit_chain_state`, con triggers append-only y cadena de hash.
- Cubre todos los dominios: autenticación, usuarios, entidades, juegos, resultados, apuestas, pagos, cierres, comisiones, exportación y lectura.
- El refactor extrae el contrato genérico al kernel (`Auditoria`), deja el catálogo de acciones del producto en la app y mueve la implementación a `kernel-laravel`.
- El resolver actual (`AuditScopeResolver`/`AuditScope`) solo cubre banca/grupo/agencia/taquilla porque master/super_master eran usuarios. Con Model B se extiende a los seis y se agregan `super_master_id`/`master_id` a `audit_events`.
- Decisión: la auditoría es transversal a todo el sistema; cada módulo decide qué emite, y Auditoría centraliza registro, evidencia, consulta, exportación y retención.

## 5. Causas raíz (ordenadas por impacto en el refactor)

1. **No hay vocabulario compartido.** El concepto de rol/nivel/rango existe en al menos diez formas. Cada regla nueva hay que editarla en varios sitios, y pueden divergir (ya lo hacen).
2. **No hay abstracción de jerarquía.** La resolución de cadena se copia por módulo, cada una con su propia variante fail-closed.
3. **El alcance mezcla política y persistencia.** `AlcanceService` no se puede testear como política pura y fuerza `abort()` dentro de decisiones de dominio.
4. **No hay frontera de agregado para el usuario.** `UserController` valida, deriva vínculos relevantes para autorización, escribe, sincroniza Spatie y audita en un solo método.
5. **El provisioning de entidades está copiado.** Banca/Grupo/Agencia/Taquilla crean entidad + usuario operador + límites + auditoría en una transacción, con chequeos de acceso duplicados.
6. **La autorización está en capas, pero no es ortogonal.** Permiso de ruta, chequeos de rol en controladores, políticas y llamadas directas al alcance se solapan; "quién puede hacer qué" no tiene una sola respuesta.

## 6. Lo que ya está bien (no romper)

- Spatie como única fuente de roles; `users.role` ya eliminada.
- Comportamiento fail-closed para master sin bancas y para usuarios sin rol.
- Catálogo de capacidades dirigido por datos y sembrado de forma idempotente, con evento de auditoría en la sincronización.
- El bypass de `super_master` está correctamente acotado a abilities del catálogo.
- Cobertura fuerte de tests de feature para jerarquía y alcance.

## 7. Arquitectura objetivo

```
backend/
  packages/kernel/                     # paquete Composer, PSR-4 "Kernel\"
    composer.json                      # php ^8.3, sin illuminate/*, sin laravel/framework
    src/
      Identidad/
        Domain/       Usuario, UsuarioId, Email, PasswordHash, Rol, Nivel, EstadoUsuario
        Application/  CrearUsuario, CrearEntidadConOperador, CrearSuperMaster, DesactivarUsuario, AutenticarCredenciales
        Ports/        UsuarioRepository, RoleAssigner, PasswordHasher, TokenIssuer, Reloj
      Jerarquia/
        Domain/       Nivel, CadenaJerarquica, NodoId, PerfilJerarquia
        Application/  PoliticaAlcance (ver / gestionar / operar), ResolverCadena
        Ports/        JerarquiaRepository
      Autorizacion/
        Domain/       Capacidad, CatalogoCapacidades, MatrizRolCapacidad, PerfilProducto
        Application/  EvaluarAcceso
      Auditoria/
        Domain/       EventoDominio
  app/
    Adapters/
      Eloquent/       repositorios y decoradores de query
      Sanctum/        tokens, device binding
      Spatie/         RoleAssigner
    Http/Controllers/ delgados: request → caso de uso → resource
```

### Mecanismo de reutilización

- `backend/packages/kernel` es un path repository (`composer.json` → `repositories: [{type: path}]`).
- La matriz de capacidades de lotería se convierte en una instancia de `PerfilProducto` (`lotería`: 6 niveles, 36 capacidades, matriz exacta).
- El sistema de apuestas depende del paquete y aporta su propio `PerfilProducto`.
- Un test de frontera falla si algún archivo bajo `packages/kernel/src` importa `Illuminate\`, `App\` o llama un helper global.

## 8. Catálogo borrador de invariantes

> Catálogo borrador de la exploración. El catálogo vigente, con Modelo B, está en
> `design.md` §2.4.

### Identidad

| ID | Invariante |
|---|---|
| INV-I1 | Un usuario tiene exactamente un rol. Varios roles están prohibidos en la frontera del dominio. |
| INV-I2 | El nivel de jerarquía se deriva del rol; nunca se almacena en paralelo. |
| INV-I3 | El vínculo es una sola entidad del nivel del rol: super_master → super_masters, master → masters, banca → bancas, grupo → grupos, agencia → agencias, taquilla → taquillas. |
| INV-I4 | El nivel taquilla requiere `taquilla_id` y una cadena de padres activa para autenticarse. |
| INV-I5 | Un usuario sin rol no puede autenticarse: 403, nunca acceso global. |
| INV-I6 | Un super_master es intocable desde otro árbol: nadie lo borra ni lo degrada. |
| INV-I7 | `created_by` es metadata de auditoría, nunca una capacidad. |
| INV-I8 | El rol es inmutable: se asigna una vez al crear y nunca cambia. No hay promoción, degradación ni traslado entre niveles. |
| INV-I9 | Una entidad tiene como máximo un operador activo. Reemplazar un operador exige desactivar antes al usuario anterior. |
| INV-I10 | Reenviar el rol actual es idempotente; un rol distinto es error de dominio, no fallo de autorización. |
| INV-I11 | Un super_master se crea por provisioning, no por el CRUD normal de usuarios. No pertenece a otro árbol y no puede ser adoptado por otro super_master. |
| INV-I12 | `super_master` no es un rol asignable por HTTP. La API lo rechaza; solo el comando de provisioning puede crear uno. |

### Jerarquía y alcance

| ID | Invariante |
|---|---|
| INV-H1 | Cada rol ve, gestiona y opera solo su propio subárbol. Pares y superiores están prohibidos. |
| INV-H2 | Un master sin bancas obtiene un conjunto vacío, nunca un fallback global. |
| INV-H3 | `ver`, `gestionar` y `operar` son tres decisiones independientes sobre el mismo nodo. |
| INV-H4 | La cadena es lineal: `super_master → master → banca → grupo → agencia → taquilla`. Una taquilla tiene exactamente una agencia; `taquillas.grupo_id` se deriva y nunca es vínculo de padre. |
| INV-H7 | Toda taquilla tiene agencia y toda agencia tiene grupo. `NULL` es deuda técnica del backfill, no un estado válido del dominio. |
| INV-H8 | El árbol empieza en `super_master`: todo master pertenece a exactamente un super_master, y el alcance de un super_master es su propio árbol. |
| INV-H9 | Ningún actor **de negocio** es global. El único global es `system_admin`, fuera del árbol y auditado; el acceso entre árboles de super_master sigue prohibido. |
| INV-H5 | Las decisiones de autorización son puras: devuelven allow/deny, nunca `abort()` ni queries Eloquent. |
| INV-H6 | `permission:` en la ruta es la puerta gruesa; la política de dominio es la fina. Cualquier otro chequeo de rol es un defecto. |

### Perfil de producto

| ID | Invariante |
|---|---|
| INV-P1 | La matriz de capacidades es la única fuente del seeder; las rutas y los chequeos de política referencian solo entradas del catálogo. |
| INV-P2 | El bypass de `super_master` aplica solo a abilities presentes en el catálogo activo. |
| INV-P3 | Cambiar el perfil de producto nunca cambia los identificadores de rol persistidos. |

## 9. Estrategia de migración (strangler, preservando comportamiento)

Cada etapa es entregable con la suite verde y el contrato HTTP sin cambios.

| Etapa | Entregable | Reemplaza |
|---|---|---|
| 0 | Tests dorados/de contrato sobre las respuestas actuales de login, usuarios, entidades y alcance | Nada (agrega seguridad) |
| 1 | `Rol`, `Nivel`, `Capacidad`, `CatalogoCapacidades` como único vocabulario | 10 duplicaciones, incluidas `ROLE_PRIORITY`, `RANKS` y constantes de precedencia |
| 2 | `CadenaJerarquica` + puerto `JerarquiaRepository` | 8 resolvedores de cadena |
| 2b | Entidades raíz `super_masters` + `masters`, `masters.super_master_id`, `bancas.master_id` → `masters.id`, `operadores` con seis tipos, aislamiento y tests | Elimina `Alcance::Global` y la visibilidad entre árboles (cambio de comportamiento, PR propio) |
| 3 | `PoliticaAlcance` pura + decoradores de query Eloquent | Interior de `AlcanceService`; la clase conserva su nombre hasta el final |
| 4 | Casos de uso de identidad: login, crear entidad+operador, desactivar | Lógica de negocio de `AuthController` y `UserController` |
| 5 | Extracción del paquete + test de frontera + path repository de Composer | Acoplamiento a `App\` |

## 10. Riesgos

| Riesgo | Mitigación |
|---|---|
| Detalles ocultos del contrato en las respuestas (proyección de permisos, etiqueta de rol, mensajes de error) | Tests dorados por endpoint en la etapa 0, aserciones JSON byte a byte |
| Deriva de comportamiento en la rama fail-open de tickets y en la divergencia visibilidad/gestión de agencia | Caracterizar primero; no "arreglar" semántica en este cambio |
| Casos borde del árbol (agencia sin taquillas, taquilla sin agencia, cadena inactiva) | Tests basados en propiedades sobre fixtures de cadena |
| Scope creep hacia comisiones | Fuera de alcance explícito; solo se reserva el namespace `Distribucion` |
| `#65` forzando un refactor de comisiones que colisione | El cambio posterior depende de este kernel; no tocar `CierreService` aquí |
| El paquete Composer por path rompe el despliegue | El paquete vive dentro de `backend/packages`; sin cambios de despliegue hasta probarlo en CI |
| Los operadores dependen hoy de editar el rol | La API sigue idempotente para el mismo rol; el panel oculta el selector solo después del release de inmutabilidad; anunciar la retirada |
| Producción podría tener operadores activos duplicados | En desarrollo no hay backfill; en producción se audita antes de la constraint y se resuelve a mano |
| MySQL 8 no tiene índices únicos parciales | Columna generada (nula cuando inactivo) o tabla `vinculos_operador` con clave única; en PostgreSQL se usa un índice parcial |
| Reinicio de esquema por estar en desarrollo | Sin migración de datos de producción. `migrate:fresh` + seed; `master_id`, `banca_id`, `grupo_id` y `agencia_id` obligatorios en el esquema nuevo |
| Varios super_masters con bypass global | Resuelto por diseño: árboles aislados vía `masters.super_master_id`. Sin backfill en desarrollo |
| Cambio de comportamiento en la visibilidad de super_master | Tests y posiblemente operaciones asumen un super_master global. Reescribir los tests afectados, anunciar el aislamiento y entregarlo como etapa reversible propia |

## 11. Preguntas abiertas para propuesta/diseño

1. ¿`super_master` se mapea a "sin capacidades, bypass total" de forma permanente, o debería convertirse en una capacidad explícita `*` del catálogo?
2. ¿El agregado Usuario debe poseer el ciclo de vida del password, o quedarse dividido entre identidad y un adaptador de autenticación?
3. ¿Mantenemos `AlcanceService` como fachada durante toda la migración, o introducimos la nueva política por recurso y borramos ramas de forma incremental?
4. ¿Cuál es el contrato exacto de configuración del "perfil de producto": array PHP, archivo JSON o respaldado por base de datos?
