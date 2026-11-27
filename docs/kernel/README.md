# Kernel de identidad, jerarquía y autorización

Documentación de los dos paquetes que se extraen del backend de lotería para
reutilizarlos en otros productos Laravel (por ejemplo, apuestas deportivas) con
la misma jerarquía de seis niveles.

## Glosario

Los términos canónicos, las convenciones de nombres y las reglas de integridad
del vínculo operador están en [`glosario.md`](glosario.md).

## Paquetes

| Paquete | Contenido | Dependencias |
|---|---|---|
| `lotto/kernel` | Dominio puro: identidad, jerarquía, autorización, acceso, auditoría | PHP 8.3+, sin Laravel |
| `lotto/kernel-laravel` | Adaptadores: Eloquent, Spatie, Sanctum, auditoría, dispositivo, comando de provisioning | `lotto/kernel` + Laravel |

Regla de frontera: `lotto/kernel` no importa `Illuminate\` ni `App\`. Un test de
arquitectura lo verifica en CI.

## Instalación en un consumidor

```bash
composer require lotto/kernel lotto/kernel-laravel
php artisan vendor:publish --tag=kernel-migrations
php artisan migrate
```

El paquete `kernel-laravel` registra el tag `kernel-migrations`:

```php
public function boot(): void
{
    $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

    $this->publishes([
        __DIR__.'/../database/migrations' => database_path('migrations'),
    ], 'kernel-migrations');
}
```

`loadMigrationsFrom` permite usar las migraciones sin publicarlas.
`vendor:publish --tag=kernel-migrations` es para los equipos que quieren
versionarlas en su propio repositorio.

## Perfil de producto

Cada producto aporta un `PerfilProducto`: los mismos seis niveles y su propia
matriz de capacidades.

```php
final class PerfilApuestas implements PerfilProducto
{
    public function niveles(): CatalogoNiveles { return CatalogoNiveles::seisNiveles(); }

    public function capacidades(): CatalogoCapacidades { /* eventos, cuotas, liquidación */ }

