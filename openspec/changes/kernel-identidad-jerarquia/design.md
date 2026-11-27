# Diseño: kernel-identidad-jerarquia

**Estado:** borrador
**Fecha:** 2026-11-24
**Cambio:** `kernel-identidad-jerarquia`
**Modelo:** B — cada nivel es una entidad + exactamente un usuario operador activo

## 1. Contextos delimitados

| Contexto | Responsabilidad | ¿Sin framework? |
|---|---|---|
| Identidad | Agregado Usuario (credenciales + rol + vínculo de operador) | Sí |
| Jerarquía | Seis niveles de entidad, árbol, cadena, política de alcance pura | Sí |
| Autorización | Catálogo de capacidades, perfil de producto, política de capacidades | Sí |
| Acceso | Identidad de dispositivo, vínculo de dispositivo, cadena de activación | Sí |
| Auditoría | Contrato y registro de auditoría de **todo el sistema**: evento, evidencia append-only, consulta, exportación y retención. El catálogo de acciones lo aporta cada módulo. | Sí |
| Administración de sistema | Principal de seguridad global (`system_admin`), fuera del árbol; provisioning por CLI | Sí |
| Distribución | Comisiones/participación (cambio futuro) | Reservado |

Regla de dependencia: `Adaptadores → Aplicación → Dominio`. El dominio no importa nada de Laravel ni de la app.

### 1.1 Auditoría: alcance y límites

El contexto de Auditoría cubre **todos** los eventos del sistema, no solo identidad, jerarquía o permisos. Centraliza:

| Responsabilidad | Dónde vive |
|---|---|
| Contrato del evento y del registro | Kernel `Auditoria/Domain` |
| Evidencia append-only y cadena de hash | Kernel (reglas) + `kernel-laravel` (triggers, hasher) |
| Registro, consulta, exportación y retención | Kernel (casos de uso/puertos) + `kernel-laravel` (adaptadores) |
| Catálogo de acciones de cada módulo | Cada módulo; el app registra el catálogo del producto |
| Resolución de alcance (super_master/master/banca/grupo/agencia/taquilla) | Puerto en el kernel; adaptador en Jerarquía |
| Redacción de PII | Puerto en el kernel; reglas por módulo en el adaptador |

Lo que **no** centraliza: la decisión de qué auditar en cada módulo. Identidad audita login, usuarios y roles; Jerarquía audita entidades; Pagos audita pagos y cierres; Juegos audita resultados y scrapers. Cada módulo emite; Auditoría registra, consulta, exporta y garantiza la evidencia.

El catálogo de acciones no es un enum global del kernel. El kernel define una interfaz `AccionAuditoria`; cada módulo aporta sus acciones y el producto registra su catálogo. Así, lotería y apuestas pueden tener acciones distintas sin tocar el módulo de auditoría.

## 2. Modelo de dominio

### 2.1 Agregados y objetos de valor

```
Usuario (raíz del agregado)
  id: UsuarioId
  nombre: string
  email: Email
  password: PasswordHash
  rol: Rol                 // inmutable
  vinculo: VinculoOperador // siempre apunta a un nodo de entidad
  estado: Activo | Inactivo
  creadoPor: ?UsuarioId    // metadata de auditoría, no una capacidad

NodoJerarquico (nodo de entidad)
  id: NodoId (tipo, id)
  nivel: Nivel
  padre: ?NodoId
  activo: bool

VinculoOperador
  SuperMaster(SuperMasterId)
  Master(MasterId)
  Banca(BancaId)
  Grupo(GrupoId)
  Agencia(AgenciaId)
  Taquilla(TaquillaId)

Rol            (super_master | master | banca | grupo | agencia | taquilla)
Nivel          (metadata del nivel: rango, padre, tabla de entidad, cardinalidad de hijos)
TipoActor      (Negocio | Sistema)
CadenaJerarquica (camino ordenado de ancestros de un NodoId)
Alcance        (Global | Subarbol(raices) | Vacio)   // Global solo para system_admin
Decision       (Permitido | Denegado(motivo))
Capacidad      (código del catálogo)
PerfilProducto (niveles, capacidades, matriz, superMasterBypass)
```

