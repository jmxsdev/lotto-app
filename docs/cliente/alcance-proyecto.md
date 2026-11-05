# Documento de Alcance del Proyecto

| Campo | Valor |
|---|---|
| Documento | Documento de Alcance del Proyecto |
| Sistema | Lotto App — plataforma de operación para centros de apuestas |
| Versión | 1.1 |
| Fecha | 2026-10-16 |
| Ámbito | Entregable para el cliente |

---

## 1. Identificación del proyecto

| Campo | Detalle |
|---|---|
| Proyecto | Lotto App — plataforma de operación para centros de apuestas |
| Objeto | Desarrollo, puesta en operación y estabilización de un sistema integral para la venta de apuestas de loterías, la obtención de resultados, el pago de premios, los cierres de caja y las comisiones de la jerarquía comercial. |
| Cliente | Titular del centro de apuestas (el Cliente). |
| Proveedor | Equipo de desarrollo del software. |
| Versión del documento | 1.1 |
| Fecha | 2026-10-16 |
| Documento de referencia | Especificación de Requisitos de Software (ERS), v1.0 — base normativa de los requisitos aquí delimitados. |

---

## 2. Objetivo general y objetivos específicos

**Objetivo general.** Definir y delimitar el desarrollo, la puesta en operación y la estabilización de Lotto App como plataforma de operación del centro de apuestas del Cliente, en cumplimiento del marco regulatorio aplicable.

**Objetivos específicos**

- Operar la venta de los juegos soportados con validación por reglas de juego, límites, ticket único y control de anulación.
- Obtener resultados desde fuentes oficiales con latencia objetivo de ≤5–10 minutos, sin simulación de datos y con reconciliación automática.
- Liquidar y pagar premios con precisión monetaria: multiplicadores configurados, comodines y snapshot sin retroactividad.
- Administrar los cierres de caja y las comisiones de la jerarquía con integridad verificable (cuadre, topes y suma cero).
- Soportar en el software las obligaciones de facturación y retención que el marco CONALOT/SENIAT exige a la operación.
- Dejar expediente documental (especificación, manuales y fuentes) que pueda alimentar el registro del Programa Informático ante CONALOT.

---

## 3. Alcance incluido

Los siguientes subsistemas forman parte del alcance del proyecto:

| Subsistema | Contenido incluido |
|---|---|
| Backend / API de negocio | API JSON (`/api/v1`) para apuestas, tickets, resultados, premios, pagos, cierres, comisiones, jerarquía, reportes y configuraciones (Laravel 13, PHP 8.3+, MySQL 8.0). |
| Motor de resultados | Scrapers por fuente oficial, agendado data-driven en hora local de Venezuela, pasadas de reintento escalonadas, reconciliación periódica y barrido de cierre del día; sin simulación de resultados. |
| Motor de premios | Configuración por juego (base, modalidades y comodines), snapshot por jugada, redondeo a 2 decimales y liquidación automática al persistirse el resultado. |
| Cierre de caja | Cierre por taquilla sobre período continuo: totales de ventas, egresos, efectivo, desglose por método, arqueo con faltante/sobrante, vista previa, reporte semanal y re-cierre con clave PIN auditado. |
| Comisiones | Matriz (juego × nivel × moneda), cascada taquilla > grupo > banca > default global, topes por familia, suma cero, snapshot del cierre y ledger de liquidación idempotente. |
| Jerarquía y seguridad | Niveles `super_master`, `master`, `banca`, `grupo`, `agencia` y `taquilla`; usuarios, roles y permisos consistentes, tokens, huella MAC de taquilla y limitación de intentos de login. |
| Panel administrativo | Gestión de juegos y premios, límites, comisiones, configuraciones, usuarios y activación de taquillas; consulta de resultados, cierres y reportes. |
| Taquilla POS con OTA | Venta, anulación dentro de ventana, pago de premios, impresión de ticket y comprobantes, y actualización OTA (aplicación Windows; también ejecutable en navegador). |
| Operación | Contenedores separados (API, cola y planificador) en VPS Linux, CI/CD con despliegue automático, respaldos diarios verificados y alertas automáticas por Telegram. |
| Cumplimiento y documentación | Campos fiscales del ticket, retención de ISLR con bruto/neto y comprobante, caducidad parametrizable, auditoría, usuario de prueba para CONALOT y documentación del expediente técnico. |

