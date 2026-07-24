# 📋 Planificación del Proyecto LottoApp - Monorepo

**Pila de Tareas (Backlog) priorizada y granular**, dividida en **14 Sprints** o bloques de trabajo.

Cada bloque incluye **Entregables** y su respectiva **Estrategia de Pruebas** (QA). Las tareas están ordenadas para minimizar el bloqueo entre backend y frontend.

---

## 📊 Estado Actual del Proyecto

```
Backend:  ████████████░░░░░░░░░░  ~57% (Sprints 0-5 completos, 6 parcial)
Frontend: █░░░░░░░░░░░░░░░░░░░  ~5%  (Solo splash screen + Electron scaffolding)
Total:    ██████░░░░░░░░░░░░░░  ~30%
```

---

## ✅ SPRINTS COMPLETADOS

### SPRINT 0: CONFIGURACIÓN DEL ENTORNO Y ARQUITECTURA
**Estado:** ✅ COMPLETO

| # | Estado | Tarea | Entregable |
|---|--------|-------|------------|
| 0.1 | ✅ | Instalar Laravel 13, Sanctum, Horizon, Redis. | Repositorio backend corriendo. |
| 0.2 | ✅ | Instalar Electron + Astro (frontend taquilla). | Proyecto frontend con pantalla de carga básica. |
| 0.3 | ✅ | Configurar entorno de desarrollo (Docker/Laragon) con MySQL/PostgreSQL y Redis. | Archivos `.env` y conexión a BD exitosa. |

**🧪 Pruebas Realizadas:**
- `php artisan migrate` crea todas las tablas base correctamente
- `npm run dev` en Astro lanza el servidor de desarrollo
- `npm run electron:dev` abre la ventana de Electron

---

### SPRINT 1: MODELOS, MIGRACIONES Y RELACIONES (BASE DE DATOS)
**Estado:** ✅ COMPLETO

| # | Estado | Tarea | Entregable |
|---|--------|-------|------------|
| 1.1 | ✅ | Crear migraciones para: `bancas`, `grupos`, `taquillas`, `users`. | Migraciones ejecutadas. |
| 1.2 | ✅ | Crear migraciones para: `juegos`, `plugin_juegos`, `apuestas`, `detalle_apuestas`, `resultados`. | Migraciones ejecutadas. |
| 1.3 | ✅ | Crear migraciones para: `exchange_rates`, `pagos`, `cierres_caja`, `configuraciones`. | Migraciones ejecutadas. |
| 1.4 | ✅ | Crear migraciones para: `comisiones` y `logs` (auditoría). | Migraciones ejecutadas. |
| 1.5 | ✅ | Definir relaciones (Eloquent) en cada modelo. | 15 modelos con `$fillable` y relaciones funcionando. |

**Modelos creados (15):** User, Banca, Grupo, Taquilla, Juego, PluginJuego, Apuesta, DetalleApuesta, Resultado, Pago, CierreCaja, Comision, ExchangeRate, Log, Configuracion

---

### SPRINT 2: AUTENTICACIÓN, ROLES Y PERMISOS (JERARQUÍA)
**Estado:** ✅ COMPLETO

| # | Estado | Tarea | Entregable |
|---|--------|-------|------------|
| 2.1 | ✅ | Instalar `spatie/laravel-permission` y publicar migraciones. | Tablas de roles/permisos creadas. |
| 2.2 | ✅ | Seeder con 5 roles y permisos delegados. | Roles asignados. Usuarios `super@lotto.com` y `master@lotto.com` creados. |
| 2.3 | ✅ | Login con Sanctum (emisión de tokens API). | Endpoint `POST /api/login` funcional. |
| 2.4 | ✅ | Middleware de roles (Spatie) aplicado a rutas. | Middleware `role:rol1\|rol2` funcionando. |
| 2.5 | ✅ | CRUD de Usuarios (solo Super Master y Master). | Endpoints `/api/users` protegidos. |
| 2.6 | ✅ | CRUD de Grupos con filtrado jerárquico. | Endpoints `/api/grupos` protegidos. |
| 2.7 | ✅ | CRUD de Taquillas con filtrado jerárquico. | Endpoints `/api/taquillas` protegidos. |
| 2.8 | ✅ | Documentación de rutas en `backend/rutas.md`. | Archivo actualizado con ejemplos de uso. |

