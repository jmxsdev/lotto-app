# Glosario del kernel de identidad

Términos canónicos del kernel de identidad, jerarquía y autorización. Este
documento es la referencia para revisar nombres, conceptos y decisiones de
persistencia. El diseño autoritativo sigue en
[`../../openspec/changes/kernel-identidad-jerarquia/design.md`](../../openspec/changes/kernel-identidad-jerarquia/design.md).

## Convenciones de nombres

| Ámbito | Convención | Ejemplo |
|---|---|---|
| Clases de dominio | Español, sin prefijo de framework | `Rol`, `Nivel`, `Operador` |
| Tablas | El nombre existente del esquema; no se renombra por estética | `users`, `bancas`, `operadores` |
| Columnas booleanas | `active`, como las once tablas existentes | `users.active`, `operadores.active` |
| Alias morph | `snake_case`, igual al valor de `Nivel`/`Rol` | `super_master`, `master` |
| Etiquetas históricas | No se usan en código | "Modelo B" queda en `design.md` |

"Modelo B" es la etiqueta de la alternativa elegida en la exploración: cada
nivel es una entidad y cada entidad tiene exactamente un operador activo. No es
un concepto del código; por eso no aparece en nombres de clases, archivos,
tablas ni columnas.

## Términos

| Término | Definición | Dónde vive |
|---|---|---|
| `Usuario` | Identidad: nombre, email, password, estado. No es un nodo del árbol. | `users`; dominio `Usuario` (destino) |
| `Entidad` | Fila de una de las seis tablas de nivel. Es un nodo del árbol. | `super_masters`, `masters`, `bancas`, `grupos`, `agencias`, `taquillas` |
| `NodoJerarquico` | Representación de dominio de una entidad: tipo, id, padre y estado. | `kernel/Jerarquia/Domain` (destino) |
| `Operador` | Vínculo entre un usuario y una entidad. Exactamente uno activo por entidad. | `operadores` |
| `Rol` | Uno de los seis roles de negocio. Es inmutable y determina el nivel. | `kernel/Identidad/Domain/Rol.php` |
| `Nivel` | Metadata del nivel: rango, padre, tabla de entidad y cardinalidad de hijos. | `kernel/Identidad/Domain/Nivel.php` |
| `rango` | Profundidad en el árbol: `super_master` 0 → `taquilla` 5. | `Nivel::rango()` |
| `jerarquía` | Orden de autorización: mayor número = mayor jerarquía. Es el inverso de `rango`. | `Nivel::jerarquia()` |
| `Capacidad` | Código de una acción autorizable. El catálogo lo define cada producto. | `kernel/Autorizacion/Domain/Capacidad.php` |
| `MatrizRolCapacidad` | Relación rol → capacidades. Es la fuente única del seeder de Spatie. | `kernel/Autorizacion/Domain/MatrizRolCapacidad.php` |
| `PerfilProducto` | Configuración de un producto: niveles, capacidades, matriz y bypass de super_master. | `kernel/Autorizacion/Domain/PerfilProducto.php` |
| `PerfilLoteria` | Perfil del producto lotería: 36 permisos y la matriz actual. | `app/Producto/PerfilLoteria.php` |
| `Alcance` | `Global`, `Subarbol(raices)` o `Vacio`. Solo `system_admin` recibe `Global`. | `kernel/Jerarquia/Domain` (destino) |
| `Árbol` | Conjunto de entidades que cuelgan de un `super_master`. | Dominio |
| `Raíz` | Entidad sin padre: `super_master`. | `super_masters` |
| `Subárbol` | Entidad y todos sus descendientes. | Dominio |
| `system_admin` | Principal de sistema, no rol de negocio. Fuera del árbol, sin fila en `operadores`, alcance global, solo por CLI. | `system_admins` (destino) |
| `Auditoría` | Módulo transversal: registra, consulta, exporta y garantiza evidencia append-only. No decide qué audita cada módulo. | `kernel/Auditoria` (destino) |

## Tres vocabularios de "nivel"

| Vocabulario | Valores | Uso |
|---|---|---|
| `Nivel` (kernel) | `super_master`, `master`, `banca`, `grupo`, `agencia`, `taquilla` | Jerarquía completa |
| `operadores.entidad_tipo` | Los mismos seis | Discriminador polimórfico del vínculo |
| `repartos.nivel` | `master`, `banca`, `grupo`, `taquilla` | Nodos de reparto; `super_master` es raíz derivada al 100 %, `agencia` es passthrough |

No son intercambiables. Si una consulta de reparto usa `Nivel`, va a incluir
niveles que el reparto no reparte.

## Morph map

Las relaciones polimórficas de Laravel guardan el tipo en `entidad_tipo`. Sin
morph map, Laravel guarda el FQCN (`App\Models\Master`); renombrar o mover la
clase rompe las filas históricas. Con morph map se guarda un alias estable:

```php
Relation::morphMap([
    'super_master' => SuperMaster::class,
    'master' => Master::class,
    'banca' => Banca::class,
    'grupo' => Grupo::class,
    'agencia' => Agencia::class,
    'taquilla' => Taquilla::class,
]);
```

Reglas:

- El alias es el contrato con la base; el FQCN es un detalle de PHP.
- Los alias coinciden con los valores de `Nivel` y con el enum de
  `operadores.entidad_tipo`.
- `Relation::enforceMorphMap([...])` puede activarse para fallar si aparece un
  morph sin alias. Hoy el único morph es `Operador`.
- El morph map no resuelve huérfanos; solo el acoplamiento al nombre de clase.

## Integridad del vínculo operador

`operadores.entidad_id` es polimórfico: apunta a seis tablas. MySQL y PostgreSQL
no permiten una FK condicional, así que la integridad no la puede garantizar la
base por sí sola.

Regla derivada de INV-I9: **al desactivar o eliminar una entidad, su operador se
desactiva o elimina en la misma transacción**. El camino por defecto es
desactivar (`active = false`), no borrar; así se conserva el histórico.

| Mecanismo | Cubre | Cuándo |
|---|---|---|
| Caso de uso | Desactivación/borrado normal desde la app | Ahora |
| Observer `deleted` | `$model->delete()` directo | Red de seguridad |
| Trigger `AFTER DELETE` | Bulk/raw SQL | Solo si hay borrados fuera de la app |
| Comando de huérfanos | Detecta drift | CI o programado |

El comando `kernel:operadores-huerfanos` (deuda de Fase 4/5) hace `LEFT JOIN`
contra las seis tablas y lista los operadores que no resuelven. No previene el
problema; lo detecta.

## Convención `active`

Once tablas existentes usan `active` (`users`, `bancas`, `grupos`, `taquillas`,
`juegos`, `agencias`, etc.). `operadores` usa `active` desde la unificación de
la Fase 2. La columna generada `entidad_activa_id` usa `IF(active, entidad_id,
NULL)`; su nombre se conserva porque es un detalle interno de MySQL. En
PostgreSQL se reemplaza por un índice único parcial `WHERE active`.

## Referencias

- `openspec/changes/kernel-identidad-jerarquia/design.md` §2 (dominio) y §3 (ER).
- `docs/kernel/README.md` (contrato de paquetes y persistencia).
- `docs/kernel/phase-1.md` (decisiones de vocabulario).
