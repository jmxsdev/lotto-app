# Especificación de Requisitos de Software (ERS)

| Campo | Valor |
|---|---|
| Documento | Especificación de Requisitos de Software (ERS) |
| Sistema | Lotto App — plataforma de operación para centros de apuestas |
| Versión | 1.1 |
| Fecha | 2026-10-16 |
| Ámbito | Entregable para el cliente |

---

## 1. Introducción

### 1.1 Propósito

Este documento especifica, en forma normativa, los requisitos funcionales y no funcionales que Lotto App **DEBE** cumplir como plataforma de operación de un centro de apuestas de loterías en Venezuela. Sus propósitos son:

- Servir de base de entendimiento, verificación y aceptación entre el cliente y el equipo de desarrollo.
- Documentar las decisiones de diseño que gobiernan la arquitectura y la operación del sistema (§5).
- Integrar el expediente técnico que puede alimentar el registro del Programa Informático ante la Comisión Nacional de Loterías (CONALOT).

Los requisitos se redactan en presente normativo ("el sistema **DEBE**…"). Todo aspecto aún no confirmado por el cliente o el operador se señala como pendiente de confirmación.

### 1.2 Alcance del producto

Lotto App es un sistema integral para operar centros de apuestas de loterías en Venezuela. Comprende tres piezas de software:

- **Backend API de negocio** (Laravel 13, PHP 8.3+): API pura JSON bajo `/api/v1`; no renderiza vistas de negocio.
- **Panel administrativo web**: gestión de juegos, premios, límites, comisiones, usuarios, cierres, resultados y reportes.
- **Taquilla POS**: aplicación de escritorio Windows con actualización OTA, que también puede ejecutarse en navegador; es el punto de venta y de pago de premios.

El producto cubre **20 juegos** agrupados en dos tipos (`animalitos` y `tripletas`), la obtención de resultados desde fuentes oficiales, el cálculo y la liquidación de premios, el pago de premios, los cierres de caja, las comisiones de la jerarquía comercial y el cumplimiento regulatorio que el software debe soportar.

La jugada de **terminal** (las 2 últimas cifras de un triple) es una **modalidad** de los juegos de `tripletas`, no un tipo de juego: la variante comercializada como *Terminal Activo / Terminal Trío* corresponde a la modalidad de terminal del Trío Activo (60×).

El sistema **NO** sortea ni genera resultados: consume los resultados publicados por las casas de lotería.

### 1.3 Audiencia y uso previsto

| Audiencia | Uso previsto |
|---|---|
| Titular del centro de apuestas (el Cliente) | Conocer y validar los requisitos; base del acta de aceptación. |
| Operador del sistema / equipo de desarrollo | Guía normativa de implementación, verificación y mantenimiento. |
| Autoridades competentes (CONALOT) | Insumo del expediente de registro del Programa Informático. |
| Personal administrativo del centro | Comprensión de las capacidades y los límites del sistema. |

### 1.4 Referencias

Documentos del set para el cliente (citados por título):

- «Guía de premiación de los juegos» — cómo se juega, se gana y se paga en los 20 juegos; tabla autoritativa de multiplicadores.
- «Multiplicadores oficiales por juego» — consolidado de referencia de premios y fuentes.
- «Glosario de premios y modalidades» — terminología de tipos de juego, modalidades y contrato de datos.
- «Registro de fuentes oficiales por juego» — fuente oficial e informativa de cada juego.
- «Registro de inconsistencias» — registro de referencias y decisiones derivadas.
- «Guía del editor de premios», «Comparación de juegos», «Seguimiento de verificación» y «Plataformas de juegos» — material de apoyo y seguimiento.

Marco regulatorio (documentación legal del proyecto):

- Ley Nacional de Lotería (LNL).
- Providencia SNAT/2009/0102 (retención de ISLR, factura e Impresora Fiscal de Apuestas).
- Providencia PROV-001 (RUNLOT).
- «Manual de cumplimiento — Desarrollador independiente» — obligaciones CONALOT/SENIAT.

Documentos técnicos de soporte (uso del proveedor):

- «Arquitectura del backend» — organización de los dos motores y del scraping.
- «Motor de cierres de caja y comisiones» — reglas del bloque económico.
- «Changelog de la taquilla» — versiones publicadas y actualización OTA.

### 1.5 Definiciones y acrónimos

| Término | Definición |
|---|---|
| Apuesta | Registro de una jugada de un cliente sobre un sorteo, con monto y moneda. Es la unidad que se liquida y se paga. |
| Jugada | Selección concreta dentro de una apuesta (figura, número, modalidad o combinación). Un ticket puede agrupar varias jugadas. |
| Ticket | Documento que agrupa una o más apuestas (multi-juego y multi-jugada) con código único e irrepetible; cumple la función de factura. |
| Sorteo | Evento de una casa de lotería identificado por juego, fecha y hora; su resultado determina los aciertos. |
| Modalidad | Forma de acertar con premio propio dentro de un tipo de juego (p. ej., triple, punta, terminal/cola, uña, zodiacal). |
| Comodín | Figura o condición especial publicada por la fuente que modifica el pago de una jugada (eleva el multiplicador o es figura apostable). |
| Multiplicador | Número por el que se multiplica el monto apostado para obtener el premio (se expresa como ×). |
| Snapshot | Copia congelada de una configuración (premios o tasa de cambio) en el momento de la venta o del cierre; protege contra cambios retroactivos. |
| Scraper | Componente que obtiene y parsea resultados desde una fuente externa (sitio o API de una casa). |
| Fuente oficial | Canal de publicación propio de la casa de lotería del juego (web o API); origen prioritario de resultados, horarios y reglas. |
| Cierre de caja | Acto por taquilla que totaliza ventas, egresos, efectivo, desglose por método y comisión de un período continuo, con arqueo opcional. |
| Arqueo | Conteo de efectivo del período; su diferencia contra el efectivo calculado produce faltante o sobrante. |
| Comisión | Porcentaje que corresponde a cada nivel jerárquico (banca, grupo o taquilla) sobre las ventas de su alcance, con cascada, topes y suma cero. |
| Ledger de comisiones | Libro de filas de comisión liquidables generadas bajo demanda por (nivel, entidad, período), con marcado de pago idempotente. |
| Banca / Grupo / Agencia / Taquilla | Jerarquía comercial: la banca y el grupo administran y cobran comisión; la agencia es el local físico (no cobra; su valor es un rollup informativo); la taquilla vende. |
| RUNLOT | Registro Único Nacional de Loterías y Casas de Apuestas: registro operativo central del centro de apuestas (Providencia PROV-001). |
| CONALOT | Comisión Nacional de Loterías: autoridad que autoriza juegos y registra el Programa Informático. |
| IOBPAS | Instituto de beneficencia pública que administra la lotería regional correspondiente; su licencia habilita la operación. |
| LNL | Ley Nacional de Lotería. |
| ISLR | Impuesto sobre la Renta. Sobre premios de lotería se aplica una retención del 16 % en los supuestos autorizados (34 % fuera de ellos). |
| OTA | Actualización Over-The-Air de la taquilla: descarga e instalación de nuevas versiones desde el feed de releases. |
| SLA | Acuerdo de nivel de servicio. Para resultados, la latencia objetivo es ≤5–10 minutos desde el sorteo. |

