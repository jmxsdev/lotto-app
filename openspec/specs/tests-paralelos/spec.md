# tests-paralelos Specification

**Estado**: draft

## Purpose

Define la ejecución en paralelo de la suite de tests del backend (local y CI) para distribuir el costo dominante de seed/migración entre N procesos, con aislamiento de base de datos por proceso y sin estado compartido entre archivos.

## Requirements

| # | Requirement | Strength |
|---|-------------|----------|
| REQ-1 | Ejecución paralela de la suite backend | MUST |
| REQ-2 | Aislamiento de base de datos por proceso | MUST |
| REQ-3 | CI habilita las bases por proceso | MUST |

### Requirement: Ejecución paralela de la suite backend

El sistema MUST permitir ejecutar la suite backend en paralelo mediante el script `test:parallel`, y la corrida MUST completarse con todos los tests en verde.

#### Scenario: Suite paralela completa y verde

- GIVEN el entorno backend con la dependencia de paralelización instalada
- WHEN se ejecuta `composer test:parallel`
- THEN la suite corre distribuida en múltiples procesos
- AND todos los tests pasan sin fallos

#### Scenario: Privilegio local insuficiente falla claro

- GIVEN un usuario MySQL local sin privilegio de crear bases de datos
- WHEN se ejecuta `composer test:parallel`
- THEN la ejecución falla con un error de privilegio explícito
- AND la documentación de desarrollo indica el prerrequisito de `CREATE DATABASE`

### Requirement: Aislamiento de base de datos por proceso

Cada proceso paralelo MUST operar sobre una base de datos de test aislada, de modo que la semántica de `RefreshDatabase` se preserve por proceso y ningún test falle por estado compartido entre archivos.

#### Scenario: Aislamiento sin contaminación entre procesos

- GIVEN la suite ejecutándose en paralelo con N procesos
- WHEN los tests corren
- THEN cada proceso opera sobre su propia base de test
- AND no se detectan fallos por orden o estado compartido entre archivos

#### Scenario: Dos corridas consecutivas sin flakiness

- GIVEN la suite paralela configurada
- WHEN se ejecuta dos veces consecutivas
- THEN ambas corridas producen el mismo resultado
- AND no aparece ningún test flaky

### Requirement: CI habilita las bases por proceso

El job de tests del CI MUST ejecutarse con credenciales capaces de crear las bases de test por proceso, y MUST correr la suite en paralelo terminando en verde.

#### Scenario: CI crea las bases por proceso

- GIVEN el workflow de CI con el service de MySQL aprovisionado
- WHEN el job de tests corre en paralelo
- THEN se crean las bases de test por proceso
- AND el job termina en verde sin errores de privilegio

#### Scenario: CI falla ante privilegio insuficiente

- GIVEN el job de tests con credenciales sin privilegio de crear bases
- WHEN corre la suite en paralelo
- THEN el job falla con un error de privilegio
- AND no se construye ni publica ninguna imagen
