# Propuesta: kernel-identidad-jerarquia

**Estado:** borrador
**Fecha:** 2026-11-24
**Cambio:** `kernel-identidad-jerarquia`

## 1. Intención

Extraer identidad, jerarquía y autorización del monolito Laravel a un **kernel sin framework** más adaptadores Laravel reutilizables, con tres resultados:

1. Una sola implementación de la jerarquía de seis niveles y sus reglas de alcance, en lugar de los diez o más vocabularios duplicados y los ocho resolvedores de cadena actuales.
2. Invariantes explícitos y ejecutables para usuarios, roles, operadores de entidad y aislamiento de super_master.
3. Reutilización en un futuro producto de apuestas deportivas en PHP/Laravel con los mismos niveles, una matriz de capacidades distinta y PostgreSQL.

El contrato HTTP que consumen `panel/` y `taquilla/` se mantiene compatible, con las excepciones deliberadas de la §4. El cliente `taquilla/` no se ve afectado.

## 2. Contexto

### Dolor actual (verificado)

- Vocabulario de roles duplicado en ≥10 lugares: `User::ROLE_PRIORITY`, `UserPolicy::RANKS`, ocho constantes de precedencia en `AlcanceService`, la matriz del seeder y 12 arrays de `hasAnyRole([...])`.
- Recorrido de la jerarquía reimplementado en ≥8 módulos.
- `AlcanceService`: 1.363 líneas, `final`, 59 call sites; mezcla política de roles, construcción de queries Eloquent y `abort(403)`.
- `UserController` y `UserPolicy` reparten las mismas reglas de subárbol entre HTTP, validación, derivación de vínculos y Eloquent.
- El provisioning de entidades (entidad + operador + límites + auditoría) está copiado y pegado en cuatro controladores.

### Activos existentes (no reinventar)

- Spatie es la única fuente de roles; `users.role` ya fue eliminada (`2026-11-03-autorizacion-rol-unico`).
- La matriz de 36 permisos ya existe (`2026-11-05-be04-permisos`, archivado). Asigna permisos a **roles**, nunca a usuarios.
- ~214 tests, incluidos `ArquitecturaAlcanceTest`, `RoutePermissionCoverageTest` y `PermissionCatalogTest`, ya congelan los invariantes centrales.
- `audit_events` es append-only, encadenada por hash y ya acotada por `banca_id/grupo_id/agencia_id/taquilla_id`.

### Simplificación por estar en desarrollo

El sistema está en desarrollo. No hay migración de datos de producción:

- `migrate:fresh` + seed es aceptable.
- Los nuevos vínculos de padre (`masters.super_master_id`, `bancas.master_id`, `grupos.banca_id`, `agencias.grupo_id`, `taquillas.agencia_id`) pueden ser `NOT NULL` desde el primer día.
- Sin backfill, sin manejo de nulos legacy, sin doble escritura.

## 3. Decisiones cerradas

| Decisión | Valor |
|---|---|
| Modelo de despliegue | Despliegues separados por producto; sin columna de tenant/producto en los agregados |
| Niveles | Los mismos seis niveles para todos los productos; las capacidades cambian por producto |
| Modelo de niveles | **Modelo B: cada nivel es una entidad + exactamente un usuario operador activo** |
| Mutabilidad del rol | Inmutable una vez creado. Mismo rol = idempotente; rol distinto = 422 |
| Operadores por entidad | Exactamente un operador **activo** por entidad, para los seis niveles |
| Super masters | Varios por despliegue, cada uno dueño de un árbol aislado |
| Creación de super_master | Solo por comando de provisioning; `POST /users` rechaza el rol |
| Reglas de alcance | Idénticas en todos los productos |
| Compatibilidad de API | Rutas y payloads preservados, salvo los cambios deliberados de la §4 |
| Base de datos | MySQL 8 para lotería; PostgreSQL para el producto de apuestas; un kernel, adaptadores conscientes del driver |
| Administración de sistema | Dos vías: SQL de solo lectura (`readonly_analytics`) y principal `system_admin` en la app, fuera del árbol y auditado |

## 4. Cambios de comportamiento deliberados

Estos **no** forman parte de la extracción; son reglas de negocio aprobadas en esta sesión. Cada uno se entrega en su propio PR, con test dedicado y anuncio.