---

## 2. Descripción general

### 2.1 Perspectiva y contexto

Lotto App opera en el marco de las apuestas de lotería regional autorizadas. La cadena comercial es **banca → grupo → agencia/taquilla**: la banca y el grupo administran y cobran comisiones; la agencia es el local físico, cuyo valor en reportes es informativo; la taquilla es el punto de venta presencial.

El sistema es una herramienta del operador autorizado: no lo sustituye en sus obligaciones regulatorias ni fiscales, y no genera resultados de sorteos. Se integra con dos clases de fuentes externas: las fuentes oficiales de resultados de cada casa (scrapers) y la tasa de cambio usada para vender en moneda extranjera (obtención automática). El límite del sistema es el de esas fuentes: si un juego no dispone de fuente oficial fiable con valores verificados, no se habilita.

### 2.2 Funciones principales

- Vender apuestas y emitir tickets (multi-juego y multi-jugada) con validación, límites, vencimiento y anulación.
- Obtener resultados de fuentes oficiales con agendado por sorteo, reintentos escalonados, reconciliación y cierre del día.
- Liquidar apuestas y calcular premios con multiplicadores configurados por juego, modalidades y comodines.
- Pagar premios con recálculo autoritativo del backend y comprobante de pago.
- Cerrar caja por taquilla, con arqueo, desglose por método de pago y re-cierre protegido por clave.
- Liquidar comisiones de la jerarquía en cascada, con topes por familia y suma cero.
- Administrar juegos, premios, límites, usuarios, activación de taquillas y configuraciones.
- Reportar ventas, premios, tickets, vencidos, cuadres y comisiones; producir los libros de jugadas y de premios.
- Operar con monitoreo, respaldos verificados, alertas y despliegue continuo.

### 2.3 Usuarios y roles

| Rol | Descripción y alcance |
|---|---|
| `super_master` | Administración global del sistema y de la jerarquía. |
| `master` | Administra bancas; alcance sobre sus bancas. |
| `banca` | Administra grupos y taquillas de su banca; cobra comisión. |
| `grupo` | Administra taquillas de su grupo; cobra comisión. |
| `agencia` | Local físico; consulta su subárbol (rollup informativo); no cobra comisión. |
| `taquilla` | Punto de venta: vende, anula dentro de la ventana configurada, paga premios y cierra su caja. |

La matriz detallada de capacidades por rol se presenta en el Anexo C.

### 2.4 Entorno de operación

| Componente | Entorno |
|---|---|
| Backend API | Linux (VPS), PHP 8.3+, Laravel 13, API JSON `/api/v1`, MySQL 8.0. |
| Cola de trabajos | Servicio dedicado de cola asíncrona sobre Redis, para scraping y liquidación. |
| Planificador | Proceso de agenda versionado en el VPS (sin dependencia de cron del host). |
| Panel administrativo | Aplicación web para navegador moderno. |
| Taquilla POS | Windows 10/11 empaquetada (instalador) con OTA; también ejecutable en navegador. |
| Exposición | HTTPS mediante proxy web frontal. |
| Zona horaria | America/Caracas en todo el agendado, la operación y la presentación de horas. |

### 2.5 Restricciones y cumplimiento regulatorio

- Solo juegos y resultados autorizados (Ley Nacional de Lotería).
- Ticket = factura con los campos exigidos por la LNL (art. 24) y la Providencia SNAT/2009/0102 (art. 13).
- Retención de ISLR sobre premios pagados (16 %), con registro de bruto y neto, comprobante de retención y datos para la declaración quincenal; la declaración queda a cargo del cliente.
- Representación impresa con los campos exigidos para la Impresora Fiscal de Apuestas del SENIAT; la integración física se acuerda como trabajo adicional, condicionada al equipo que defina el cliente.
- Registro del Programa Informático ante CONALOT: usuario de prueba, Manual Descriptivo y entrega de fuentes bajo confidencialidad.
- El software no sortea: no hay generador de números aleatorios ni certificación de metrología de sorteo asociada.

### 2.6 Supuestos y dependencias

- El cliente cuenta con licencia IOBPAS vigente y RUNLOT, y mantiene sus registros ante CONALOT y SENIAT.
- Las fuentes oficiales de resultados son públicas y estables en disponibilidad y formato.
- El centro dispone de equipos Windows con conectividad a internet.
- El cliente aporta los reglamentos faltantes y confirma las decisiones operativas pendientes (ver «Decisiones pendientes del cliente» en el Documento de Alcance del Proyecto).
- El operador de cada juego mantiene publicados sus horarios oficiales.
- La edición de premios no tiene retroactividad: no altera tickets ya vendidos.

---

## 3. Requisitos funcionales

Convención: cada módulo se identifica con `RF-nn` y sus requisitos con `RF-nn.m`. El verbo **DEBE** expresa obligación normativa.

### RF-01 Catálogo y configuración de juegos

| ID | Requisito |
|---|---|
| RF-01.1 | El sistema **DEBE** mantener un catálogo único de juegos con su tipo (`animalitos` o `tripletas`), nombre, estado (activo/inactivo), monedas habilitadas y costo mínimo de venta. |
| RF-01.2 | El sistema **DEBE** registrar por juego sus horarios de sorteo en hora local de Venezuela y formato uniforme de 24 horas (HH:mm), su fuente de resultados y la casa u operadora responsable. |
| RF-01.3 | El sistema **DEBE** almacenar la configuración de premios por juego (multiplicador base, modalidades y comodines) como dato editable, con historial de auditoría de cada cambio. |
| RF-01.4 | El sistema **DEBE** soportar límites de monto de venta (mínimo y máximo) por juego, moneda y nivel de la jerarquía, configurables desde el panel administrativo. |
| RF-01.5 | El sistema **DEBE** permitir activar y desactivar juegos. Un juego inactivo **NO DEBE** ofrecerse en venta, **NO DEBE** scrapearse y **NO DEBE** liquidarse. |
| RF-01.6 | El sistema **DEBE** exigir configuración explícita de la fuente de scraping por juego (URL o endpoint y parámetros), sin valores por defecto silenciosos. |
| RF-01.7 | El sistema **NO DEBE** soportar un tipo de juego `terminales`: la jugada de terminal (2 últimas cifras de un triple) **DEBE** modelarse como modalidad `terminal` dentro de los juegos de `tripletas`. La variante comercial *Terminal Activo / Terminal Trío* es la modalidad de terminal del Trío Activo. |