---

## 4. Fuera de alcance

Los siguientes aspectos **no** forman parte de este alcance:

- **Sorteo de resultados.** El sistema **no** sortea ni genera resultados: consume los resultados publicados por las casas de lotería. No incluye generador de números aleatorios ni certificación de metrología de sorteo.
- **Operación offline.** Por decisión de negocio del Cliente, la venta requiere conexión; solo se incluye el aviso visible de desconexión. No hay cola de ventas offline.
- **Portal de apuestas para el jugador final.** El canal de venta es la taquilla del centro de apuestas; no se desarrolla sitio ni aplicación pública de apuestas.
- **Juegos sin fuente oficial verificada.** Los juegos sin fuente oficial fiable con valores (caso de referencia: La Ricachona) permanecen deshabilitados hasta obtener y verificar la fuente.
- **Modalidades y juegos nuevos no listados.** Su incorporación (por ejemplo, las candidatas evaluadas o juegos candidatos) constituye trabajo adicional a convenir (decisiones DEC-03 y DEC-06, §11).
- **Integración física con la Impresora Fiscal de Apuestas.** Se entrega la representación impresa con los campos exigidos; la integración física con el hardware del SENIAT se acuerda como trabajo adicional, supeditado al equipo que defina el Cliente.
- **Declaración fiscal y contable.** La liquidación y presentación de tributos (ISLR quincenal, impuesto a la actividad y demás) queda a cargo del Cliente; el software provee los datos y reportes que la sustentan.

---

## 5. Entregables

| Entregable | Descripción | Estado |
|---|---|---|
| Backend / API de negocio | API de venta, resultados, premios, pagos, cierres, comisiones y reportes en producción. | ✅ Entregado |
| Motor de resultados | Scrapers de fuentes oficiales, agendado por sorteo, reconciliación y cierre del día operando en el VPS. | ✅ Entregado |
| Motor de premios | Cálculo config-driven con snapshot, modalidades y comodines. | ✅ Entregado |
| Cierre de caja | Cierre por taquilla con arqueo, desglose, re-cierre con PIN y reportes. | ✅ Entregado |
| Comisiones | Cascada con topes, suma cero, snapshot del cierre y ledger de liquidación. | ✅ Entregado |
| Jerarquía y seguridad | Jerarquía completa, roles/permisos, activación de taquillas por MAC y controles de acceso. | ✅ Entregado |
| Panel administrativo | Editor de premios, límites, configuraciones, usuarios y consultas operativas. | ✅ Entregado |
| Taquilla POS 1.0.x | Venta multi-juego, pago de premios, comprobantes e impresión; actualización OTA publicada. | ✅ Entregado |
| Operación | VPS con contenedores separados, CI/CD, respaldos diarios verificados y alertas por Telegram. | ✅ Entregado |
| Documentación del cliente | Especificación de requisitos, alcance, guías de juegos, de premios y material de cumplimiento. | ⚠️ En curso |
| Estabilización y consistencia | Ajustes de consistencia en pagos, cierres, comisiones, latencia de resultados y deduplicación; confirmaciones del Cliente. | ⚠️ En curso |

---

## 6. Fases del proyecto