| # | Cambio | Antes | Después |
|---|---|---|---|
| B1 | Inmutabilidad del rol | `PUT /users/{id}` puede cambiar el rol | Mismo rol = no-op; rol distinto = 422 |
| B2 | Un operador activo | Varios usuarios pueden apuntar a la misma entidad | Segundo operador activo = 422, para los seis niveles |
| B3 | Aislamiento de super_master | Cada super_master ve todo | Cada super_master ve solo su árbol |
| B4 | Provisioning de super_master | `POST /users` puede crear un super_master | Solo el comando Artisan crea entidad + usuario + vínculo |
| B5 | Semántica de `master_id` | `master_id` es un `users.id` | `master_id` es un `masters.id`; panel actualizado |
| B6 | Todos los niveles son entidades | `super_master`/`master` son nodos de usuario | Ambos son filas de entidad con un usuario operador |
| B7 | Administrador de sistema | Ningún actor global | `system_admin` es el único actor global, fuera del árbol y auditado; no es un super_master |

## 5. Alcance

### Dentro del alcance

- `packages/kernel` (PHP 8.3, sin dependencias de framework): Identidad, Jerarquía, Autorización, Acceso, Auditoría.
- `packages/kernel-laravel` (adaptadores): repositorios Eloquent, asignador de roles Spatie, emisor de tokens Sanctum, recorder de auditoría, middleware de dispositivo, comando de provisioning, migraciones conscientes del driver.
- Tablas nuevas `super_masters` y `masters`; `bancas.master_id` como FK a `masters`; `operadores` para los seis niveles.
- Tests de contrato para la superficie HTTP actual.
- `AlcanceService` convertido en fachada sobre una `PoliticaAlcance` pura.
- Módulo de auditoría de todo el sistema: contrato genérico, catálogo de acciones por módulo, recorder, consulta, exportación, cadena de hash y retención.
- Controladores delgados para usuarios, login y los CRUD de entidades.
- Principal `system_admin` (`system_admins`, `TipoActor`, alcance global auditado, provisioning CLI, menú del panel) y roles de BD `app_rw`, `migrations`, `readonly_analytics`.
- Los cambios de comportamiento B1–B7.

### Fuera del alcance

- Comisiones, distribución y `juego_limites.participacion` (solo se reserva el namespace `Distribucion`).
- Pagos, tickets, juegos, resultados, reportes.
- API v2 o rediseño de rutas.
- Una tabla genérica `nodos` (documentada como opción futura, no en este cambio).
- Rediseño de UI más allá de lo mínimo que exigen B1–B6.

## 6. Fases

Cada fase es un punto de revisión. Las fases que preservan comportamiento deben mantener verde la suite completa y los tests de contrato. Las fases que cambian comportamiento actualizan los tests de contrato de forma deliberada.

| Fase | Objetivo | ¿Cambia comportamiento? | Slices de PR | Criterio de salida |
|---|---|---|---|---|
| 0 | Tests de contrato, higiene de la suite, esqueleto de paquetes | No | 3–4 | Baseline medido; suite verde; el paquete instala por path |
| 1 | Vocabulario: `Rol`, `Nivel`, `Capacidad`, `PerfilLoteria` | No | 2 | Suite verde; `PermissionCatalogTest` sin cambios |
| 2 | Entidades raíz: `super_masters` + `masters`, FK `bancas.master_id`, `operadores` con seis tipos, modelos, resolvedores | Estructural (B5, B6) | 4–6 | Tests de entidad master; página de masters del panel actualizada |
| 3 | Alcance: `Alcance`, `Decision`, `PoliticaAlcance`, decoradores de query; `AlcanceService` como fachada por recurso | No | 6–8 | Tests dorados por recurso |
| 3b | Auditoría: contrato genérico, catálogo por módulo, recorder/query/export/retención, migración de `App\Audit` | No | 3 | Tests de integridad, exportación y retención |
| 4 | Casos de uso de identidad: crear entidad+operador, desactivar, login | No (forma preservada) | 3 | Tests de contrato verdes |
| 5a | Inmutabilidad del rol (B1) | **Sí** | 1 | Tests de 422; rol de solo lectura en el panel |
| 5b | Un operador activo (B2) | **Sí** | 1 | Constraint única + test de duplicado |
| 5c | Aislamiento de super_master (B3) | **Sí** | 1–2 | Fixtures de dos árboles; 403 entre árboles |
| 5d | Provisioning de super_master (B4) | **Sí** | 1 | Test del comando; `POST /users` 422 |
| 5e | Administrador de sistema (B7): `system_admins`, `TipoActor`, `Alcance::Global`, login, panel, CLI, auditoría | **Sí** | 2 | Tests de aislamiento global y auditoría |
| 6 | Endurecimiento del paquete, adaptador PostgreSQL, roles de BD, smoke test de consumidor, eliminar proyecciones | No | 3–4 | Test de frontera; job de CI con PostgreSQL |