**🧪 Pruebas Realizadas (PHPUnit):**
- Usuario con rol **Taquilla** no puede listar usuarios (403 vs 200)
- Usuario **Banca** solo puede crear grupos en su propia banca
- Usuario **Grupo** solo puede crear taquillas en su grupo
- Token Bearer obligatorio (401 sin token)
- Middleware `role` bloquea accesos no autorizados (403)

**📌 Estructura de Permisos Delegados:**

| Rol | Puede gestionar |
|-----|-----------------|
| **Super Master** | Usuarios, Grupos, Taquillas (todo el sistema) |
| **Master** | Usuarios (de su banca), Grupos, Taquillas |
| **Banca** | Grupos (de su banca), Taquillas (de sus grupos) |
| **Grupo** | Taquillas (de su grupo) |
| **Taquilla** | Solo apuestas y pagos (sin gestión de entidades) |

---

### SPRINT 3: GESTIÓN DE LA TASA DE CAMBIO (DÓLAR/BS)
**Estado:** ✅ COMPLETO

| # | Estado | Tarea | Entregable |
|---|--------|-------|------------|
| 3.1 | ✅ | Controlador `ExchangeRateController` con métodos CRUD. | Endpoints para gestionar tasas. |
| 3.2 | ✅ | Lógica: Solo Master/Super Master pueden modificar la tasa. | Políticas implementadas. |
| 3.3 | ✅ | Job `ScrapeExchangeRateJob` que consume API pública (BCV). | Job encolado y programado. |
| 3.4 | ✅ | Endpoint público `GET /api/exchange-rate/active`. | Respuesta JSON con `rate`, `updated_at`. |

**🧪 Pruebas Realizadas:**
- Al guardar nueva tasa, la anterior NO se elimina (historial mantenido)
- Tasa activa es la última insertada con `is_active = true`
- Usuario con rol "Banca" recibe error 403 al intentar actualizar tasa
- Nuevas tasas desactivan automáticamente las anteriores

---

### SPRINT 4: SISTEMA DE PLUGINS Y JUEGO "ANIMALITOS"
**Estado:** ✅ COMPLETO

| # | Estado | Tarea | Entregable |
|---|--------|-------|------------|
| 4.1 | ✅ | Interfaz `JuegoInterface` con métodos requeridos. | `app/Plugins/Contracts/JuegoInterface.php` |
| 4.2 | ✅ | Service Provider `PluginServiceProvider` que escanea directorio. | Clases cargadas al bootear Laravel. |
| 4.3 | ✅ | Plugin `Animalitos.php` con reglas completas. | Clase concreta implementando interfaz. |
| 4.4 | ✅ | Tabla `plugin_juegos` y lógica activar/desactivar. | Admin puede habilitar/deshabilitar juegos. |

**Características del plugin Animalitos:**
- Mapeo de 36 animales con números (0-36)
- Validación de apuestas por nombre de animal
- Cálculo de premio x30
- Métodos auxiliares: `obtenerAnimalPorNumero()`, `obtenerNumeroPorAnimal()`

**🧪 Pruebas Realizadas:**
- Unitarias: Mock de `Animalitos` con datos de prueba; `calcularPremio()` devuelve valor esperado
- Integración: Nuevo archivo PHP en carpeta detectado sin reiniciar servidor
- Al desactivar juego, la taquilla deja de mostrarlo

---

### SPRINT 5: SCRAPER DE RESULTADOS (ANIMALITOS)
**Estado:** ✅ COMPLETO

| # | Estado | Tarea | Entregable |
|---|--------|-------|------------|
| 5.1 | ✅ | Clase Base `BaseScraper` con Guzzle y DomCrawler. | `app/Plugins/Scrapers/BaseScraper.php` |
| 5.2 | ✅ | `AnimalitosScraper` extendiendo la base. | Scraping de lottoactivo.com/resultados/animalitos/ |
| 5.3 | ✅ | Job `FetchResultsJob` programado cada 5 minutos. | `app/Jobs/FetchResultsJob.php` en console.php |
| 5.4 | ✅ | Dashboard admin con vista historial + botón manual. | Vista Blade con filtros y tabla de resultados |
| 5.5 | ✅ | Endpoint API `GET /api/resultados`. | Listado filtrado por fecha/juego/hora |
| 5.6 | ✅ | Sistema de login web para dashboard admin. | Login con sesiones (no tokens API) |
| 5.7 | ✅ | Fixtures de prueba para testing sin dependencias externas. | JSON y HTML simulados |

