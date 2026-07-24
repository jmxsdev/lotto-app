# 📋 Planificación del Proyecto LottoApp - Monorepo

**Pila de Tareas (Backlog) priorizada y granular**, dividida en **14 Sprints** o bloques de trabajo.

Cada bloque incluye **Entregables** y su respectiva **Estrategia de Pruebas** (QA). Las tareas están ordenadas para minimizar el bloqueo entre backend y frontend.

---

## 📊 Estado Actual del Proyecto

```
Backend:  ████████████████░░░░░░  ~71% (Sprints 0-7 completos)
Frontend: ██████░░░░░░░░░░░░░░░░  ~35% (Sprint 8 completo, 9-14 pendientes)
Total:    █████████▌░░░░░░░░░░░░  ~50%
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

### SPRINT 6: MÓDULO DE APUESTAS (CON PAGO MIXTO BS/USD)
**Estado:** ✅ COMPLETO

| # | Estado | Tarea | Entregable |
|---|--------|-------|------------|
| 6.1 | ✅ | Endpoint `POST /api/apuestas`. Recibe: `juego_id`, `combinacion`, `amount_bs`, `amount_usd`. | Validación con FormRequest. |
| 6.2 | ✅ | Lógica de negocio: Calcular `total_bs_equivalent = amount_bs + (amount_usd * tasa_activa)`. | Regla de validación personalizada. |
| 6.3 | ✅ | Guardar apuesta con `exchange_rate_applied` histórico. | Registro en BD con todos los campos monetarios. |
| 6.4 | ✅ | Endpoint `GET /api/apuestas/historial` (filtrado por taquilla/fechas). | Listado paginado. |
| 6.5 | ✅ | Endpoint `GET /api/apuestas/{id}` para ver detalle del ticket. | Detalle de la apuesta específica. |

**Características implementadas:**
- ✅ Cálculo `total_bs_equivalent = amount_bs + (amount_usd * tasa)`
- ✅ Tasa `exchange_rate_applied` guardada históricamente (immutable)
- ✅ Validación costo mínimo del juego
- ✅ Validación animal válido (36 animales)
- ✅ Generación automática de ticket_code único
- ✅ Filtrado jerárquico por rol (taquilla/grupo/banca/master/super_master)
- ✅ Resumen estadístico con totales por moneda

**Tests pasando: 43/43 (100%)**

---

### SPRINT 7: ACTIVACIÓN DE TAQUILLAS (CÓDIGO + MAC ADDRESS)
**Estado:** ✅ COMPLETO

| # | Estado | Tarea | Entregable |
|---|--------|-------|------------|
| 7.1 | ✅ | Endpoint `POST /api/activar` (sin autenticación). | Valida código, registra MAC, activa `active=true`. |
| 7.2 | ✅ | Middleware `VerifyMac` para rutas de taquilla. | Si MAC no coincide, devuelve 403. |
| 7.3 | ✅ | Lógica de reemplazo: Si una MAC ya existe, desactiva la anterior. | Control de concurrencia automático. |
| 7.4 | ✅ | Logging detallado de cada activación/reasignación. | Auditoría completa en tabla logs. |

**Características implementadas:**
- ✅ Endpoint público `POST /api/activar` (sin auth)
- ✅ Middleware `VerifyMac` selectivo (solo aplica a rol 'taquilla')
- ✅ Reasignación automática de MAC (desactiva taquilla anterior)
- ✅ Logging en tabla logs con detalles de activación
- ✅ Validación formato MAC (regex AA:BB:CC:DD:EE:FF)
- ✅ Actualización last_connection_at en cada request válido

**Tests pasando: 56/56 (100%)**

---

### SPRINT 8: FRONTEND TAQUILLA - PANTALLAS BASE
**Estado:** ✅ COMPLETO

| # | Estado | Tarea | Entregable |
|---|--------|-------|------------|
| 8.1 | ✅ | Pantalla de Activación (vista en Astro) con input para código y botón "Activar". | Llama al endpoint `/api/activar` y guarda token + MAC local. |
| 8.2 | ✅ | Pantalla de Login (con credenciales de usuario taquilla). | Almacena token en localStorage. |
| 8.3 | ✅ | Layout principal: Header (con tasa actual, hora, nombre de taquilla) y menú lateral. | Navegación entre módulos. |
| 8.4 | ✅ | Conectar Electron con Astro (IPC) para obtener la MAC real del sistema operativo. | Función `getMacAddress()` en `ipcHandlers.js`. |

**Pantallas implementadas:**
- `index.astro`: Splash screen con verificación de autenticación
- `activacion.astro`: Pantalla de activación de taquilla (código + MAC)
- `login.astro`: Pantalla de login con credenciales
- `dashboard.astro`: Dashboard principal con estadísticas y últimas apuestas

**Componentes UI reutilizables:**
- `Button.astro`: Botones con variantes (primary, secondary, danger, success)
- `Input.astro`: Inputs con validación y mensajes de error
- `Alert.astro`: Alertas tipo success, error, warning, info

**Layouts:**
- `MainLayout.astro`: Layout principal con sidebar y header
  - Sidebar con navegación (Dashboard, Nueva Apuesta, Historial, etc.)
  - Header con tasa USD, hora actual, nombre taquilla y botón logout

**Utilidades:**
- `api.ts`: Cliente API con axios, interceptores para token y MAC
- `authStore.ts`: Store de autenticación con señales de Solid.js
- `currency.ts`: Funciones de formateo BS/USD y conversiones

**Integración Electron:**
- IPC handlers existentes: `getMac()`, `printTicket()`, `getVersion()`
- Preload script expone APIs al renderer
- ContextBridge configurado correctamente

**Build:**
- 4 páginas generadas exitosamente
- Build estático optimizado para Electron
- Total build time: ~7 segundos

---

## ⏳ SPRINTS PENDIENTES

### SPRINT 9: FRONTEND TAQUILLA - OPERACIONES CRÍTICAS
**Estado:** ❌ NO INICIADO

**Objetivo:** Apostar, pagar y eliminar tickets.

| # | Tarea | Entregable |
|---|-------|------------|
| 9.1 | UI de "Nueva Apuesta": Selección de juego (Animalitos), selector de combinación, inputs para monto en BS y $. | Interfaz con cálculo automático cruzado. |
| 9.2 | Integración con impresora térmica (usando `node-printer` o generando PDF). | Botón "Imprimir Ticket" funciona. |
| 9.3 | UI de "Historial": Lista de apuestas del día, con estado (Pendiente/Pagada). | Vista con filtros. |
| 9.4 | UI de "Pagar Premio": Seleccionar apuesta ganadora y registrar pago (con opción de moneda). | Endpoint `POST /api/pagos` consumido. |
| 9.5 | UI de "Eliminar Ticket": Botón visible solo si cumple regla (5 min y sorteo no realizado). | Botón deshabilitado con tooltip explicativo. |

**🧪 Pruebas Esperadas:**
- **E2E (Playwright o manual)**: Crear una apuesta con pago mixto, imprimir ticket.
- Probar que el cálculo cruzado sea exacto (ej. si pongo 100$ y tasa 36, el campo BS se llena con 3600 automáticamente).
- Probar la impresión en impresora real (o simular guardando PDF).

---

### SPRINT 10: ELIMINACIÓN DE TICKETS (REGLA DE 5 MINUTOS)
**Estado:** ❌ NO INICIADO

| # | Tarea | Entregable |
|---|-------|------------|
| 10.1 | Agregar SoftDeletes a `apuestas`. | Campo `deleted_at` en migración. |
| 10.2 | Crear Policy `ApuestaPolicy` con método `delete()`. Validar: `created_at > now()->subMinutes(5)` y sorteo no realizado. | Lógica centralizada. |
| 10.3 | Endpoint `DELETE /api/apuestas/{id}`. Al eliminar, revertir el monto en el cierre de caja (crear registro de ajuste). | Reversión contable. |
| 10.4 | Registrar en `logs` la acción de eliminación con motivo (input del operador). | Trazabilidad total. |

**🧪 Pruebas Esperadas:**
- **Unitarias**: Probar la Policy con apuestas de 1 min, 4 min, 6 min y sorteo pasado/futuro.
- Probar que el endpoint devuelva 200 OK cuando es válido, y 422 con mensaje específico cuando no.
- Verificar en BD que el registro no desaparezca (solo `deleted_at` tenga fecha).

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

**🧪 Pruebas Esperadas:**
- Probar que un "Banca" no pueda ver la opción de "Gestión de Usuarios" en el menú.
- Probar que al desactivar una taquilla desde el dashboard, esta no pueda iniciar sesión.
- Probar la creación de una taquilla con generación automática del `activation_code`.

---

### SPRINT 12: CIERRE DE CAJA Y REPORTES CONTABLES (Doble Moneda)
**Estado:** ❌ NO INICIADO

| # | Tarea | Entregable |
|---|-------|------------|
| 12.1 | Lógica de cálculo: Sumar `amount_bs` y `amount_usd` de apuestas y pagos del turno. | Servicio `CierreService`. |
| 12.2 | Guardar el cierre en `cierres_caja` con la tasa del momento del cierre. | Modelo guardado. |
| 12.3 | Endpoint `POST /api/cierre` (desde taquilla) que genera el resumen y lo envía al backend. | Operador cierra su turno. |
| 12.4 | Dashboard: Generar reporte en PDF/Excel con columnas: Ventas en $, Ventas en BS, Equivalente en BS, Premios, Utilidad. | Exportación funcional. |
| 12.5 | Gráfico de evolución de ventas (BS/USD) en el dashboard del Master. | Dashboard visual. |

**🧪 Pruebas Esperadas:**
- **Cálculo cruzado**: Crear 3 apuestas mixtas y ejecutar cierre. Verificar que los totales sumen exactamente lo que hay en la tabla `apuestas`.
- Probar que el PDF muestre correctamente los símbolos monetarios (Bs. y $).
- Probar que si el operador intenta cerrar caja teniendo apuestas sin pagar, el sistema lo advierta (pero permita, según regla de negocio).

---

### SPRINT 13: CÁLCULO DE COMISIONES (JOBS)
**Estado:** ❌ NO INICIADO

| # | Tarea | Entregable |
|---|-------|------------|
| 13.1 | Crear Job `CalculateCommissionsJob` que se ejecute diariamente a medianoche. | Calcula % sobre ventas netas. |
| 13.2 | Guardar resultados en tabla `comisiones` con período (mes/año) y estado (pendiente). | Registros generados. |
| 13.3 | Dashboard: Vista para que Master vea comisiones generadas y marque como "Pagado". | CRUD de comisiones. |
| 13.4 | Notificar por email (o sistema) a los responsables cuando se genere una nueva comisión. | Notificaciones de Laravel. |

**🧪 Pruebas Esperadas:**
- Ejecutar el Job manualmente con `php artisan tinker` y verificar que los montos coincidan con el porcentaje configurado en la Banca.
- Probar que si una Banca no tiene ventas, no se genere comisión (o genere 0).

---

### SPRINT 14: PRUEBAS INTEGRALES, SEGURIDAD Y DESPLIEGUE
**Estado:** ❌ NO INICIADO

| # | Tarea | Entregable |
|---|-------|------------|
| 14.1 | **Pruebas de Carga (K6)**: Simular 100 taquillas apostando simultáneamente. | Reporte de rendimiento. |
| 14.2 | **Auditoría de Seguridad**: Revisar SQL Injection (usar Eloquent), XSS (escapar en vistas), CSRF en formularios. | Escaneo con herramientas (Semgrep, OWASP). |
| 14.3 | Configurar Supervisor para mantener Horizon y el worker corriendo. | Archivo `supervisor.conf`. |
| 14.4 | Configurar **electron-updater** para que la app de taquilla se actualice automáticamente al abrirse. | Actualización OTA funcional. |
| 14.5 | Crear script de despliegue (`deploy.sh`) que ejecute migraciones y optimice cache (config, route, view). | Despliegue en servidor Linux. |
| 14.6 | **Pruebas UAT (Aceptación de Usuario)**: Simular un día completo de operación con el cliente (desde activación, apuesta, eliminación, sorteo, pago y cierre). | Acta de conformidad firmada (interna). |

**🧪 Pruebas Esperadas:**
- E2E: Usar Laravel Dusk o Playwright para automatizar el flujo completo en el navegador del dashboard.
- Verificar que los logs no expongan datos sensibles (como contraseñas o tokens completos).
- Probar la recuperación ante fallos: Apagar Redis y ver que Laravel caiga con gracia (muestre error amigable en taquilla).

---

## 🚨 Gaps y Riesgos Identificados

### Backend
| Gap | Impacto | Prioridad |
|-----|---------|-----------|
| Typo en archivo: `JuegoControlle.php` (falta "r") | Posible autoload fail | BAJA |
| Jobs usan `dispatchSync()` en controladores | Timeout en navegador | MEDIA |

### Frontend (Taquilla)
| Gap | Impacto | Prioridad |
|-----|---------|-----------|
| Sin componentes Vue/React integrados | Limitado a Astro static | MEDIA |
| Impresión térmica es stub | Funcionalidad crítica no operativa | ALTA |
| Sin icono (`public/icon.ico` faltante) | Build falla en producción | BAJA |
| Build Linux vacío (`linux-unpacked/` vacío) | No hay build funcional | MEDIA |

---

## 📦 Inventario de Archivos Creados (Sprints 0-8)

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
│   │   │   ├── ResultadoController.php
│   │   │   ├── ApuestaController.php
│   │   │   └── ActivacionController.php
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
│   ├── Services/ApuestaService.php
│   └── Providers/PluginServiceProvider.php
├── database/
│   ├── migrations/ (22 archivos)
│   ├── seeders/ (6 archivos)
│   └── factories/ (4 archivos)
├── resources/views/
│   ├── admin/
│   │   ├── auth/login.blade.php
│   │   ├── layouts/app.blade.php
│   │   └── resultados/index.blade.php
│   └── welcome.blade.php
├── routes/
│   ├── api.php (87 líneas)
│   ├── web.php (25 líneas)
│   └── console.php (programación jobs)
└── tests/
    ├── Unit/AnimalitosScraperTest.php (5 tests)
    ├── Unit/ApuestaServiceTest.php (10 tests)
    ├── Unit/ActivacionTest.php (4 tests)
    ├── Feature/
    │   ├── ExchangeRateTest.php
    │   ├── FetchResultsJobTest.php (4 tests)
    │   ├── PluginIntegrationTest.php
    │   ├── RoleAuthorizationTest.php
    │   ├── ApuestaTest.php (10 tests)
    │   └── ActivacionTest.php (9 tests)
    └── Fixtures/
        ├── animalitos_response.json
        └── animalitos_page.html
```