**El usuario nunca es un nodo.** Cada nivel, incluidos `super_master` y `master`, es una fila de entidad. El usuario es el operador de esa fila.

**Excepción controlada:** el `system_admin` es un principal de sistema, no un rol de negocio. No pertenece a ningún árbol, no tiene fila en `operadores` y es el único actor con `Alcance::Global`.

### 2.2 Catálogo de niveles

```
super_master   padre: —              tabla: super_masters   hijos: master (N)
master         padre: super_master   tabla: masters         hijos: banca (N)
banca          padre: master         tabla: bancas          hijos: grupo (N)
grupo          padre: banca          tabla: grupos          hijos: agencia (N)
agencia        padre: grupo          tabla: agencias        hijos: taquilla (N)
taquilla       padre: agencia        tabla: taquillas       hijos: —
```

Cada entidad tiene exactamente un usuario operador activo (`operadores`). Los vínculos de padre son:

- `masters.super_master_id` → `super_masters.id`
- `bancas.master_id` → `masters.id`
- `grupos.banca_id` → `bancas.id`
- `agencias.grupo_id` → `grupos.id`
- `taquillas.agencia_id` → `agencias.id`

`taquillas.grupo_id` es una proyección de lectura, no un vínculo de padre.

### 2.3 Políticas (puras)

```php
final class PoliticaAlcance
{
    public function visibilidad(Actor $actor, Recurso $recurso): Alcance;
    public function puedeGestionar(Actor $actor, NodoJerarquico $nodo): Decision;
    public function puedeOperar(Actor $actor, NodoJerarquico $nodo): Decision;
    public function puedeCrear(Actor $actor, Rol $rol, NodoJerarquico $padre): Decision;
}

final class PoliticaCapacidades
{
    public function tiene(Actor $actor, Capacidad $capacidad): bool;
}
```

`Actor` es `{ usuarioId, tipo: Negocio|Sistema, rol, nivel, raices }`. `ResolverActor` consulta primero `system_admins`: si el usuario es un principal de sistema, devuelve `Actor::sistema()` sin rol ni raíces. Si no, carga el vínculo del operador → entidad → árbol. La política nunca consulta la base de datos.

`PoliticaAlcance::visibilidad()` devuelve `Alcance::global()` **solo** si `actor->esSistema()`. Ningún actor de negocio recibe alcance global. `PoliticaCapacidades::tiene()` devuelve `true` para el system admin, porque puede usar todo el catálogo.

### 2.4 Catálogo de invariantes

