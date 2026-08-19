# Apply Progress: despliegue-vps — Slice 1 (PR 1)

## Estado de tareas (Fase 1)

| Tarea | Estado | Evidencia |
|---|---|---|
| 1.1 quitar `backend/.env` + `.env.example` | ✅ | commit `36e55d2` |
| 1.2 RED (commit state): filter-repo rechaza worktree sucio | ✅ | "Aborting: ... (you have unstaged changes)" en clon de prueba `/tmp/opencode/fr-red` |
| 1.3 Purga de historial | ✅ local | Clon limpio `/tmp/opencode/fr-purge`; `backend/.env` fuera de los 168 commits; APP_KEY viejo: 0; 167 commits con `MYSQL_ROOT_PASSWORD: REDACTED`; ramas preservadas. **Pendiente: force-push (requiere confirmación del usuario)** |
| 1.4 Rotación APP_KEY + credenciales DB | ✅ | commit `0fb36a8`; APP_KEY nuevo en `.env` local; MySQL local alineado vía `ALTER USER` |
| 1.5 Prefix `v1` en `routes/api.php` | ✅ | commit `25e284a` |
| 1.6 CORS/Sanctum por env documentado | ✅ | `backend/.env.example` con valores prod comentados |
| 1.7 Clientes taquilla/panel `/api/v1` | ✅ | commit `102000b` (13 archivos) |
| 1.8 Verificación rutas + tests | ✅ | 74 rutas `/api/v1/*`, 0 desnudas; suite: **214 passed, 0 failed, 2 skipped** (566 s) |

## Commits del slice (rama `deploy/seguridad-versionado-v1`)

- `36e55d2` fix(security): quitar backend/.env del seguimiento y crear .env.example
- `0fb36a8` fix(security): rotar credenciales de desarrollo y añadir plantilla .env.production
- `25e284a` feat(api): versionar rutas bajo /api/v1 y actualizar tests
- `102000b` feat(clients): apuntar taquilla y panel a /api/v1
- `89bae55` docs(sdd): artefactos de planificación del cambio despliegue-vps

## Evidencia de verificación

- `php artisan route:list --path=api`: 74 rutas con prefijo `api/v1/`, 0 rutas desnudas `api/`.
- PHPUnit completo (MySQL local con credenciales rotadas): `{"tool":"phpunit","result":"passed","tests":216,"passed":214,"assertions":860,"skipped":2,"duration_ms":566568}`.

## Pendiente de confirmación del usuario

1. ~~Force-push del historial purgado~~ **✅ COMPLETADO 2026-08-19**: mirror push de `/tmp/opencode/fr-purge` a `origin`. Verificado en clon fresco del remoto: 0 apariciones del APP_KEY viejo, 0 de `backend/.env`, 0 de `MYSQL_PASSWORD: secret`; 45 heads y 172 commits reescritos. El repo local adoptó el historial reescrito (`git reset --hard origin/deploy/seguridad-versionado-v1`).
2. ~~Push de la rama + apertura del PR 1~~ **✅ ENTREGADO directo a main (decisión del mantenedor, 2026-08-19)**: el usuario prefirió merge directo en lugar de PR. Fast-forward `feat/seguridad-versionado-v1` → `main` (d0147fc) y push a origin. La rama queda como `feat/seguridad-versionado-v1`.

## Rollback boundary

Revertir los commits `25e284a` y `102000b` devuelve `/api` sin v1 sin efectos colaterales (los cambios de seguridad 36e55d2/0fb36a8 son independientes y no se revierten). No hay migraciones de BD en este slice.

## Gotchas encontrados

- El shell de sesión exporta variables viejas (`DB_PASSWORD=secret`, APP_KEY viejo) que tienen precedencia sobre `.env` → ejecutar artisan/phpunit con `env -u DB_* APP_KEY` o limpiar el entorno.
- `git filter-repo` aplica los regex de `--replace-text` al archivo completo SIN flag MULTILINE → los anclajes `^...$` no matchean archivos multi-línea; usar lookahead (`root(?![\w])`) o literales.
- La suite completa tarda ~9.5 min; `composer test` (que llama `artisan test`) parece colgarse pero solo es lento. El CI (slice 3) debe presupuestar timeout amplio.
- `*.md` está en `.gitignore` raíz: los artefactos OpenSpec nuevos requieren `git add -f`.
