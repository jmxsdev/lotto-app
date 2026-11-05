<?php

namespace Tests\Unit\Audit;

use App\Audit\AuditAction;
use App\Audit\AuditPriority;
use App\Audit\AuditReason;
use App\Audit\ReasonPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ValueError;

class AuditActionTest extends TestCase
{
    public function test_catalogo_cerrado_tiene_sesenta_y_siete_eventos_sin_duplicados(): void
    {
        $values = AuditAction::values();

        $this->assertCount(67, $values);
        $this->assertSame($values, array_values(array_unique($values)));
    }

    public function test_cada_caso_expone_dominio_y_valor_validos(): void
    {
        foreach (AuditAction::cases() as $action) {
            $this->assertMatchesRegularExpression(
                '/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/',
                $action->value,
                "El valor [{$action->value}] no respeta la taxonomía domain.verb.",
            );
            $this->assertSame(explode('.', $action->value, 2)[0], $action->domain());
            $this->assertNotSame('', $action->domain());
        }
    }

    public function test_prioridad_y_motivo_son_coherentes_en_todo_el_catalogo(): void
    {
        foreach (AuditAction::cases() as $action) {
            $this->assertSame($action->reasonPolicy(), ReasonPolicy::for($action));
            $this->assertSame(
                $action->reasonPolicy() === ReasonPolicy::Required,
                $action->requiresReason(),
                "requiresReason() no coincide con la política de [{$action->value}].",
            );
            $this->assertSame(
                $action->priority() === AuditPriority::P0,
                $action->isFailClosed(),
                "isFailClosed() no coincide con P0 en [{$action->value}].",
            );
        }
    }

    public function test_motivo_automatico_solo_aplica_a_acciones_automaticas_y_usa_el_enum(): void
    {
        foreach (AuditAction::cases() as $action) {
            $reason = $action->defaultAutomaticReason();

            if ($action->reasonPolicy() === ReasonPolicy::Automatic) {
                $this->assertNotNull($reason, "La acción automática [{$action->value}] necesita un motivo por defecto.");
            }

            if ($reason !== null) {
                $this->assertNotNull(
                    AuditReason::tryFrom($reason),
                    "El motivo [{$reason}] de [{$action->value}] no pertenece al enum cerrado.",
                );
            }

            if ($action->reasonPolicy() === ReasonPolicy::Required) {
                $this->assertNull($reason, "La acción obligatoria [{$action->value}] no debe tener motivo automático.");
            }
        }
    }

    public function test_un_string_fuera_del_catalogo_es_rechazado(): void
    {
        $this->expectException(ValueError::class);

        AuditAction::from('user.hacked');
    }

    #[DataProvider('prioridadesPorAccion')]
    public function test_prioridad_por_accion(string $value, AuditPriority $expected): void
    {
        $this->assertSame($expected, AuditAction::from($value)->priority());
    }

    /**
     * @return array<string, array{0: string, 1: AuditPriority}>
     */
    public static function prioridadesPorAccion(): array
    {
        return [
            'login exitoso' => ['auth.login_succeeded', AuditPriority::P0],
            're-cierre' => ['cierre.reclosed', AuditPriority::P0],
            'cambio de rol de usuario' => ['user.role_changed', AuditPriority::P0],
            'juego toggled' => ['game.toggled', AuditPriority::P0],
            'tasa scrapeada' => ['exchange_rate.scraped', AuditPriority::P0],
            'token revocado' => ['auth.token_revoked', AuditPriority::P1],
            'banca creada' => ['banca.created', AuditPriority::P1],
            'export de auditoría' => ['audit.exported', AuditPriority::P1],
            'backfill' => ['backfill.completed', AuditPriority::P2],
        ];
    }

    #[DataProvider('motivosPorAccion')]
    public function test_politica_de_motivo_por_accion(string $value, ReasonPolicy $expected): void
    {
        $this->assertSame($expected, AuditAction::from($value)->reasonPolicy());
    }