| ID | Invariante | Dónde se impone |
|---|---|---|
| INV-I1 | Un rol por usuario | `Usuario::crear`; Spatie `syncRoles([$rol])` |
| INV-I2 | El nivel se deriva del rol | `Rol::nivel()` |
| INV-I3 | El vínculo es coherente con el rol | Factory de `VinculoOperador` + `Usuario::crear` |
| INV-I4 | Taquilla requiere una cadena activa para autenticarse | `CadenaActivacion` + `AutenticarCredenciales` |
| INV-I5 | Sin rol ⇒ no hay login (403) | `AutenticarCredenciales` |
| INV-I6 | Un super_master es intocable desde otro árbol | `PoliticaAlcance` |
| INV-I7 | `created_by` es metadata, no capacidad | El dominio no lo lee para autorizar |
| INV-I8 | El rol es inmutable | No existe `cambiarRol()`; HTTP mapea `RolInmutable` → 422 |
| INV-I9 | Un operador activo por entidad, para **los seis** niveles | `operadores` con único activo + chequeo del repositorio |
| INV-I10 | El mismo rol es idempotente; un rol distinto es error de dominio | `Usuario` + adaptador HTTP |
| INV-I11 | El provisioning de super_master crea entidad + usuario + vínculo | `CrearSuperMaster` + comando |
| INV-I12 | `super_master` no es asignable por HTTP | Enum del request + política |
| INV-I13 | Un usuario siempre es operador, nunca un nodo | Sin vínculos de jerarquía usuario-a-usuario |
| INV-I14 | El system_admin vive fuera del árbol: sin rol de negocio, sin fila en `operadores`, sin entidad | `system_admins` + `ResolverActor` |
| INV-I15 | El system_admin solo se crea por CLI; nunca por HTTP | `system-admin:provision`; la API rechaza el rol |
| INV-I16 | Toda acción del system_admin queda auditada y no puede borrar la auditoría | `actor_roles=['system_admin']` + append-only |
| INV-I17 | El system_admin no puede ser borrado ni degradado por un actor de negocio | `PoliticaAlcance` + provisioning |
| INV-H1 | Solo el subárbol propio es visible/gestionable/operable | `PoliticaAlcance` |
| INV-H2 | Master sin bancas = vacío, nunca global | `Alcance::vacio()` |
| INV-H3 | ver/gestionar/operar son decisiones independientes | Tres métodos de la política |
| INV-H4 | Árbol: `super_master → master → banca → grupo → agencia → taquilla` | `CatalogoNiveles` |
| INV-H5 | Las decisiones son puras (sin `abort`, sin queries) | Test de frontera + firma de la política |
| INV-H6 | `permission:` en la ruta es la puerta gruesa; la política de dominio es la fina | Tests de rutas + tests de política |
| INV-H7 | Todo nodo excepto `super_master` tiene padre | Esquema NOT NULL + dominio |
| INV-H8 | Todo master pertenece a exactamente un super_master | `masters.super_master_id` NOT NULL |
| INV-H9 | Ningún actor **de negocio** es global; el único principal global es `system_admin` | `PoliticaAlcance` |
| INV-H10 | El acceso entre árboles está prohibido, incluso entre super_masters | `PoliticaAlcance` + tests |
| INV-P1 | La matriz de capacidades es la única fuente del seeder | `PerfilProducto` + seeder |
| INV-P2 | El bypass de super_master aplica solo a abilities del catálogo | `PoliticaCapacidades` |
| INV-P3 | El perfil de producto nunca cambia los identificadores de rol persistidos | Test de validación del perfil |

El catálogo vive en `docs/domain/invariants.md`, con un enlace de cada ID a su clase de test.

## 3. Modelo de persistencia (ER)

### 3.1 Diagrama ER

```
super_masters
  id PK
  name
  code UQ
  active
  created_by FK users.id
  timestamps
       │ 1:N (super_master_id)
       ▼
masters
  id PK
  super_master_id FK super_masters.id NOT NULL
  name
  code
  active
  created_by FK users.id
  timestamps
  UNIQUE (super_master_id, code)
       │ 1:N (master_id)
       ▼
bancas
  id PK
  master_id FK masters.id NOT NULL
  name
  code
  active
  ...
  UNIQUE (master_id, code)
       │ 1:N (banca_id)
       ▼
grupos
  id PK
  banca_id FK bancas.id NOT NULL
  ...
       │ 1:N (grupo_id)
       ▼
agencias
  id PK
  grupo_id FK grupos.id NOT NULL
  ...
       │ 1:N (agencia_id)
       ▼
taquillas
  id PK
  agencia_id FK agencias.id NOT NULL
  grupo_id FK grupos.id NOT NULL   -- proyección
  mac_address
  device_fingerprint
  activation_code
  last_connection_at
  ...

users
  id PK
  name
  email UQ
  password
  active
  -- destino: sin FKs de jerarquía
  -- transitorio: banca_id/grupo_id/agencia_id/taquilla_id se mantienen
  -- como proyección de lectura hasta que termine el strangler; luego se eliminan
       ├─ 1:1 → operadores     (operador de negocio; no existe para system_admin)
       └─ 1:1 → system_admins  (principal de sistema; sin rol ni operador)

operadores
  id PK
  user_id FK users.id UNIQUE
  entidad_tipo ENUM(super_master, master, banca, grupo, agencia, taquilla)
  entidad_id BIGINT
  activo BOOL
  entidad_activa_id GENERATED (IF(activo, entidad_id, NULL)) STORED  -- MySQL
  UNIQUE (entidad_tipo, entidad_activa_id)

system_admins
  id PK
  user_id FK users.id UNIQUE
  active
  created_by FK users.id
  reason
  created_at
```

