# Comisiones de cierre: escenarios para decisión

**Para:** cliente / área de negocio
**Objetivo:** definir tres reglas del cálculo de comisiones antes de corregir el sistema.

Este documento no propone un cambio unilateral. Muestra con cifras qué ocurre hoy y qué
ocurriría con cada alternativa, para que la definición sea del negocio y no del equipo técnico.

**Base de todos los ejemplos:** 1.000.000 Bs vendidos en el período, un juego de tipo
animalitos y una sola taquilla, en una cadena banca → grupo → taquilla.

---

## 1. Cómo se calcula hoy, en una frase

Cada nivel de la cadena configura un porcentaje. El sistema resuelve la tasa de cada nivel,
la limita al tope del tipo de juego y la multiplica por las ventas. **Los tres niveles cobran
por separado y los tres montos se suman.**

Topes vigentes por tipo de juego:

| Tipo de juego | Tope |
|---|---|
| Animalitos | 16% |
| Tripletas | 25% |
| Resto de juegos | 100% |

---

## 2. Primera decisión — ¿el tope es por nivel o de toda la cadena?

Configuración del ejemplo: **banca 10%, grupo 6%, taquilla 16%**.

### Hoy: el tope se aplica a cada nivel por separado

| Nivel | Configurado | Cálculo | Tasa aplicada | Comisión |
|---|---|---|---|---|
| Banca | 10% | mínimo(10; 100; 16) | 10% | 100.000 Bs |
| Grupo | 6% | mínimo(6; 90; 16) | 6% | 60.000 Bs |
| Taquilla | 16% | mínimo(16; 84; 16) | 16% | 160.000 Bs |
| **Total** | **32%** | | **32%** | **320.000 Bs** |

Con esta regla, si los tres niveles se configuran al tope, la cadena reparte **48%** en
animalitos, aunque el tope del tipo sea 16%.

### Alternativa A: tope de toda la cadena, cobra primero el nivel superior

| Nivel | Configurado | Cálculo | Tasa aplicada | Comisión |
|---|---|---|---|---|
| Banca | 10% | mínimo(10; 16) | 10% | 100.000 Bs |
| Grupo | 6% | mínimo(6; 16 − 10) | 6% | 60.000 Bs |
| Taquilla | 16% | mínimo(16; 16 − 16) | 0% | 0 Bs |
| **Total** | **32%** | | **16%** | **160.000 Bs** |

### Alternativa B: tope de toda la cadena, reparto proporcional

| Nivel | Configurado | Reparto | Tasa aplicada | Comisión |
|---|---|---|---|---|
| Banca | 10% | 10 × (16 ÷ 32) | 5% | 50.000 Bs |
| Grupo | 6% | 6 × (16 ÷ 32) | 3% | 30.000 Bs |
| Taquilla | 16% | 16 × (16 ÷ 32) | 8% | 80.000 Bs |
| **Total** | **32%** | | **16%** | **160.000 Bs** |

**Pregunta 1:** el tope de 16% en animalitos, ¿es el máximo que puede configurar **cada
nivel**, o el máximo **total** que reparte la cadena?

---

## 3. Segunda decisión — cuando un nivel no configura tasa, ¿cobra?

Hoy, si un nivel no configura su porcentaje, toma el del nivel superior y **lo cobra igual**.
El resultado es que una sola tasa configurada se paga varias veces.

### Caso 3.1 — Solo la banca configura 10%; grupo y taquilla quedan vacíos

| Nivel | Configurado | Tasa que aplica hoy | Comisión hoy |
|---|---|---|---|
| Banca | 10% | 10% | 100.000 Bs |
| Grupo | — (vacío) | 10% heredada | 100.000 Bs |
| Taquilla | — (vacío) | 10% heredada | 100.000 Bs |
| **Total** | | **30%** | **300.000 Bs** |

### Alternativa: solo cobra quien tiene la tasa configurada

| Nivel | Configurado | Tasa que aplicaría | Comisión |
|---|---|---|---|
| Banca | 10% | 10% | 100.000 Bs |
| Grupo | — (vacío) | 0% | 0 Bs |
| Taquilla | — (vacío) | 0% | 0 Bs |
| **Total** | | **10%** | **100.000 Bs** |

