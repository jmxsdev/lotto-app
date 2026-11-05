<?php

namespace App\Audit;

/**
 * Closed set of reason codes accepted for audited actions.
 *
 * `unspecified` and `other` are explicit escape hatches so callers never need
 * to persist a free-form code. Obsolete codes must be kept, never removed.
 */
enum AuditReason: string
{
    case DuplicateAccount = 'duplicate_account';
    case EmployeeOffboarding = 'employee_offboarding';
    case FraudSuspicion = 'fraud_suspicion';
    case DataCorrection = 'data_correction';
    case BusinessDecision = 'business_decision';
    case SecurityIncident = 'security_incident';
    case OperationalError = 'operational_error';
    case Unspecified = 'unspecified';
    case Other = 'other';
}
