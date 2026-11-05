# Auditoría y logs — estado actual

> Documento canónico del **sistema de logs y auditoría vigente**.
> Describe qué se registra hoy, qué no, cómo consultarlo y qué bugs conocidos hay.
> El trabajo de mejora se sigue en [issue #89](https://github.com/jmxsdev/lotto-app/issues/89).
>
> Última actualización: 2026-11-03.

---

## 1. Resumen

El sistema tiene **dos mecanismos de auditoría parciales**:

1. Tabla `logs` — eventos puntuales de negocio y operación.
2. Tabla `juego_auditoria` — cambios de juegos y premios con `before`/`after`.

No es una auditoría completa: **no registra login, usuarios, roles, permisos, entidades, tasas, clave de cierre ni reaperturas de caja**. Tampoco es append-only ni tamper-evident.

Además, el visor `/logs` del panel **está roto por contrato**: el endpoint devuelve un paginador y la página asume un array.

---

## 2. Tabla `logs`

- Modelo: `backend/app/Models/Log.php`
- Migración: `backend/database/migrations/2026_07_10_035557_create_logs_table.php`.
- Columnas:
  - `id`
  - `user_id` nullable → `users.id` (`onDelete('set null')`)
  - `action` string
  - `details` JSON nullable
  - `ip` string nullable
  - `user_agent` string nullable
  - `created_at`, `updated_at`

### Consulta desde la aplicación

- Endpoint: `GET /api/v1/logs?per_page=50`
- Autorización: `role:super_master|master` (`backend/routes/api.php:166-169`).
- Respuesta: paginador Laravel (`data`, `current_page`, `last_page`, …).

### Consulta directa

```sql
SELECT created_at, user_id, action, details, ip
FROM logs
ORDER BY id DESC
LIMIT 50;
```

```sql
SELECT action, COUNT(*) AS total
FROM logs
GROUP BY action
ORDER BY total DESC;
```

---

## 3. Tabla `juego_auditoria`

- Modelo: `backend/app/Models/JuegoAuditoria.php`
- Columnas:
  - `id`
  - `juego_id` → `juegos.id`
  - `user_id` → `users.id`
  - `accion` (`activar`, `desactivar`, `actualizar`, `premios`)
  - `cambios` JSON (`before`/`after`)
  - `created_at`, `updated_at`
- Se escribe desde:
  - `backend/app/Http/Controllers/Api/JuegoController.php` (toggle/update)
  - `backend/app/Services/PremiosConfigService.php` (premios)

Es la auditoría más completa del sistema: tiene actor y before/after. No guarda IP ni user agent.

---

## 4. Cobertura actual

| Acción | ¿Se registra? | ¿Quién? | ¿Before/after? | ¿IP/UA? |
|---|---|---|---|---|
| Activación de taquilla | Sí, `logs` (`activacion_taquilla`) | `user_id = null` (endpoint público) | Detalles de código/MAC/resultado | Sí |
| Pago de premio | Sí, `logs` (`pago_premio`) | `user_id` | Apuesta, ticket, animal, premio, pago | Sí |
| Anulación de ticket | Sí, `logs` (`ticket_anulado`) | `user_id` | Motivo, devoluciones, totales | Sí |
| Anulación de apuesta suelta | Sí, `logs` (`apuesta.anulada`) | `user_id` | Motivo, devolución, totales | Sí |
| Activar/desactivar/editar juego | Sí, `juego_auditoria` | `user_id` | `before`/`after` | No |
| Editar premios de juego | Sí, `juego_auditoria` (`accion=premios`) | `user_id` | `before`/`after` | No |
| Scrapers / jobs | Sí, `logs` (`scrape_resultados`, `scrape_animalitos`) | `user_id = null` | Detalles operativos | Según job |
| Auditoría de pagos legacy | Sí, `logs` (`auditoria.pago_legacy`) | `user_id = null` | Old/new values | No |
| Creación de entidades | Parcial: `created_by` en bancas, grupos, agencias, taquillas, pagos, cierres | Sí, por FK | No | No |
| Tasas de cambio | Parcial: `set_by` al crear | Sí, por FK | No | No |
| Cierre de caja | Parcial: `created_by`, `reclosed_by`, `reclosed_at` | Sí, por FK | No | No |

---

## 5. Qué NO se audita

- Login, logout y logins fallidos.
- CRUD de usuarios (crear, editar, desactivar, borrar).
- Cambios de rol y permisos.
- CRUD de bancas, grupos, agencias y taquillas (solo `created_by` al crear; updates, deletes y toggles sin actor ni before/after).
- Cambios de tasa de cambio (`ExchangeRateController::update()` no actualiza `set_by` ni registra log).
- Cambio de clave de cierre (`ClaveCierreController::update()` no registra nada).
- Cambios de defaults de comisión (`ComisionController::updateDefaults()` no registra actor ni before/after).
- Reapertura de cierre de caja (solo `reclosed_by`/`reclosed_at`; sin motivo ni log).
- Creación de tickets/apuestas (no hay log explícito; solo el `Pago` de ingreso tiene `created_by`).
- Lecturas/consultas sensibles.
- Cambios de permisos o roles hechos por seeders/comandos.

---

## 6. Bugs y limitaciones conocidas

### 6.1. Visor `/logs` roto

El endpoint devuelve un paginador:

```php
// backend/routes/api.php:167-169
Route::get('/logs', function (Request $request) {
    return Log::with('user')->latest()->paginate($request->input('per_page', 50));
});
```

Pero la página asume un array:

```js
// panel/src/pages/logs.astro:24-28
logs = await apiFetch('GET','/logs?per_page=50');
...
const filtered = filter ? logs.filter(l => (l.action||'').toLowerCase().includes(filter)) : logs;
```

`logs.filter` no existe en un objeto paginador (`{data: [...], current_page, ...}`). La página falla o no renderiza.

**Fix posible:** que el endpoint devuelva `data` directamente (perdiendo metadata de paginación) o que la página use `logs.data` y muestre la paginación. La segunda opción es la correcta a futuro.

### 6.2. Doble codificación de `details`

`backend/app/Http/Controllers/Api/ActivacionController.php:130-139` pasa `json_encode([...])` a una columna con cast `array`. El detalle queda como string JSON dentro de JSON, no como objeto. Al leerlo, `details` puede volver como string en lugar de array.

### 6.3. Nombres de acción inconsistentes

No hay enum ni constantes centralizadas. Conviven:

- `pago_premio`
- `ticket_anulado`
- `apuesta.anulada`
- `activacion_taquilla`
- `auditoria.pago_legacy`
- `scrape_resultados`
- `scrape_animalitos`

### 6.4. Sin índices

La tabla `logs` no tiene índices por `action`, `user_id` ni `created_at`. Para volumen bajo no es crítico; para auditoría real conviene.

### 6.5. No es append-only

No hay triggers, hash chain ni permisos DB que impidan `UPDATE`/`DELETE`. Cualquier proceso con acceso a la BD puede modificar o borrar logs.

### 6.6. Sin `request_id`

No se puede correlacionar un request con todos los eventos que generó.

---

## 7. Relación con #69 (roles)

Si se elimina `users.role` y Spatie queda como fuente única, el cambio de rol de un usuario deja de tener cualquier rastro. Hoy tampoco lo tiene, pero el riesgo aumenta porque el rol pasa a ser el único mecanismo de autorización.

**Mínimo recomendado en el cambio de #69:**

- `user.role_changed` con actor, target, `before`, `after`, IP/UA.
- `user.created` y `user.deleted` si se quiere cobertura de usuarios.

El resto del sistema de auditoría se sigue en #89.

---

## 8. Referencias

- Issue de mejora: [jmxsdev/lotto-app#89](https://github.com/jmxsdev/lotto-app/issues/89)
- Issue de roles: [jmxsdev/lotto-app#69](https://github.com/jmxsdev/lotto-app/issues/69)
- Modelo `Log`: `backend/app/Models/Log.php`
- Modelo `JuegoAuditoria`: `backend/app/Models/JuegoAuditoria.php`
- Endpoint: `backend/routes/api.php:166-169`
- Panel: `panel/src/pages/logs.astro`
- Escrituras:
  - `backend/app/Http/Controllers/Api/ActivacionController.php:130`
  - `backend/app/Http/Controllers/Api/PagoController.php:207`
  - `backend/app/Services/TicketAnulacionService.php:337`
  - `backend/app/Http/Controllers/Api/JuegoController.php:63,95`
  - `backend/app/Services/PremiosConfigService.php:82`
  - `backend/app/Jobs/FetchResultsJob.php:82`
  - `backend/app/Jobs/ScrapeResultsJob.php:173`
  - `backend/app/Jobs/ScrapeSourceJob.php:155`
  - `backend/app/Console/Commands/AuditarPagosLegacy.php:463`