### 3.2 Fuente de verdad vs proyección

| Dato | Fuente de verdad | Proyección |
|---|---|---|
| super_master → masters | `masters.super_master_id` | — |
| master → bancas | `bancas.master_id` | — |
| banca → grupo → agencia → taquilla | FKs de jerarquía | `taquillas.grupo_id` |
| usuario → nodo | `operadores` | `users.*_id` durante el strangler |
| system admin | `system_admins` | — |
| rol | Spatie `model_has_roles` | — |
| capacidades | Spatie `role_has_permissions` | — |
| auditoría | `audit_events` | — |

Reglas:

- Solo los adaptadores escriben las proyecciones. Los controladores y el dominio nunca lo hacen.
- Un test de consistencia verifica que cada proyección coincide con `operadores` + la jerarquía.
- `operadores` es el soporte del invariante INV-I9. `entidad_id` es polimórfico (seis tablas); el dominio valida la existencia y la base garantiza la unicidad.
- MySQL no tiene índices parciales; la columna generada `entidad_activa_id` da unicidad parcial. PostgreSQL usa un índice parcial real (`WHERE activo`). Ver §6.

### 3.3 Tres vocabularios

| Vocabulario | Valores | Dónde |
|---|---|---|
| `Nivel` (kernel) | super_master, master, banca, grupo, agencia, taquilla | Jerarquía de seis niveles de entidad |
| `operadores.entidad_tipo` | super_master, master, banca, grupo, agencia, taquilla | Cada entidad tiene un operador |
| `TipoActor` | Negocio, Sistema | Distingue roles de negocio de principales de seguridad |
| `repartos.nivel` / `cierre_repartos.nivel` | master, banca, grupo, taquilla | Nodos de reparto; super_master es raíz derivada al 100 %, agencia es passthrough |

`system_admin` no es un `Nivel` ni un `Rol`: es un `TipoActor`. No aparece en la matriz de capacidades ni en `operadores`.

### 3.4 Auditoría: alcance de los eventos

`audit_events` guarda dos cosas distintas:

- **Actor**: `actor_type`, `actor_id`, `actor_label`, `actor_roles`. Es quien hizo la acción.
- **Alcance**: la cadena de entidades a la que pertenece el evento. Es el "dónde" de la acción.

El alcance incluye **los seis niveles**:

```
audit_events (columnas de alcance)
  super_master_id
  master_id
  banca_id
  grupo_id
  agencia_id
  taquilla_id
```

El resolver guarda la cadena completa, no solo el nodo afectado:

| Evento sobre | super_master_id | master_id | banca_id | grupo_id | agencia_id | taquilla_id |
|---|---|---|---|---|---|---|
| super_master | id | — | — | — | — | — |
| master | id del padre | id | — | — | — | — |
| banca | id del abuelo | id del padre | id | — | — | — |
| grupo | id | id | id | id | — | — |
| agencia | id | id | id | id | id | — |
| taquilla | id | id | id | id | id | id |

Así, una consulta de auditoría por árbol es un `where('super_master_id', $id)`, sin joins y sin depender de que la entidad siga existiendo. Los eventos globales (tasas, releases, configuración) dejan todas las columnas en `null`.

El `AuditScopeResolver` actual solo resuelve las cuatro entidades porque nació cuando `master` y `super_master` eran usuarios, no filas. En el Modelo B se extiende a los seis y se agregan `super_master_id` y `master_id` a `audit_events`, con índices por árbol.

## 4. Estructura de carpetas

### 4.1 `packages/kernel` (sin framework)

