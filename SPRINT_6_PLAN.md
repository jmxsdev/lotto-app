# 📋 Planificación Sprint 6: Módulo de Apuestas (Pago Mixto BS/USD)

**Objetivo:** Implementar el corazón de la taquilla - permitir operar apuestas con pago mixto en Bolívares y Dólares, calculando automáticamente el equivalente según la tasa de cambio activa.

---

## 🎯 Entregables del Sprint

| # | Tarea | Entregable | Prioridad |
|---|-------|------------|-----------|
| 6.1 | Migración para tabla `apuestas` (campos adicionales) | Migración ejecutada | ALTA |
| 6.2 | FormRequest para validación de apuestas | Validación personalizada | ALTA |
| 6.3 | `ApuestaController` con métodos index, store, show | Endpoints API funcionales | ALTA |
| 6.4 | Lógica de cálculo `total_bs_equivalent` | Cálculo correcto con tasa activa | ALTA |
| 6.5 | Guardar `exchange_rate_applied` histórico | Registro immutable de tasa al momento | ALTA |
| 6.6 | Endpoint historial filtrado por taquilla/fechas | Listado paginado | MEDIA |
| 6.7 | Endpoint detalle de ticket | JSON completo de apuesta | MEDIA |
| 6.8 | Tests unitarios e integración | Cobertura > 80% | ALTA |

---

## 🏗️ Arquitectura y Diseño

### Tabla `apuestas` - Campos Requeridos

```php
Schema::create('apuestas', function (Blueprint $table) {
    $table->id();
    $table->foreignId('taquilla_id')->constrained()->onDelete('cascade');
    $table->foreignId('juego_id')->constrained()->onDelete('cascade');
    $table->foreignId('resultado_id')->nullable()->constrained()->onDelete('set null');
    
    // Combinaicón apostada
    $table->json('combinacion'); // {animal: 'leon', numero: 5}
    
    // Montos en ambas monedas
    $table->decimal('amount_bs', 12, 2)->default(0);
    $table->decimal('amount_usd', 12, 2)->default(0);
    
    // Tasa aplicada en el momento de la apuesta
    $table->decimal('exchange_rate_applied', 10, 4);
    
    // Total equivalente en bolívares
    $table->decimal('total_bs_equivalent', 12, 2);
    
    // Estado de la apuesta
    $table->enum('status', ['pending', 'won', 'lost', 'cancelled'])->default('pending');
    
    // Timestamps
    $table->timestamp('sorteo_hora')->nullable(); // Hora del sorteo apostado
    $table->timestamps();
});
```

### Modelo Apuesta - Relaciones

```php
class Apuesta extends Model
{
    protected $fillable = [
        'taquilla_id', 'juego_id', 'resultado_id',
        'combinacion', 'amount_bs', 'amount_usd',
        'exchange_rate_applied', 'total_bs_equivalent',
        'status', 'sorteo_hora'
    ];

    protected $casts = [
        'combinacion' => 'array',
        'amount_bs' => 'decimal:2',
        'amount_usd' => 'decimal:2',
        'exchange_rate_applied' => 'decimal:4',
        'total_bs_equivalent' => 'decimal:2',
        'sorteo_hora' => 'datetime',
    ];

    public function taquilla() { return $this->belongsTo(Taquilla::class); }
    public function juego() { return $this->belongsTo(Juego::class); }
    public function resultado() { return $this->belongsTo(Resultado::class); }
    public function pagos() { return $this->hasMany(Pago::class); }
}
```

### Fórmula de Cálculo

```
total_bs_equivalent = amount_bs + (amount_usd * exchange_rate_applied)

Ejemplo:
- amount_bs = 1800
- amount_usd = 50
- tasa_activa = 36.50
- total_bs_equivalent = 1800 + (50 * 36.50) = 3625 BS
```

---

## 📝 Detalle por Tarea

### 6.1 - Migración para tabla `apuestas`

**Archivo:** `database/migrations/YYYY_MM_DD_create_apuestas_table.php`

