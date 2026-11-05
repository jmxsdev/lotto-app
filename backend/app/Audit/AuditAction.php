<?php

namespace App\Audit;

/**
 * Closed catalogue of auditable actions, expressed as `domain.verb`.
 *
 * Values are versioned API: they must never be renamed or removed. New actions
 * are appended; obsolete actions remain for historical rows.
 */
enum AuditAction: string
{
    // Authentication, session and access
    case AuthLoginSucceeded = 'auth.login_succeeded';
    case AuthLoginFailed = 'auth.login_failed';
    case AuthLogout = 'auth.logout';
    case AuthTokenRevoked = 'auth.token_revoked';
    case UserPasswordResetByAdmin = 'user.password_reset_by_admin';
    case DeviceVerificationFailed = 'device.verification_failed';
    case TaquillaActivationSucceeded = 'taquilla.activation_succeeded';
    case TaquillaActivationFailed = 'taquilla.activation_failed';
    case TaquillaMacReassigned = 'taquilla.mac_reassigned';
    case ClaveCierreUpdated = 'clave_cierre.updated';

    // Users, roles and permissions
    case UserCreated = 'user.created';
    case UserUpdated = 'user.updated';
    case UserRoleChanged = 'user.role_changed';
    case UserPermissionsChanged = 'user.permissions_changed';
    case UserActivated = 'user.activated';
    case UserDeactivated = 'user.deactivated';
    case UserDeleted = 'user.deleted';

    // Entities and hierarchy
    case BancaCreated = 'banca.created';
    case BancaUpdated = 'banca.updated';
    case BancaToggled = 'banca.toggled';
    case BancaDeleted = 'banca.deleted';
    case GrupoCreated = 'grupo.created';
    case GrupoUpdated = 'grupo.updated';
    case GrupoToggled = 'grupo.toggled';
    case GrupoDeleted = 'grupo.deleted';
    case GrupoBancaChanged = 'grupo.banca_changed';
    case AgenciaCreated = 'agencia.created';
    case AgenciaUpdated = 'agencia.updated';
    case AgenciaToggled = 'agencia.toggled';
    case AgenciaDeleted = 'agencia.deleted';
    case AgenciaBancaChanged = 'agencia.banca_changed';
    case TaquillaCreated = 'taquilla.created';
    case TaquillaUpdated = 'taquilla.updated';
    case TaquillaToggled = 'taquilla.toggled';
    case TaquillaDeleted = 'taquilla.deleted';
    case TaquillaGroupChanged = 'taquilla.group_changed';

    // Money and configuration
    case ExchangeRateCreated = 'exchange_rate.created';
    case ExchangeRateUpdated = 'exchange_rate.updated';
    case ExchangeRateActivated = 'exchange_rate.activated';
    case CommissionDefaultsUpdated = 'commission_defaults.updated';
    case CierreCreated = 'cierre.created';
    case CierreReclosed = 'cierre.reclosed';
    case PagoPrizePaid = 'pago.prize_paid';
    case TicketVoided = 'ticket.voided';
    case BetVoided = 'bet.voided';

    // Games, limits and operational configuration
    case GameUpdated = 'game.updated';
    case GameToggled = 'game.toggled';
    case GamePrizesUpdated = 'game.prizes_updated';
    case GameLimitsUpdated = 'game.limits_updated';
    case GameLimitsBatchUpdated = 'game.limits_batch_updated';
    case GameLimitDeleted = 'game.limit_deleted';
    case ConfigBetExpirationWindowUpdated = 'config.bet_expiration_window_updated';

    // Draws, releases and audit itself
    case DrawScrapeRequested = 'draw.scrape_requested';
    case DrawScrapeAllRequested = 'draw.scrape_all_requested';
    case DrawResultCorrected = 'draw.result_corrected';
    case ReleasePublished = 'release.published';
    case AuditExported = 'audit.exported';
    case AuditRetentionPurged = 'audit.retention_purged';
    case AuditChainVerified = 'audit.chain_verified';
    case PermissionCatalogSynced = 'permission.catalog_synced';

    // System jobs and commands
    case ExchangeRateScraped = 'exchange_rate.scraped';
    case DrawResultIngested = 'draw.result_ingested';
    case DrawSettlementCompleted = 'draw.settlement_completed';
    case TicketExpired = 'ticket.expired';
    case BetExpired = 'bet.expired';
    case PagoLegacyReconciled = 'pago.legacy_reconciled';
    case BackfillCompleted = 'backfill.completed';

    public function domain(): string
    {
        return explode('.', $this->value, 2)[0];
    }

