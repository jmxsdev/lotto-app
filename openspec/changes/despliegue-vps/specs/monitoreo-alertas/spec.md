---
capability: monitoreo-alertas
change: despliegue-vps
status: done
---

# monitoreo-alertas Specification

## Purpose

Observabilidad del stack de producción: Prometheus con node_exporter y cAdvisor como fuentes de métricas, Grafana para visualización, Alertmanager con receptor Telegram para notificaciones y Uptime Kuma en `status.gzuz.dev` vigilando la disponibilidad de la API. Los umbrales de disco se calibran sobre el almacenamiento real de 126 GB.

## Requirements

| # | Requirement | Strength | Estado |
|---|------------|----------|--------|
| REQ-1 | Stack de métricas (Prometheus/Grafana) | MUST | draft |
| REQ-2 | Alertmanager con receptor Telegram | MUST | draft |
| REQ-3 | Uptime Kuma en `status.gzuz.dev` | MUST | draft |
| REQ-4 | Alertas configuradas | MUST | draft |
| REQ-5 | Aislamiento del stack de monitoreo | SHOULD | draft |

### Requirement: REQ-1 — Stack de métricas (Prometheus/Grafana)

El sistema MUST operar Prometheus recolectando métricas de node_exporter (host) y cAdvisor (contenedores), con Grafana como interfaz de dashboards. Las métricas del host y de los contenedores del stack principal MUST estar disponibles en un dashboard consultable.

#### Scenario: SCENARIO-1 — Métricas visibles en Grafana

- GIVEN el stack de monitoreo operativo
- WHEN se consulta el dashboard de Grafana
- THEN se muestran métricas del host (CPU, RAM, disco) y de los contenedores (API, MySQL, Redis)
- AND los targets de Prometheus aparecen healthy sin errores de scrape

### Requirement: REQ-2 — Alertmanager con receptor Telegram

Alertmanager MUST enviar notificaciones a un bot de Telegram configurado, con el flujo completo: Prometheus evalúa la regla de alerta, Alertmanager la recibe y el mensaje llega al chat de Telegram.

#### Scenario: SCENARIO-2 — Alerta disparada llega a Telegram

- GIVEN una regla de alerta en estado firing
- WHEN transcurre el intervalo de evaluación y enrutamiento
- THEN se recibe el mensaje de alerta en el chat de Telegram
- AND el mensaje identifica el servicio y el valor observado

### Requirement: REQ-3 — Uptime Kuma en `status.gzuz.dev`

Uptime Kuma MUST estar publicada en `status.gzuz.dev` (proxied por Cloudflare) y MUST monitorear el endpoint `https://lotto.gzuz.dev/api/v1/juegos`, notificando por Telegram cuando el endpoint deja de responder.

#### Scenario: SCENARIO-3 — Caída de la API detectada por Uptime Kuma

- GIVEN Uptime Kuma monitoreando `https://lotto.gzuz.dev/api/v1/juegos`
- WHEN el endpoint responde error o timeout durante más de 2 minutos
- THEN el status cambia a down en `status.gzuz.dev`
- AND se envía la notificación de caída por Telegram

### Requirement: REQ-4 — Alertas configuradas

El sistema MUST disparar alertas en: API caída o error 5xx sostenido, certificado SSL por expirar (≤ 14 días), uso de disco superior al 80 % (sobre 126 GB → 100,8 GB ocupados), MySQL caído o sin respuesta y fallo del backup diario. Los umbrales de disco MUST calibrarse sobre el tamaño real de 126 GB.

#### Scenario: SCENARIO-4 — Umbral de disco sobre 126 GB

- GIVEN un disco de 126 GB con 101 GB ocupados (80,2 %)
- WHEN Prometheus evalúa la regla de uso de disco
- THEN la alerta pasa a pending y luego firing
- AND la notificación llega por Telegram sin esperar a un umbral global de otro tamaño

#### Scenario: SCENARIO-5 — SSL por expirar

- GIVEN un certificado de `lotto.gzuz.dev` con 10 días de validez restante
- WHEN Prometheus evalúa la regla de expiración SSL
- THEN se dispara la alerta de renovación inminente

#### Scenario: SCENARIO-6 — MySQL caído

- GIVEN MySQL del stack sin responder al exporter o al probe
- WHEN transcurre el intervalo de evaluación
- THEN se dispara la alerta de MySQL down
- AND la API muestra errores de conexión en las métricas de cAdvisor

### Requirement: REQ-5 — Aislamiento del stack de monitoreo

El stack de monitoreo SHOULD desplegarse de forma independiente del compose principal para que su degradación no afecte a la API; detener los servicios de monitoreo MUST NOT interrumpir la API.

#### Scenario: SCENARIO-7 — Monitoreo detenido no afecta la API

- GIVEN los servicios de monitoreo detenidos
- WHEN se consulta `https://lotto.gzuz.dev/api/v1/juegos`
- THEN la API sigue respondiendo 200
- AND solo deja de actualizarse la telemetría
