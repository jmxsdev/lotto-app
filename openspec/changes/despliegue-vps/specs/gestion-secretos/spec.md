---
capability: gestion-secretos
change: despliegue-vps
status: done
---

# gestion-secretos Specification

## Purpose

Eliminar los secretos expuestos del repositorio público (`backend/.env` commiteado), establecer plantillas y archivos de producción seguros, rotar credenciales comprometidas y centralizar el manejo de secretos del CI/CD en GitHub Secrets.

## Requirements

| # | Requirement | Strength | Estado |
|---|------------|----------|--------|
| REQ-1 | `.env` fuera del repo e historial | MUST | draft |
| REQ-2 | `.env.example` sin secretos | MUST | draft |
| REQ-3 | `.env.production` con permiso 600 | MUST | draft |
| REQ-4 | Rotación de APP_KEY y credenciales DB | MUST | draft |
| REQ-5 | Secretos de CI/CD en GitHub | MUST | draft |

### Requirement: REQ-1 — `.env` fuera del repo e historial

El repositorio MUST NOT contener `backend/.env` ni ningún secreto de producción en el árbol de trabajo ni en el historial de git. La purga del historial MUST usar `git filter-repo` y el acceso remoto al repo público no debe conservar los commits originales con credenciales.

#### Scenario: SCENARIO-1 — Verificación tras la purga

- GIVEN la purga de historial completada con `git filter-repo`
- WHEN se ejecuta `git ls-files` buscando `backend/.env` y `git log --all` buscando el contenido original
- THEN el archivo no aparece en el índice
- AND ningún commit del historial reescrito contiene el APP_KEY ni las credenciales DB previas

#### Scenario: SCENARIO-2 — Nuevos clones sin secretos

- GIVEN un clon limpio del repositorio público
- WHEN se busca cualquier valor secreto conocido (APP_KEY, `lotto_user/secret`) en el árbol
- THEN no se encuentra ningún secreto en archivos versionados

### Requirement: REQ-2 — `.env.example` sin secretos

El backend MUST incluir `backend/.env.example` con todas las variables de entorno requeridas por la aplicación, usando placeholders o valores ficticios. MUST NOT contener claves reales ni credenciales válidas.

#### Scenario: SCENARIO-3 — Plantilla completa sin valores reales

- GIVEN el archivo `backend/.env.example`
- WHEN se compara contra las variables usadas por la aplicación
- THEN incluye todas las variables requeridas (APP_KEY, DB_*, CORS_ALLOWED_ORIGINS, SANCTUM_STATEFUL_DOMAINS, QUEUE_CONNECTION, CACHE_STORE, SESSION_DRIVER)
- AND ningún valor coincide con secretos reales de producción

### Requirement: REQ-3 — `.env.production` con permiso 600

El VPS MUST contener `backend/.env.production` con los secretos reales, con permisos 600, propiedad del usuario `deploy`, y MUST NOT estar versionado en el repositorio.

#### Scenario: SCENARIO-4 — Permisos y exclusión de git

- GIVEN el archivo `.env.production` creado en el VPS
- WHEN se inspeccionan permisos y estado en git
- THEN el archivo tiene modo 600 y propietario `deploy`
- AND `git check-ignore` lo excluye y nunca aparece en `git status`

### Requirement: REQ-4 — Rotación de APP_KEY y credenciales DB

Antes del go-live, el sistema MUST rotar el APP_KEY y las credenciales de la base de datos comprometidas. Tras la rotación, las credenciales antiguas MUST NOT autenticar en ningún servicio y las sesiones/tokens existentes quedan invalidados.

#### Scenario: SCENARIO-5 — Credenciales antiguas rechazadas

- GIVEN APP_KEY y credenciales DB rotadas
- WHEN se intenta autenticar con el APP_KEY o usuario/contraseña antiguos
- THEN la autenticación falla
- AND los datos cifrados con la clave antigua requieren re-emisión (nuevo login) para ser accesibles

### Requirement: REQ-5 — Secretos de CI/CD en GitHub

El pipeline CI/CD MUST obtener todos los secretos (credenciales DB de test, deploy key, token GHCR, endpoints) desde GitHub Secrets o Environments, y MUST NOT incluirlos en archivos versionados ni exponerlos en logs de Actions.

#### Scenario: SCENARIO-6 — Inyección y no-exposición en logs

- GIVEN un workflow configurado con GitHub Secrets
- WHEN el pipeline se ejecuta y se inspeccionan los logs
- THEN los secretos se inyectan como variables de entorno
- AND los logs no contienen ningún valor secreto en claro
