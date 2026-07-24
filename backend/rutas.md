
---

# API Routes - LottoApp

## 🌐 Base URL
```
http://localhost:8000/api
```

## 🔐 Autenticación

| Método | URI | Nombre de Ruta | Controlador | Middleware | Descripción |
|--------|-----|----------------|-------------|------------|-------------|
| POST | `/login` | - | `AuthController@login` | - | Iniciar sesión y obtener token Bearer |
| GET | `/user` | - | `AuthController@user` | `auth:sanctum` | Obtener usuario autenticado |
| POST | `/logout` | - | `AuthController@logout` | `auth:sanctum` | Cerrar sesión (revocar token) |

---

## 👥 Usuarios (CRUD)

**Middleware:** `auth:sanctum`, `role:super_master|master`  
**Roles permitidos:** Super Master, Master

| Método | URI | Nombre de Ruta | Controlador | Descripción |
|--------|-----|----------------|-------------|-------------|
| GET | `/users` | `users.index` | `UserController@index` | Listar usuarios (filtrados por jerarquía) |
| POST | `/users` | `users.store` | `UserController@store` | Crear un nuevo usuario |
| GET | `/users/{user}` | `users.show` | `UserController@show` | Ver un usuario específico |
| PUT/PATCH | `/users/{user}` | `users.update` | `UserController@update` | Actualizar un usuario |
| DELETE | `/users/{user}` | `users.destroy` | `UserController@destroy` | Eliminar un usuario |

**Filtrado jerárquico:**
- **Super Master:** Ve todos los usuarios.
- **Master:** Ve solo usuarios de su banca.

---

## 🏢 Grupos (CRUD)

**Middleware:** `auth:sanctum`, `role:super_master|master|banca`  
**Roles permitidos:** Super Master, Master, Banca

| Método | URI | Nombre de Ruta | Controlador | Descripción |
|--------|-----|----------------|-------------|-------------|
| GET | `/grupos` | `grupos.index` | `GrupoController@index` | Listar grupos (filtrados por jerarquía) |
| POST | `/grupos` | `grupos.store` | `GrupoController@store` | Crear un grupo |
| GET | `/grupos/{grupo}` | `grupos.show` | `GrupoController@show` | Ver un grupo específico |
| PUT/PATCH | `/grupos/{grupo}` | `grupos.update` | `GrupoController@update` | Actualizar un grupo |
| DELETE | `/grupos/{grupo}` | `grupos.destroy` | `GrupoController@destroy` | Eliminar un grupo |

**Filtrado jerárquico:**
- **Super Master:** Ve todos los grupos.
- **Master:** Ve grupos de su banca.
- **Banca:** Ve solo grupos de su propia banca.

---

## 🖥️ Taquillas (CRUD)

**Middleware:** `auth:sanctum`, `role:super_master|master|banca|grupo`  
**Roles permitidos:** Super Master, Master, Banca, Grupo

| Método | URI | Nombre de Ruta | Controlador | Descripción |
|--------|-----|----------------|-------------|-------------|
| GET | `/taquillas` | `taquillas.index` | `TaquillaController@index` | Listar taquillas (filtradas por jerarquía) |
| POST | `/taquillas` | `taquillas.store` | `TaquillaController@store` | Crear una taquilla |
| GET | `/taquillas/{taquilla}` | `taquillas.show` | `TaquillaController@show` | Ver una taquilla específica |
| PUT/PATCH | `/taquillas/{taquilla}` | `taquillas.update` | `TaquillaController@update` | Actualizar una taquilla |
| DELETE | `/taquillas/{taquilla}` | `taquillas.destroy` | `TaquillaController@destroy` | Eliminar una taquilla |

**Filtrado jerárquico:**
- **Super Master:** Ve todas las taquillas.
- **Master:** Ve taquillas de su banca.
- **Banca:** Ve taquillas de grupos de su banca.
- **Grupo:** Ve solo taquillas de su grupo.

---
## 💱 Tasas de Cambio (Exchange Rates)

**Middleware (protegidas):** `auth:sanctum`, `permission:manage_exchange_rates` (para crear, actualizar, activar) y `permission:view_exchange_rates` (para listar y ver).  
**Roles permitidos:** Solo Super Master y Master (con los permisos correspondientes).  
**Ruta pública:** `GET /exchange-rate/active` (sin autenticación).

| Método | URI | Nombre de Ruta | Controlador | Middleware | Descripción |
|--------|-----|----------------|-------------|------------|-------------|
| GET | `/exchange-rate/active` | - | `ExchangeRateController@active` | - | Obtener la tasa activa (pública) |
| GET | `/exchange-rates` | `exchange-rates.index` | `ExchangeRateController@index` | `auth:sanctum`, `permission:view_exchange_rates` | Listar historial de todas las tasas |
| POST | `/exchange-rates` | `exchange-rates.store` | `ExchangeRateController@store` | `auth:sanctum`, `permission:manage_exchange_rates` | Crear una nueva tasa (opcionalmente activarla) |
| GET | `/exchange-rates/{exchange_rate}` | `exchange-rates.show` | `ExchangeRateController@show` | `auth:sanctum`, `permission:view_exchange_rates` | Ver una tasa específica |
| PUT | `/exchange-rates/{exchange_rate}` | `exchange-rates.update` | `ExchangeRateController@update` | `auth:sanctum`, `permission:manage_exchange_rates` | Actualizar una tasa (activar/desactivar o cambiar valor) |
| DELETE | `/exchange-rates/{exchange_rate}` | `exchange-rates.destroy` | `ExchangeRateController@destroy` | `auth:sanctum`, `permission:manage_exchange_rates` | Eliminar una tasa (no puede estar activa) |
| POST | `/exchange-rates/{exchange_rate}/set-active` | `exchange-rates.set-active` | `ExchangeRateController@setActive` | `auth:sanctum`, `permission:manage_exchange_rates` | Activar una tasa específica (desactiva las demás) |

---
## 📋 Estructura de Permisos Delegados

| Rol | Puede gestionar | Middleware |
|-----|-----------------|------------|
| **super_master** | Usuarios, Grupos, Taquillas (todo el sistema) | `role:super_master` |
| **master** | Usuarios (de su banca), Grupos, Taquillas | `role:master` |
| **banca** | Grupos (de su banca), Taquillas (de sus grupos) | `role:banca` |
| **grupo** | Taquillas (de su grupo) | `role:grupo` |
| **taquilla** | Solo apuestas y pagos (sin gestión de entidades) | `role:taquilla` |

---

## 🔑 Autenticación

**Obtener token:**
```bash
curl -X POST http://localhost:8000/api/login \
  -H "Content-Type: application/json" \
  -d '{"email":"super@lotto.com","password":"password"}'
```

**Usar token en peticiones:**
```bash
curl -X GET http://localhost:8000/api/user \
  -H "Authorization: Bearer {token}"
```

---

## 📌 Ejemplos de Peticiones

### Crear Grupo (como Banca)
```bash
curl -X POST http://localhost:8000/api/grupos \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{"name":"Grupo Sur","code":"GS001","banca_id":1}'
```

### Crear Taquilla (como Grupo)
```bash
curl -X POST http://localhost:8000/api/taquillas \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{"name":"Taquilla 02","code":"T002","grupo_id":1}'
```

---
### Obtener tasa activa (público)
```bash
curl -X GET http://localhost:8000/api/exchange-rate/active
```
---
**Última actualización:** 2026-07-10

