**Pila de Tareas (Backlog) priorizada y granular**, dividida en **14 Sprints** o bloques de trabajo.

Cada bloque incluye **Entregables** y su respectiva **Estrategia de Pruebas** (QA). Las tareas están ordenadas para minimizar el bloqueo entre backend y frontend.

---

### SPRINT 0: CONFIGURACIÓN DEL ENTORNO Y ARQUITECTURA
**Objetivo**: Tener el esqueleto de los 3 proyectos funcionando.

| #  | EStado | Tarea | Entregable |
|----|-------|------------|
| 0.1| Listo | Instalar Laravel 10, Sanctum, Horizon, Redis. | Repositorio backend corriendo. |
| 0.2| Listo | Instalar Electron + Astro (frontend taquilla). | Proyecto frontend con pantalla de carga básica. |
| 0.3| Listo | Configurar entorno de desarrollo (Docker/Laragon) con MySQL/PostgreSQL y Redis. | Archivos `.env` y conexión a BD exitosa. |

**🧪 Pruebas (Sprint 0)**: Verificar que `php artisan migrate` crea las tablas base (users, migrations). Verificar que `npm run dev` en Astro lanza el servidor de desarrollo y `npm run electron:start` abre una ventana vacía.

---

### SPRINT 1: MODELOS, MIGRACIONES Y RELACIONES (BASE DE DATOS)
**Objetivo**: Materializar todas las tablas definidas en el modelo ER.

| # | Tarea | Entregable |
|---|-------|------------|
| 1.1 | Crear migraciones para: `bancas`, `grupos`, `taquillas`, `users` (con campos de relación y `mac_address`). | Migraciones ejecutadas. |
| 1.2 | Crear migraciones para: `juegos`, `plugin_juegos`, `apuestas`, `detalle_apuestas`, `resultados`. | Migraciones ejecutadas. |
| 1.3 | Crear migraciones para: `exchange_rates`, `pagos`, `cierres_caja`, `configuraciones`. | Migraciones ejecutadas. |
| 1.4 | Crear migraciones para: `comisiones` y `logs` (auditoría). | Migraciones ejecutadas. |
| 1.5 | Definir relaciones (Eloquent) en cada modelo (belongsTo, hasMany, morphMany para logs). | Modelos con `$fillable` y relaciones funcionando. |

**🧪 Pruebas (Sprint 1)**: 
- Ejecutar `php artisan migrate:fresh --seed` (con seeders básicos).
- Verificar en Tinker que `User::with('taquilla.banca')->first()` devuelve datos anidados sin errores.
- Revisar índices foráneos en PHPMyAdmin/Workbench.

---

### SPRINT 2: AUTENTICACIÓN, ROLES Y PERMISOS (JERARQUÍA)
**Objetivo**: Implementar login y la rigurosa jerarquía (Super Master > Master > Banca > Grupo > Taquilla).

| # | Tarea | Entregable |
|---|-------|------------|
| 2.1 | Instalar `spatie/laravel-permission` y crear migraciones de roles/permisos. | Tablas `roles`, `permissions` creadas. |
| 2.2 | Crear Seeder con los 5 roles y permisos base (ej. `manage_rates`, `delete_apuesta`, `view_reports`). | Roles asignados en BD. |
| 2.3 | Implementar Login con Sanctum (emisión de tokens API). | Endpoint `POST /api/login` funcional. |
| 2.4 | Crear Middleware `CheckRole` o utilizar políticas para filtrar datos por jerarquía (ej. un Grupo solo ve sus taquillas). | Middleware aplicado a rutas. |
| 2.5 | CRUD de Usuarios (solo para Super Master y Master) con asignación de rol y entidad (banca/grupo/taquilla). | Endpoints `/api/users` (GET, POST, PUT, DELETE). |

**🧪 Pruebas (Sprint 2)**:
- **Unitarias (PHPUnit)**: Probar que un usuario con rol "Taquilla" NO pueda listar usuarios, pero "Master" sí.
- **Funcionales (Postman)**: Probar login con credenciales correctas/incorrectas.
- **Seguridad**: Verificar que el token enviado en Header `Authorization` sea obligatorio para rutas protegidas.