```
backend/packages/kernel/
  composer.json                  # php ^8.3, sin illuminate/*, sin laravel/framework
  src/
    Compartido/
      Ports/Reloj.php
      Ports/EventBus.php
      Domain/Resultado.php
    Identidad/
      Domain/
        Usuario.php
        UsuarioId.php
        Email.php
        PasswordHash.php
        Rol.php
        Nivel.php
        VinculoOperador.php
        EstadoUsuario.php
        TipoActor.php
        Evento/UsuarioCreado.php
        Evento/UsuarioDesactivado.php
        Excepcion/RolInmutable.php
        Excepcion/VinculoIncoherente.php
        Excepcion/OperadorDuplicado.php
      Application/
        CrearUsuario.php
        CrearEntidadConOperador.php
        CrearSuperMaster.php
        CrearSystemAdmin.php
        DesactivarUsuario.php
        AutenticarCredenciales.php
        ResolverActor.php
        Comando/CrearUsuarioCommand.php
        Resultado/UsuarioCreadoResult.php
      Ports/
        UsuarioRepository.php
        SystemAdminRepository.php
        PasswordHasher.php
        TokenIssuer.php
        RoleAssigner.php
    Jerarquia/
      Domain/
        NodoId.php
        CadenaJerarquica.php
        Alcance.php
        Decision.php
        PoliticaAlcance.php
      Application/
        ResolverAlcance.php
      Ports/
        JerarquiaRepository.php
    Autorizacion/
      Domain/
        Capacidad.php
        CatalogoCapacidades.php
        MatrizRolCapacidad.php
        PerfilProducto.php
        PoliticaCapacidades.php
    Acceso/
      Domain/
        IdentidadDispositivo.php
        CadenaActivacion.php
        PoliticaDispositivo.php
      Ports/
        DispositivoRepository.php
    Auditoria/
      Domain/
        AccionAuditoria.php
        EventoAuditoria.php
        RegistroAuditoria.php
        PoliticaRetencion.php
        Excepcion/CadenaRota.php
      Application/
        RegistrarEvento.php
        ConsultarAuditoria.php
        ExportarAuditoria.php
        VerificarIntegridad.php
      Ports/
        RepositorioAuditoria.php
        ConsultaAuditoria.php
        ExportadorAuditoria.php
        ResolutorAlcanceAuditoria.php
        Redactor.php
```

### 4.2 `packages/kernel-laravel` (adaptadores)

```
backend/packages/kernel-laravel/
  composer.json                  # requiere lotto/kernel + laravel/framework
  src/
    Persistencia/
      EloquentUsuarioRepository.php
      EloquentJerarquiaRepository.php
      EloquentOperadorRepository.php
      EloquentSystemAdminRepository.php
    Auth/
      SpatieRoleAssigner.php
      SanctumTokenIssuer.php
    Auditoria/
      EloquentAuditRecorder.php
      AuditQueryService.php
      AuditExportService.php
      AuditChainHasher.php
      AuditRedactor.php
      AuditScopeResolver.php
      AuditRetentionCommand.php
    Dispositivo/
      VerifyMac.php
      PoliticaDispositivoLoteria.php
    Console/
      ProvisionSuperMasterCommand.php
      ProvisionSystemAdminCommand.php
    Providers/
      KernelLaravelServiceProvider.php
  database/migrations/           # audit_events, audit_chain_state
  tests/
```

### 4.3 App (específico del producto)

```
backend/app/
  Models/
    SuperMaster.php
    Master.php
    Banca.php
    Grupo.php
    Agencia.php
    Taquilla.php
    Operador.php
    SystemAdmin.php
  Http/Controllers/Api/AuthController.php      # delgado
  Http/Controllers/Api/UserController.php      # delgado
  Http/Requests/StoreUserRequest.php
  Http/Resources/UsuarioResource.php
  Producto/PerfilLoteria.php                   # 36 permisos + matriz
  Producto/PerfilProductoServiceProvider.php
  Providers/AppServiceProvider.php             # enlaza puertos
```

## 5. Uso en el proyecto de lotería existente

### 5.1 Composer

```json
{
  "repositories": [
    { "type": "path", "url": "packages/kernel" },
    { "type": "path", "url": "packages/kernel-laravel" }
  ],
  "require": {
    "lotto/kernel": "@dev",
    "lotto/kernel-laravel": "@dev"
  }
}
```

### 5.2 Enlaces de puertos