    /**
     * @return array<string, array{0: string, 1: ReasonPolicy}>
     */
    public static function motivosPorAccion(): array
    {
        return [
            'borrar usuario' => ['user.deleted', ReasonPolicy::Required],
            'desactivar usuario' => ['user.deactivated', ReasonPolicy::Required],
            'cambiar rol' => ['user.role_changed', ReasonPolicy::Required],
            'cambiar permisos' => ['user.permissions_changed', ReasonPolicy::Required],
            'borrar banca' => ['banca.deleted', ReasonPolicy::Required],
            'toggle banca' => ['banca.toggled', ReasonPolicy::Required],
            'cambiar banca de grupo' => ['grupo.banca_changed', ReasonPolicy::Required],
            'cambiar grupo de taquilla' => ['taquilla.group_changed', ReasonPolicy::Required],
            're-cierre' => ['cierre.reclosed', ReasonPolicy::Required],
            'anular ticket' => ['ticket.voided', ReasonPolicy::Required],
            'anular apuesta' => ['bet.voided', ReasonPolicy::Required],
            'actualizar premios' => ['game.prizes_updated', ReasonPolicy::Required],
            'borrar límite' => ['game.limit_deleted', ReasonPolicy::Required],
            'publicar release' => ['release.published', ReasonPolicy::Required],
            'login fallido' => ['auth.login_failed', ReasonPolicy::Automatic],
            'fallo de verificación de dispositivo' => ['device.verification_failed', ReasonPolicy::Automatic],
            'fallo de activación' => ['taquilla.activation_failed', ReasonPolicy::Automatic],
            'MAC reasignada' => ['taquilla.mac_reassigned', ReasonPolicy::Automatic],
            'export' => ['audit.exported', ReasonPolicy::Automatic],
            'permission catalog' => ['permission.catalog_synced', ReasonPolicy::Automatic],
            'login exitoso' => ['auth.login_succeeded', ReasonPolicy::Optional],
            'logout' => ['auth.logout', ReasonPolicy::Optional],
            'banca creada' => ['banca.created', ReasonPolicy::Optional],
            'tasa creada' => ['exchange_rate.created', ReasonPolicy::Optional],
            'premio pagado' => ['pago.prize_paid', ReasonPolicy::Optional],
            'juego actualizado' => ['game.updated', ReasonPolicy::Optional],
        ];
    }

    public function test_acciones_de_auth_no_tienen_entidad(): void
    {
        foreach (['auth.login_succeeded', 'auth.login_failed', 'auth.logout', 'auth.token_revoked'] as $value) {
            $this->assertNull(AuditAction::from($value)->entityType(), "auth [{$value}] no debe tener entidad.");
        }
    }

    #[DataProvider('entidadesPorAccion')]
    public function test_entidad_por_accion(string $value, string $expected): void
    {
        $this->assertSame($expected, AuditAction::from($value)->entityType());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function entidadesPorAccion(): array
    {
        return [
            'usuario' => ['user.deleted', 'user'],
            'password' => ['user.password_reset_by_admin', 'user'],
            'dispositivo' => ['device.verification_failed', 'device'],
            'banca' => ['banca.created', 'banca'],
            'grupo' => ['grupo.updated', 'grupo'],
            'agencia' => ['agencia.toggled', 'agencia'],
            'taquilla' => ['taquilla.activation_succeeded', 'taquilla'],
            'clave cierre' => ['clave_cierre.updated', 'configuracion'],
            'tasa' => ['exchange_rate.scraped', 'exchange_rate'],
            'cierre' => ['cierre.reclosed', 'cierre_caja'],
            'pago' => ['pago.prize_paid', 'pago'],
            'ticket' => ['ticket.voided', 'ticket'],
            'apuesta' => ['bet.voided', 'apuesta'],
            'juego' => ['game.toggled', 'juego'],
            'límite' => ['game.limits_updated', 'juego_limite'],
            'resultado' => ['draw.result_ingested', 'resultado'],
            'release' => ['release.published', 'release'],
            'auditoría' => ['audit.exported', 'audit'],
            'permisos' => ['permission.catalog_synced', 'permission_catalog'],
        ];
    }
}
