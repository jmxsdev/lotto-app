<?php

namespace App\Audit;

/**
 * Reason requirement for an audited action.
 *
 * - Required: destructive or access-changing operations; the caller must send
 *   a reason.
 * - Optional: sensitive but reversible operations; a reason may be sent.
 * - Automatic: the system derives the reason (auth failures, jobs, commands).
 */
enum ReasonPolicy: string
{
    case Required = 'required';
    case Optional = 'optional';
    case Automatic = 'automatic';

    public static function for(AuditAction $action): self
    {
        return match ($action) {
            AuditAction::UserPasswordResetByAdmin,
            AuditAction::UserRoleChanged,
            AuditAction::UserPermissionsChanged,
            AuditAction::UserDeactivated,
            AuditAction::UserDeleted,
            AuditAction::BancaToggled,
            AuditAction::BancaDeleted,
            AuditAction::GrupoToggled,
            AuditAction::GrupoDeleted,
            AuditAction::GrupoBancaChanged,
            AuditAction::AgenciaToggled,
            AuditAction::AgenciaDeleted,
            AuditAction::AgenciaBancaChanged,
            AuditAction::TaquillaToggled,
            AuditAction::TaquillaDeleted,
            AuditAction::TaquillaGroupChanged,
            AuditAction::CierreReclosed,
            AuditAction::TicketVoided,
            AuditAction::BetVoided,
            AuditAction::GamePrizesUpdated,
            AuditAction::GameLimitDeleted,
            AuditAction::DrawResultCorrected,
            AuditAction::ReleasePublished => self::Required,

            AuditAction::AuthLoginFailed,
            AuditAction::DeviceVerificationFailed,
            AuditAction::TaquillaActivationFailed,
            AuditAction::TaquillaMacReassigned,
            AuditAction::AuditExported,
            AuditAction::AuditRetentionPurged,
            AuditAction::AuditChainVerified,
            AuditAction::PermissionCatalogSynced,
            AuditAction::ExchangeRateScraped,
            AuditAction::DrawResultIngested,
            AuditAction::DrawSettlementCompleted,
            AuditAction::TicketExpired,
            AuditAction::BetExpired,
            AuditAction::PagoLegacyReconciled,
            AuditAction::BackfillCompleted => self::Automatic,

            default => self::Optional,
        };
    }

    public function isRequired(): bool
    {
        return $this === self::Required;
    }

    public function isAutomatic(): bool
    {
        return $this === self::Automatic;
    }
}