**Criterios de aceptación**

- El catálogo refleja los 20 juegos con los valores de la «Guía de premiación de los juegos» sin diferencias.
- No existe ningún juego de tipo `terminales`: la jugada de terminal vive como modalidad de los juegos de `tripletas`.
- Editar la configuración de premios de un juego no altera tickets ya vendidos.
- Un juego sin fuente oficial con valores verificados permanece deshabilitado y fuera de venta, scraping y liquidación.
- Toda edición de premios o límites queda auditada (usuario, fecha y contenido del cambio).

### RF-02 Venta de apuestas y tickets

| ID | Requisito |
|---|---|
| RF-02.1 | El sistema **DEBE** validar cada jugada con las reglas del tipo de juego correspondiente (forma de la combinación, opciones válidas, cantidad de selecciones) y rechazar las jugadas inválidas sin persistirlas. |
| RF-02.2 | El sistema **DEBE** exigir una tasa de cambio activa para vender en bolívares o dólares, obtenida automáticamente, y registrar la tasa aplicada como snapshot por apuesta. |
| RF-02.3 | El sistema **DEBE** aplicar los límites por juego y nivel, el costo mínimo y la vigencia de la apuesta (vencimiento configurable en horas). |
| RF-02.4 | El sistema **DEBE** asignar un código único e irrepetible de ticket y soportar tickets multi-juego y multi-jugada. |
| RF-02.5 | El sistema **DEBE** permitir la anulación de una apuesta solo dentro de una ventana configurable, excluyéndola de los totales de ventas y de comisiones. |
| RF-02.6 | El sistema **DEBE** imprimir el ticket y el comprobante con los campos fiscales y de juego exigidos; el ticket impreso **NO DEBE** exponer el premio potencial. |
| RF-02.7 | El sistema **NO DEBE** permitir ventas sin conexión con el backend: ante una desconexión **DEBE** mostrar un aviso visible y bloquear la venta. |

**Criterios de aceptación**

- Una jugada válida produce un ticket con código único, la apuesta en estado pendiente y su registro de ingreso por venta.
- Vender sin tasa activa, fuera de límites o con combinación inválida es rechazado sin emitir ticket.
- Una anulación dentro de la ventana excluye la apuesta de los totales del cierre de caja.
- El ticket impreso contiene nombre del juego, fecha/hora/número de cada sorteo, opciones apostadas, monto por opción y total.

### RF-03 Obtención de resultados (scrapers)

| ID | Requisito |
|---|---|
| RF-03.1 | El sistema **DEBE** obtener resultados exclusivamente de la fuente oficial de cada casa; el uso de agregadores **DEBE** tratarse como excepción autorizada, documentada y sujeta a migración a la fuente oficial. |
| RF-03.2 | El sistema **DEBE** agrupar los juegos que comparten fuente en un único fetch y resolver de forma explícita la fuente y el parser de cada juego. |
| RF-03.3 | El sistema **DEBE** persistir cada resultado con índice único por (juego, fecha de sorteo, hora de sorteo) e idempotencia de escritura, y deduplicar el lote del día antes de liquidar. |
| RF-03.4 | El sistema **DEBE** normalizar la ingesta: hora en formato HH:mm (24 h), siglas o etiquetas de signos, padding de triples a 3 cifras y textos normalizados para comparación; el contrato scraper→motor **DEBE** ser explícito y equivalente por familia. |
| RF-03.5 | El sistema **DEBE** ejecutar, tras cada sorteo, pasadas de reintento escalonadas dentro de la ventana del SLA (≤5–10 minutos), un barrido periódico de reconciliación que despache automáticamente las fuentes con huecos y un barrido de cierre del día. |
| RF-03.6 | El sistema **DEBE** reconciliar los resultados persistidos contra el calendario de sorteos esperados por (fuente × hora de sorteo) y detectar, reportar y despachar los huecos. |
| RF-03.7 | El sistema **NO DEBE** simular, estimar ni inventar resultados. Un juego sin fuente oficial fiable con valores verificados permanece deshabilitado. |
| RF-03.8 | El sistema **DEBE** capturar y persistir de forma estructurada los campos especiales publicados por la fuente (por ejemplo, comodines), junto con el resto del resultado del sorteo. |

**Criterios de aceptación**

- En condiciones normales de la fuente, el resultado de un sorteo queda persistido dentro de la ventana del SLA.
- Ejecutar dos veces el mismo scrape no duplica resultados ni provoca dobles liquidaciones.
- Un hueco de resultado es detectado y reintentado automáticamente por el barrido de reconciliación.
- Los datos persistidos contienen los campos que el motor de premios requiere por familia (triples, signo, figura, comodines).

### RF-04 Cálculo y liquidación de premios

| ID | Requisito |
|---|---|
| RF-04.1 | El sistema **DEBE** separar responsabilidades: el plugin del juego determina la forma del acierto y valida la jugada; el motor de premios determina exclusivamente el multiplicador. Ningún plugin **DEBE** decidir montos. |
| RF-04.2 | El sistema **DEBE** congelar la configuración de premios vigente al momento de la venta (snapshot por jugada); editar premios con posterioridad **NO DEBE** alterar tickets ya vendidos. |
| RF-04.3 | El sistema **DEBE** calcular el premio como monto × multiplicador, con redondeo a 2 decimales, en la moneda de la apuesta (bolívares o dólares). |
| RF-04.4 | Al persistirse un resultado, el sistema **DEBE** liquidar las apuestas afectadas (ganadora o perdida), acumular el premio en el ticket y habilitar su pago. |
| RF-04.5 | El sistema **DEBE** liquidar los comodines según su tipo: bandera, número o palabra (elevación del multiplicador), acumulativos (suma) y letra apostable (figura apostable independiente). |
| RF-04.6 | El sistema **DEBE** normalizar los textos comparados (mayúsculas, minúsculas y acentos) de modo que variantes como "Delfín" y "Delfin" sean equivalentes. |
| RF-04.7 | El sistema **DEBE** cubrir con pruebas la forma de acierto de cada modalidad, incluida la familia `tripletas`, alineada 1:1 con los datos que persisten los scrapers. |
| RF-04.8 | El sistema **DEBE** garantizar que una apuesta no se liquide dos veces para el mismo resultado (anti doble liquidación). |

**Criterios de aceptación**