```php
final class KernelLaravelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(UsuarioRepository::class, EloquentUsuarioRepository::class);
        $this->app->bind(JerarquiaRepository::class, EloquentJerarquiaRepository::class);
        $this->app->bind(PasswordHasher::class, LaravelPasswordHasher::class);
        $this->app->bind(TokenIssuer::class, SanctumTokenIssuer::class);
        $this->app->bind(RoleAssigner::class, SpatieRoleAssigner::class);
        $this->app->bind(RegistroAuditoria::class, EloquentAuditRecorder::class);
        $this->app->singleton(PerfilProducto::class, PerfilLoteria::class);
    }
}
```

### 5.3 Fachada strangler

`AlcanceService` sigue siendo `final` y conserva sus métodos públicos. Por cada recurso migrado, delega:

```php
public function applyToUsuarios(Builder $query, User $actor): void
{
    $alcance = $this->resolver->para($actor, Recurso::usuario());
    $this->traductor->aplicarUsuarios($query, $alcance);
}
```

Las ramas legacy permanecen hasta que su recurso se migre. La clase se vacía recurso por recurso, nunca se borra en un solo commit.

### 5.4 Ejemplo: crear un master

```
POST /users  { role: "master", entity_name: "...", entity_code: "...", user_email: "...", ... }
  └─ UserController
      └─ CrearEntidadConOperador (entidad master + usuario + operador)
           1. ResolverActor
           2. PoliticaAlcance::puedeCrear(actor, master, super_master_node)
           3. transacción: masters::create + users::create + operadores::create
           4. RoleAssigner::asignar(user, master)
           5. EventBus::emitir(MasterCreado, UsuarioCreado)
  └─ MasterResource + UsuarioResource (mismo JSON, `master_id` = id de entidad)
```

### 5.5 Impacto en la API

- `master_id` en requests/responses cambia de un `users.id` a un `masters.id`. Es un cambio de contrato deliberado. El panel debe usar la lista de entidades master para crear bancas y para la página de masters.
- `POST /users` con `role=super_master` se rechaza (422). Solo `super-master:provision` crea un super_master (entidad + usuario + vínculo).
- El `system_admin` inicia sesión sin rol de negocio; la respuesta expone `role: "system_admin"` e `is_system_admin: true`. El panel muestra todos los menús para ese rol, sin una UI aparte.
- `GET /users` excluye a los `system_admin` de la lista de usuarios de negocio. `POST/PUT/DELETE /users` no puede crear, editar ni borrar un system admin; solo el comando de provisioning.
- Toda acción del system admin se audita con `actor_roles=['system_admin']`.
- `GET /masters` lista entidades master con su usuario operador, no usuarios con rol master.
- `users.banca_id/grupo_id/agencia_id/taquilla_id` se mantienen como proyección de lectura durante el strangler; se eliminan en la fase 6.
- El cliente `taquilla/` no usa `master_id`, así que no se ve afectado.

### 5.6 Administración de sistema

Hay dos vías complementarias:

| Vía | Qué permite | Cómo se controla |
|---|---|---|
| `system_admin` en la app | Ver y operar cualquier árbol desde el panel existente | Provisioning por CLI, auditoría, sin rol de negocio, sin fila en `operadores` |
| SQL de solo lectura | Consultar todos los datos sin pasar por la app | Rol de BD `readonly_analytics` con `SELECT`; sin escritura |

El `system_admin` no es un super_master global: es un principal de sistema. El super_master sigue viendo solo su árbol. El system admin es el único actor con `Alcance::Global`, y esa excepción está auditada.

El panel no se duplica: las condiciones de menú se amplían para `role === 'system_admin'`.

La provisión es:

```bash
php artisan system-admin:provision --name="..." --email="..." --reason="..."
```

El comando crea el usuario y la fila en `system_admins` en una transacción, sin asignar rol de negocio. La revocación también es por CLI (`system-admin:revoke`).

## 6. Reutilización y PostgreSQL

### 6.1 Un kernel, dos adaptadores