**Acciones:**
1. Verificar si migración existe (debería existir del Sprint 1)
2. Agregar campos faltantes: `exchange_rate_applied`, `total_bs_equivalent`, `sorteo_hora`
3. Agregar índices: `taquilla_id`, `fecha_sorteo`, `status`
4. Foreign keys a `taquillas`, `juegos`, `resultados`

**Comandos:**
```bash
php artisan make:migration add_fields_to_apuestas_table --table=apuestas
```

---

### 6.2 - FormRequest para validación de apuestas

**Archivo:** `app/Http/Requests/ApuestaStoreRequest.php`

**Validaciones:**
```php
public function rules(): array
{
    return [
        'juego_id' => 'required|exists:juegos,id',
        'combinacion' => 'required|array',
        'combinacion.animal' => 'required|string|in:delfin,ballena,carnero,...',
        'amount_bs' => 'required|numeric|min:0',
        'amount_usd' => 'required|numeric|min:0',
        'sorteo_hora' => 'required|date_format:H:i',
        
        // Regla personalizada para validar que cubre costo del juego
        'total_bs_equivalent' => 'required_if:check_minimum,true|numeric|min:0',
    ];
}

public function messages(): array
{
    return [
        'combinacion.animal.in' => 'El animal seleccionado no es válido.',
        'amount_bs.min' => 'El monto mínimo en bolívares debe ser mayor a 0.',
        'amount_usd.min' => 'El monto mínimo en dólares debe ser mayor a 0.',
    ];
}

public function withValidator($validator): void
{
    $validator->after(function ($validator) {
        $bs = $this->input('amount_bs');
        $usd = $this->input('amount_usd');
        
        if ($bs == 0 && $usd == 0) {
            $validator->errors()->add('amount', 'Debe ingresar un monto en BS o USD.');
        }
    });
}
```

---

### 6.3 - `ApuestaController` con métodos CRUD

**Archivo:** `app/Http/Controllers/Api/ApuestaController.php`

**Métodos:**

#### `index(Request $request)` - Listar apuestas
```php
public function index(Request $request)
{
    $query = Apuesta::with(['juego', 'taquilla', 'resultado'])
        ->latest();

    // Filtrado por rol
    $user = $request->user();
    if ($user->role === 'taquilla') {
        $query->where('taquilla_id', $user->taquilla_id);
    } elseif ($user->role === 'grupo') {
        $query->whereHas('taquilla.grupo', function ($q) use ($user) {
            $q->where('grupo_id', $user->grupo_id);
        });
    }
    // ... más filtros jerárquicos

    // Filtros opcionales
    if ($request->has('fecha_desde')) {
        $query->whereDate('created_at', '>=', $request->fecha_desde);
    }
    if ($request->has('fecha_hasta')) {
        $query->whereDate('created_at', '<=', $request->fecha_hasta);
    }
    if ($request->has('status')) {
        $query->where('status', $request->status);
    }
    if ($request->has('juego_id')) {
        $query->where('juego_id', $request->juego_id);
    }

    return response()->json($query->paginate(50));
}
```

#### `store(ApuestaStoreRequest $request)` - Crear apuesta
```php
public function store(ApuestaStoreRequest $request)
{
    $user = $request->user();
    $taquillaId = $user->taquilla_id;
    
    // 1. Obtener tasa activa del momento
    $tasaActiva = ExchangeRate::where('is_active', true)->first();
    if (!$tasaActiva) {
        return response()->json([
            'message' => 'No hay tasa activa configurada.'
        ], 422);
    }

    // 2. Calcular total_bs_equivalent
    $amountBs = $request->amount_bs;
    $amountUsd = $request->amount_usd;
    $totalBsEquivalent = $amountBs + ($amountUsd * $tasaActiva->rate);

    // 3. Validar que cubre costo mínimo del juego
    $juego = Juego::find($request->juego_id);
    $costoMinimo = $juego->config['precio'] ?? 3600; // Default 3600 BS
    
    if ($totalBsEquivalent < $costoMinimo) {
        return response()->json([
            'message' => "El monto no cubre el costo mínimo del juego ({$costoMinimo} BS).",
            'required_min' => $costoMinimo,
            'current_total' => $totalBsEquivalent
        ], 422);
    }

    // 4. Guardar apuesta con tasa histórica
    $apuesta = Apuesta::create([
        'taquilla_id' => $taquillaId,
        'juego_id' => $request->juego_id,
        'combinacion' => json_encode($request->combinacion),
        'amount_bs' => $amountBs,
        'amount_usd' => $amountUsd,
        'exchange_rate_applied' => $tasaActiva->rate, // Immutable
        'total_bs_equivalent' => $totalBsEquivalent,
        'status' => 'pending',
        'sorteo_hora' => $request->sorteo_hora,
    ]);

    // 5. Generar código de ticket único
    $apuesta->update([
        'ticket_code' => strtoupper(Str::random(8)) . '-' . $apuesta->id
    ]);

    return response()->json($apuesta->load(['juego', 'taquilla']), 201);
}
```