- El premio calculado coincide con monto × multiplicador redondeado a 2 decimales en todas las modalidades cubiertas por pruebas.
- Editar la configuración de premios después de una venta no modifica el premio de tickets existentes.
- Los comodines documentados (MEGA 40×, Selva Plus A 160× / B 200×, Guacharito 150×, Guácharo 120×, Patronus 120×, palabra PATRONUS +20×) se liquidan con su multiplicador correcto.
- Reproducir la liquidación del mismo resultado no genera segundos abonos.

### RF-05 Pago de premios

| ID | Requisito |
|---|---|
| RF-05.1 | El sistema **DEBE** registrar el pago de premio como egreso y recalcular el premio en el backend con el motor de premios (fuente de verdad del dinero), sin confiar en montos provistos por el cliente de la API. |
| RF-05.2 | El pago de premio **DEBE** ser el único flujo que marca la apuesta como pagada. |
| RF-05.3 | Las devoluciones y los ingresos **NO DEBEN** alterar el estado de la apuesta ni el premio liquidado. |
| RF-05.4 | El sistema **DEBE** soportar el pago en bolívares, dólares o mixto, imprimir el comprobante con los montos reales registrados y admitir su reimpresión. |
| RF-05.5 | El sistema **DEBE** registrar el premio bruto, la retención de ISLR y el monto neto, y emitir el comprobante de retención correspondiente. |
| RF-05.6 | El sistema **DEBE** aplicar la caducidad parametrizable de premios y reportar los premios vencidos. |

**Criterios de aceptación**

- Pagar un premio marca solo esa apuesta como pagada y registra el egreso con el monto calculado por el motor.
- Una devolución posterior no cambia estados de apuesta ni montos de premio.
- El comprobante muestra el premio realmente pagado y su reimpresión conserva los montos registrados.
- El reporte de premios pagados expone monto bruto y neto por ticket para la declaración quincenal.

### RF-06 Cierre de caja

| ID | Requisito |
|---|---|
| RF-06.1 | El sistema **DEBE** calcular el cierre por taquilla sobre un período continuo que inicia donde terminó el cierre anterior y finaliza al momento de cerrar. |
| RF-06.2 | El sistema **DEBE** totalizar ventas (excluyendo apuestas anuladas), egresos y efectivo, con desglose por método de pago y moneda, normalizando el equivalente en dólares. |
| RF-06.3 | El desglose por método de pago **DEBE** cuadrar con el total de ventas de las apuestas del período (conciliación verificable). |
| RF-06.4 | El sistema **DEBE** ofrecer arqueo opcional de efectivo, con faltante o sobrante calculado a 2 decimales. |
| RF-06.5 | El sistema **DEBE** ofrecer vista previa sin persistir y reporte semanal consolidado por taquilla. |
| RF-06.6 | El re-cierre de un día ya cerrado **DEBE** exigir una clave PIN (4–8 dígitos, almacenada hasheada) y registrar auditoría de quién y cuándo re-cerró. |
| RF-06.7 | El cálculo y la persistencia del cierre **DEBEN** ejecutarse en transacción, sin duplicar cierres ante reintentos. |

**Criterios de aceptación**

- Un cierre nuevo toma como inicio el fin del cierre anterior de la misma taquilla.
- Los totales excluyen apuestas anuladas y el desglose por método cuadra con las ventas del período (diferencia cero).
- El re-cierre sin clave válida es rechazado; con clave válida queda auditado.
- La vista previa no persiste datos y coincide con el cierre que luego se persiste.

### RF-07 Comisiones

| ID | Requisito |
|---|---|
| RF-07.1 | El sistema **DEBE** configurar comisiones en una matriz por (juego × nivel × moneda), con cascada de tasa efectiva: taquilla > grupo > banca > default global. |
| RF-07.2 | El sistema **DEBE** aplicar topes por familia: `animalitos` 16 %, `tripletas` 25 % y otras 100 %; los defaults globales **NO DEBEN** exceder esos topes. |
| RF-07.3 | El sistema **DEBE** garantizar suma cero: lo liquidable de un nivel **NO DEBE** exceder lo que queda tras las tasas propias de sus ancestros. |
| RF-07.4 | El sistema **DEBE** calcular el snapshot de comisión del cierre sobre el período exacto del cierre y solo sobre la taquilla que cierra. |
| RF-07.5 | El ledger de liquidación **DEBE** generarse bajo demanda, ser idempotente (restricción única y bloqueos) y rechazar rangos solapados. |
| RF-07.6 | El marcado de pago de una comisión **DEBE** ser idempotente, con transición `pendiente → pagado` y sin reverso. |
| RF-07.7 | La agencia **NO DEBE** cobrar comisión; su valor en reportes es un rollup informativo del subárbol. |

**Criterios de aceptación**

- Con tasas propias de 10 % (banca), 20 % (grupo) y 100 % (taquilla), lo liquidable resulta 10 / 20 / 70 (suma 100).
- Dos liquidaciones concurrentes del mismo rango no generan filas duplicadas.
- El snapshot del cierre coincide con el cálculo sobre el período exacto y la taquilla que cierra, con más de una taquilla por grupo.
- Configurar una tasa por encima del tope de la familia es rechazado.

### RF-08 Jerarquía, usuarios y seguridad

| ID | Requisito |
|---|---|
| RF-08.1 | El sistema **DEBE** modelar la jerarquía `super_master → master → banca → grupo → agencia/taquilla`, con usuarios anclados a un nivel y alcance de datos por rol. |
| RF-08.2 | El sistema **DEBE** autenticar por tokens y almacenar contraseñas y claves PIN hasheadas, nunca en claro ni expuestas en respuestas de la API. |
| RF-08.3 | El sistema **DEBE** aplicar un único mecanismo de autorización (roles y permisos) consistente en todos los módulos, sin verificaciones divergentes entre pantallas. |
| RF-08.4 | El sistema **DEBE** identificar y activar cada taquilla por huella de dispositivo (MAC) y token asociado. |
| RF-08.5 | El sistema **DEBE** limitar los intentos de inicio de sesión como medida anti fuerza bruta. |
| RF-08.6 | El sistema **DEBE** exponer toda comunicación sobre HTTPS. |

**Criterios de aceptación**

- Un usuario solo accede a los datos de su alcance; fuera de él recibe rechazo (403).
- Un cambio de rol o permiso se refleja de forma uniforme en todos los módulos.
- Las credenciales y claves nunca se devuelven en las respuestas de la API.
- Una taquilla no activada o sin huella válida no puede vender.

### RF-09 Panel administrativo