**Arquitectura del Scraper:**
1. GET a `https://www.lottoactivo.com/resultados/animalitos/{fecha}/`
2. Extrae token CSRF del HTML con DomCrawler
3. POST a `/core/process.php` con token + fecha
4. Parsea respuesta JSON → Array de Resultados
5. Guarda en BD evitando duplicados (juego_id + fecha + hora)

**🧪 Pruebas Realizadas (9 tests totales):**
- ✅ `test_extracts_token_from_html()` - Extracción correcta del token
- ✅ `test_parses_json_response()` - Parsing correcto del JSON (6 resultados)
- ✅ `test_maps_to_resultado_structure()` - Mapeo completo de campos
- ✅ `test_handles_empty_results()` - Manejo de respuesta vacía
- ✅ `test_handles_invalid_json()` - Error en JSON inválido
- ✅ `test_scraper_parses_and_saves_results_correctly()` - Guardado exitoso
- ✅ `test_scraper_avoids_duplicates()` - No duplica resultados
- ✅ `test_scraper_handles_empty_response()` - Response vacío maneja correctamente
- ✅ `test_scraper_creates_log_on_success()` - Logging de auditoría

**Archivos creados:**
```
backend/app/Plugins/Scrapers/
├── BaseScraper.php          # Clase abstracta base
└── AnimalitosScraper.php    # Scraping de lottoactivo.com

backend/app/Jobs/FetchResultsJob.php  # Job encolado con reintentos
backend/app/Http/Controllers/Api/ResultadoController.php
backend/app/Http/Controllers/Admin/ResultadoController.php
backend/resources/views/admin/layouts/app.blade.php
backend/resources/views/admin/auth/login.blade.php
backend/resources/views/admin/resultados/index.blade.php
backend/tests/Unit/AnimalitosScraperTest.php (5 tests)
backend/tests/Feature/FetchResultsJobTest.php (4 tests)
backend/tests/Fixtures/animalitos_response.json
backend/tests/Fixtures/animalitos_page.html
```

---

## 🔄 SPRINT EN PROGRESO

### SPRINT 6: MÓDULO DE APUESTAS (CON PAGO MIXTO BS/USD)
**Estado:** ⏳ EN PROGRESO (~20%)

**Objetivo:** El corazón de la taquilla - permitir operar apuestas con pago mixto en Bolívares y Dólares.

| # | Estado | Tarea | Entregable |
|---|--------|-------|------------|
| 6.1 | ⏳ | Endpoint `POST /api/apuestas`. Recibe: `juego_id`, `combinacion`, `amount_bs`, `amount_usd`. | En progreso |
| 6.2 | ❌ | Lógica de negocio: Calcular `total_bs_equivalent = amount_bs + (amount_usd * tasa_activa)`. | Pendiente |
| 6.3 | ❌ | Guardar apuesta con `exchange_rate_applied` histórico. | Pendiente |
| 6.4 | ❌ | Endpoint `GET /api/apuestas/historial` (filtrado por taquilla/fechas). | Pendiente |
| 6.5 | ❌ | Endpoint `GET /api/apuestas/{id}` detalle del ticket. | Pendiente |

**Rutas actuales comentadas en `api.php`:**
```php
/*// APUESTAS (todos los roles)
Route::middleware(['role:super_master|master|banca|grupo|taquilla'])->group(function () {
    Route::get('/apuestas', [ApuestaController::class, 'index']);
    Route::post('/apuestas', [ApuestaController::class, 'store']);
    Route::delete('/apuestas/{id}', [ApuestaController::class, 'destroy']);
});*/
```

**Próximos pasos:**
1. Descomentar y completar rutas de apuestas en api.php
2. Crear `ApuestaController` con métodos index, store, show
3. Implementar FormRequest para validación
4. Calcular total_bs_equivalent usando tasa activa del momento
5. Guardar exchange_rate_applied históricamente
6. Tests unitarios y de integración

