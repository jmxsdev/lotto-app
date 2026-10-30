<?php

namespace Tests\Unit;

use App\Models\Agencia;
use App\Models\Apuesta;
use App\Models\Banca;
use App\Models\Grupo;
use App\Models\Juego;
use App\Models\Resultado;
use App\Models\Taquilla;
use App\Models\User;
use App\Policies\ApuestaPolicy;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WU1 — `ApuestaPolicy::motivoNoAnulable`: códigos de motivo de anulación.
 *
 * La policy es la única fuente de verdad de la regla de anulación. El orden
 * es `estado → con_resultado → sorteo_ocurrido → fuera_de_ventana →
 * sin_ownership` y `null` cuando la apuesta sí es anulable.
 */
class ApuestaPolicyAnulacionTest extends TestCase
{
    use RefreshDatabase;

    private ApuestaPolicy $policy;

    private Banca $banca;

    private Grupo $grupo;

    private Agencia $agencia;

    private Taquilla $taquilla;

    private Juego $juego;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-10-30 12:00:00'));

        $this->policy = new ApuestaPolicy;
        $this->juego = Juego::where('slug', 'lotto-activo')->firstOrFail();

        $this->banca = Banca::factory()->create();
        $this->grupo = Grupo::factory()->create(['banca_id' => $this->banca->id]);
        $this->agencia = Agencia::factory()->create(['grupo_id' => $this->grupo->id]);
        $this->taquilla = Taquilla::factory()->create([
            'grupo_id' => $this->grupo->id,
            'agencia_id' => $this->agencia->id,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function apuesta(array $overrides = []): Apuesta
    {
        return Apuesta::create(array_merge([
            'taquilla_id' => $this->taquilla->id,
            'juego_id' => $this->juego->id,
            'amount_bs' => 100,
            'amount_usd' => 0,
            'exchange_rate_applied' => 36.50,
            'total_bs_equivalent' => 100,
            'estado' => 'pendiente',
            'fecha_hora' => now(),
            'sorteo_hora' => now()->addHour(),
        ], $overrides));
    }

    /**
     * Desplaza `created_at` para simular antigüedad sin tocar `sorteo_hora`.
     */
    private function envejecer(Apuesta $apuesta, int $minutos): Apuesta
    {
        $apuesta->created_at = now()->subMinutes($minutos);
        $apuesta->save();

        return $apuesta;
    }

    private function resultado(): Resultado
    {
        return Resultado::create([
            'juego_id' => $this->juego->id,
            'fecha_sorteo' => '2026-10-30',
            'hora_sorteo' => '13:00',
            'numeros_ganadores' => ['numero' => 0, 'nombre_animal' => 'Delfin'],
        ]);
    }

    private function usuario(
        string $role,
        ?Banca $banca = null,
        ?Grupo $grupo = null,
        ?Agencia $agencia = null,
        ?Taquilla $taquilla = null,
    ): User {
        $banca ??= $this->banca;
        $grupo ??= $this->grupo;
        $agencia ??= $this->agencia;
        $taquilla ??= $this->taquilla;

        $contexto = match ($role) {
            'taquilla' => [
                'taquilla_id' => $taquilla->id,
                'grupo_id' => $grupo->id,
                'banca_id' => $banca->id,
                'agencia_id' => $agencia->id,
            ],
            'agencia' => [
                'agencia_id' => $agencia->id,
                'grupo_id' => $grupo->id,
                'banca_id' => $banca->id,
            ],
            'grupo' => ['grupo_id' => $grupo->id, 'banca_id' => $banca->id],
            'banca' => ['banca_id' => $banca->id],
            default => [],
        };

        return User::factory()->create(['role' => $role] + $contexto);
    }

    public function test_los_codigos_de_motivo_son_estables(): void
    {
        $this->assertSame('estado', ApuestaPolicy::MOTIVO_ESTADO);
        $this->assertSame('con_resultado', ApuestaPolicy::MOTIVO_CON_RESULTADO);
        $this->assertSame('sorteo_ocurrido', ApuestaPolicy::MOTIVO_SORTEO_OCURRIDO);
        $this->assertSame('fuera_de_ventana', ApuestaPolicy::MOTIVO_FUERA_DE_VENTANA);
        $this->assertSame('sin_ownership', ApuestaPolicy::MOTIVO_SIN_OWNERSHIP);
    }

    public function test_apuesta_anulable_no_tiene_motivo(): void
    {
        $apuesta = $this->apuesta();
        $user = $this->usuario('taquilla');

        $this->assertNull($this->policy->motivoNoAnulable($user, $apuesta));
    }

    public function test_motivo_estado_para_cualquier_estado_no_pendiente(): void
    {
        $user = $this->usuario('taquilla');

        foreach (['ganadora', 'pagada', 'perdida', 'vencido', 'anulada'] as $estado) {
            $apuesta = $this->apuesta(['estado' => $estado]);

            $this->assertSame(
                ApuestaPolicy::MOTIVO_ESTADO,
                $this->policy->motivoNoAnulable($user, $apuesta),
                "estado '{$estado}' no es anulable"
            );
        }
    }

    public function test_motivo_con_resultado_cuando_ya_fue_liquidada(): void
    {
        $apuesta = $this->apuesta(['resultado_id' => $this->resultado()->id]);

        $this->assertSame(
            ApuestaPolicy::MOTIVO_CON_RESULTADO,
            $this->policy->motivoNoAnulable($this->usuario('taquilla'), $apuesta)
        );
    }

    public function test_motivo_sorteo_ocurrido_cuando_el_sorteo_ya_paso(): void
    {
        $apuesta = $this->apuesta(['sorteo_hora' => now()->subMinute()]);

        $this->assertSame(
            ApuestaPolicy::MOTIVO_SORTEO_OCURRIDO,
            $this->policy->motivoNoAnulable($this->usuario('taquilla'), $apuesta)
        );
    }

    public function test_motivo_fuera_de_ventana_en_el_limite_exacto(): void
    {
        // Ventana efectiva por defecto (5): la regla es `>=`, así que 5
        // minutos exactos ya quedan fuera.
        $apuesta = $this->envejecer($this->apuesta(), 5);

        $this->assertSame(
            ApuestaPolicy::MOTIVO_FUERA_DE_VENTANA,
            $this->policy->motivoNoAnulable($this->usuario('taquilla'), $apuesta)
        );
    }

    public function test_apuesta_un_segundo_antes_del_limite_sigue_siendo_anulable(): void
    {
        $apuesta = $this->apuesta();
        $apuesta->created_at = now()->subSeconds(299);
        $apuesta->save();

        $this->assertNull($this->policy->motivoNoAnulable($this->usuario('taquilla'), $apuesta));
    }

    public function test_orden_de_motivos_estado_con_resultado_sorteo_ventana_ownership(): void
    {
        $resultado = $this->resultado();
        $ajeno = $this->usuario('taquilla', taquilla: Taquilla::factory()->create());

        // Todas las reglas violadas a la vez: gana `estado`.
        $apuesta = $this->envejecer($this->apuesta([
            'estado' => 'ganadora',
            'resultado_id' => $resultado->id,
            'sorteo_hora' => now()->subHour(),
        ]), 60);
        $this->assertSame(ApuestaPolicy::MOTIVO_ESTADO, $this->policy->motivoNoAnulable($ajeno, $apuesta));

        // Sin violar `estado`: gana `con_resultado`.
        $apuesta = $this->envejecer($this->apuesta([
            'resultado_id' => $resultado->id,
            'sorteo_hora' => now()->subHour(),
        ]), 60);
        $this->assertSame(ApuestaPolicy::MOTIVO_CON_RESULTADO, $this->policy->motivoNoAnulable($ajeno, $apuesta));

        // Sin resultado: gana `sorteo_ocurrido` (antes que la ventana).
        $apuesta = $this->envejecer($this->apuesta(['sorteo_hora' => now()->subHour()]), 60);
        $this->assertSame(ApuestaPolicy::MOTIVO_SORTEO_OCURRIDO, $this->policy->motivoNoAnulable($ajeno, $apuesta));

        // Sin sorteo ocurrido: gana `fuera_de_ventana` (antes que ownership).
        $apuesta = $this->envejecer($this->apuesta(), 60);
        $this->assertSame(ApuestaPolicy::MOTIVO_FUERA_DE_VENTANA, $this->policy->motivoNoAnulable($ajeno, $apuesta));

        // Dentro de ventana y sin bloqueos de estado: gana `sin_ownership`.
        $apuesta = $this->apuesta();
        $this->assertSame(ApuestaPolicy::MOTIVO_SIN_OWNERSHIP, $this->policy->motivoNoAnulable($ajeno, $apuesta));
    }

    public function test_ownership_por_rol_dentro_del_arbol(): void
    {
        $apuesta = $this->apuesta();

        foreach (['taquilla', 'agencia', 'grupo', 'banca', 'master', 'super_master'] as $role) {
            $user = $this->usuario($role);

            $this->assertNull(
                $this->policy->motivoNoAnulable($user, $apuesta),
                "rol {$role} puede anular una apuesta de su propio árbol"
            );
        }
    }

    public function test_ownership_por_rol_fuera_del_arbol(): void
    {
        $apuesta = $this->apuesta();

        $otraBanca = Banca::factory()->create();
        $otroGrupo = Grupo::factory()->create(['banca_id' => $this->banca->id]);
        $otraAgencia = Agencia::factory()->create(['grupo_id' => $this->grupo->id]);
        $otraTaquilla = Taquilla::factory()->create([
            'grupo_id' => $this->grupo->id,
            'agencia_id' => $this->agencia->id,
        ]);

        $casos = [
            'taquilla' => $this->usuario('taquilla', taquilla: $otraTaquilla),
            'agencia' => $this->usuario('agencia', agencia: $otraAgencia),
            'grupo' => $this->usuario('grupo', grupo: $otroGrupo),
            'banca' => $this->usuario('banca', banca: $otraBanca),
        ];

        foreach ($casos as $role => $user) {
            $this->assertSame(
                ApuestaPolicy::MOTIVO_SIN_OWNERSHIP,
                $this->policy->motivoNoAnulable($user, $apuesta),
                "rol {$role} de otra rama no puede anular"
            );
        }
    }

    public function test_agencia_sin_agencia_asignada_no_tiene_ownership(): void
    {
        $user = User::factory()->create(['role' => 'agencia', 'agencia_id' => null]);

        $this->assertSame(
            ApuestaPolicy::MOTIVO_SIN_OWNERSHIP,
            $this->policy->motivoNoAnulable($user, $this->apuesta())
        );
    }

    public function test_jerarquia_usa_el_tiempo_de_la_taquilla(): void
    {
        $this->taquilla->update(['tiempo_eliminacion' => 10]);
        $apuesta = $this->envejecer($this->apuesta(), 7);

        $this->assertNull($this->policy->motivoNoAnulable($this->usuario('taquilla'), $apuesta));
    }

    public function test_jerarquia_hereda_el_tiempo_del_grupo(): void
    {
        $this->grupo->update(['tiempo_eliminacion' => 15]);
        $apuesta = $this->envejecer($this->apuesta(), 7);

        $this->assertNull($this->policy->motivoNoAnulable($this->usuario('taquilla'), $apuesta));
    }

    public function test_jerarquia_hereda_el_tiempo_de_la_banca(): void
    {
        $this->banca->update(['tiempo_eliminacion' => 20]);
        $apuesta = $this->envejecer($this->apuesta(), 12);

        $this->assertNull($this->policy->motivoNoAnulable($this->usuario('taquilla'), $apuesta));
    }

    public function test_jerarquia_cae_al_default_de_cinco_minutos(): void
    {
        $apuesta = $this->envejecer($this->apuesta(), 6);

        $this->assertSame(
            ApuestaPolicy::MOTIVO_FUERA_DE_VENTANA,
            $this->policy->motivoNoAnulable($this->usuario('taquilla'), $apuesta)
        );
    }

    public function test_minutos_efectivos_precalculados_tienen_prioridad_sobre_la_jerarquia(): void
    {
        $this->taquilla->update(['tiempo_eliminacion' => 10]);
        $apuesta = $this->envejecer($this->apuesta(), 7);
        $user = $this->usuario('taquilla');

        // La taquilla permite 10 minutos; el valor precalculado manda.
        $this->assertSame(
            ApuestaPolicy::MOTIVO_FUERA_DE_VENTANA,
            $this->policy->motivoNoAnulable($user, $apuesta, 5)
        );
        $this->assertNull($this->policy->motivoNoAnulable($user, $apuesta, 30));
    }

    public function test_delete_delega_en_motivo_no_anulable(): void
    {
        $user = $this->usuario('taquilla');

        $this->assertTrue($this->policy->delete($user, $this->apuesta()));

        $bloqueadas = [
            'estado' => $this->apuesta(['estado' => 'ganadora']),
            'con_resultado' => $this->apuesta(['resultado_id' => $this->resultado()->id]),
            'sorteo_ocurrido' => $this->apuesta(['sorteo_hora' => now()->subMinute()]),
            'fuera_de_ventana' => $this->envejecer($this->apuesta(), 6),
        ];

        foreach ($bloqueadas as $motivo => $apuesta) {
            $this->assertFalse(
                $this->policy->delete($user, $apuesta),
                "delete() debe rechazar por {$motivo}"
            );
        }

        $this->assertFalse(
            $this->policy->delete($this->usuario('taquilla', taquilla: Taquilla::factory()->create()), $this->apuesta()),
            'delete() debe rechazar sin ownership'
        );
    }
}
