# metodo-pago Specification

**Estado**: draft

## Purpose

Define la captura y el modelado del método de pago (`metodo_pago`) en los cobros de venta (`ingreso`) y pagos de premio (`egreso`/`devolucion`), sus reglas de validación, el default de USD a `efectivo` y el backfill histórico.

## Requirements

### Requirement: Captura de método en el cobro (ingreso)

`ApuestaService::crearApuesta` MUST persistir `metodo_pago` en el `Pago` de tipo `ingreso` generado por cada venta. El valor MUST pertenecer a `efectivo|transferencia|pago_movil|punto_venta`. Si el pago es USD, el método MUST fijarse a `efectivo`; si se omite, MUST tomar el default `efectivo`.

#### Scenario: Venta VES con método

- GIVEN una venta VES con `metodo_pago = transferencia`
- WHEN se crea la apuesta
- THEN el `Pago` ingreso persiste `metodo_pago = transferencia`

#### Scenario: Venta USD fuerza efectivo

- GIVEN una venta USD (o mixta con USD)
- WHEN se crea la apuesta
- THEN el `Pago` ingreso persiste `metodo_pago = efectivo`

#### Scenario: Método omitido

- GIVEN una venta sin `metodo_pago` explícito
- WHEN se crea la apuesta
- THEN el `Pago` ingreso persiste el default `efectivo`

### Requirement: Captura de método en el pago de premio (egreso/devolucion)

`PagoController::store` MUST aceptar y validar `metodo_pago` al registrar `egreso`/`devolucion` y persistirlo en el `Pago`. USD MUST fijarse a `efectivo`; si se omite, default `efectivo`.

#### Scenario: Pago de premio con método

- GIVEN un premio VES a pagar con `metodo_pago = pago_movil`
- WHEN se registra el pago
- THEN el `Pago` persiste `metodo_pago = pago_movil`

#### Scenario: Premio USD

- GIVEN un premio en USD
- WHEN se registra el pago
- THEN `metodo_pago = efectivo` (aunque se envíe otro valor)

#### Scenario: Método inválido

- GIVEN `metodo_pago` fuera del enum
- WHEN se registra el pago
- THEN responde 422 con errores de validación

### Requirement: Reglas de validación de método

El sistema MUST validar `metodo_pago` contra el enum `efectivo|transferencia|pago_movil|punto_venta`. Los pagos en USD MUST NOT admitir un método distinto de `efectivo`. Cada `Pago` SHALL llevar un único `metodo_pago` para todo el pago (incluido `mixto`).

#### Scenario: Valor fuera de enum

- GIVEN un `metodo_pago` no permitido
- WHEN se valida
- THEN se rechaza (422)

#### Scenario: USD con método no efectivo

- GIVEN un pago USD con `metodo_pago = transferencia`
- WHEN se procesa
- THEN el sistema lo fija a `efectivo`

### Requirement: Método único para pagos mixtos

Un `Pago` con `moneda = mixto` MUST llevar un único `metodo_pago` aplicable a todo el pago; el método por moneda (distinto para VES y USD) queda diferido como refinamiento (limitación documentada).

#### Scenario: Pago mixto con un método

- GIVEN un pago mixto (VES + USD) con `metodo_pago = efectivo`
- WHEN se persiste
- THEN un único método aplica a todo el pago

### Requirement: Default y backfill histórico

La migración MUST agregar `pagos.metodo_pago` (enum, nullable) con default `efectivo` y MUST hacer backfill de las filas históricas sin método a `efectivo` (OQ5). El desglose histórico resultante SHALL documentarse como aproximado.

#### Scenario: Backfill de filas existentes

- GIVEN `Pago` históricos sin `metodo_pago`
- WHEN se ejecuta la migración
- THEN quedan con `metodo_pago = efectivo`

#### Scenario: Modelo expone el método

- GIVEN el modelo `Pago`
- WHEN se serializa
- THEN incluye `metodo_pago` (fillable/cast)