### Frontend (Electron + Astro)
```
taquilla/
├── src/
│   ├── pages/
│   │   ├── index.astro (splash screen)
│   │   ├── activacion.astro
│   │   ├── login.astro
│   │   └── dashboard.astro
│   ├── components/
│   │   ├── ui/
│   │   │   ├── Button.astro
│   │   │   ├── Input.astro
│   │   │   └── Alert.astro
│   │   └── layout/
│   │       ├── Header.astro
│   │       └── Sidebar.astro
│   ├── layouts/
│   │   └── MainLayout.astro
│   ├── utils/
│   │   ├── api.ts
│   │   ├── currency.ts
│   │   └── auth.ts
│   └── store/
│       └── authStore.ts
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
| **9** - Frontend Operaciones | 35h | 5-6 días |
| **10** - Eliminación Tickets | 10h | 1-2 días |
| **11** - Dashboard Admin | 25h | 3-4 días |
| **12** - Cierre de Caja | 20h | 3 días |
| **13** - Comisiones | 15h | 2 días |
| **14** - Pruebas y Despliegue | 30h | 4-5 días |
| **TOTAL RESTANTE** | **~135h** | **~19 días** |

---

## 📌 Notas Importantes

1. **Laravel 13 vs Plan Original**: La planificación mencionaba Laravel 10, pero se usa Laravel 13.8 (versión más reciente, positivo)

2. **Jobs Síncronos vs Asíncronos**: Los jobs actualmente usan `dispatchSync()` que ejecuta durante la petición HTTP. Para producción se recomienda cambiar a `dispatch()` y usar colas con Redis/Supervisor.

3. **Dependencia Externa del Scraper**: El scraper de resultados depende de lottoactivo.com. Si la página cambia su estructura, el scraper fallará. Se recomienda:
   - Tests con fixtures HTML/JSON (ya implementado)
   - Logging detallado de errores
   - Considerar alternativa: API oficial si existe

4. **Frontend en progreso**: El frontend tiene ~35% completado (Sprint 8 completo). Falta implementar operaciones críticas (Sprint 9).

5. **Cobertura de Tests**: Actualmente 56 tests en PHPUnit. Objetivo: mínimo 70% cobertura en lógica de negocio (apuestas, tasas, cierres).

---

**Última actualización:** 2026-07-24
**Sprint actual:** Completado (Sprint 8)
**Tests pasando:** 56/56 (100%)