| Fase | Contenido | Estado |
|---|---|---|
| Jerarquía de agencias y locales | Modelado y administración de la cadena banca, grupo, agencia y taquilla. | ✅ Entregada |
| Catálogo e integración de juegos | Catálogo de 20 juegos con premios y scrapers construidos desde las fuentes oficiales. | ✅ Entregada |
| Cierre de caja | Base del cierre más ajustes: clave PIN, arqueo, vista previa y reporte semanal. | ✅ Entregada |
| Motor de premios y tasa de cambio | Motor config-driven y obtención automatizada de la tasa de cambio. | ✅ Entregada |
| Configuración de premios del panel | Editor de base, modalidades y comodines con auditoría de cambios. | ✅ Entregada |
| Límites por juego | Límites de monto por juego y nivel. | ✅ Entregada |
| Colecciones de API | Colección de peticiones para operación y pruebas del equipo. | ✅ Entregada |
| Comisiones | Cascada por niveles, topes por familia y ledger de liquidación. | ✅ Entregada |
| Tooling y CI/CD | Integración continua, pruebas y despliegue automatizado, preservando la taquilla. | ✅ Entregada |
| Taquilla 1.0.x | Venta, pagos, impresión y actualización OTA (versiones 1.0.0 a 1.0.3). | ✅ Entregada |
| Resultados en producción | Planificador versionado en el VPS, desplegado y verificado con resultados reales. | ✅ Entregada |
| Estabilización | Ajustes de consistencia (pagos, cierres, comisiones, latencia de resultados, deduplicación); confirmaciones del Cliente (DEC-01 a DEC-08) y ampliaciones opcionales a convenir. | ⚠️ En curso / planificada |

---

## 7. Criterios de aceptación del proyecto

| Área | Criterio de aceptación |
|---|---|
| Catálogo y premios | Los 20 juegos con los multiplicadores de la guía autoritativa; edición de premios sin retroactividad; juego sin fuente deshabilitado y fuera de venta. |
| Venta y tickets | Ticket único multi-juego con validación de reglas, límites, tasa activa y anulación en ventana; sin venta sin conexión ni sin tasa. |
| Resultados | Resultados persistidos dentro del SLA (≤5–10 minutos), sin duplicados, con reconciliación automática de huecos. |
| Premios | Cálculo correcto por modalidad y comodín; snapshot respetado; sin doble liquidación. |
| Pagos | Pago con recálculo autoritativo del motor; solo el pago marca la apuesta como `pagada`; comprobante con montos reales. |
| Cierres de caja | Período continuo; desglose por método cuadrado con las ventas; arqueo operativo; re-cierre auditado con clave. |
| Comisiones | Cascada con topes (16 / 25 / 100), suma cero entre niveles, ledger y pagos idempotentes; agencia sin cobro. |
| Seguridad | Roles y permisos uniformes en todos los módulos; taquillas activadas por MAC; credenciales hasheadas; HTTPS. |
| Operación | Despliegues sin interrumpir ventas; respaldos diarios verificados; alertas por Telegram activas. |
| Cumplimiento | Ticket-factura con todos los campos; retención de ISLR (bruto/neto) y comprobante; usuario de prueba para CONALOT; expediente documental entregado. |
| Estabilización | Ajustes de consistencia verificados y decisiones DEC-01 a DEC-08 resueltas o documentadas con el Cliente. |

---

## 8. Supuestos

- El Cliente cuenta con licencia IOBPAS vigente y RUNLOT, y mantiene al día sus registros ante CONALOT y SENIAT.
- Las fuentes oficiales de resultados son públicas y estables en disponibilidad y formato.
- El centro dispone de equipos Windows con conectividad a internet.
- El Cliente aporta los reglamentos faltantes y confirma las decisiones operativas pendientes (§11).
- El operador de cada juego mantiene publicados sus horarios y resultados oficiales.

---

## 9. Dependencias externas

- Sitios y API de las casas de lotería (fuentes oficiales de resultados y reglas).
- Fuente de la tasa de cambio utilizada por el sistema (obtención automática).
- VPS Linux de producción (contenedores de API, cola y planificador).
- Repositorio de código y registro de releases (CI/CD y feed de actualizaciones OTA).
- Servicio de mensajería Telegram (alertas al operador).

