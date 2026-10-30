# Delta for colecciones-api

> **Open decisions (proposed, owner review)**: D8 (`POST /pagos` egreso-only), D9 (`DELETE /tickets/{id}` annuls + refunds). Spec written for the proposed behavior.

## MODIFIED Requirements

### Requirement: Contratos de payload y respuesta

Los ejemplos MUST ser fieles al contrato de `main` (autoridad: controladores, no tests):

| Área | Contrato vigente |
|---|---|
| Límites | sin `fraccion`/`limite_tiempo`; PUT `banca_id` required; batch modo `scope` |
| Apuestas/Tickets | `selecciones[]`; modalidades single-draw |
| Pagos | `POST /pagos` egreso-only; `ingreso`/`devolucion` → 422; montos opcionales (premio autoritativo); pagable `pendiente\|ganadora` |
| Tickets | `DELETE /tickets/{id}` anula el ticket y registra `devolucion` `Pago`(s) |
| Usuarios/Taquillas | `agencia_id`; rol `agencia` |
| Auth | `X-Panel` o fingerprint según flujo |
| Juegos/reglas | respuesta con `premios` |

(Previously: the Pagos row allowed any `tipo` and `devolucion` mutated state; Tickets had no annulment+refund row.)

#### Scenario: Límites sin campos retirados

- GIVEN `PUT /api/v1/limites/{juego}`
- WHEN se inspecciona el body
- THEN no incluye `fraccion` ni `limite_tiempo`, y `banca_id` es required

#### Scenario: Pago autoritativo (egreso)

- GIVEN `POST /api/v1/pagos` tipo `egreso` sin montos
- WHEN la apuesta está `pendiente|ganadora`
- THEN el backend aplica el premio del motor

#### Scenario: Multi-selección

- GIVEN `POST /api/v1/apuestas` o `/tickets`
- WHEN se inspecciona el body
- THEN ejemplifica `combinacion.selecciones[]`

#### Scenario: ingreso/devolucion rejected

- GIVEN `POST /api/v1/pagos` con `tipo=ingreso` o `tipo=devolucion`
- WHEN se inspecciona el contrato
- THEN documenta 422 con el mensaje de redirección (ingreso automático en venta; devolución por anulación de ticket)

## ADDED Requirements

### Requirement: Annulment + refund contract in collections

The collection MUST document that `DELETE /api/v1/tickets/{id}` performs a pre-draw annulment with refund, and MUST document the error codes: 422 for invalid state/window, 403 for cross-tenant, 404 for unknown ticket.

#### Scenario: annulment documented

- GIVEN the `Tickets` collection
- WHEN `DELETE /tickets/{id}` is inspected
- THEN it documents refund + annulment semantics and the 422/403/404 codes