---

### SPRINT 3: GESTIÓN DE LA TASA DE CAMBIO (DÓLAR/BS)
**Objetivo**: CRUD de tasas y lógica de tasa "activa".

| # | Tarea | Entregable |
|---|-------|------------|
| 3.1 | Crear Controlador `ExchangeRateController` con métodos `index`, `store`, `update`, `setActive`. | Endpoints para gestionar tasas. |
| 3.2 | Implementar lógica: Solo Master/Super Master pueden modificar la tasa. | Política `ExchangeRatePolicy`. |
| 3.3 | Crear Job opcional `ScrapeExchangeRateJob` que consuma API pública (BCV/DolarToday) y guarde sugerencia. | Job encolado. |
| 3.4 | Endpoint público `GET /api/exchange-rate/active` para consultar tasa actual desde la taquilla. | Respuesta JSON con `rate`, `updated_at`. |

**🧪 Pruebas (Sprint 3)**:
- Probar que al guardar una nueva tasa, la anterior NO se elimine (historial).
- Probar que la tasa activa sea la última insertada con `is_active = true`.
- Probar que un usuario con rol "Banca" reciba error 403 al intentar actualizar la tasa.

---

### SPRINT 4: SISTEMA DE PLUGINS Y JUEGO "ANIMALITOS"
**Objetivo**: Motor de plugins cargable dinámicamente sin tocar el core.

| # | Tarea | Entregable |
|---|-------|------------|
| 4.1 | Crear Interfaz `JuegoInterface` con métodos: `validarApuesta()`, `calcularPremio()`, `obtenerReglas()`. | Archivo `app/Plugins/Contracts/JuegoInterface.php`. |
| 4.2 | Crear Service Provider `PluginServiceProvider` que escanea el directorio `app/Plugins/Juegos` y registra las clases. | Clases cargadas en memoria al bootear Laravel. |
| 4.3 | Desarrollar plugin `Animalitos.php` con reglas simples (acierta el animal, premio x10). | Clase concreta implementando la interfaz. |
| 4.4 | Crear tabla `plugin_juegos` y lógica para activar/desactivar plugins desde el dashboard. | Admin puede habilitar/deshabilitar juegos. |

**🧪 Pruebas (Sprint 4)**:
- **Unitarias**: Mock de `Animalitos` con datos de prueba; validar que `calcularPremio()` devuelva el valor esperado.
- **Integración**: Probar que si se agrega un nuevo archivo PHP en la carpeta, el sistema lo detecta sin reiniciar el servidor (cache de clases).
- Probar que al desactivar un juego, la taquilla deja de mostrarlo.

---

### SPRINT 5: SCRAPER DE RESULTADOS (ANIMALITOS)
**Objetivo**: Consumir resultados oficiales de la página externa.

| # | Tarea | Entregable |
|---|-------|------------|
| 5.1 | Crear Clase Base `BaseScraper` con Guzzle y DomCrawler. | Clase abstracta con métodos `fetch()` y `parse()`. |
| 5.2 | Implementar `AnimalitosScraper` extendiendo la base, apuntando a la URL oficial de prueba. | Lógica de extracción de números/animales ganadores. |
| 5.3 | Crear Job `FetchResultsJob` que se ejecute cada X minutos (cron) y almacene en `resultados`. | Job encolado y programado en `Kernel.php`. |
| 5.4 | Dashboard: Vista para ver historial de sorteos y botón "Ejecutar Scraper Manual". | Controlador y vista (Livewire o Inertia). |

**🧪 Pruebas (Sprint 5)**:
- **Mocking**: Simular respuestas HTML (guardar HTML de muestra en `/tests/Fixtures`) para no depender de internet en pruebas unitarias.
- Probar que si el scraper falla (404, timeout), se registre en `logs` y se reintente con backoff.
- Probar que no se dupliquen resultados para la misma fecha/sorteo.

---