    public function matriz(): MatrizRolCapacidad { /* mismas filas, otras capacidades */ }
}
```

La lotería usa `PerfilLoteria`, que conserva los 36 permisos actuales. La matriz
sigue siendo la fuente única del seeder de Spatie.

## Uso en `lotto-app`

```json
"repositories": [
  { "type": "path", "url": "packages/kernel" },
  { "type": "path", "url": "packages/kernel-laravel" }
],
"require": {
  "lotto/kernel": "@dev",
  "lotto/kernel-laravel": "@dev"
}
```

El backend registra `KernelLaravelServiceProvider` y enlaza los puertos:

```php
$this->app->bind(UsuarioRepository::class, EloquentUsuarioRepository::class);
$this->app->bind(JerarquiaRepository::class, EloquentJerarquiaRepository::class);
$this->app->singleton(PerfilProducto::class, PerfilLoteria::class);
```

Los controladores quedan delgados: validan la forma HTTP, arman un comando y
delegan en el caso de uso. La respuesta JSON no cambia.

## Modelo de persistencia: notas

- `super_masters`, `masters`, `bancas`, `grupos`, `agencias` y `taquillas` son
  las seis tablas de entidad. Cada una representa un nodo del árbol.
- `operadores` es la fuente de verdad del vínculo usuario → nodo. Una fila por
  usuario: `(user_id único, entidad_tipo, entidad_id, activo)`. `entidad_tipo`
  puede ser cualquiera de los seis niveles, incluidos `super_master` y `master`.
- `masters.super_master_id` es el padre de un master; `bancas.master_id`
  referencia `masters.id`, no `users.id`. El resto de la cadena
  (`grupos.banca_id`, `agencias.grupo_id`, `taquillas.agencia_id`) se mantiene.
- `users` es identidad pura: nombre, email, password, active. **No es un nodo.**
  Durante el strangler conserva `banca_id/grupo_id/agencia_id/taquilla_id` como
  proyección de lectura; en el destino se eliminan.
- `entidad_activa_id` es una columna generada (`IF(activo, entidad_id, NULL)`)
  con un índice único `(entidad_tipo, entidad_activa_id)`. MySQL 8 no tiene
  índices parciales; esta columna emula la unicidad de los vínculos activos y
  permite reemplazar un operador sin que el histórico bloquee al nuevo.
- En PostgreSQL no hace falta esa columna: se usa un índice único parcial
  (`CREATE UNIQUE INDEX ... WHERE activo`).

### Tres vocabularios distintos de "nivel"

| Vocabulario | Valores | Dónde |
|---|---|---|
| `Nivel` (kernel) | super_master, master, banca, grupo, agencia, taquilla | Jerarquía de seis niveles |
| `operadores.entidad_tipo` | super_master, master, banca, grupo, agencia, taquilla | Todos los niveles tienen entidad y operador |
| `repartos.nivel` | master, banca, grupo, taquilla | Nodos de reparto; super_master es raíz derivada al 100 %, agencia es passthrough |

## Auditoría

El módulo cubre **todos** los eventos del sistema, no solo identidad, jerarquía o permisos. Centraliza:

| Responsabilidad | Dónde |
|---|---|
| Contrato del evento y del registro | `kernel/Auditoria/Domain` |
| Evidencia append-only y cadena de hash | Kernel + `kernel-laravel` |
| Registro, consulta, exportación y retención | Kernel (casos de uso/puertos) + adaptadores |
| Catálogo de acciones de cada módulo | Cada módulo; el producto registra el catálogo |
| Resolución de alcance (los seis niveles) | Puerto; adaptador de Jerarquía |
| Redacción de PII | Puerto; reglas por módulo |

Lo que **no** centraliza: la decisión de qué audita cada módulo. Identidad emite login, usuarios y roles; Jerarquía emite entidades; Pagos emite pagos y cierres; Juegos emite resultados y scrapers. Auditoría registra, consulta, exporta y garantiza la evidencia.

El catálogo de acciones no es un enum global del kernel: cada módulo aporta sus acciones y el producto registra su catálogo. Lotería y apuestas pueden tener acciones distintas sin tocar el módulo.

## Administración de sistema

Dos vías complementarias:

| Vía | Qué permite | Control |
|---|---|---|
| `system_admin` en la app | Ver y operar cualquier árbol desde el panel existente | CLI-only, auditoría, sin rol de negocio, sin fila en `operadores` |
| SQL de solo lectura | Consultar todos los datos sin pasar por la app | Rol de BD `readonly_analytics` con `SELECT` |

El `system_admin` no es un super_master global. Es el único actor con `Alcance::Global`; el super_master sigue viendo solo su árbol. El panel no se duplica: `role === 'system_admin'` muestra todos los menús.

Provisioning:

```bash
php artisan system-admin:provision --name="..." --email="..." --reason="..."
```

Roles de BD:

| Rol | Permisos |
|---|---|
| `app_rw` | DML, sin DDL |
| `migrations` | DDL, solo despliegues |
| `readonly_analytics` | `SELECT` en todas las tablas |

## PostgreSQL

El kernel es agnóstico de la base. Las diferencias viven en `kernel-laravel`:

| Tema | MySQL 8 | PostgreSQL |
|---|---|---|
| Unicidad de operadores activos | Columna generada + `UNIQUE` | `CREATE UNIQUE INDEX ... WHERE activo` |
| Auditoría append-only | Triggers con `SIGNAL SQLSTATE '45000'` | Trigger con `RAISE EXCEPTION`, o revocar `UPDATE/DELETE` |
| JSON | `json` | `jsonb` |
| Enum | `ENUM` nativo | `varchar` + `CHECK` (portable) o enum nativo |
| Fechas, soft deletes, incrementos | Portable | Portable |

El kernel y sus tests de dominio no tocan la base. Los adaptadores se prueban
contra MySQL y PostgreSQL en CI.

## Mapa de carpetas

```
backend/packages/kernel/src/
  Compartido/     Resultado, Reloj, EventBus
  Identidad/      Domain · Application · Ports
  Jerarquia/      Domain · Application · Ports
  Autorizacion/   Domain (PerfilProducto, matriz)
  Acceso/         Domain (dispositivo, cadena)
  Auditoria/      Domain (eventos)
  Distribucion/   reservado para el cambio de reparto

backend/packages/kernel-laravel/src/
  Persistencia/   repositorios Eloquent
  Auth/           Spatie, Sanctum
  Auditoria/      recorder + query
  Dispositivo/    VerifyMac, política de lotería
  Console/        provisioning de super_master
  Providers/      KernelLaravelServiceProvider
```

## Qué se reutiliza y qué no

| Se reutiliza | No se reutiliza |
|---|---|
| Reglas de jerarquía y alcance | Resources HTTP y formato de errores |
| Matriz de capacidades (como perfil) | UI del panel |
| Invariantes de identidad | Reportes y queries de negocio |
| Eventos de auditoría y su esquema | Reglas de hardware específicas (van en el adaptador) |
| Política de dispositivo (como puerto) | Módulos de negocio: juegos, apuestas, pagos |

## Versionado

- `lotto/kernel` no depende de Laravel y tiene su propia suite rápida.
- Agregar una capacidad es un cambio menor; agregar o quitar un nivel, o cambiar
  las reglas de alcance, es un cambio mayor.
- No se hacen forks: un producto que necesita reglas de alcance distintas
  requiere una versión mayor del kernel, no una copia.