Total aproximado: 36–49 PRs. Cada slice cabe en el presupuesto de revisión de 400 líneas; la fase 3 se corta por recurso.

## 7. Criterios de aceptación

1. Después de cada fase que preserva comportamiento, todos los tests existentes pasan sin modificación.
2. Los tests de contrato verifican las mismas rutas, payloads, códigos de estado y mensajes de error para el comportamiento no tocado.
3. `packages/kernel/src` no contiene imports de `Illuminate\` ni `App\` (test de frontera).
4. La matriz de capacidades de lotería es idéntica: los mismos 36 permisos y los mismos conteos por rol.
5. Cada invariante `INV-I*`, `INV-H*` e `INV-P*` tiene al menos un test automatizado.
6. Los flujos de `panel/` y `taquilla/` funcionan; el panel se actualiza para `master_id` y las entidades master.
7. B1–B6 tienen tests dedicados y una nota de despliegue escrita.
8. La migración de `operadores` funciona en MySQL y PostgreSQL (matriz de CI).
9. `system_admin` inicia sesión sin rol de negocio, ve todos los árboles, queda auditado y no puede crearse/editarse por HTTP.
10. Los roles de BD `app_rw`, `migrations` y `readonly_analytics` están documentados y aplicados en el despliegue.
11. La auditoría cubre todos los módulos y los seis niveles de alcance; integridad de cadena, exportación y retención tienen tests.

## 8. Riesgos

| Riesgo | Mitigación |
|---|---|
| Scope creep hacia comisiones | No-goal explícito; solo se reserva el namespace |
| La fase 3 se vuelve un mega-PR | Cortar por recurso; `AlcanceService` se mantiene como fachada |
| Detalles ocultos del contrato | Tests dorados en la fase 0 |
| Cambio de semántica de `master_id` | Fase 2 con actualización del panel y nota de contrato |
| Rama fail-open de `ticketVisible` y la divergencia visibilidad/gestión de agencia | Caracterizar primero; no "arreglar" semántica en este cambio |
| El aislamiento cambia lo que ven los super_masters | Fase 5c dedicada, fixtures de dos árboles, anuncio |
| Diferencias de PostgreSQL | Migraciones conscientes del driver; matriz de CI; tests de dominio sin base |
| Compromiso de `system_admin` | CLI-only, password fuerte, MFA futuro, allowlist de IP, auditoría append-only |
| `system_admin` en listas de negocio | Excluirlo de `GET /users`; no tiene rol ni operador |
| Uso indebido del SQL de solo lectura | `readonly_analytics` solo con `SELECT`; escritura por migraciones/commands |
| Pérdida de integridad de la auditoría | Append-only, cadena de hash, tests de integridad; la retención no borra la cadena |
| PII en la auditoría | Redactor por módulo; tests de redacción |
| Alcance de auditoría incompleto | El resolver actual solo cubre cuatro niveles; la fase 3b agrega `super_master_id`/`master_id` y tests por árbol |
| Deriva de configuración del worktree/base | Worktree separado, base de test separada, bootstrap documentado |

## 9. No-goals

- Rediseñar el dominio de loterías, apuestas, premios o reportes.
- Cambiar la matriz de capacidades.
- Publicar el kernel en Packagist (un repositorio VCS privado o un path repository es suficiente por ahora).
- Reemplazar Spatie o Sanctum.
- Una tabla genérica `nodos` (opción futura si el árbol se comparte entre productos).
- Un panel UI separado para el administrador de sistema; usa el panel existente con todos los menús.
