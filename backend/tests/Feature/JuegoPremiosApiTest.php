<?php

namespace Tests\Feature;

use App\Models\Juego;
use App\Models\JuegoAuditoria;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S1a — PUT /api/v1/juegos/{juego}/premios (design D1/D2/D3, spec
 * configuracion-premios).
 *
 * Edición atómica de `config.premios` con merge seguro de alto nivel sobre
 * `config` (preserva `scraper`/`modalidades_permitidas`), validación estricta
 * del payload (base int ≥ 1; claves de modalidad en plugin ∪ catálogo oficial;
 * comodines con tipo/premio_multiplo válidos y `acumulativo` solo con
 * tipo=palabra; la-ricachona sin base oficial → 422), sincronización de
 * espejos legacy y auditoría `accion=premios` con before/after.
 */
class JuegoPremiosApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function superUser(): User
    {
        return User::where('email', 'super@lotto.com')->firstOrFail();
    }

    private function juego(string $slug): Juego
    {
        return Juego::where('slug', $slug)->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'base' => 600,
            'modalidades' => ['terminal' => 60],
            'comodines' => [],
        ], $overrides);
    }

    public function test_put_premios_acepta_claves_de_plugin_y_catalogo(): void
    {
        // Unión D3: `triple_a` lo lista el plugin Tripletas y `terminal` el
        // catálogo oficial; ambas claves deben ser válidas a la vez.
        $juego = $this->juego('triple-zulia');

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->putJson("/api/v1/juegos/{$juego->id}/premios", $this->payload([
                'modalidades' => ['triple_a' => 600, 'terminal' => 60],
            ]));

        $response->assertStatus(200);
        // El orden de claves JSON no es contrato (MySQL reordena claves de
        // objetos en columnas json) → comparación canónica, como JuegosJsonTest.
        $this->assertEqualsCanonicalizing(
            ['triple_a' => 600, 'terminal' => 60],
            $response->json('config.premios.modalidades')
        );
    }

    public function test_put_premios_clave_invalida_422(): void
    {
        $juego = $this->juego('triple-zulia');

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->putJson("/api/v1/juegos/{$juego->id}/premios", $this->payload([
                'modalidades' => ['clave_inexistente' => 100],
            ]));

        $response->assertStatus(422);

        $this->assertEqualsCanonicalizing(
            ['base' => 600, 'modalidades' => ['terminal' => 60, 'signo_triple' => 6000, 'signo_terminal' => 600], 'comodines' => []],
            $juego->fresh()->config['premios'],
            'El 422 no debe tocar los premios vigentes.'
        );
    }

    public function test_put_premios_la_ricachona_422(): void
    {
        // REQ7/D3: la-ricachona no tiene base oficial → no se edita, 422 claro.
        $juego = $this->juego('la-ricachona');

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->putJson("/api/v1/juegos/{$juego->id}/premios", $this->payload());

        $response->assertStatus(422);
        $this->assertStringContainsString(
            'premios',
            strtolower((string) $response->json('message')),
            'El mensaje debe explicar que el juego no tiene premios configurables.'
        );
    }

    public function test_put_premios_reemplaza_atomicamente_y_sincroniza_espejos(): void
    {
        $juego = $this->juego('triple-zulia');

        $payload = [
            'base' => 600,
            'modalidades' => ['terminal' => 60, 'signo_triple' => 6000],
            'comodines' => [
                'comodin-x' => ['tipo' => 'letra', 'premio_multiplo' => 100, 'valor' => 'X', 'nombre' => 'Comodín X'],
            ],
        ];

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->putJson("/api/v1/juegos/{$juego->id}/premios", $payload);

        $response->assertStatus(200);

        // Reemplazo atómico: el body ES el objeto `premios` completo
        // (comparación canónica: el orden de claves JSON no es contrato).
        $this->assertEqualsCanonicalizing($payload, $response->json('config.premios'));

        // Espejos legacy sincronizados (D2): premio_multiplo = base,
        // modalidades con claves históricas y comodines en espejo.
        $this->assertSame(600, $response->json('config.premio_multiplo'));
        $this->assertSame(['cola' => 60, 'zodiacal' => 6000], $response->json('config.modalidades'));
        $this->assertEqualsCanonicalizing($payload['comodines'], $response->json('config.comodines'));

        // Persistido en BD (config cast array).
        $this->assertEqualsCanonicalizing($payload, $juego->fresh()->config['premios']);
    }

    public function test_put_premios_403_para_rol_banca(): void
    {
        // Ruta dentro del grupo role:super_master|master → banca no puede.
        $banca = User::where('email', 'banca@lotto.com')->firstOrFail();
        $juego = $this->juego('triple-zulia');

        $response = $this->actingAs($banca, 'sanctum')
            ->putJson("/api/v1/juegos/{$juego->id}/premios", $this->payload());

        $response->assertStatus(403);
    }

    public function test_put_premios_base_invalida_422(): void
    {
        $juego = $this->juego('triple-zulia');
        $user = $this->superUser();

        // 0, negativo, no entero y decimal → 422 (base required|integer|min:1).
        foreach ([0, -5, 'abc', 1.5] as $base) {
            $response = $this->actingAs($user, 'sanctum')
                ->putJson("/api/v1/juegos/{$juego->id}/premios", $this->payload(['base' => $base]));

            $response->assertStatus(422);
        }

        $this->assertSame(600, $juego->fresh()->config['premios']['base'], 'Ningún PUT inválido persiste.');
    }

    public function test_put_premios_tipo_comodin_invalido_422(): void
    {
        $juego = $this->juego('triple-zulia');

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->putJson("/api/v1/juegos/{$juego->id}/premios", $this->payload([
                'comodines' => ['comodin-x' => ['tipo' => 'doble', 'premio_multiplo' => 100]],
            ]));

        $response->assertStatus(422);
    }

    public function test_put_premios_acumulativo_sin_tipo_palabra_422(): void
    {
        $juego = $this->juego('triple-zulia');

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->putJson("/api/v1/juegos/{$juego->id}/premios", $this->payload([
                'comodines' => ['comodin-x' => ['tipo' => 'numero', 'premio_multiplo' => 100, 'acumulativo' => true]],
            ]));

        $response->assertStatus(422);
    }

    public function test_put_premios_valores_invalidos_422(): void
    {
        $juego = $this->juego('triple-zulia');
        $user = $this->superUser();

        // Comodín con premio_multiplo inválido (0, negativo, no entero).
        foreach ([0, -3, 'abc'] as $multiplo) {
            $response = $this->actingAs($user, 'sanctum')
                ->putJson("/api/v1/juegos/{$juego->id}/premios", $this->payload([
                    'comodines' => ['comodin-x' => ['tipo' => 'flag', 'premio_multiplo' => $multiplo]],
                ]));

            $response->assertStatus(422);
        }

        // Modalidad con valor inválido (0 y no entero).
        foreach ([0, 'abc'] as $valor) {
            $response = $this->actingAs($user, 'sanctum')
                ->putJson("/api/v1/juegos/{$juego->id}/premios", $this->payload([
                    'modalidades' => ['terminal' => $valor],
                ]));

            $response->assertStatus(422);
        }
    }

    public function test_put_premios_clave_canonica_no_listada_por_el_plugin_200(): void
    {
        // D3: `signo_terminal` vive en el catálogo oficial (PremiosOficiales)
        // pero el plugin Tripletas NO lo lista en obtenerModalidades() → se
        // acepta (clave canónica sin plugin, no se bloquea).
        $juego = $this->juego('triple-zulia');

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->putJson("/api/v1/juegos/{$juego->id}/premios", $this->payload([
                'modalidades' => ['signo_terminal' => 600],
            ]));

        $response->assertStatus(200);
        $this->assertSame(['signo_terminal' => 600], $response->json('config.premios.modalidades'));

        // Espejo legacy: signo_terminal → terminal_zodiacal.
        $this->assertSame(['terminal_zodiacal' => 600], $response->json('config.modalidades'));
    }

    public function test_put_premios_merge_preserva_scraper_y_modalidades_permitidas(): void
    {
        // Merge de alto nivel: solo se toca `premios` y sus espejos.
        $juego = $this->juego('triple-zulia');
        $config = $juego->config;
        $config['scraper'] = ['product_id' => '42'];
        $config['modalidades_permitidas'] = ['triple_a', 'triple_b', 'triple_c'];
        $juego->update(['config' => $config]);

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->putJson("/api/v1/juegos/{$juego->id}/premios", $this->payload());

        $response->assertStatus(200);
        $this->assertSame(['product_id' => '42'], $response->json('config.scraper'));
        $this->assertSame(['triple_a', 'triple_b', 'triple_c'], $response->json('config.modalidades_permitidas'));
    }

    public function test_put_premios_audita_before_y_after(): void
    {
        $user = $this->superUser();
        $juego = $this->juego('triple-zulia');
        $premiosAntes = $juego->config['premios'];

        $payload = $this->payload(['base' => 700, 'modalidades' => ['terminal' => 70]]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/juegos/{$juego->id}/premios", $payload)
            ->assertStatus(200);

        $auditoria = JuegoAuditoria::where('juego_id', $juego->id)
            ->where('accion', 'premios')
            ->firstOrFail();

        $this->assertSame($user->id, $auditoria->user_id);
        $this->assertEqualsCanonicalizing($premiosAntes, $auditoria->cambios['before']);
        $this->assertEqualsCanonicalizing($payload, $auditoria->cambios['after']);

        $this->assertSame($user->id, $juego->fresh()->updated_by, 'updated_by se registra en el juego.');
    }

    // ==================================================
    // S1b — Integración end-to-end (espejos vía GET,
    // auditoría expuesta y reflejo en /reglas)
    // ==================================================

    public function test_put_premios_espejos_reflejados_en_get_juego(): void
    {
        // S1b: tras el PUT, GET /juegos/{id} refleja premios canónicos Y los
        // espejos legacy sincronizados (premio_multiplo=base, modalidades
        // espejo, comodines) — el contrato que consume el panel.
        $juego = $this->juego('triple-zulia');

        $payload = [
            'base' => 700,
            'modalidades' => ['terminal' => 70, 'signo_triple' => 7000],
            'comodines' => [
                'comodin-x' => ['tipo' => 'letra', 'premio_multiplo' => 100, 'valor' => 'X', 'nombre' => 'Comodín X'],
            ],
        ];

        $this->actingAs($this->superUser(), 'sanctum')
            ->putJson("/api/v1/juegos/{$juego->id}/premios", $payload)
            ->assertStatus(200);

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->getJson("/api/v1/juegos/{$juego->id}");

        $response->assertStatus(200);
        $this->assertEqualsCanonicalizing($payload, $response->json('config.premios'));
        $this->assertSame(700, $response->json('config.premio_multiplo'));
        $this->assertEqualsCanonicalizing(
            ['cola' => 70, 'zodiacal' => 7000],
            $response->json('config.modalidades'),
            'El espejo legacy de modalidades debe reflejarse en GET /juegos/{id}.'
        );
        $this->assertEqualsCanonicalizing($payload['comodines'], $response->json('config.comodines'));
    }

    public function test_put_premios_reflejados_en_reglas(): void
    {
        // S1b: GET /juegos/{id}/reglas refleja los premios nuevos del motor
        // (aditivo, spec REQ "Reflejo en las reglas del juego").
        $juego = $this->juego('triple-zulia');

        $payload = $this->payload(['base' => 700, 'modalidades' => ['terminal' => 70]]);

        $this->actingAs($this->superUser(), 'sanctum')
            ->putJson("/api/v1/juegos/{$juego->id}/premios", $payload)
            ->assertStatus(200);

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->getJson("/api/v1/juegos/{$juego->id}/reglas");

        $response->assertStatus(200);
        $this->assertArrayHasKey('premios', $response->json(), 'reglas debe exponer premios del motor.');
        $this->assertEqualsCanonicalizing($payload, $response->json('premios'));
        $this->assertSame(700, $response->json('premios.base'));
    }

    public function test_put_premios_auditoria_expuesta_en_get_juego(): void
    {
        // S1b: GET /juegos/{id} expone auditoria[] con la entrada `premios`
        // (before/after) y el usuario editor (relación user cargada por show()).
        $user = $this->superUser();
        $juego = $this->juego('triple-zulia');
        $premiosAntes = $juego->config['premios'];

        $payload = $this->payload(['base' => 700, 'modalidades' => ['terminal' => 70]]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/juegos/{$juego->id}/premios", $payload)
            ->assertStatus(200);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/juegos/{$juego->id}");

        $response->assertStatus(200);

        $entradas = collect($response->json('auditoria'))->where('accion', 'premios')->values();
        $this->assertCount(1, $entradas, 'Debe existir exactamente una auditoría accion=premios expuesta.');

        $entrada = $entradas->first();
        $this->assertSame($user->id, $entrada['user_id']);
        $this->assertEqualsCanonicalizing($premiosAntes, $entrada['cambios']['before']);
        $this->assertEqualsCanonicalizing($payload, $entrada['cambios']['after']);
        $this->assertSame($user->email, $entrada['user']['email'] ?? null, 'El usuario editor viaja en la relación user.');
    }
}
