<?php

use App\Audit\AuditReason;

return [

    /*
    |--------------------------------------------------------------------------
    | Audit switches
    |--------------------------------------------------------------------------
    |
    | `enabled` turns event capture on/off. `fail_closed_domains` lists the
    | domains whose P0 writes must abort the business transaction when the
    | audit insert fails. Use `*` to fail closed for every domain.
    |
    */

    'enabled' => (bool) env('AUDIT_ENABLED', true),

    'fail_closed' => (bool) env('AUDIT_FAIL_CLOSED', true),

    'fail_closed_domains' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('AUDIT_FAIL_CLOSED_DOMAINS', '*')),
    ))),

    'hash_chain' => (bool) env('AUDIT_HASH_CHAIN', false),

    'schema_version' => (int) env('AUDIT_SCHEMA_VERSION', 1),

    /*
    |--------------------------------------------------------------------------
    | Retention and archive
    |--------------------------------------------------------------------------
    */

    'retention' => [
        'hot_months' => (int) env('AUDIT_HOT_MONTHS', 12),
        'archive_years' => (int) env('AUDIT_ARCHIVE_YEARS', 5),
        'archive_path' => env('AUDIT_ARCHIVE_PATH', 'audit/archive'),
        'connection' => env('AUDIT_RETENTION_CONNECTION', env('DB_CONNECTION', 'mysql')),
        'chunk' => (int) env('AUDIT_RETENTION_CHUNK', 1000),
    ],

    'archive' => [
        'years' => (int) env('AUDIT_ARCHIVE_YEARS', 5),
        'disk' => env('AUDIT_ARCHIVE_DISK', 'local'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Export
    |--------------------------------------------------------------------------
    */

    'export' => [
        'max_rows' => (int) env('AUDIT_EXPORT_MAX_ROWS', 50000),
        'chunk' => (int) env('AUDIT_EXPORT_CHUNK', 500),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reasons
    |--------------------------------------------------------------------------
    */

    'reasons' => [
        'codes' => array_map(
            static fn (AuditReason $reason): string => $reason->value,
            AuditReason::cases(),
        ),
        'text_max' => 500,
    ],

    /*
    |--------------------------------------------------------------------------
    | PII and secrets
    |--------------------------------------------------------------------------
    |
    | Keys that must never reach `before`, `after`, `context` or `reason_text`.
    |
    */

    'pii' => [
        'keys' => [
            'password',
            'password_confirmation',
            'remember_token',
            'token',
            'access_token',
            'authorization',
            'clave_cierre',
            'mac_address',
            'device_fingerprint',
            'activation_code',
            'secret',
        ],
        'mask_email' => true,
        'mask_mac' => true,
        'hash_fingerprint' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Operational logs
    |--------------------------------------------------------------------------
    |
    | Historical `logs.action` values that belong to the operational log view,
    | not to the audit trail.
    |
    */

    'logs' => [
        'operational_actions' => [
            'activacion_taquilla',
            'scrape_resultados',
            'scrape_animalitos',
        ],
    ],

];
