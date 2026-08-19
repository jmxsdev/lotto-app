---
capability: ci-cd
change: despliegue-vps
status: done
---

# ci-cd Specification

## Purpose

Pipeline de integración y despliegue continuos con GitHub Actions para el repositorio público `jmxsdev/lotto-app`: tests con MySQL como service, lint con Pint, build de la imagen FrankenPHP hacia GHCR, deploy SSH al VPS, healthcheck post-deploy y rollback automático a la imagen anterior. Los secretos se inyectan desde GitHub Secrets y el acceso SSH usa una deploy key de solo lectura.

## Requirements

| # | Requirement | Strength | Estado |
|---|------------|----------|--------|
| REQ-1 | Tests PHPUnit con MySQL service | MUST | draft |
| REQ-2 | Pint como gate de estilo | MUST | draft |
| REQ-3 | Build y push a GHCR | MUST | draft |
| REQ-4 | Deploy SSH y healthcheck | MUST | draft |
| REQ-5 | Rollback a tag anterior | MUST | draft |
| REQ-6 | Deploy key de solo lectura | MUST | draft |

### Requirement: REQ-1 — Tests PHPUnit con MySQL service

El workflow MUST ejecutar la suite PHPUnit del backend contra un MySQL 8 provisionado como service de Actions, con `.env.testing` afinado para ese entorno (credenciales del service, no SQLite en memoria salvo para suites unitarias). El pipeline MUST abortar antes del build si algún test falla.

#### Scenario: SCENARIO-1 — Suite verde habilita el despliegue

- GIVEN un push a la rama principal con tests en verde contra MySQL service
- WHEN el workflow avanza por sus etapas
- THEN la suite completa pasa
- AND el job continúa al build de la imagen

#### Scenario: SCENARIO-2 — Test rojo bloquea el pipeline

- GIVEN un push con un test de feature fallando
- WHEN el job de tests termina
- THEN el workflow falla
- AND no se construye ni despliega ninguna imagen

### Requirement: REQ-2 — Pint como gate de estilo

El workflow MUST ejecutar Laravel Pint en modo check y fallar si el estilo del código difiere de la convención del proyecto.

#### Scenario: SCENARIO-3 — Violación de estilo aborta CI

- GIVEN un push con código fuera del estilo Pint
- WHEN se ejecuta `vendor/bin/pint --test`
- THEN el job falla señalando los archivos afectados
- AND el pipeline no continúa

### Requirement: REQ-3 — Build y push a GHCR

El workflow MUST construir la imagen FrankenPHP del backend y publicarla en GHCR con dos tags: el hash del commit y `latest`. El push MUST autenticarse contra GHCR sin exponer el token en logs.

#### Scenario: SCENARIO-4 — Imagen publicada con doble tag

- GIVEN el build exitoso del commit `abc1234`
- WHEN se consulta el registry GHCR
- THEN existen las tags `abc1234` y `latest` apuntando a la misma imagen
- AND el manifest no incluye secretos de build en las labels

### Requirement: REQ-4 — Deploy SSH y healthcheck

El workflow MUST desplegar en el VPS vía SSH con `docker compose pull && docker compose up -d` y MUST validar la disponibilidad con un healthcheck `GET /api/v1/juegos` que devuelva 200 antes de declarar el despliegue exitoso.

#### Scenario: SCENARIO-5 — Despliegue verificado por healthcheck

- GIVEN una imagen nueva publicada en GHCR
- WHEN el job de deploy ejecuta `compose pull && up -d` y luego el healthcheck
- THEN `GET /api/v1/juegos` responde 200 en `https://lotto.gzuz.dev`
- AND el job reporta éxito solo si la respuesta fue 200

### Requirement: REQ-5 — Rollback a tag anterior

Si el healthcheck falla tras un despliegue, el workflow MUST ejecutar rollback automático apuntando el compose a la imagen del commit anterior y revalidar el healthcheck. El tag `latest` anterior MUST seguir disponible en GHCR.

#### Scenario: SCENARIO-6 — Rollback automático ante healthcheck fallido

- GIVEN un despliegue del commit `abc1234` cuyo healthcheck responde distinto de 200
- WHEN el job de rollback apunta a la imagen del commit previo `abc1233` y re-ejecuta `up -d`
- THEN el healthcheck vuelve a responder 200
- AND el workflow termina en estado de fallo informando el rollback aplicado

#### Scenario: SCENARIO-7 — Rollback manual documentado

- GIVEN una incidencia detectada fuera del pipeline
- WHEN el operador ejecuta `docker compose pull <tag-anterior> && docker compose up -d`
- THEN la API vuelve a la versión anterior sin migraciones destructivas
- AND las migraciones son idempotentes y no ejecutan seeders destructivos

### Requirement: REQ-6 — Deploy key de solo lectura

El acceso SSH del CI MUST usar una deploy key de solo lectura (sin permisos de escritura en el repositorio), almacenada en GitHub Secrets, y MUST NOT usar tokens con alcance de escritura ni credenciales de usuario personal.

#### Scenario: SCENARIO-8 — La deploy key no puede escribir

- GIVEN la deploy key configurada en Secrets
- WHEN se intenta un push al repositorio usando esa llave
- THEN la operación es rechazada por el servidor
- AND el pipeline sí logra conectar por SSH al VPS para desplegar