### SPRINT 6: MÓDULO DE APUESTAS (CON PAGO MIXTO BS/USD)
**Objetivo**: El corazón de la taquilla.

| # | Tarea | Entregable |
|---|-------|------------|
| 6.1 | Endpoint `POST /api/apuestas`. Recibe: `juego_id`, `combinacion`, `amount_bs`, `amount_usd`. | Validación con FormRequest. |
| 6.2 | Lógica de negocio: Calcular `total_bs_equivalent = amount_bs + (amount_usd * tasa_activa)`. Validar que cubra el costo del juego. | Regla de validación personalizada. |
| 6.3 | Guardar la apuesta con la `exchange_rate_applied` del momento (histórica). | Registro en BD con todos los campos monetarios. |
| 6.4 | Endpoint `GET /api/apuestas/historial` (filtrado por taquilla/fechas). | Listado paginado. |
| 6.5 | Endpoint `GET /api/apuestas/{id}` para ver detalle del ticket. | Detalle de la apuesta específica. |

**🧪 Pruebas (Sprint 6)**:
- Probar escenario: `amount_usd=50`, `amount_bs=1800`, tasa=36 => Total equivalente = 3600. Si el juego cuesta 3600, pasa. Si cuesta 4000, rechaza (error 422).
- Probar que el `exchange_rate_applied` se guarde correctamente aunque la tasa cambie 1 minuto después.
- **Seguridad**: Verificar que un usuario de taquilla solo vea sus propias apuestas.

---

### SPRINT 7: ACTIVACIÓN DE TAQUILLAS (CÓDIGO + MAC ADDRESS)
**Objetivo**: Bloquear el .exe a una sola PC física.

| # | Tarea | Entregable |
|---|-------|------------|
| 7.1 | Dashboard: Generar `activation_code` único al crear una taquilla. | Campo `activation_code` lleno. |
| 7.2 | Endpoint `POST /api/activar` (sin autenticación). Recibe `codigo` y `mac`. | Valida código, registra MAC, activa `active=true`. |
| 7.3 | Middleware `VerifyMac` para todas las rutas autenticadas. Compara `X-Device-MAC` header con el guardado. | Si no coincide, devuelve 403. |
| 7.4 | Lógica de reemplazo: Si una MAC ya existe para otra taquilla, desactivar la anterior automáticamente (o pedir confirmación). | Control de concurrencia. |

**🧪 Pruebas (Sprint 7)**:
- Probar activación con código correcto/incorrecto.
- Probar que al intentar usar la API con Postman sin el header `X-Device-MAC` o con una MAC distinta, la petición sea bloqueada.
- Probar que si se activa una nueva PC con el mismo código, la anterior quede desactivada (o se notifique).

---

### SPRINT 8: FRONTEND TAQUILLA (ELECTRON + ASTRO) - PANTALLAS BASE
**Objetivo**: Construir las interfaces de usuario para el operador.

| # | Tarea | Entregable |
|---|-------|------------|
| 8.1 | Pantalla de Activación (vista en Astro) con input para código y botón "Activar". | Llama al endpoint y guarda token + MAC local. |
| 8.2 | Pantalla de Login (con credenciales de usuario taquilla). | Almacena token en `keytar` (seguro). |
| 8.3 | Layout principal: Header (con tasa actual, hora, nombre de taquilla) y menú lateral. | Navegación entre módulos. |
| 8.4 | Conectar Electron con Astro (IPC) para obtener la MAC real del sistema operativo. | Función `getMacAddress()` en `main.js`. |

**🧪 Pruebas (Sprint 8)**:
- **Manual**: Empaquetar `.exe` en desarrollo y ejecutar en una máquina virtual. Verificar que la MAC se lea correctamente.
- Probar que el token se guarde y se recupere al cerrar y abrir la app.

---

### SPRINT 9: FRONTEND TAQUILLA - OPERACIONES CRÍTICAS
**Objetivo**: Apostar, pagar y eliminar tickets.