**No** hagas un fork del kernel para el producto de apuestas. El kernel es PHP puro y agnóstico de la base. Solo `kernel-laravel` lleva las migraciones y los adaptadores específicos del driver.

| Tema | MySQL 8 | PostgreSQL |
|---|---|---|
| Unicidad parcial de operadores activos | Columna generada `entidad_activa_id` + `UNIQUE (entidad_tipo, entidad_activa_id)` | `CREATE UNIQUE INDEX ... ON operadores (entidad_tipo, entidad_id) WHERE activo` |
| Auditoría append-only | Triggers `BEFORE UPDATE/DELETE` con `SIGNAL SQLSTATE '45000'` | Trigger con `RAISE EXCEPTION`, o revocar `UPDATE, DELETE` al rol de la app |
| Columnas JSON | `json` | `jsonb` |
| Columnas enum | `ENUM` nativo | `varchar` + constraint `CHECK` (portable), o enum nativo |
| Fechas, soft deletes, incrementos | Portable | Portable |

La migración de `operadores` bifurca según el driver:

```php
if (DB::getDriverName() === 'pgsql') {
    DB::statement('CREATE UNIQUE INDEX operadores_activo_unique
        ON operadores (entidad_tipo, entidad_id) WHERE activo');
} else {
    // MySQL: agregar columna generada + índice único
}
```

Los tests de dominio del kernel corren sin base de datos, así que pasan en cualquier driver. Los tests de contrato de los adaptadores corren contra MySQL y PostgreSQL en CI.

### 6.2 Qué reutiliza el producto de apuestas

- `lotto/kernel` sin cambios: identidad, jerarquía, políticas, invariantes.
- `lotto/kernel-laravel` con su variante de migraciones para PostgreSQL.
- Su propio `PerfilApuestas`.
- Sus propios resources HTTP y su UI.

Si el producto de apuestas usa tablas distintas, implementa los puertos y mantiene el kernel sin cambios. Si usa las mismas seis tablas de entidad, reutiliza los adaptadores y las migraciones.

### 6.3 Roles de base de datos

| Rol | Permisos | Uso |
|---|---|---|
| `app_rw` | DML (`SELECT/INSERT/UPDATE/DELETE`), sin DDL | La aplicación |
| `migrations` | DDL, solo despliegues | Migraciones y cambios de esquema |
| `readonly_analytics` | `SELECT` en todas las tablas | Consultas y análisis del jefe de desarrollo |

```sql
-- MySQL
CREATE USER 'readonly_analytics'@'%' IDENTIFIED BY '...';
GRANT SELECT ON lotto_db.* TO 'readonly_analytics'@'%';

-- PostgreSQL
CREATE ROLE readonly_analytics LOGIN PASSWORD '...';
GRANT CONNECT ON DATABASE lotto_db TO readonly_analytics;
GRANT USAGE ON SCHEMA public TO readonly_analytics;
GRANT SELECT ON ALL TABLES IN SCHEMA public TO readonly_analytics;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES TO readonly_analytics;
```

El usuario de la app no debe tener DDL. El acceso de solo lectura no puede romper invariantes ni escribir, y no pasa por la auditoría de la aplicación: es una vía de consulta, no de operación.

## 7. Fases

| Fase | Objetivo | ¿Cambia comportamiento? | Slices de PR |
|---|---|---|---|
| 0 | Tests de contrato, higiene de la suite, esqueleto de paquetes | No | 3–4 |
| 1 | Vocabulario: `Rol`, `Nivel`, `Capacidad`, `PerfilLoteria` | No | 2 |
| 2 | Entidades raíz: tablas `super_masters` + `masters`, FK `bancas.master_id`, `operadores` con seis tipos, modelos, resolvedores | Estructural | 4–6 |
| 3 | Alcance: `PoliticaAlcance` pura, `AlcanceService` como fachada, recurso por recurso | No | 6–8 |
| 3b | Auditoría: contrato genérico, catálogo por módulo, recorder/query/export/retención, migración de `App\Audit` | No | 3 |
| 4 | Casos de uso de identidad: crear entidad+operador, desactivar, login | No (forma de API preservada) | 3 |
| 5a | Inmutabilidad del rol | Sí | 1 |
| 5b | Un operador activo por entidad (los seis) | Sí | 1 |
| 5c | Aislamiento de super_master | Sí | 1–2 |
| 5d | Provisioning de super_master + rechazo por HTTP | Sí | 1 |
| 5e | System admin: `system_admins`, `TipoActor`, `Alcance::Global` solo para sistema, login, panel, CLI, auditoría | **Sí** | 2 |
| 6 | Endurecimiento del paquete, adaptador PostgreSQL, roles de BD, smoke test de consumidor, eliminar proyecciones | No | 3–4 |

