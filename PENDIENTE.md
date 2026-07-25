# Implementaciones Pendientes

## Próximos Sprints

### 1. Costo Mínimo por Juego
- Agregar columna `costo_minimo` a la tabla `juegos` (decimal, moneda configurable)
- Validar en `ApuestaStoreRequest` que el monto apostado no sea menor al mínimo del juego
- Permitir configurar el valor desde el endpoint `PUT /api/juegos/{juego}`

### 2. Historial de Auditoría (juego_auditoria)
- Endpoint `GET /api/juegos/{juego}/auditoria` para consultar el historial
- Incluir datos del usuario que realizó la acción

### 3. Scrapers por Juego
- Los juegos que requieran scraper (`requires_scraper = true`) deben tener su propio job
- Generalizar `ScrapeExchangeRateJob` como ejemplo base

### 4. Plugin de Triple Zulia
- Verificar que el seeder `TripleZuliaSeeder` esté agregado a `DatabaseSeeder`
- Probar el endpoint `GET /api/juegos/triple-zulia/opciones` y reglas

### 5. Cierre de Caja
- Endpoint `POST /api/cierre` para cerrar caja de una taquilla
- Resumen de apuestas del día, pagos, comisiones

### 6. Permisos Finos (Spatie)
- Reemplazar los `role:` middleware con `permission:` middleware
- Crear permisos específicos: `view_juegos`, `manage_juegos`, `view_apuestas`, `manage_apuestas`
- Asignar permisos a roles en seeder

### 7. Pruebas Faltantes
- Tests para `JuegoController::update()`
- Tests para `JuegoController::toggle()` con auditoría
- Tests para el plugin `TripleZulia`

### 8. Bruno Collection
- Actualizar collection con los cambios de rutas de juegos
- Agregar request para `PUT /api/juegos/{juego}`
- Agregar request para `GET /api/juegos/{juego}/auditoria` (cuando exista)