| # | Tarea | Entregable |
|---|-------|------------|
| 9.1 | UI de "Nueva Apuesta": Selección de juego (Animalitos), selector de combinación, inputs para monto en BS y $. | Interfaz con cálculo automático cruzado. |
| 9.2 | Integración con impresora térmica (usando `node-printer` o generando PDF). | Botón "Imprimir Ticket" funciona. |
| 9.3 | UI de "Historial": Lista de apuestas del día, con estado (Pendiente/Pagada). | Vista con filtros. |
| 9.4 | UI de "Pagar Premio": Seleccionar apuesta ganadora y registrar pago (con opción de moneda). | Endpoint `POST /api/pagos` consumido. |
| 9.5 | UI de "Eliminar Ticket": Botón visible solo si cumple regla (5 min y sorteo no realizado). | Botón deshabilitado con tooltip explicativo. |

**🧪 Pruebas (Sprint 9)**:
- **E2E (Playwright o manual)**: Crear una apuesta con pago mixto, imprimir ticket.
- Probar que el cálculo cruzado sea exacto (ej. si pongo 100$ y tasa 36, el campo BS se llena con 3600 automáticamente).
- Probar la impresión en impresora real (o simular guardando PDF).

---

### SPRINT 10: ELIMINACIÓN DE TICKETS (REGLA DE 5 MINUTOS)
**Objetivo**: Implementar la lógica dura de anulación.

| # | Tarea | Entregable |
|---|-------|------------|
| 10.1 | Agregar SoftDeletes a `apuestas`. | Campo `deleted_at` en migración. |
| 10.2 | Crear Policy `ApuestaPolicy` con método `delete()`. Validar: `created_at > now()->subMinutes(5)` y sorteo no realizado. | Lógica centralizada. |
| 10.3 | Endpoint `DELETE /api/apuestas/{id}`. Al eliminar, revertir el monto en el cierre de caja (crear registro de ajuste). | Reversión contable. |
| 10.4 | Registrar en `logs` la acción de eliminación con motivo (input del operador). | Trazabilidad total. |

**🧪 Pruebas (Sprint 10)**:
- **Unitarias**: Probar la Policy con apuestas de 1 min, 4 min, 6 min y sorteo pasado/futuro.
- Probar que el endpoint devuelva 200 OK cuando es válido, y 422 con mensaje específico cuando no.
- Verificar en BD que el registro no desaparezca (solo `deleted_at` tenga fecha).

---

### SPRINT 11: DASHBOARD ADMINISTRATIVO (PANEL DE CONTROL)
**Objetivo**: Que Super Master y Master puedan gestionar todo visualmente (Livewire/Inertia).

| # | Tarea | Entregable |
|---|-------|------------|
| 11.1 | Panel de Gestión de Usuarios (listar, editar, asignar roles/entidades). | Vista y lógica. |
| 11.2 | Panel de Gestión de Bancas, Grupos y Taquillas (CRUD). | Vista y lógica. |
| 11.3 | Panel de Gestión de Juegos (activar/desactivar, ver plugins instalados). | Vista y lógica. |
| 11.4 | Panel de Gestión de Tasas de Cambio (historial y setear activa). | Vista y lógica. |
| 11.5 | Visualización de Logs de Auditoría (filtrados por usuario/fecha). | Vista y lógica. |

**🧪 Pruebas (Sprint 11)**:
- Probar que un "Banca" no pueda ver la opción de "Gestión de Usuarios" en el menú.
- Probar que al desactivar una taquilla desde el dashboard, esta no pueda iniciar sesión.
- Probar la creación de una taquilla con generación automática del `activation_code`.

---

### SPRINT 12: CIERRE DE CAJA Y REPORTES CONTABLES (Doble Moneda)
**Objetivo**: El módulo financiero más importante.

