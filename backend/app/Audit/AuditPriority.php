<?php

namespace App\Audit;

enum AuditPriority: string
{
    case P0 = 'P0';
    case P1 = 'P1';
    case P2 = 'P2';

    public function isFailClosed(): bool
    {
        return $this === self::P0;
    }
}