---

## ⏳ SPRINTS PENDIENTES

### SPRINT 7: ACTIVACIÓN DE TAQUILLAS (CÓDIGO + MAC ADDRESS)
**Estado:** ❌ NO INICIADO

| # | Tarea | Entregable |
|---|-------|------------|
| 7.1 | Dashboard: Generar `activation_code` único al crear una taquilla. | Campo `activation_code` lleno. |
| 7.2 | Endpoint `POST /api/activar` (sin autenticación). Recibe `codigo` y `mac`. | Valida código, registra MAC, activa `active=true`. |
| 7.3 | Middleware `VerifyMac` para todas las rutas autenticadas. | Si no coincide, devuelve 403. |
| 7.4 | Lógica de reemplazo: Si una MAC ya existe, desactivar la anterior. | Control de concurrencia. |

**🧪 Pruebas Esperadas:**
- Activación con código correcto/incorrecto
- Bloqueo sin header `X-Device-MAC` o MAC distinta (403)
- Reemplazo de PC con mismo código

---

### SPRINT 8: FRONTEND TAQUILLA - PANTALLAS BASE
**Estado:** ❌ NO INICIADO

**Objetivo:** Construir las interfaces de usuario para el operador.

| # | Tarea | Entregable |
|---|-------|------------|
| 8.1 | Pantalla de Activación (vista en Astro) con input para código y botón "Activar". | Llama al endpoint y guarda token + MAC local. |
| 8.2 | Pantalla de Login (con credenciales de usuario taquilla). | Almacena token en `keytar` (seguro). |
| 8.3 | Layout principal: Header (con tasa actual, hora, nombre de taquilla) y menú lateral. | Navegación entre módulos. |
| 8.4 | Conectar Electron con Astro (IPC) para obtener la MAC real del sistema operativo. | Función `getMacAddress()` en `main.js`. |

**Estado actual del frontend:**
- Solo 1 página (`index.astro` - splash screen)
- Electron con IPC básico: `getMac()`, `printTicket()` (stub), `getVersion()`
- Sin componentes, layouts, utilities ni stores
- Dependencias declaradas pero no usadas (axios, keytar, electron-pos-printer)

---

### SPRINT 9: FRONTEND TAQUILLA - OPERACIONES CRÍTICAS
**Estado:** ❌ NO INICIADO

| # | Tarea | Entregable |
|---|-------|------------|
| 9.1 | UI de "Nueva Apuesta": Selección de juego, combinaciones, inputs monto BS/$. | Interfaz con cálculo automático cruzado. |
| 9.2 | Integración con impresora térmica. | Botón "Imprimir Ticket" funciona. |
| 9.3 | UI de "Historial": Lista de apuestas del día con estado. | Vista con filtros. |
| 9.4 | UI de "Pagar Premio": Seleccionar apuesta ganadora y registrar pago. | Endpoint `POST /api/pagos` consumido. |
| 9.5 | UI de "Eliminar Ticket": Botón visible solo si cumple regla 5 min. | Botón deshabilitado con tooltip. |

---

### SPRINT 10: ELIMINACIÓN DE TICKETS (REGLA DE 5 MINUTOS)
**Estado:** ❌ NO INICIADO

| # | Tarea | Entregable |
|---|-------|------------|
| 10.1 | Agregar SoftDeletes a `apuestas`. | Campo `deleted_at` en migración. |
| 10.2 | Crear Policy `ApuestaPolicy` con método `delete()`. | Validar: `created_at > now()->subMinutes(5)` y sorteo no realizado. |
| 10.3 | Endpoint `DELETE /api/apuestas/{id}` con reversión contable. | Ajuste en cierre de caja. |
| 10.4 | Registrar eliminación en `logs` con motivo. | Trazabilidad total. |

---

### SPRINT 11: DASHBOARD ADMINISTRATIVO (PANEL DE CONTROL)
**Estado:** ❌ NO INICIADO