    public function priority(): AuditPriority
    {
        return match ($this) {
            self::AuthLoginSucceeded,
            self::AuthLoginFailed,
            self::AuthLogout,
            self::UserPasswordResetByAdmin,
            self::TaquillaActivationSucceeded,
            self::TaquillaActivationFailed,
            self::TaquillaMacReassigned,
            self::ClaveCierreUpdated,
            self::UserCreated,
            self::UserUpdated,
            self::UserRoleChanged,
            self::UserPermissionsChanged,
            self::UserActivated,
            self::UserDeactivated,
            self::UserDeleted,
            self::BancaToggled,
            self::BancaDeleted,
            self::GrupoToggled,
            self::GrupoDeleted,
            self::GrupoBancaChanged,
            self::AgenciaToggled,
            self::AgenciaDeleted,
            self::AgenciaBancaChanged,
            self::TaquillaToggled,
            self::TaquillaDeleted,
            self::TaquillaGroupChanged,
            self::ExchangeRateCreated,
            self::ExchangeRateUpdated,
            self::ExchangeRateActivated,
            self::CommissionDefaultsUpdated,
            self::CierreReclosed,
            self::PagoPrizePaid,
            self::TicketVoided,
            self::BetVoided,
            self::GameToggled,
            self::GamePrizesUpdated,
            self::GameLimitsUpdated,
            self::DrawResultCorrected,
            self::ExchangeRateScraped,
            self::TicketExpired,
            self::BetExpired => AuditPriority::P0,

            self::AuthTokenRevoked,
            self::DeviceVerificationFailed,
            self::BancaCreated,
            self::BancaUpdated,
            self::GrupoCreated,
            self::GrupoUpdated,
            self::AgenciaCreated,
            self::AgenciaUpdated,
            self::TaquillaCreated,
            self::TaquillaUpdated,
            self::CierreCreated,
            self::GameUpdated,
            self::GameLimitsBatchUpdated,
            self::GameLimitDeleted,
            self::ConfigBetExpirationWindowUpdated,
            self::DrawScrapeRequested,
            self::DrawScrapeAllRequested,
            self::ReleasePublished,
            self::AuditExported,
            self::AuditRetentionPurged,
            self::AuditChainVerified,
            self::PermissionCatalogSynced,
            self::DrawResultIngested,
            self::DrawSettlementCompleted,
            self::PagoLegacyReconciled => AuditPriority::P1,

            self::BackfillCompleted => AuditPriority::P2,
        };
    }

    public function reasonPolicy(): ReasonPolicy
    {
        return ReasonPolicy::for($this);
    }

    public function requiresReason(): bool
    {
        return $this->reasonPolicy()->isRequired();
    }

    public function isFailClosed(): bool
    {
        return $this->priority()->isFailClosed();
    }

    public function entityType(): ?string
    {
        return match ($this) {
            self::AuthLoginSucceeded,
            self::AuthLoginFailed,
            self::AuthLogout,
            self::AuthTokenRevoked => null,

            self::UserPasswordResetByAdmin,
            self::UserCreated,
            self::UserUpdated,
            self::UserRoleChanged,
            self::UserPermissionsChanged,
            self::UserActivated,
            self::UserDeactivated,
            self::UserDeleted => 'user',

            self::DeviceVerificationFailed => 'device',

            self::BancaCreated,
            self::BancaUpdated,
            self::BancaToggled,
            self::BancaDeleted => 'banca',

            self::GrupoCreated,
            self::GrupoUpdated,
            self::GrupoToggled,
            self::GrupoDeleted,
            self::GrupoBancaChanged => 'grupo',

            self::AgenciaCreated,
            self::AgenciaUpdated,
            self::AgenciaToggled,
            self::AgenciaDeleted,
            self::AgenciaBancaChanged => 'agencia',

            self::TaquillaActivationSucceeded,
            self::TaquillaActivationFailed,
            self::TaquillaMacReassigned,
            self::TaquillaCreated,
            self::TaquillaUpdated,
            self::TaquillaToggled,
            self::TaquillaDeleted,
            self::TaquillaGroupChanged => 'taquilla',

            self::ClaveCierreUpdated,
            self::CommissionDefaultsUpdated,
            self::ConfigBetExpirationWindowUpdated => 'configuracion',

            self::ExchangeRateCreated,
            self::ExchangeRateUpdated,
            self::ExchangeRateActivated,
            self::ExchangeRateScraped => 'exchange_rate',

            self::CierreCreated,
            self::CierreReclosed => 'cierre_caja',

            self::PagoPrizePaid,
            self::PagoLegacyReconciled => 'pago',

            self::TicketVoided,
            self::TicketExpired => 'ticket',

            self::BetVoided,
            self::BetExpired => 'apuesta',

            self::GameUpdated,
            self::GameToggled,
            self::GamePrizesUpdated => 'juego',

            self::GameLimitsUpdated,
            self::GameLimitsBatchUpdated,
            self::GameLimitDeleted => 'juego_limite',

            self::DrawScrapeRequested,
            self::DrawScrapeAllRequested,
            self::DrawResultCorrected,
            self::DrawResultIngested,
            self::DrawSettlementCompleted => 'resultado',

            self::ReleasePublished => 'release',

            self::AuditExported,
            self::AuditRetentionPurged,
            self::AuditChainVerified,
            self::BackfillCompleted => 'audit',

            self::PermissionCatalogSynced => 'permission_catalog',
        };
    }

    /**
     * Code the recorder may persist for events whose reason is automatic.
     */
    public function defaultAutomaticReason(): ?string
    {
        return match ($this) {
            self::AuthLoginFailed,
            self::DeviceVerificationFailed,
            self::TaquillaMacReassigned => AuditReason::SecurityIncident->value,

            self::TaquillaActivationFailed => AuditReason::OperationalError->value,

            self::AuditExported,
            self::AuditRetentionPurged,
            self::AuditChainVerified,
            self::PermissionCatalogSynced,
            self::ExchangeRateScraped,
            self::DrawResultIngested,
            self::DrawSettlementCompleted,
            self::TicketExpired,
            self::BetExpired,
            self::PagoLegacyReconciled,
            self::BackfillCompleted => AuditReason::Unspecified->value,

            default => null,
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $action): string => $action->value, self::cases());
    }
}