| ID | Requisito |
|---|---|
| RF-09.1 | El panel **DEBE** permitir gestionar el catálogo de juegos: alta y configuración, activación/desactivación y edición de premios (base, modalidades y comodines) con auditoría. |
| RF-09.2 | El panel **DEBE** permitir configurar límites por juego y nivel, la matriz de comisiones y el default global por moneda. |
| RF-09.3 | El panel **DEBE** permitir configurar el vencimiento de apuestas (horas) y la clave de cierre. |
| RF-09.4 | El panel **DEBE** permitir gestionar usuarios y la activación de taquillas. |
| RF-09.5 | El panel **DEBE** exponer resultados, ventas, ganadores, cierres y comisiones para consulta, según el alcance del rol. |
| RF-09.6 | Toda acción sensible del panel **DEBE** quedar registrada en auditoría. |

**Criterios de aceptación**

- El editor de premios guarda base, modalidades y comodines sin afectar otras secciones de la configuración del juego.
- Las páginas de configuración aplican los roles definidos y rechazan al resto.
- Las consultas del panel respetan el alcance del rol del usuario autenticado.
- Cada cambio de configuración sensible deja registro de auditoría consultable.

### RF-10 Reportes

| ID | Requisito |
|---|---|
| RF-10.1 | El sistema **DEBE** ofrecer el reporte de cuadre de caja por nivel (`banca`, `grupo`, `agencia`, `taquilla`) con venta, pagados, devoluciones, vencidos, efectivo, comisión y participación. |
| RF-10.2 | El sistema **DEBE** ofrecer el reporte de ventas totales con venta, premio, utilidad, participación y comisión por entidad. |
| RF-10.3 | El sistema **DEBE** ofrecer la relación de tickets con sus jugadas, sorteos y estados. |
| RF-10.4 | El sistema **DEBE** ofrecer el reporte de apuestas y premios vencidos. |
| RF-10.5 | El sistema **DEBE** producir los libros de jugadas y de premios a partir de los datos registrados. |
| RF-10.6 | Todos los reportes **DEBEN** aplicar el alcance de datos del rol del usuario solicitante. |

**Criterios de aceptación**

- El cuadre de caja y las ventas totales cuadran con los cierres persistidos del período.
- La relación de tickets permite reconstruir las jugadas y los sorteos de cada ticket.
- Los libros de jugadas y premios son exportables como reportes.
- Un usuario no puede obtener por reporte datos fuera de su alcance.

### RF-11 Operación, monitoreo y continuidad

| ID | Requisito |
|---|---|
| RF-11.1 | La operación **DEBE** ejecutarse en contenedores separados para API, cola y planificador sobre un VPS Linux; solo el servicio API **DEBE** ejecutar migraciones. |
| RF-11.2 | El proyecto **DEBE** contar con integración continua que ejecute pruebas y despliegue automáticamente. |
| RF-11.3 | El sistema **DEBE** ejecutar respaldos diarios y verificar su resultado automáticamente. |
| RF-11.4 | El sistema **DEBE** enviar alertas automáticas al operador por Telegram ante caída de la API, fallas de respaldo e incidencias relevantes. |
| RF-11.5 | La taquilla **DEBE** actualizarse por OTA, con chequeo al inicio y periódico, y una guarda que impida instalar mientras haya una venta o un ticket en curso. |
| RF-11.6 | El planificador de scraping **DEBE** operar versionado en el VPS, sin depender del cron del host. |

**Criterios de aceptación**

- El despliegue de una nueva versión del backend no interrumpe una venta en curso.
- Una falla de backup o de disponibilidad genera alerta al operador.
- Una actualización OTA pendiente se posterga automáticamente durante una venta activa.
- El planificador queda registrado y versionado junto al despliegue del VPS.

### RF-12 Cumplimiento regulatorio soportado por el software

| ID | Requisito |
|---|---|
| RF-12.1 | El ticket **DEBE** cumplir la función de factura con los campos exigidos: nombre del juego e IOBPAS, RIF del comercializador, RIF del centro, fecha/hora/número de factura, fecha/hora/número de cada sorteo, opciones apostadas, monto por opción y total. |
| RF-12.2 | El sistema **DEBE** calcular y registrar la retención de ISLR (16 %) sobre premios pagados, con monto bruto, retención y monto neto, y emitir el comprobante para la declaración quincenal del cliente. |
| RF-12.3 | La caducidad de premios **DEBE** ser parametrizable. |
| RF-12.4 | El sistema **DEBE** registrar auditoría de apuestas, anulaciones, pagos y accesos. |
| RF-12.5 | El sistema **DEBE** generar los libros de jugadas y de premios como reportes. |
| RF-12.6 | El sistema **DEBE** soportar control de edad para impedir la venta a menores de edad. |
| RF-12.7 | El sistema **DEBE** disponer de un usuario de prueba para la verificación de CONALOT que no afecte datos reales. |
| RF-12.8 | El sistema **DEBE** emitir la representación impresa con los campos exigidos para la Impresora Fiscal de Apuestas del SENIAT; la integración física **DEBE** acordarse como trabajo adicional, condicionada al equipo que defina el cliente. |
| RF-12.9 | El proveedor **DEBE** entregar las fuentes del software y el Manual Descriptivo requeridos para el registro del Programa Informático ante CONALOT, bajo confidencialidad. |

**Criterios de aceptación**

- Un ticket de muestra contiene todos los campos fiscales y de sorteo exigidos.
- El sistema produce el detalle de retenciones de ISLR (bruto y neto) por período quincenal.
- El usuario de prueba permite navegar el sistema sin alterar datos reales del negocio.
- La caducidad de premios aplicada según parámetro queda reflejada en estados y reportes.

---

## 4. Requisitos no funcionales

