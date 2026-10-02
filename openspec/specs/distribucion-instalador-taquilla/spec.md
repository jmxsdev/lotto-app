# distribucion-instalador-taquilla Specification

## Purpose

Especifica la persistencia y el servicio del instalador de la taquilla
(`Taquilla-Setup-<version>.exe`) a través de deploys de producción: montaje
correcto del volumen, contrato `serve`/`update-check`, migración de la release
vigente y verificación post-deploy.

## Requirements

### Requirement: Persistencia del instalador tras deploy

El sistema MUST mantener servible el instalador publicado tras la recreación del
contenedor de producción.

#### Scenario: Persistencia tras deploy

- GIVEN un instalador publicado y `update-check` reportando la versión X
- WHEN se ejecuta un deploy de CI/CD exitoso (recreación de `lotto_api_prod`)
- THEN `GET /api/v1/releases/serve` con URL firmada devuelve 200
- AND el `sha256` servido coincide con el reportado por `update-check`
- AND el archivo existe en `/app/storage/app/releases`

### Requirement: Publicación en la ruta real del contenedor

El comando `releases:publish` MUST escribir el instalador en
`/app/storage/app/releases` dentro del contenedor.

#### Scenario: Nueva publicación

- GIVEN el flujo manual del runbook y un `.exe` nuevo
- WHEN se ejecuta `releases:publish` con el `.exe`
- THEN `update-check` reporta la nueva versión y `serve` la sirve
- AND la versión anterior queda reemplazada (fila única, sin historial)

### Requirement: Contrato notify-only sin cambios

El contrato de `update-check` y `latest` MUST permanecer sin cambios: notify-only,
sin URL de descarga y sin auto-instalación.

#### Scenario: Contrato notify-only intacto

- GIVEN el fix aplicado y una taquilla con versión distinta
- WHEN la taquilla consulta `/update-check`
- THEN recibe solo `version` y `sha256` (sin URL de descarga)
- AND nunca auto-instala

### Requirement: Montaje y documentación del volumen

El volumen `taquilla_releases` MUST quedar montado en la ruta real de la app
(`/app/storage/app/releases`) y MUST quedar documentado en la documentación de
operación.

#### Scenario: Volumen montado en la ruta real

- GIVEN la definición de `docker-compose.prod.yml` corregida
- WHEN se inspecciona el montaje del contenedor
- THEN `taquilla_releases` está montado en `/app/storage/app/releases`

#### Scenario: Documentación actualizada

- GIVEN la documentación de mantenimiento y el runbook de operación
- WHEN se revisa la ruta y el estado de persistencia documentados
- THEN la documentación indica `/app/storage/app/releases` y que el volumen persiste a través de deploys
- AND el runbook incluye el paso de verificación post-deploy

### Requirement: Migración consistente de la release vigente

La migración MUST dejar `update-check` y `serve` consistentes: nunca se anuncia
una versión sin archivo servible.

#### Scenario: Migración sin release previa

- GIVEN un VPS con el volumen `taquilla_releases` vacío y una fila en `releases`
- WHEN se despliega con el montaje corregido
- THEN el archivo se recupera (copy-on-mount) o el runbook indica re-publicar
- AND `serve` deja de devolver 404

### Requirement: Verificación post-deploy

El job `deploy` SHOULD verificar tras `up -d` que `/api/v1/releases/serve`
responde 200, para que una regresión de persistencia falle el deploy.

#### Scenario: Chequeo post-deploy en CI

- GIVEN un deploy ejecutado por el job `deploy`
- WHEN termina `up -d`
- THEN se verifica que `releases/serve` responde 200 o que el archivo existe en el volumen
- AND un fallo en la verificación marca el deploy como fallido