---

## 10. Riesgos y mitigaciones

| Riesgo | Efecto potencial | Mitigación |
|---|---|---|
| Cambio de formato o estructura de una fuente | Resultados no obtenidos para el juego afectado | Scrapers por fuente, verificación contra reglamentos, reconciliación con despacho automático y alertas; ajuste del scraper como soporte. |
| Indisponibilidad o lentitud de una fuente | Resultados fuera de la ventana del SLA | Reintentos escalonados, barrido de reconciliación y prioridad de fuentes oficiales. |
| Diferencia entre reglamento y operación real de una casa | Premios u horarios de referencia en duda | Protocolo de verificación (oficial vs informativa) y decisión documentada del Cliente (§11). |
| Hardware fiscal no definido | Bloqueo de la emisión fiscal del ticket | Representación impresa entregada; integración física como trabajo adicional supeditado al equipo que defina el Cliente. |
| Cambios regulatorios (CONALOT/SENIAT) | Nuevos requisitos de cumplimiento | Software parametrizable (juegos, caducidad, reportes) y expediente documental actualizable. |
| Dependencia de VPS y servicios externos | Interrupción de la operación | Contenedores separados, CI/CD, respaldos diarios verificados y alertas automáticas. |
| Decisiones del Cliente pendientes | Ambigüedad operativa en juegos afectados | Registro de decisiones pendientes (§11) y fase de estabilización para su cierre. |
| Compromiso de credenciales o accesos | Fraude o fuga de información | HTTPS, tokens, hash de credenciales, límite de intentos de login, MAC de taquilla y auditoría. |

---

## 11. Decisiones pendientes del cliente

| Ref | Tema | Decisión requerida |
|---|---|---|
| DEC-01 | Terminal Trío | Reglamento oficial 60× vs FAQ oficial 70× (+5× de aproximación). Confirmar el valor vigente. |
| DEC-02 | Triple Chance | Reglamento (solo A o B 150×; C+Signo 6.000×) vs afiche oficial (100×; 5.000×). Confirmar el valor vigente. |
| DEC-03 | Dupleta 1.000× y El Patronus | ¿Se soportan como modalidades nuevas? Requiere definir la forma de apuesta, confirmar el premio oficial y evaluar su implementación en el motor. |
| DEC-04 | La Ricachona | Juego deshabilitado por falta de fuente oficial con valores. Confirmar la obtención de la fuente o mantener el juego deshabilitado y oculto en las interfaces. |
| DEC-05 | Horarios operativos | Confirmar con el operador los casos documentados: domingos de Triple Táchira y Triple Zamorano; Trío Activo (3 vs 12 sorteos); Triple Caliente (5 vs 3 sorteos). |
| DEC-06 | Juegos y modalidades nuevos | ¿Se integran los candidatos evaluados (Granjita Plus y otros)? Las jugadas de terminal de terceros se evalúan como modalidad de un juego de `tripletas`, nunca como juego o tipo propio. Cada integración constituye trabajo adicional. |
| DEC-07 | PIN de cierre de caja | Confirmar el formato 4–8 dígitos (predeterminado 6) en ambas interfaces. |
| DEC-08 | Iconos de comodines | Confirmar la representación gráfica de los comodines (~8–10), aproximada en las interfaces. |
| — | Tope de comisión de la jugada de terminal | Al modelarse como modalidad de `tripletas`, confirmar el tope de comisión de la jugada de terminal (el tope de `tripletas` es 25 %; el tipo `terminales` aplicaba el default de 100 %). |
| — | Reglamentos no publicados | El Cliente debe aportar los reglamentos faltantes (Triple Fácil, El Arrejuntado, El Guacharito, Guácharo Activo, Loto Chaima, Selva Plus y Mega Animal 40) y versiones extraíbles de los documentos escaneados. |
| — | Confirmación general de horarios | Validar con el operador la vigencia de los horarios publicados por cada casa y su calendario semanal. |