| ID | Requisito | Verificación |
|---|---|---|
| RNF-01 | **Disponibilidad.** Los servicios de venta y de resultados **DEBEN** estar disponibles durante el horario de operación del centro; las caídas **DEBEN** generar alerta en minutos. Objetivo mensual exacto: pendiente de confirmación con el operador. | Monitoreo y alertas; bitácora de incidencias. |
| RNF-02 | **Latencia de resultados (SLA).** Un resultado **DEBE** quedar persistido y liquidado en ≤5–10 minutos desde el sorteo, mediante pasadas de reintento escalonadas dentro de esa ventana. | Métricas de latencia del planificador; conciliación de huecos. |
| RNF-03 | **Integridad monetaria.** Premios, comisiones y arqueos **DEBEN** calcularse con redondeo a 2 decimales; el desglose por método **DEBE** cuadrar con las ventas; las comisiones **DEBEN** cumplir suma cero. | Pruebas de cálculo; conciliación del cierre. |
| RNF-04 | **Idempotencia.** Resultados, liquidación de apuestas, ledger de comisiones, marcado de pagos y cierres **DEBEN** resistir reintentos y concurrencia sin duplicar efectos. | Pruebas de doble ejecución y concurrencia. |
| RNF-05 | **Auditoría y trazabilidad.** **DEBE** registrarse quién, qué y cuándo en apuestas, anulaciones, pagos, premios, accesos, ediciones de configuración y re-cierres. | Consulta de registros de auditoría. |
| RNF-06 | **Seguridad.** **DEBEN** aplicarse HTTPS, autenticación por token, hash de credenciales y PIN, limitación de intentos de login y huella MAC de taquilla, con permisos uniformes por rol. | Revisión de configuración y pruebas de autorización. |
| RNF-07 | **Usabilidad de taquilla.** La operación **DEBE** ser posible con teclado (atajos de función y flechas), con foco directo en los campos de venta, mensajes de error reales del backend y confirmación explícita en operaciones de dinero. | Pruebas de flujo de venta y pago; verificación en Windows. |
| RNF-08 | **Portabilidad y actualización.** La taquilla **DEBE** funcionar en Windows 10/11 como aplicación instalable y en navegador; la actualización **NO DEBE** interrumpir nunca una venta. | Gate de release; verificación en Windows. |
| RNF-09 | **Zona horaria.** Todo el sistema (calendario, scraping, tickets, cierres) **DEBE** operar en hora local de Venezuela (America/Caracas), con horas en formato de 24 h uniforme y sin doble conversión. | Pruebas de agendado; revisión de horarios persistidos. |
| RNF-10 | **Observabilidad.** **DEBEN** existir métricas y logs de resultados, scraping y trabajos de liquidación, y alertas por Telegram ante incidencias relevantes. | Panel de métricas; receptor de alertas. |
| RNF-11 | **Respaldo y recuperación.** Los respaldos **DEBEN** ser diarios y verificados, con alerta ante fallo; el procedimiento de restauración **DEBE** estar documentado. RPO/RTO objetivo: pendiente de confirmación con el operador. | Bitácora de respaldos; simulación de restauración. |
| RNF-12 | **Mantenibilidad y extensibilidad.** El calendario y el catálogo **DEBEN** ser data-driven (agregar juegos u horarios sin tocar el código de agendado); el contrato scraper→motor **DEBE** ser explícito; **DEBE** existir cobertura de pruebas por familia y modalidad. | Revisión de configuración; suite de pruebas. |

---

## 5. Decisiones de diseño

Esta sección documenta las decisiones estructurales del sistema y su justificación. El detalle por área (catálogo, motores, comisiones y operación) se complementa con los documentos hermanos citados en §1.4.

### DD-01 Obtención de resultados por scrapers multi-fuente ante la ausencia de un proveedor único de datos

**Contexto.** En Venezuela no existe un servicio centralizado que publique los resultados de los 20 juegos: cada casa publica por su propia web o API, con formatos distintos y disponibilidad variable.

**Decisión.** Obtener resultados con un scraper por fuente/familia, agrupando en un solo fetch los juegos que comparten fuente; dar prioridad absoluta a las fuentes oficiales; admitir agregadores solo como excepción autorizada y documentada; verificar de forma permanente contra los reglamentos oficiales; normalizar los formatos en la ingesta; reconciliar contra el calendario de sorteos; y no simular el dato: sin fuente fiable, el juego no se habilita ni se liquida.

**Consecuencias.** El catálogo depende de terceros: cada juego nuevo exige identificar y validar su fuente; un cambio de formato de una casa requiere ajuste de su scraper (trabajo de soporte); la calidad del dato se compensa con verificación cruzada, idempotencia y reconciliación.

### DD-02 Separación estricta de responsabilidades: DATO / FORMA / DINERO

**Contexto.** El resultado de una apuesta exige responder tres preguntas distintas: qué salió en el sorteo, si la jugada acierta y cuánto se paga.

**Decisión.** Los scrapers obtienen el dato; los plugins de juego definen la forma del acierto y validan la jugada; el motor de premios decide exclusivamente el multiplicador con la configuración del juego. Ningún plugin decide dinero.

**Consecuencias.** Los cambios de premios son configuración, no código; las reglas de cada juego quedan aisladas y probables por modalidad; el cálculo es auditable (forma detectada + multiplicador aplicado).

### DD-03 Premios config-driven con snapshot inmutable por jugada

**Contexto.** Los valores de premio cambian por decisión del operador y esos cambios no deben afectar lo ya vendido.

**Decisión.** La configuración de premios (base, modalidades y comodines) vive por juego y se congela en el momento de la venta (snapshot por jugada). Editar premios con posterioridad no altera tickets existentes.

**Consecuencias.** No hay retroactividad; cada ticket conserva el respaldo del valor con el que se vendió; las correcciones de premios aplican a ventas futuras; el editor de premios es seguro para operar en caliente.

### DD-04 Regla de soporte de juegos

**Contexto.** No todos los juegos disponen de reglamento o publicación oficial con valores verificados (juego de referencia: La Ricachona).

**Decisión.** Sin fuente oficial con valores verificados, el juego queda deshabilitado: no se ofrece, no se scrapea y no se liquida. Se habilita solo cuando exista y se verifique la fuente.

**Consecuencias.** El catálogo ofrecido es confiable; habilitar un juego nuevo exige fuente + configuración + verificación previa; los juegos deshabilitados quedan documentados y visibles para el operador.

### DD-05 Calendario data-driven en hora local

**Contexto.** Los horarios y las fuentes cambian con frecuencia, y las conversiones de zona horaria son fuente de errores de agendado.

**Decisión.** El calendario se registra como dato (fuente × hora de sorteo) en hora local de Venezuela; el agendado se genera desde la base de datos y se ejecuta en hora local, sin conversiones dobles; incluye pasadas escalonadas de reintento por sorteo, un barrido periódico de reconciliación y un barrido de cierre del día.

**Consecuencias.** Agregar un juego u horario no requiere tocar el código de agendado; la operación se mantiene alineada con la hora del país; los huecos se corrigen automáticamente.

### DD-06 Integridad por idempotencia

**Contexto.** El scraping, la liquidación y los pagos pueden reintentarse o ejecutarse en paralelo.

**Decisión.** Resultados con índice único por (juego, fecha, hora de sorteo) y escritura idempotente; liquidación de apuestas protegida contra doble ejecución; operaciones de dinero en transacción; deduplicación explícita del lote del día.

**Consecuencias.** Ejecutar dos veces un scrape no duplica ni paga dos veces; los reintentos son seguros; la reconciliación no produce efectos dobles.

### DD-07 Moneda dual Bs/USD con tasa gestionada por el sistema

**Contexto.** Se apuesta en bolívares y en dólares, y la tasa varía en el tiempo.

