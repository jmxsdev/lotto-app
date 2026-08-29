# reportes-agencia Specification

**Estado**: draft

## Purpose

Agrupación de reportes por local físico (`nivel=agencia` → tabla `agencias`), sin configuración propia del local. Corrige el bug latente donde `nivel=agencia` cae a banca.

## Requirements

### Requirement: ventasTotales por local

`ventasTotales` MUST soportar `nivel=agencia` agrupando por `agencias` (locals), con joins `taquillas→agencias→grupos→bancas`; `nivel=taquilla` agrupa por máquina.

#### Scenario: Agrupación por local

- GIVEN un reporte con `nivel=agencia`
- WHEN se calcula `ventasTotales`
- THEN agrupa por local (no cae a banca)

#### Scenario: Agrupación por máquina

- GIVEN `nivel=taquilla`
- WHEN se calcula `ventasTotales`
- THEN agrupa por máquina

### Requirement: Cuadre de caja por local

`cuadreCaja` MUST agrupar por local cuando `nivel=agencia` y por máquina cuando `nivel=taquilla`.

#### Scenario: Cuadre por local

- GIVEN `nivel=agencia`
- WHEN se calcula el cuadre de caja
- THEN agrupa por local

#### Scenario: Cuadre por máquina

- GIVEN `nivel=taquilla`
- WHEN se calcula el cuadre
- THEN agrupa por máquina

### Requirement: Rendimiento por agencia

El sistema MUST ofrecer agrupación de rendimiento por local (rendimiento por agencia) y etiquetar el nivel máquina como "Taquilla".

#### Scenario: Rendimiento por local

- GIVEN un reporte de rendimiento
- WHEN se solicita por agencia
- THEN agrupa por local

#### Scenario: Label de máquina

- GIVEN el rendimiento por máquina
- WHEN se muestra
- THEN el label es "Taquilla"

### Requirement: Semántica de labels (Agencia=local, Taquilla=máquina)

Los labels/claves de reportes MUST usar "Agencia" para el local y "Taquilla" para la máquina (relacionTickets, vencidos, serie temporal).

#### Scenario: Label de local

- GIVEN un reporte que expone el local
- WHEN se renderiza
- THEN el label es "Agencia" (local)

#### Scenario: Label de máquina

- GIVEN un reporte que expone la máquina
- WHEN se renderiza
- THEN el label es "Taquilla"

### Requirement: Sin configuración propia del local

La agrupación por local SHALL NOT depender de configuración local (monedas/vigencia/tiempo/límites); el local es passthrough.

#### Scenario: Herencia de grupo/banca

- GIVEN un local sin configuración propia
- WHEN se agrupa por agencia
- THEN hereda del grupo/banca

### Requirement: Actualización de tests de reportes

Los tests que fijan `nivel=agencia`≡taquillas (`CuadreCajaReportTest`, `ReporteTest`) MUST actualizarse en el mismo cambio.

#### Scenario: Suite de reportes verde

- GIVEN la suite actualizada
- WHEN se ejecuta `composer test`
- THEN los tests de reportes pasan con la nueva semántica