| # | Tarea | Entregable |
|---|-------|------------|
| 11.1 | Panel de Gestión de Usuarios (listar, editar, asignar roles/entidades). | Vista y lógica. |
| 11.2 | Panel de Gestión de Bancas, Grupos y Taquillas (CRUD). | Vista y lógica. |
| 11.3 | Panel de Gestión de Juegos (activar/desactivar, ver plugins instalados). | Vista y lógica. |
| 11.4 | Panel de Gestión de Tasas de Cambio (historial y setear activa). | Vista y lógica. |
| 11.5 | Visualización de Logs de Auditoría (filtrados por usuario/fecha). | Vista y lógica. |

**Nota:** Parcialmente iniciado con Sprint 5 (vistas básicas de resultados creadas)

---

### SPRINT 12: CIERRE DE CAJA Y REPORTES CONTABLES (Doble Moneda)
**Estado:** ❌ NO INICIADO

| # | Tarea | Entregable |
|---|-------|------------|
| 12.1 | Lógica de cálculo: Sumar `amount_bs` y `amount_usd` de apuestas y pagos del turno. | Servicio `CierreService`. |
| 12.2 | Guardar cierre en `cierres_caja` con tasa del momento. | Modelo guardado. |
| 12.3 | Endpoint `POST /api/cierre` (desde taquilla). | Operador cierra su turno. |
| 12.4 | Dashboard: Reporte PDF/Excel con columnas detalladas. | Exportación funcional. |
| 12.5 | Gráfico de evolución de ventas (BS/USD) en dashboard. | Dashboard visual. |

---

### SPRINT 13: CÁLCULO DE COMISIONES (JOBS)
**Estado:** ❌ NO INICIADO

| # | Tarea | Entregable |
|---|-------|------------|
| 13.1 | Job `CalculateCommissionsJob` diario a medianoche. | Calcula % sobre ventas netas. |
| 13.2 | Guardar resultados en tabla `comisiones` con período y estado. | Registros generados. |
| 13.3 | Dashboard: Vista comisiones generadas + marcar como "Pagado". | CRUD de comisiones. |
| 13.4 | Notificar por email a responsables. | Notificaciones de Laravel. |

---

### SPRINT 14: PRUEBAS INTEGRALES, SEGURIDAD Y DESPLIEGUE
**Estado:** ❌ NO INICIADO

| # | Tarea | Entregable |
|---|-------|------------|
| 14.1 | **Pruebas de Carga (K6)**: Simular 100 taquillas apostando simultáneamente. | Reporte de rendimiento. |
| 14.2 | **Auditoría de Seguridad**: SQL Injection, XSS, CSRF. | Escaneo OWASP. |
| 14.3 | Configurar Supervisor para Horizon y worker. | Archivo `supervisor.conf`. |
| 14.4 | Configurar **electron-updater** para actualizaciones OTA. | Actualización automática. |
| 14.5 | Script de despliegue (`deploy.sh`). | Despliegue en Linux. |
| 14.6 | **Pruebas UAT**: Simulación día completo de operación. | Acta de conformidad. |

---

## 🚨 GAPS Y RIESGOS IDENTIFICADOS

### Backend
| Gap | Impacto | Prioridad |
|-----|---------|-----------|
| `ApuestaController` no implementado | Corazón del sistema bloqueado | ALTA |
| Scraper depende de HTTP externo | Puede fallar si lottoactivo cambia | MEDIA |
| Sin Policies (Directorio `app/Policies/` vacío) | Necesario para Sprint 10 | MEDIA |
| Sin Services (`app/Services/` no existe) | Necesario para cierres/comisiones | MEDIA |
| Typo en archivo: `JuegoControlle.php` (falta "r") | Posible autoload fail | BAJA |
| Jobs usan `dispatchSync()` en controladores | Timeout en navegador | MEDIA |

### Frontend (Taquilla)
| Gap | Impacto | Prioridad |
|-----|---------|-----------|
| Solo 1 página (splash screen) | 95% del frontend por hacer | ALTA |
| Sin componentes, layouts, stores | Desarrollo desde cero | ALTA |
| Impresión térmica es stub | Funcionalidad crítica no operativa | ALTA |
| Sin icono (`public/icon.ico` faltante) | Build falla | BAJA |
| Build Linux vacío (`linux-unpacked/` vacío) | No hay build funcional | MEDIA |

---

## 📦 Inventario de Archivos Creados (Sprints 0-5)