| # | Tarea | Entregable |
|---|-------|------------|
| 12.1 | Lógica de cálculo: Sumar `amount_bs` y `amount_usd` de apuestas y pagos del turno. | Servicio `CierreService`. |
| 12.2 | Guardar el cierre en `cierres_caja` con la tasa del momento del cierre. | Modelo guardado. |
| 12.3 | Endpoint `POST /api/cierre` (desde taquilla) que genera el resumen y lo envía al backend. | Operador cierra su turno. |
| 12.4 | Dashboard: Generar reporte en PDF/Excel con columnas: Ventas en $, Ventas en BS, Equivalente en BS, Premios, Utilidad. | Exportación funcional. |
| 12.5 | Gráfico de evolución de ventas (BS/USD) en el dashboard del Master. | Dashboard visual. |

**🧪 Pruebas (Sprint 12)**:
- **Cálculo cruzado**: Crear 3 apuestas mixtas y ejecutar cierre. Verificar que los totales sumen exactamente lo que hay en la tabla `apuestas`.
- Probar que el PDF muestre correctamente los símbolos monetarios (Bs. y $).
- Probar que si el operador intenta cerrar caja teniendo apuestas sin pagar, el sistema lo advierta (pero permita, según regla de negocio).

---

### SPRINT 13: CÁLCULO DE COMISIONES (JOBS)
**Objetivo**: Automatizar el pago a bancas y grupos.

| # | Tarea | Entregable |
|---|-------|------------|
| 13.1 | Crear Job `CalculateCommissionsJob` que se ejecute diariamente a medianoche. | Calcula % sobre ventas netas. |
| 13.2 | Guardar resultados en tabla `comisiones` con período (mes/año) y estado (pendiente). | Registros generados. |
| 13.3 | Dashboard: Vista para que Master vea comisiones generadas y marque como "Pagado". | CRUD de comisiones. |
| 13.4 | Notificar por email (o sistema) a los responsables cuando se genere una nueva comisión. | Notificaciones de Laravel. |

**🧪 Pruebas (Sprint 13)**:
- Ejecutar el Job manualmente con `php artisan tinker` y verificar que los montos coincidan con el porcentaje configurado en la Banca.
- Probar que si una Banca no tiene ventas, no se genere comisión (o genere 0).

---

### SPRINT 14: PRUEBAS INTEGRALES, SEGURIDAD Y DESPLIEGUE
**Objetivo**: Poner el sistema en producción con garantías.

| # | Tarea | Entregable |
|---|-------|------------|
| 14.1 | **Pruebas de Carga (K6)**: Simular 100 taquillas apostando simultáneamente. | Reporte de rendimiento. |
| 14.2 | **Auditoría de Seguridad**: Revisar SQL Injection (usar Eloquent), XSS (escapar en vistas), CSRF en formularios. | Escaneo con herramientas (Semgrep, OWASP). |
| 14.3 | Configurar Supervisor para mantener Horizon y el worker corriendo. | Archivo `supervisor.conf`. |
| 14.4 | Configurar **electron-updater** para que la app de taquilla se actualice automáticamente al abrirse. | Actualización OTA funcional. |
| 14.5 | Crear script de despliegue (`deploy.sh`) que ejecute migraciones y optimice cache (config, route, view). | Despliegue en servidor Linux. |
| 14.6 | **Pruebas UAT (Aceptación de Usuario)**: Simular un día completo de operación con el cliente (desde activación, apuesta, eliminación, sorteo, pago y cierre). | Acta de conformidad firmada (interna). |

**🧪 Pruebas (Sprint 14)**:
- E2E: Usar Laravel Dusk o Playwright para automatizar el flujo completo en el navegador del dashboard.
- Verificar que los logs no expongan datos sensibles (como contraseñas o tokens completos).
- Probar la recuperación ante fallos: Apagar Redis y ver que Laravel caiga con gracia (muestre error amigable en taquilla).

---

### 📌 RESUMEN DE ENTREGABLES TRANSVERSALES (Durante todo el desarrollo)

- **Documentación API (Postman/Swagger)**: Actualizar en cada sprint.
- **Migraciones y Seeders**: Mantener actualizados para desarrollo y testing.
- **Cobertura de Código**: Mínimo 70% en pruebas unitarias (PHPUnit) para la lógica de negocio (cálculo de premios, tasas, cierres).