**Decisión.** Para vender se exige una tasa de cambio activa, obtenida automáticamente; cada apuesta y cada cierre guardan snapshot de la tasa aplicada; los desgloses normalizan el equivalente en dólares.

**Consecuencias.** Los reportes son comparables entre monedas; un cambio de tasa no altera montos ya registrados; sin tasa activa la venta se bloquea.

### DD-08 Comisiones por cascada con topes y suma cero

**Contexto.** Banca, grupo y taquilla participan de los ingresos con porcentajes por juego y moneda.

**Decisión.** Matriz (juego × nivel × moneda) con cascada taquilla > grupo > banca > default global; topes por familia (animalitos 16 %, tripletas 25 %, otras 100 %); cada nivel es una fila independiente cuyo liquidable nunca excede lo que queda tras las tasas propias de sus ancestros; la agencia no cobra (rollup informativo).

**Consecuencias.** El total de comisiones nunca excede el 100 %; cada nivel cobra lo suyo sin afectar a sus hermanos; el snapshot del cierre y el ledger usan el mismo motor de tasas.

### DD-09 Sin modo offline (decisión de negocio del cliente)

**Contexto.** El cliente definió que la operación de venta funciona en línea.

**Decisión.** La venta requiere conexión con el backend; ante desconexión se muestra un aviso visible y se bloquea la venta; no existe cola de ventas offline.

**Consecuencias.** No hay ventas con datos diferidos ni riesgo de duplicación al reconectar; la conectividad del local es un requisito operativo crítico.

### DD-10 Topología de despliegue

**Contexto.** La operación en VPS necesita aislar la venta del procesamiento asíncrono y del agendado.

**Decisión.** API pura + panel web + taquilla de escritorio (Windows) con OTA; contenedores separados para API, cola y planificador; CI/CD con pruebas y despliegue automático; solo el servicio API ejecuta migraciones.

**Consecuencias.** Despliegues reproducibles; el scraping y la liquidación no compiten con la venta; una única fuente de migración evita condiciones de carrera sobre el esquema.

### DD-11 Normalización estricta de datos de entrada

**Contexto.** Las fuentes publican horas en formatos mixtos, textos con y sin acentos, números sin relleno y signos por sigla o etiqueta.

**Decisión.** Normalizar en la ingesta y en la comparación: hora en formato de 24 h (HH:mm), siglas y etiquetas de signos equivalentes, padding de triples a 3 cifras y comparación de textos insensible a mayúsculas y acentos; contrato scraper→motor explícito por familia.

**Consecuencias.** El motor liquida con datos uniformes; las variantes de la fuente no producen premios en cero; el contrato queda verificable mediante pruebas.

### DD-12 Operación monitoreada

**Contexto.** La operación depende de fuentes externas, red y servicios propios.

**Decisión.** Alertas automáticas al operador vía Telegram (caída de la API, respaldos e incidencias relevantes); respaldos diarios verificados; actualización OTA de la taquilla controlada para no interrumpir una venta en curso.

**Consecuencias.** Las incidencias se detectan sin esperar un reclamo; existe recuperación ante pérdida de datos; las actualizaciones no sorprenden al punto de venta.

### DD-13 Seguridad de terminal de venta

**Contexto.** La taquilla es un terminal que opera dinero dentro de un local.

**Decisión.** Huella MAC del dispositivo para activar e identificar cada taquilla, token por taquilla, limitación de intentos de inicio de sesión y credenciales y PIN hasheados.

**Consecuencias.** Una máquina no autorizada no vende; los intentos de fuerza bruta sobre el login quedan mitigados; los PIN de cierre no se almacenan en claro.

### DD-14 Cumplimiento embebido en el software

**Contexto.** El operador está sujeto a la Ley Nacional de Lotería, a la Providencia SNAT/2009/0102 y a los registros de CONALOT.

**Decisión.** Incorporar en el software los campos fiscales del ticket, la retención de ISLR del 16 % con bruto y neto y comprobante, la caducidad parametrizable de premios, la auditoría de operaciones, el usuario de prueba para CONALOT y la representación impresa para la Impresora Fiscal de Apuestas.

**Consecuencias.** El software produce los datos que el cliente necesita para declarar y registrar; la integración física con la impresora queda supeditada al hardware que defina el cliente; el expediente técnico (esta especificación, manuales y fuentes) es parte del entregable.

---

## 6. Criterios de aceptación por módulo (resumen)

| Módulo | Criterio de aceptación | Evidencia |
|---|---|---|
| RF-01 Catálogo y juegos | Catálogo completo con premios sin diferencias contra la guía autoritativa y edición sin retroactividad. | «Guía de premiación de los juegos»; pruebas de edición. |
| RF-02 Venta y tickets | Ticket único con jugadas válidas, límites, tasa y anulación operativos. | Pruebas funcionales; ticket de muestra. |
| RF-03 Resultados | Resultados persistidos dentro del SLA, sin duplicados y con huecos reconciliados. | Métricas del planificador; pruebas de idempotencia. |
| RF-04 Premios | Cálculo correcto por modalidad y comodín; snapshot respetado; sin doble liquidación. | Suite de pruebas; documentos de premiación. |
| RF-05 Pagos | Pago autoritativo del motor; único flujo que marca `pagada`; comprobante correcto. | Pruebas de pago y devolución; comprobantes de muestra. |
| RF-06 Cierre de caja | Período continuo, desglose cuadrado, arqueo y re-cierre auditado. | Reporte de cierre de muestra; pruebas de cierre. |
| RF-07 Comisiones | Cascada con topes y suma cero; ledger y pagos idempotentes. | Pruebas de comisiones; configuración de matriz. |
| RF-08 Jerarquía y seguridad | Alcance por rol uniforme; credenciales protegidas; taquillas activadas por MAC. | Pruebas de autorización; revisión de configuración. |
| RF-09 Panel | Operaciones administrativas completas y auditadas. | Guía de uso del panel; registros de auditoría. |
| RF-10 Reportes | Reportes y libros de jugadas/premios coherentes con los datos operados. | Muestras de reportes por rol. |
| RF-11 Operación y continuidad | Alertas, respaldos verificados y despliegue sin interrumpir ventas. | Configuración de monitoreo; bitácora de respaldos. |
| RF-12 Cumplimiento | Ticket-factura, retención ISLR, caducidad, usuario de prueba y expediente completos. | Ticket de muestra; reporte quincenal; expediente CONALOT. |

---

## 7. Anexos

### Anexo A — Catálogo de juegos y premios (tabla resumida)

Valores tomados de la «Guía de premiación de los juegos», tabla autoritativa a la fecha de este documento.