#### `show(Apuesta $apuesta)` - Ver detalle del ticket
```php
public function show(Apuesta $apuesta)
{
    $user = request()->user();
    
    // Validar permisos según rol
    if ($user->role === 'taquilla' && $apuesta->taquilla_id !== $user->taquilla_id) {
        return response()->json(['message' => 'No autorizado'], 403);
    }
    
    return response()->json($apuesta->load(['juego', 'taquilla', 'resultado', 'pagos']));
}
```

---

### 6.4 - Lógica de negocio: Cálculo total_bs_equivalent

**Servicio:** `app/Services/ApuestaService.php`

```php
class ApuestaService
{
    /**
     * Calcular total_bs_equivalent usando tasa activa del momento
     */
    public function calcularTotal(float $amountBs, float $amountUsd): float
    {
        $tasaActiva = ExchangeRate::where('is_active', true)->first();
        
        if (!$tasaActiva) {
            throw new \RuntimeException('No hay tasa activa configurada');
        }
        
        return $amountBs + ($amountUsd * $tasaActiva->rate);
    }

    /**
     * Validar que el monto cubre el costo mínimo del juego
     */
    public function validarCostoMinimo(float $totalBsEquivalent, int $juegoId): bool
    {
        $juego = Juego::findOrFail($juegoId);
        $costoMinimo = $juego->config['precio'] ?? 3600;
        
        return $totalBsEquivalent >= $costoMinimo;
    }

    /**
     * Convertir BS a USD equivalente (para mostrar al usuario)
     */
    public function bsToUsd(float $amountBs): float
    {
        $tasaActiva = ExchangeRate::where('is_active', true)->first();
        return $tasaActiva ? $amountBs / $tasaActiva->rate : 0;
    }

    /**
     * Convertir USD a BS equivalente (para cálculo)
     */
    public function usdToBs(float $amountUsd): float
    {
        $tasaActiva = ExchangeRate::where('is_active', true)->first();
        return $tasaActiva ? $amountUsd * $tasaActiva->rate : 0;
    }
}
```

---

### 6.5 - Guardar `exchange_rate_applied` histórico

**Importante:** La tasa debe guardarse INMUTABLE al momento de la apuesta.

**Estrategia:**
1. Al crear apuesta, obtener `ExchangeRate::where('is_active', true)->first()`
2. Guardar su valor en `exchange_rate_applied` (campo decimal)
3. Este valor NO cambia aunque la tasa activa se modifique después
4. Para cálculos históricos, usar siempre `exchange_rate_applied` de cada apuesta

**Beneficios:**
- Auditoría completa: saber qué tasa se aplicó a cada apuesta
- Reportes precisos de ganancias/pérdidas
- No afectar apuestas anteriores si cambia la tasa

---

### 6.6 - Endpoint historial filtrado

**Ruta:** `GET /api/apuestas/historial`