### Backend (Laravel 13)
```
backend/
├── app/
│   ├── Http/Controllers/
│   │   ├── Api/
│   │   │   ├── AuthController.php
│   │   │   ├── ExchangeRateController.php
│   │   │   ├── GrupoController.php
│   │   │   ├── JuegoController.php
│   │   │   ├── TaquillaController.php
│   │   │   ├── UserController.php
│   │   │   └── ResultadoController.php
│   │   ├── Admin/
│   │   │   ├── Auth/AuthController.php
│   │   │   └── ResultadoController.php
│   │   └── Controller.php
│   ├── Jobs/
│   │   ├── FetchResultsJob.php
│   │   └── ScrapeExchangeRateJob.php
│   ├── Models/ (15 archivos)
│   ├── Plugins/
│   │   ├── Contracts/JuegoInterface.php
│   │   ├── Juegos/Animalitos.php
│   │   └── Scrapers/
│   │       ├── BaseScraper.php
│   │       └── AnimalitosScraper.php
│   └── Providers/PluginServiceProvider.php
├── database/
│   ├── migrations/ (21 archivos)
│   ├── seeders/ (6 archivos)
│   └── factories/ (4 archivos)
├── resources/views/
│   ├── admin/
│   │   ├── auth/login.blade.php
│   │   ├── layouts/app.blade.php
│   │   └── resultados/index.blade.php
│   └── welcome.blade.php
├── routes/
│   ├── api.php (79 líneas)
│   ├── web.php (25 líneas)
│   └── console.php (programación jobs)
└── tests/
    ├── Unit/AnimalitosScraperTest.php (5 tests)
    ├── Feature/
    │   ├── ExchangeRateTest.php
    │   ├── FetchResultsJobTest.php (4 tests)
    │   ├── PluginIntegrationTest.php
    │   └── RoleAuthorizationTest.php
    └── Fixtures/
        ├── animalitos_response.json
        └── animalitos_page.html
```

### Frontend (Electron + Astro)
```
taquilla/
├── src/
│   └── pages/index.astro (única página - splash screen)
├── electron/
│   ├── main/main.js
│   ├── main/ipcHandlers.js
│   └── preload/preload.js
├── package.json (dependencias: astro, electron, axios, keytar)
└── astro.config.mjs
```

---

## 🎯 Estimación de Esfuerzo Restante

| Sprint | Horas estimadas | Días estimados |
|--------|-----------------|----------------|
| **6** - Módulo de Apuestas | 20h | 3 días |
| **7** - Activación Taquillas | 12h | 2 días |
| **8** - Frontend Pantallas Base | 30h | 4-5 días |
| **9** - Frontend Operaciones | 35h | 5-6 días |
| **10** - Eliminación Tickets | 10h | 1-2 días |
| **11** - Dashboard Admin | 25h | 3-4 días |
| **12** - Cierre de Caja | 20h | 3 días |
| **13** - Comisiones | 15h | 2 días |
| **14** - Pruebas y Despliegue | 30h | 4-5 días |
| **TOTAL RESTANTE** | **~200h** | **~28 días** |

---

## 📌 Notas Importantes

1. **Laravel 13 vs Plan Original**: La planificación mencionaba Laravel 10, pero se usa Laravel 13.8 (versión más reciente, positivo)

2. **Jobs Síncronos vs Asíncronos**: Los jobs actualmente usan `dispatchSync()` que ejecuta durante la petición HTTP. Para producción se recomienda cambiar a `dispatch()` y usar colas con Redis/Supervisor.

3. **Dependencia Externa del Scraper**: El scraper de resultados depende de lottoactivo.com. Si la página cambia su estructura, el scraper fallará. Se recomienda:
   - Tests con fixtures HTML/JSON (ya implementado)
   - Logging detallado de errores
   - Considerar alternativa: API oficial si existe

4. **Frontend Muy Atrasado**: El frontend tiene solo ~5% completado. Se recomienda trabajar en paralelo con el backend cuando las APIs estén listas.

5. **Cobertura de Tests**: Actualmente ~9 tests en PHPUnit. Objetivo: mínimo 70% cobertura en lógica de negocio (apuestas, tasas, cierres).

---

**Última actualización:** 2026-07-24
**Sprint actual:** 6 - Módulo de Apuestas (en progreso)
**Tests pasando:** 23/23 (100%)