Total aproximado: 36–49 PRs. La fase 2 es el pivote estructural: cambia la semántica de `master_id` y el panel, pero conserva las cuatro FKs de entidad y el resto de la superficie de queries.

## 8. Worktree

```bash
cd /home/gzuz/Documentos/lotto-app
git worktree add /home/gzuz/Documentos/lotto-app-worktrees/kernel-identidad \
  -b refactor/kernel-identidad
```

Bootstrap:

```bash
cd /home/gzuz/Documentos/lotto-app-worktrees/kernel-identidad/backend
composer install
cp .env.example .env
php artisan key:generate
# bases separadas: lotto_kernel (dev) y lotto_kernel_test (tests)
php artisan migrate:fresh --seed
```

Reglas:

- El worktree necesita su propio índice `.codegraph/`; nunca copies ni enlaces el índice del checkout principal.
- `vendor/` y `node_modules/` no se comparten; instálalos en el worktree.
- Usa una base de test separada para no pisar los datos del checkout principal.
- Una rama por fase/slice.
- Mueve la sesión de OpenCode al worktree cuando empiece la implementación.

## 9. Estrategia de testing

| Nivel | Ubicación | Velocidad | Demuestra |
|---|---|---|---|
| Dominio | `packages/kernel/tests/Domain` | ms | invariantes, objetos de valor, políticas |
| Aplicación | `packages/kernel/tests/Application` | ms | orquestación con fakes en memoria |
| Adaptadores (MySQL) | `backend/tests/Feature` | segundos | SQL, HTTP, contrato |
| Adaptadores (PostgreSQL) | job de CI | segundos | migraciones y queries específicas del driver |
| Frontera | `backend/tests/Unit/Architecture` | ms | sin imports de Laravel en `kernel/src` |
| Contrato | `backend/tests/Feature/Contract` | segundos | compatibilidad byte a byte de las respuestas |
| Sistema | `backend/tests/Feature/SystemAdminTest.php` | segundos | login sin rol, alcance global, auditoría, rechazo de CRUD por HTTP |

La suite del kernel corre sin levantar Laravel. Los 214+ tests existentes se convierten en el contrato del consumidor y no se reescriben durante las fases que preservan comportamiento.

## 10. Riesgos

| Riesgo | Mitigación |
|---|---|
| Mega-PR de la fase 3 | Cortar por recurso; delegación en la fachada |
| Cambio de semántica de `master_id` | Fase 2 con actualización del panel y nota de contrato; taquilla no afectada |
| Deriva de proyecciones (`users.*_id` vs `operadores`) | Test de consistencia + escritura solo desde el adaptador |
| El aislamiento cambia visibilidad | Fase 5c con fixtures de dos árboles y anuncio |
| Página de masters del panel | Actualizar `masters.astro` y los formularios de banca para usar entidades master |
| Diferencias de PostgreSQL | Migraciones conscientes del driver; matriz de CI; tests de dominio sin base |
| Compromiso de la cuenta system_admin | CLI-only, password fuerte, MFA futuro, allowlist de IP, auditoría append-only, revocación por CLI |
| `system_admin` visible en listas de negocio | Excluirlo de `GET /users`; no tiene rol ni operador |
| Uso indebido del SQL de solo lectura | Rol `readonly_analytics` solo con `SELECT`; escritura solo por migraciones/commands |
| Deriva de configuración del worktree/base | Base separada, índice de CodeGraph propio, bootstrap documentado |