**Parámetros de query:**
```
?fecha_desde=2026-07-01
&fecha_hasta=2026-07-24
&status=pending|won|lost|cancelled
&juego_id=1
&per_page=50
&page=1
```

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "taquilla": {"name": "Taquilla 01"},
      "juego": {"name": "Animalitos"},
      "combinacion": {"animal": "leon", "numero": 5},
      "amount_bs": 1800.00,
      "amount_usd": 50.00,
      "exchange_rate_applied": 36.5000,
      "total_bs_equivalent": 3625.00,
      "status": "pending",
      "sorteo_hora": "2026-07-24T10:00:00Z",
      "ticket_code": "A1B2C3D4-1",
      "created_at": "2026-07-24T09:45:00Z"
    }
  ],
  "meta": {
    "total": 150,
    "page": 1,
    "per_page": 50,
    "last_page": 3
  },
  "summary": {
    "total_bs": 543750.00,
    "total_usd": 1500.00,
    "total_bet_amount_bs": 598500.00,
    "pending_count": 10,
    "won_count": 130,
    "lost_count": 10
  }
}
```

---

### 6.7 - Endpoint detalle del ticket

**Ruta:** `GET /api/apuestas/{apuesta}`

**Response:**
```json
{
  "id": 1,
  "taquilla": {
    "name": "Taquilla 01",
    "code": "T001"
  },
  "juego": {
    "name": "Animalitos",
    "type": "animalitos"
  },
  "combinacion": {
    "animal": "leon",
    "numero": 5
  },
  "amount_bs": 1800.00,
  "amount_usd": 50.00,
  "exchange_rate_applied": 36.5000,
  "total_bs_equivalent": 3625.00,
  "status": "pending",
  "sorteo_hora": "2026-07-24T10:00:00Z",
  "ticket_code": "A1B2C3D4-1",
  "created_at": "2026-07-24T09:45:00Z",
  "updated_at": "2026-07-24T09:45:00Z",
  "resultado": null,
  "pagos": []
}
```

---

## 🧪 Estrategia de Pruebas

### Tests Unitarios (`tests/Unit/ApuestaServiceTest.php`)

```php
class ApuestaServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    public function test_calcula_total_bs_equivalent_correctamente()
    {
        // Crear tasa activa
        $user = User::where('email', 'super@lotto.com')->first();
        ExchangeRate::create([
            'rate' => 36.50,
            'base_currency' => 'USD',
            'reference_date' => now(),
            'set_by' => $user->id,
            'is_active' => true,
        ]);

        $service = new ApuestaService();
        $total = $service->calcularTotal(1800, 50);
        
        $this->assertEquals(3625.00, $total);
    }

    public function test_valida_costo_minimo()
    {
        $juego = Juego::create([
            'name' => 'Animalitos',
            'slug' => 'animalitos',
            'type' => 'animalitos',
            'config' => json_encode(['precio' => 3600]),
            'active' => true,
        ]);

        $service = new ApuestaService();
        
        // Debe pasar (3625 >= 3600)
        $this->assertTrue($service->validarCostoMinimo(3625, $juego->id));
        
        // Debe fallar (3500 < 3600)
        $this->assertFalse($service->validarCostoMinimo(3500, $juego->id));
    }

    public function test_convierte_bs_a_usd()
    {
        ExchangeRate::create([
            'rate' => 36.50,
            'base_currency' => 'USD',
            'reference_date' => now(),
            'set_by' => User::first(),
            'is_active' => true,
        ]);

        $service = new ApuestaService();
        $usd = $service->bsToUsd(3650);
        
        $this->assertEquals(100.00, $usd);
    }

    public function test_convierte_usd_a_bs()
    {
        ExchangeRate::create([
            'rate' => 36.50,
            'base_currency' => 'USD',
            'reference_date' => now(),
            'set_by' => User::first(),
            'is_active' => true,
        ]);

        $service = new ApuestaService();
        $bs = $service->usdToBs(100);
        
        $this->assertEquals(3650.00, $bs);
    }

    public function test_lanza_excepcion_sin_tasa_activa()
    {
        $service = new ApuestaService();
        
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No hay tasa activa configurada');
        
        $service->calcularTotal(1000, 0);
    }
}
```

### Tests de Integración (`tests/Feature/ApuestaTest.php`)

```php
class ApuestaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->seed(\Database\Seeders\JuegoAnimalitosSeeder::class);
    }

    public function test_taquilla_puede_crear_apuesta()
    {
        // Crear tasa activa
        $user = User::where('email', 'super@lotto.com')->first();
        ExchangeRate::create([
            'rate' => 36.50,
            'base_currency' => 'USD',
            'reference_date' => now(),
            'set_by' => $user->id,
            'is_active' => true,
        ]);

        // Crear taquilla y usuario
        $taquilla = Taquilla::factory()->create();
        $taquillaUser = User::factory()->create([
            'taquilla_id' => $taquilla->id,
            'role' => 'taquilla',
        ]);
        $taquillaUser->assignRole('taquilla');

        // Hacer apuesta
        $response = $this->actingAs($taquillaUser, 'sanctum')
            ->postJson('/api/apuestas', [
                'juego_id' => 1,
                'combinacion' => ['animal' => 'leon', 'numero' => 5],
                'amount_bs' => 1800,
                'amount_usd' => 50,
                'sorteo_hora' => '2026-07-24 10:00:00',
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'amount_bs' => 1800,
                'amount_usd' => 50,
                'total_bs_equivalent' => 3625.00,
                'exchange_rate_applied' => 36.50,
                'status' => 'pending',
            ]);

        $this->assertDatabaseHas('apuestas', [
            'taquilla_id' => $taquilla->id,
            'total_bs_equivalent' => 3625.00,
        ]);
    }

    public function test_rechaza_monto_inferior_al_costo_minimo()
    {
        // Setup tasa y juego...
        
        $response = $this->actingAs($taquillaUser, 'sanctum')
            ->postJson('/api/apuestas', [
                'juego_id' => 1,
                'combinacion' => ['animal' => 'leon'],
                'amount_bs' => 1000,
                'amount_usd' => 0,
                'sorteo_hora' => '2026-07-24 10:00:00',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', str_contains('costo mínimo'));
    }

    public function test_guarda_tasa_historica_inmutable()
    {
        // Crear apuesta con tasa 36.50
        // Luego cambiar tasa a 37.00
        // Verificar que apuesta mantiene tasa 36.50
        
        $apuesta = Apuesta::first();
        $this->assertEquals(36.50, $apuesta->exchange_rate_applied);
    }

    public function test_usuario_vio_solamente_taquilla_propia()
    {
        // Crear 2 taquillas con usuarios diferentes
        // Usuario 1 solo ve apuestas de taquilla 1
        // Usuario 2 solo ve apuestas de taquilla 2
        
        $response = $this->actingAs($taquillaUser, 'sanctum')
            ->getJson('/api/apuestas');

        $apuestas = $response->json('data');
        foreach ($apuestas as $apuesta) {
            $this->assertEquals($taquilla->id, $apuesta['taquilla_id']);
        }
    }

    public function test_master_ve_todas_las_apuestas()
    {
        // Master puede ver todas las apuestas del sistema
        
        $master = User::where('email', 'master@lotto.com')->first();
        $response = $this->actingAs($master, 'sanctum')
            ->getJson('/api/apuestas');

        $response->assertStatus(200);
        // Debería ver todas las apuestas sin filtrar por taquilla
    }
}
```

---

## 📊 Rutas API a Implementar

```php
// routes/api.php

Route::middleware(['auth:sanctum', 'role:super_master|master|banca|grupo|taquilla'])->group(function () {
    
    // Listar apuestas (filtrado jerárquico)
    Route::get('/apuestas', [ApuestaController::class, 'index']);
    
    // Crear nueva apuesta
    Route::post('/apuestas', [ApuestaController::class, 'store']);
    
    // Ver detalle de apuesta/ticket
    Route::get('/apuestas/{apuesta}', [ApuestaController::class, 'show']);
    
    // Historial con filtros avanzados
    Route::get('/apuestas/historial', [ApuestaController::class, 'historial']);
    
    // Resumen estadístico
    Route::get('/apuestas/resumen', [ApuestaController::class, 'resumen']);
});
```

---

## 🎨 UI Frontend (Sprint 8-9 preview)

### Componente Nueva Apuesta (Astro/Vue)

```vue
<script setup lang="ts">
import { ref, computed } from 'vue';

const selectedAnimal = ref('');
const amountBs = ref(0);
const amountUsd = ref(0);
const tasaActual = ref(0);

// Cálculo automático cruzado
const totalBsEquivalent = computed(() => {
    return amountBs.value + (amountUsd.value * tasaActual.value);
});

function updateFromBs(value: number) {
    amountBs.value = value;
    // Opcional: recalcular USD si se quiere mantener total fijo
}

function updateFromUsd(value: number) {
    amountUsd.value = value;
    // Opcional: recalcular BS si se quiere mantener total fijo
}

async function submitApuesta() {
    const response = await fetch('/api/apuestas', {
        method: 'POST',
        headers: { 'Authorization': `Bearer ${token}` },
        body: JSON.stringify({
            juego_id: 1,
            combinacion: { animal: selectedAnimal.value },
            amount_bs: amountBs.value,
            amount_usd: amountUsd.value,
            sorteo_hora: currentSorteoHora.value,
        })
    });
    // Handle response...
}
</script>

<template>
    <div class="nueva-apuesta">
        <h2>Nueva Apuesta - Animalitos</h2>
        
        <!-- Selector de animal -->
        <select v-model="selectedAnimal">
            <option v-for="animal in animales" :value="animal.name">
                {{ animal.number }} - {{ animal.name }}
            </option>
        </select>
        
        <!-- Inputs de monto con cálculo cruzado -->
        <div class="monto-inputs">
            <input type="number" v-model="amountBs" @input="updateFromBs" placeholder="Montante en Bs">
            <span class="separator">o</span>
            <input type="number" v-model="amountUsd" @input="updateFromUsd" placeholder="Montante en $">
        </div>
        
        <!-- Display de tasas y totales -->
        <div class="resumen-monto">
            <p>Tasa actual: {{ tasaActual }} Bs/$</p>
            <p>Total equivalente: {{ totalBsEquivalent.toFixed(2) }} Bs</p>
        </div>
        
        <button @click="submitApuesta">Confirmar Apuesta</button>
    </div>
</template>
```

---

## ✅ Criterios de Aceptación

- [ ] Migración de tabla `apuestas` ejecutada con todos los campos
- [ ] Modelo `Apuesta` con relaciones y casts correctos
- [ ] `ApuestaService` calcula `total_bs_equivalent` correctamente
- [ ] `ApuestaController@store` valida y guarda apuestas con tasa histórica
- [ ] Tasa `exchange_rate_applied` es immutable (no cambia si se modifica tasa activa)
- [ ] Endpoint `GET /api/apuestas` filtra por jerarquía de roles
- [ ] Endpoint `GET /api/apuestas/historial` con filtros avanzados
- [ ] Endpoint `GET /api/apuestas/{id}` devuelve detalle completo
- [ ] Respuesta incluye resumen estadístico (totals por moneda)
- [ ] Tests unitarios: 5+ tests pasando
- [ ] Tests de integración: 5+ tests pasando
- [ ] Costo mínimo validado antes de guardar apuesta
- [ ] Mensaje claro cuando monto es insuficiente
- [ ] Sin tasas activas → error 422 descriptivo

---

## ⚡ Estimación de Esfuerzo

| Tarea | Horas estimadas |
|-------|-----------------|
| 6.1 - Migración y modelo | 2h |
| 6.2 - FormRequest validación | 2h |
| 6.3 - ApuestaController | 4h |
| 6.4 - ApuestaService (cálculos) | 3h |
| 6.5 - Tasa histórica immutable | 1h |
| 6.6 - Endpoint historial | 2h |
| 6.7 - Endpoint detalle ticket | 1h |
| 6.8 - Tests unitarios | 3h |
| 6.9 - Tests integración | 3h |
| **TOTAL** | **~21h (~3 días)** |

---

## 🔗 Dependencias entre Sprints

| Depende de | Sprint | Estado |
|------------|--------|--------|
| Tasa de cambio activa | Sprint 3 | ✅ Listo |
| Juegos (Animalitos) | Sprint 4 | ✅ Listo |
| Roles y permisos | Sprint 2 | ✅ Listo |
| Modelos base | Sprint 1 | ✅ Listo |
| **Este sprint (6)** | **6** | **⏳ En progreso** |
| Pagos | Sprint 9 | ❌ Pendiente |
| Eliminación tickets | Sprint 10 | ❌ Pendiente |
| Cierre de caja | Sprint 12 | ❌ Pendiente |

---

**Próximo sprint después de 6:** Sprint 7 - Activación de Taquillas (MAC Address)
