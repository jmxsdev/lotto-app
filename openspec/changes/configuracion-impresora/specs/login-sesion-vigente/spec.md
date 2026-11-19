# Spec: Login con sesión vigente (Fase 7)

> Alcance agregado por pedido del owner para la release 2.1.1. No forma parte
> del objetivo original de `configuracion-impresora`; se documenta acá porque
> comparte el ciclo y la release.

## Requirements

### Requirement: Reanudar una sesión existente

Al abrir `/login`, si existe `auth_token` en `localStorage`, la app DEBE
validarlo contra `GET /api/v1/user` (Sanctum, sin `verify.mac`) antes de mostrar
el formulario.

#### Scenario: Token válido

- **GIVEN** un `auth_token` en `localStorage`
- **AND** el backend responde `200` con el usuario y sus permisos
- **WHEN** se abre `/login`
- **THEN** se actualiza `user_data` con la respuesta
- **AND** se redirige a `/dashboard`
- **AND** el formulario de credenciales no se muestra

#### Scenario: Token inválido o expirado

- **GIVEN** un `auth_token` en `localStorage`
- **AND** el backend responde `401`
- **WHEN** se abre `/login`
- **THEN** `apiFetch` elimina el `auth_token`
- **AND** se muestra el formulario de credenciales
- **AND** no se redirige al dashboard

#### Scenario: Backend no disponible

- **GIVEN** un `auth_token` en `localStorage`
- **AND** la API no responde o la validación excede el timeout
- **WHEN** se abre `/login`
- **THEN** se muestra el formulario con un aviso
- **AND** el `auth_token` NO se elimina (una caída temporal no cierra la sesión)

#### Scenario: Sin token

- **GIVEN** que no hay `auth_token`
- **WHEN** se abre `/login`
- **THEN** se muestra el formulario sin ninguna llamada de red de validación

### Requirement: La verificación del dispositivo se mantiene

`index.astro` DEBE seguir verificando el dispositivo (`POST /dispositivo/verificar`)
antes de redirigir a `/login` o `/activacion`; la reanudación de sesión ocurre
después, en `/login`.

#### Scenario: Dispositivo activo con token vigente

- **GIVEN** un dispositivo activo y un `auth_token` vigente
- **WHEN** se abre la app
- **THEN** el splash verifica el dispositivo
- **AND** `/login` reanuda la sesión y termina en `/dashboard`

### Requirement: La validación no consume el throttle de login

La reanudación DEBE usar `GET /user`, no `POST /login`, para no consumir el
límite `throttle:10,2` del endpoint de credenciales.

#### Scenario: Suite E2E completa

- **GIVEN** 9 logins UI y 1 login API del global setup
- **WHEN** los specs de reanudación abren la app con un token inyectado
- **THEN** no se hace `POST /login`
- **AND** la suite no recibe `429`