| Juego | Tipo | Premio base | Modalidad / comodín destacado |
|---|---|---|---|
| Lotto Activo | `animalitos` | 30× | Dupleta 1.000× |
| Triple Zulia | `tripletas` | 600× | Zodiacal 6.000× |
| Lotto Activo RD | `animalitos` | 30× | — |
| Lotto Activo Rep. Dom. | `animalitos` | 30× | — |
| Monje Millonario | `animalitos` | 50× | Patronus (75) 120× |
| Trío Activo | `tripletas` | 600× | Terminal/Punta 60× |
| Triple Caliente | `tripletas` | 600× | Signo 6.000× |
| Cazalotón | `animalitos` | 30× | Dupleta 800× / Tripleta 200× |
| Triple Chance | `tripletas` | 600× | A+B 200.000× / C+Signo 6.000× |
| El Arrejuntado | `tripletas` | 40× | Pegadito 60.000× |
| El Guacharito | `animalitos` | 70× | Guacharito (99) 150× |
| Guácharo Activo | `animalitos` | 60× | Guácharo (75) 120× |
| La Granjita | `animalitos` | 30× | — |
| La Ricachona | `tripletas` | — | Deshabilitado (sin fuente oficial con valores) |
| Loto Chaima | `animalitos` | 40× | Tripleta 50× |
| Mega Animal 40 | `animalitos` | 30× | Comodín MEGA 40× |
| Selva Plus | `animalitos` | 80× | Comodín A 160× / B 200× |
| Triple Táchira | `tripletas` | 500× | Zodiacal 5.000× |
| Triple Fácil | `tripletas` | 700× | Terminal 60× / Aprox 10× |
| Triple Zamorano | `tripletas` | 600× | Astro 6.000× |

**Reglas del anexo**

- **Redondeo**: todo monto de premio se calcula como monto × multiplicador, redondeado a 2 decimales, en la moneda de la apuesta.
- **Regla de soporte**: sin fuente oficial con valores verificados, el juego permanece deshabilitado (no se ofrece, no se scrapea, no se liquida).
- En la guía didáctica, la familia `tripletas` se presenta bajo el rótulo "Triples"; son equivalentes.
- La jugada de **terminal** es una modalidad de los juegos de `tripletas` (no un juego ni un tipo): el terminal del Trío Activo se comercializa como *Terminal Activo / Terminal Trío* y paga 60×.

### Anexo B — Modalidades y comodines (resumen del glosario)

**B.1 Modalidades de los juegos de `tripletas`** (ejemplo sobre el triple 310):

| Modalidad (clave canónica) | Definición | Ejemplo sobre 310 |
|---|---|---|
| Triple (A/B/C) | Número exacto de 3 cifras | `310` |
| Punta | Las 2 primeras cifras | `31` |
| Terminal (= Cola) | Las 2 últimas cifras | `10` |
| Uña | La última cifra (1 dígito) | `0` |
| Signo | Uno de los 12 signos zodiacales | Escorpio |
| Zodiacal (= Astro = Signo Caliente = C+Signo) | Triple exacto + signo (ambos) | `310` + Escorpio |
| Terminal + Signo (Cola+Signo) | Terminal + signo | `10` + Escorpio |
| Uña + Signo | Uña + signo | `0` + Escorpio |
| Signo solo | Únicamente el signo (Triple Chance, 6×) | Escorpio |
| Aproximación | Terminal ±1 (Triple Fácil, 10×) | `09` o `11` |
| Cruzado | Dos puntas (contra A y B): ambas 3.000× / una 10× | — |
| Par A+B / Solo A o B | Dos triples a la vez: ambos 200.000×; solo uno 150× (decisión pendiente, ver Documento de Alcance) | — |
| Arrimao | Número exacto de 4 cifras (El Arrejuntado, 6.000×) | `1825` |
| Pegadito | Número exacto de 5 cifras (El Arrejuntado, 60.000×) | `10503` |

**B.2 Comodines de los juegos de `animalitos`**:

| Tipo de comodín | Comportamiento | Ejemplos |
|---|---|---|
| Bandera / número / palabra | El comodín acompaña a la figura y eleva el multiplicador de la jugada | MEGA 40×; Guacharito (99) 150×; Guácharo (75) 120×; Patronus (75) 120× |
| Palabra acumulativa | Suma al multiplicador de la figura | Palabra PATRONUS +20× (con figura base de 50× resulta 70×) |
| Letra (figura apostable) | Se apuesta al comodín como figura; el comodín es el resultado | Selva Plus A "Leoncito" 160×; Selva Plus B 200× |

**B.3 Aviso terminológico** (evita confundir términos):

- `tripletas` es un **tipo de juego** (números de 3 cifras); `tripleta` es una **modalidad de animalitos** (3 figuras, solo Cazalotón y Loto Chaima).
- `terminal` es una **modalidad** de los juegos de `tripletas` (las 2 últimas cifras de un triple): el sistema **no** soporta un tipo de juego `terminales`. La variante comercial *Terminal Activo / Terminal Trío* es la modalidad de terminal del Trío Activo.

### Anexo C — Matriz de roles resumida

| Rol | Alcance | Venta (POS) | Cierres de caja | Comisiones (ledger) |
|---|---|---|---|---|
| `super_master` | Global | No | Todas las taquillas | Lista, liquida y marca pagos (todo); gestiona el default global |
| `master` | Sus bancas | No | Taquillas de sus bancas | Lista, liquida y marca pagos (sus bancas) |
| `banca` | Su banca | No | Taquillas de su banca | Sin acceso |
| `grupo` | Su grupo | No | Taquillas de su grupo | Sin acceso |
| `agencia` | Su local | No | Taquillas de su local | Sin acceso |
| `taquilla` | Su punto de venta | Sí | Su propia caja | Sin acceso |

Notas: la gestión de juegos y premios y las configuraciones globales corresponden a los roles administrativos (`super_master` y `master`); el registro de pagos de premios está disponible para los roles con alcance (según validaciones de la API). La configuración de límites y comisiones de un nivel la realizan los roles superiores de su cadena.

### Anexo D — Control de cambios del documento

| Versión | Fecha | Descripción | Autor |
|---|---|---|---|
| 1.0 | 2026-10-16 | Emisión inicial: requisitos funcionales (RF-01..RF-12), no funcionales (RNF-01..RNF-12), decisiones de diseño (DD-01..DD-14) y anexos. | Equipo de desarrollo del software |
| 1.1 | 2026-10-16 | Ajuste de taxonomía: la jugada de terminal es modalidad de `tripletas` (no un tipo de juego); Terminal Activo se absorbe en el Trío Activo y el catálogo queda en 20 juegos (RF-01.7). | Equipo de desarrollo del software |