### Caso 3.2 — Banca 10% y grupo 20%; taquilla vacía

| Nivel | Configurado | Comisión hoy | Comisión con la alternativa |
|---|---|---|---|
| Banca | 10% | 100.000 Bs | 100.000 Bs |
| Grupo | 20% | 200.000 Bs | 200.000 Bs |
| Taquilla | — (vacío) | 200.000 Bs (hereda 20%) | 0 Bs |
| **Total** | | **500.000 Bs (50%)** | **300.000 Bs (30%)** |

**Pregunta 2:** cuando un nivel no configura tasa, ¿debe cobrar la heredada del nivel
superior (como hoy) o **cero**, de modo que solo cobre quien la configuró explícitamente?

---

## 4. Tercera decisión — ¿qué período cubre la comisión del cierre?

El cierre encadena desde el cierre anterior, pero hoy la comisión se expande al **día
calendario completo**, no al tiempo realmente transcurrido.

**Ejemplo:** cierre de ayer a las 23:00 y cierre de hoy a las 23:00.
Ventas: 100.000 Bs ayer antes de las 23:00 (ya incluidas en el cierre de ayer) y 20.000 Bs
en las 24 horas del período que se está cerrando.

| Concepto | Hoy (día calendario) | Ventana exacta |
|---|---|---|
| Base sobre la que se calcula | 120.000 Bs | 20.000 Bs |
| Comisión al 16% | 19.200 Bs | 3.200 Bs |

La base se multiplica por 6 porque el tramo de ayer ya se había cobrado en el cierre de ayer.

**Aclaración importante:** si el cierre se hace siempre a medianoche, ambas opciones dan el
mismo resultado. La diferencia aparece cuando el cierre se hace a cualquier otra hora.

**Pregunta 3:** la comisión del cierre, ¿debe cubrir el **día calendario completo** o
exactamente el **tiempo transcurrido desde el cierre anterior**?

---

## 5. Resumen del impacto

| Decisión | Hoy | Alternativa | Diferencia en el ejemplo |
|---|---|---|---|
| 1. Tope por nivel vs. tope de cadena | 320.000 Bs | 160.000 Bs | −160.000 Bs |
| 2. Herencia (caso 3.1) | 300.000 Bs | 100.000 Bs | −200.000 Bs |
| 3. Ventana (ejemplo) | 19.200 Bs | 3.200 Bs | −16.000 Bs |

Las tres alternativas **reducen** lo que se paga. Ninguna lo aumenta. Por eso la decisión es
de negocio y no puede tomarla el equipo técnico.

---

## 6. Qué no cambia con ninguna de las opciones

- **El histórico no se recalcula:** los cierres ya guardados quedan tal como están.
- **El efectivo no cambia:** ventas menos egresos, arqueo y faltante/sobrante quedan intactos.
- **Las liquidaciones ya pagadas no se tocan:** las nuevas usarán el criterio que se defina.
- **El cierre sigue siendo una vez al día:** ninguna opción modifica esa operación.

---

## 7. Preguntas para responder

1. **Tope:** ¿16% en animalitos es el máximo por nivel (hoy) o el máximo total de la cadena?
   - [ ] Por nivel (como hoy)
   - [ ] Total de la cadena, cobra primero el nivel superior
   - [ ] Total de la cadena, reparto proporcional

2. **Herencia:** si un nivel no configura tasa, ¿cobra la del nivel superior o cobra cero?
   - [ ] Cobra la heredada (como hoy)
   - [ ] Cobra cero; solo cobra quien configuró

3. **Ventana:** la comisión del cierre, ¿cubre el día calendario o el período exacto entre cierres?
   - [ ] Día calendario (como hoy)
   - [ ] Período exacto entre cierres

---

*Documento preparado a partir de los hallazgos técnicos de los issues #65 y #68. Las cifras
corresponden a un ejemplo de 1.000.000 Bs; el equipo puede repetir el cálculo con los montos
reales de operación si el negocio lo solicita.*