---

## 12. Responsabilidades

| Ámbito | Cliente | Proveedor |
|---|---|---|
| Licencias y registros operativos (IOBPAS, RUNLOT y registros del centro) | Responsable de gestionarlos y mantenerlos vigentes. | Acompaña y aporta la documentación técnica que corresponda. |
| Registro del Programa Informático ante CONALOT (planilla, fuentes y Manual Descriptivo) | Designa al responsable del programa y consigna ante el ente. | Prepara y entrega la documentación técnica y las fuentes bajo confidencialidad. |
| Oficial de cumplimiento (LC/FT) y declaraciones fiscales | Responsable, con sus asesores. | Provee los datos y reportes generados por el software. |
| Hardware del centro y conectividad | Responsable de definir y adquirir los equipos (PCs, red, impresora fiscal). | Define requisitos técnicos e integra el software cuando el hardware esté definido. |
| Reglamentos y decisiones operativas | Aporta los reglamentos faltantes y confirma las decisiones pendientes. | Verifica fuentes, documenta diferencias y aplica lo decidido. |
| Desarrollo, mantenimiento y soporte del software | Reporta incidencias y valida entregas. | Responsable de la implementación y el mantenimiento. |
| Operación técnica (VPS, despliegues, monitoreo, respaldos y alertas) | — | Responsable. |
| Configuración operativa (usuarios, jerarquía, comisiones y límites) | Responsable de sus decisiones de negocio. | Habilita las herramientas, capacita y acompaña. |

---

## 13. Gestión de cambios

- **Versionado del alcance.** Todo cambio aprobado se refleja en una nueva versión de este documento y en la Especificación de Requisitos de Software cuando corresponda.
- **Edición de premios.** La actualización de premios desde el editor del panel es operación normal; por diseño no altera tickets ya vendidos (sin retroactividad).
- **Funcionalidad nueva.** La incorporación de juegos, modalidades, integraciones o canales no listados en §3 se evalúa como cambio de alcance (esfuerzo, costo y plazo) antes de su implementación.
- **Cambios en fuentes externas.** El ajuste de un scraper ante un cambio de formato de la fuente se atiende como soporte; si implica una fuente o un juego nuevos, se evalúa como cambio de alcance.
- **Canal de solicitudes.** Las solicitudes se presentan por el canal escrito que las partes acuerden y quedan registradas con su decisión (aprobada, aplazada o rechazada).

---

## 14. Glosario y referencias

- Los términos de negocio, las siglas regulatorias y las definiciones operativas constan en la sección 1.5 y en los anexos de la Especificación de Requisitos de Software (ERS), que es el documento normativo de referencia.
- Documentación de apoyo del set para el cliente: «Guía de premiación de los juegos», «Multiplicadores oficiales por juego», «Glosario de premios y modalidades», «Registro de fuentes oficiales por juego», «Registro de inconsistencias», «Guía del editor de premios», «Comparación de juegos», «Seguimiento de verificación» y «Plataformas de juegos».
- Marco regulatorio de referencia: Ley Nacional de Lotería, Providencia SNAT/2009/0102, Providencia PROV-001 (RUNLOT) y «Manual de cumplimiento — Desarrollador independiente».

---

## Control de cambios del documento

| Versión | Fecha | Descripción | Autor |
|---|---|---|---|
| 1.0 | 2026-10-16 | Emisión inicial del Documento de Alcance del Proyecto. | Equipo de desarrollo del software |
| 1.1 | 2026-10-16 | Ajuste de taxonomía: terminal como modalidad de `tripletas` (no un tipo de juego); catálogo de 20 juegos; nueva decisión pendiente del cliente (tope de comisión del terminal). | Equipo de desarrollo del software |
