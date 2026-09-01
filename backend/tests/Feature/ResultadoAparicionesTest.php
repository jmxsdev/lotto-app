<?php

namespace Tests\Feature;

use App\Models\Juego;
use App\Models\Resultado;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/v1/resultados/{resultado}/apariciones — tracker de apariciones previas.
 *
 * Identidad server-side por tipo (design D1/D2):
 *  - animalitos/terminales → valor `numero` del JSON de la fila clicada (sin params);
 *  - tripletas → ?posicion (whitelist triple_a|triple_b|triple_c) + signo de la fila
 *    cuando es no-nulo.
 * Orden fecha_sorteo DESC, luego hora_sorteo DESC; cap FIJO 5; exclusion del sorteo
 * clicado; sin historial → [] (200).
 */
class ResultadoAparicionesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function userByRole(string $role): User
    {
        $emails = [
            'super_master' => 'super@lotto.com',
            'master' => 'master@lotto.com',
            'banca' => 'banca@lotto.com',
            'grupo' => 'grupo@lotto.com',
            'agencia' => 'agencia@lotto.com',
        ];

        $user = User::where('email', $emails[$role])->first();
        $user->assignRole($role);

        return $user;
    }

    private function juego(string $slug): Juego
    {
        return Juego::where('slug', $slug)->first();
    }

    private function crearResultado(Juego $juego, array $numeros, Carbon $fecha, string $hora): Resultado
    {
        return Resultado::create([
            'juego_id' => $juego->id,
            'fecha_sorteo' => $fecha,
            'hora_sorteo' => $hora,
            'numeros_ganadores' => $numeros,
        ]);
    }

    // ==================================================
    // C1 — Endpoint y autenticación
    // ==================================================

    public function test_apariciones_401_sin_token()
    {
        $this->getJson('/api/v1/resultados/1/apariciones')
            ->assertStatus(401);
    }

    public function test_apariciones_404_id_inexistente()
    {
        $this->actingAs($this->userByRole('super_master'), 'sanctum')
            ->getJson('/api/v1/resultados/999999/apariciones')
            ->assertStatus(404);
    }

    public function test_apariciones_200_para_todos_los_roles_del_panel()
    {
        $animalitos = $this->juego('lotto-activo');
        $resultado = $this->crearResultado(
            $animalitos,
            ['numero' => 7, 'nombre_animal' => 'perro', 'color_animal' => 'marrón', 'pais' => 'VE'],
            Carbon::today()->setTime(19, 0, 0),
            '19:00'
        );

        foreach (['super_master', 'master', 'banca', 'grupo', 'agencia'] as $role) {
            $this->actingAs($this->userByRole($role), 'sanctum')
                ->getJson('/api/v1/resultados/'.$resultado->id.'/apariciones')
                ->assertStatus(200);
        }
    }

    // ==================================================
    // C2 — Identidad por numero (animalitos/terminales)
    // ==================================================

    public function test_apariciones_animalitos_identidad_numero_ordena_desc_y_excluye_clicada()
    {
        $animalitos = $this->juego('lotto-activo');

        // 6 previas con numero:7 (d-6 … d-1) + fila clicada hoy con numero:7 + ruido numero:8
        $previas = [];
        for ($i = 1; $i <= 6; $i++) {
            $previas[] = $this->crearResultado(
                $animalitos,
                ['numero' => 7, 'nombre_animal' => 'perro', 'color_animal' => 'marrón', 'pais' => 'VE'],
                Carbon::today()->subDays($i)->setTime(19, 0, 0),
                '19:00'
            );
        }
        $clicada = $this->crearResultado(
            $animalitos,
            ['numero' => 7, 'nombre_animal' => 'perro', 'color_animal' => 'marrón', 'pais' => 'VE'],
            Carbon::today()->setTime(19, 0, 0),
            '19:00'
        );
        $ruido = $this->crearResultado(
            $animalitos,
            ['numero' => 8, 'nombre_animal' => 'gato', 'color_animal' => 'negro', 'pais' => 'VE'],
            Carbon::today()->setTime(12, 0, 0),
            '12:00'
        );

        $response = $this->actingAs($this->userByRole('super_master'), 'sanctum')
            ->getJson('/api/v1/resultados/'.$clicada->id.'/apariciones');

        $response->assertStatus(200);
        $data = $response->json();

        // Cap fijo 5, las más recientes primero (d-1 … d-5)
        $this->assertCount(5, $data);
        $ids = array_column($data, 'id');
        $this->assertEquals(
            array_column(array_slice($previas, 0, 5), 'id'),
            $ids
        );

        // C4: la fila clicada jamás aparece; el ruido numero:8 tampoco
        $this->assertNotContains($clicada->id, $ids);
        $this->assertNotContains($ruido->id, $ids);
    }

    public function test_apariciones_terminales_identidad_por_numero()
    {
        $terminales = $this->juego('terminal-activo');

        $previa1 = $this->crearResultado(
            $terminales,
            ['numero' => 42],
            Carbon::yesterday()->setTime(19, 0, 0),
            '19:00'
        );
        $previa2 = $this->crearResultado(
            $terminales,
            ['numero' => 42],
            Carbon::today()->setTime(12, 45, 0),
            '12:45'
        );
        $clicada = $this->crearResultado(
            $terminales,
            ['numero' => 42],
            Carbon::today()->setTime(19, 5, 0),
            '19:05'
        );
        $ruido = $this->crearResultado(
            $terminales,
            ['numero' => 43],
            Carbon::today()->setTime(11, 0, 0),
            '11:00'
        );

        $response = $this->actingAs($this->userByRole('master'), 'sanctum')
            ->getJson('/api/v1/resultados/'.$clicada->id.'/apariciones');

        $response->assertStatus(200);
        $data = $response->json();

        // Solo las filas con numero 42 (previa2 más reciente por hora desc)
        $this->assertCount(2, $data);
        $this->assertEquals([$previa2->id, $previa1->id], array_column($data, 'id'));
        $this->assertNotContains($clicada->id, array_column($data, 'id'));
        $this->assertNotContains($ruido->id, array_column($data, 'id'));
    }

    public function test_apariciones_tolerancia_fila_sin_numero_devuelve_vacio()
    {
        $animalitos = $this->juego('lotto-activo');

        // Fila estilo ResultadoTestSeeder: SIN clave numero → nunca matchea identidad
        $sinNumero = $this->crearResultado(
            $animalitos,
            ['nombre_animal' => 'perro', 'color_animal' => 'marrón', 'pais' => 'VE'],
            Carbon::today()->setTime(19, 0, 0),
            '19:00'
        );
        $this->crearResultado(
            $animalitos,
            ['numero' => 7, 'nombre_animal' => 'perro', 'color_animal' => 'marrón', 'pais' => 'VE'],
            Carbon::yesterday()->setTime(19, 0, 0),
            '19:00'
        );

        $response = $this->actingAs($this->userByRole('super_master'), 'sanctum')
            ->getJson('/api/v1/resultados/'.$sinNumero->id.'/apariciones');

        $response->assertStatus(200);
        $response->assertExactJson([]);
    }

    // ==================================================
    // C3 — Tripletas: identidad por posición + signo
    // ==================================================

    public function test_apariciones_tripletas_posicion_c_con_signo_excluye_otro_signo()
    {
        $tripleZulia = $this->juego('triple-zulia');

        $clicada = $this->crearResultado(
            $tripleZulia,
            ['triple_a' => '111', 'triple_b' => '222', 'triple_c' => '789', 'signo' => 'LEO', 'pais' => 'VE'],
            Carbon::today()->setTime(19, 5, 0),
            '19:05'
        );
        $coincide = $this->crearResultado(
            $tripleZulia,
            ['triple_a' => '333', 'triple_b' => '444', 'triple_c' => '789', 'signo' => 'LEO', 'pais' => 'VE'],
            Carbon::yesterday()->setTime(19, 5, 0),
            '19:05'
        );
        $otroSigno = $this->crearResultado(
            $tripleZulia,
            ['triple_a' => '555', 'triple_b' => '666', 'triple_c' => '789', 'signo' => 'TAU', 'pais' => 'VE'],
            Carbon::today()->setTime(12, 45, 0),
            '12:45'
        );
        $otroNumero = $this->crearResultado(
            $tripleZulia,
            ['triple_a' => '777', 'triple_b' => '888', 'triple_c' => '111', 'signo' => 'LEO', 'pais' => 'VE'],
            Carbon::yesterday()->setTime(12, 45, 0),
            '12:45'
        );

        $response = $this->actingAs($this->userByRole('super_master'), 'sanctum')
            ->getJson('/api/v1/resultados/'.$clicada->id.'/apariciones?posicion=triple_c');

        $response->assertStatus(200);
        $data = $response->json();

        // Solo coinciden triple_c Y signo: ni otroSigno (TAU) ni otroNumero
        $this->assertCount(1, $data);
        $this->assertEquals([$coincide->id], array_column($data, 'id'));
        $this->assertNotContains($clicada->id, array_column($data, 'id'));
        $this->assertNotContains($otroSigno->id, array_column($data, 'id'));
        $this->assertNotContains($otroNumero->id, array_column($data, 'id'));
    }

    public function test_apariciones_tripletas_posicion_b_con_signo_excluye_signo_distinto()
    {
        $tripleZulia = $this->juego('triple-zulia');

        $clicada = $this->crearResultado(
            $tripleZulia,
            ['triple_a' => '111', 'triple_b' => '456', 'triple_c' => '789', 'signo' => 'LEO', 'pais' => 'VE'],
            Carbon::today()->setTime(19, 5, 0),
            '19:05'
        );
        $coincide = $this->crearResultado(
            $tripleZulia,
            ['triple_a' => '222', 'triple_b' => '456', 'triple_c' => '333', 'signo' => 'LEO', 'pais' => 'VE'],
            Carbon::yesterday()->setTime(19, 5, 0),
            '19:05'
        );
        $otroSigno = $this->crearResultado(
            $tripleZulia,
            ['triple_a' => '444', 'triple_b' => '456', 'triple_c' => '555', 'signo' => 'TAU', 'pais' => 'VE'],
            Carbon::today()->setTime(12, 45, 0),
            '12:45'
        );

        $response = $this->actingAs($this->userByRole('super_master'), 'sanctum')
            ->getJson('/api/v1/resultados/'.$clicada->id.'/apariciones?posicion=triple_b');

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertCount(1, $data);
        $this->assertEquals([$coincide->id], array_column($data, 'id'));
        $this->assertNotContains($clicada->id, array_column($data, 'id'));
        $this->assertNotContains($otroSigno->id, array_column($data, 'id'));
    }

    public function test_apariciones_trio_activo_sin_signo_identidad_por_posicion_pura()
    {
        $trioActivo = $this->juego('trio-activo');

        // Forma trio_activo: triples SIN clave signo → posición pura
        $clicada = $this->crearResultado(
            $trioActivo,
            ['triple_a' => '111', 'triple_b' => '222', 'triple_c' => '123'],
            Carbon::today()->setTime(19, 5, 0),
            '19:05'
        );
        $previa1 = $this->crearResultado(
            $trioActivo,
            ['triple_a' => '333', 'triple_b' => '444', 'triple_c' => '123'],
            Carbon::yesterday()->setTime(19, 5, 0),
            '19:05'
        );
        $previa2 = $this->crearResultado(
            $trioActivo,
            ['triple_a' => '555', 'triple_b' => '666', 'triple_c' => '123'],
            Carbon::today()->setTime(12, 45, 0),
            '12:45'
        );
        $otroNumero = $this->crearResultado(
            $trioActivo,
            ['triple_a' => '777', 'triple_b' => '888', 'triple_c' => '999'],
            Carbon::yesterday()->setTime(12, 45, 0),
            '12:45'
        );

        $response = $this->actingAs($this->userByRole('banca'), 'sanctum')
            ->getJson('/api/v1/resultados/'.$clicada->id.'/apariciones?posicion=triple_c');

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertCount(2, $data);
        $this->assertEquals([$previa2->id, $previa1->id], array_column($data, 'id'));
        $this->assertNotContains($clicada->id, array_column($data, 'id'));
        $this->assertNotContains($otroNumero->id, array_column($data, 'id'));
    }

    public function test_apariciones_tripletas_posicion_invalida_o_ausente_422()
    {
        $tripleZulia = $this->juego('triple-zulia');
        $resultado = $this->crearResultado(
            $tripleZulia,
            ['triple_a' => '123', 'triple_b' => '456', 'triple_c' => '789', 'signo' => 'LEO', 'pais' => 'VE'],
            Carbon::today()->setTime(19, 5, 0),
            '19:05'
        );

        // ?posicion=hack → 422
        $this->actingAs($this->userByRole('super_master'), 'sanctum')
            ->getJson('/api/v1/resultados/'.$resultado->id.'/apariciones?posicion=hack')
            ->assertStatus(422)
            ->assertJsonValidationErrors('posicion');

        // tripletas sin posicion → 422
        $this->actingAs($this->userByRole('super_master'), 'sanctum')
            ->getJson('/api/v1/resultados/'.$resultado->id.'/apariciones')
            ->assertStatus(422)
            ->assertJsonValidationErrors('posicion');
    }

    // ==================================================
    // C5 — Cap fijo 5 y orden (fecha desc, hora desc)
    // ==================================================

    public function test_apariciones_cap_fijo_5_y_orden_fecha_luego_hora_desc()
    {
        $animalitos = $this->juego('lotto-activo');

        // 7 previas el MISMO día (mismo timestamp) con horas distintas → ordena por hora_sorteo desc
        $horas = ['19:05', '18:30', '15:00', '12:45', '10:00', '09:00', '08:00'];
        $previas = [];
        foreach ($horas as $hora) {
            $previas[] = $this->crearResultado(
                $animalitos,
                ['numero' => 7, 'nombre_animal' => 'perro', 'color_animal' => 'marrón', 'pais' => 'VE'],
                Carbon::yesterday()->setTime(0, 0, 0),
                $hora
            );
        }
        $clicada = $this->crearResultado(
            $animalitos,
            ['numero' => 7, 'nombre_animal' => 'perro', 'color_animal' => 'marrón', 'pais' => 'VE'],
            Carbon::today()->setTime(19, 5, 0),
            '19:05'
        );

        $response = $this->actingAs($this->userByRole('super_master'), 'sanctum')
            ->getJson('/api/v1/resultados/'.$clicada->id.'/apariciones');

        $response->assertStatus(200);
        $data = $response->json();

        // Exactamente 5, las más recientes primero (19:05, 18:30, 15:00, 12:45, 10:00)
        $this->assertCount(5, $data);
        $this->assertEquals(
            ['19:05', '18:30', '15:00', '12:45', '10:00'],
            array_column($data, 'hora_sorteo')
        );
        $this->assertEquals(
            array_column(array_slice($previas, 0, 5), 'id'),
            array_column($data, 'id')
        );
    }

    // ==================================================
    // C6 — Historial vacío
    // ==================================================

    public function test_apariciones_sin_historial_devuelve_array_vacio()
    {
        $terminales = $this->juego('terminal-activo');

        // Única fila del juego: sin apariciones previas posibles
        $unica = $this->crearResultado(
            $terminales,
            ['numero' => 5],
            Carbon::today()->setTime(19, 0, 0),
            '19:00'
        );

        $response = $this->actingAs($this->userByRole('agencia'), 'sanctum')
            ->getJson('/api/v1/resultados/'.$unica->id.'/apariciones');

        $response->assertStatus(200);
        $response->assertExactJson([]);
    }
}