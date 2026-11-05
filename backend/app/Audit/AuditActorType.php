<?php

namespace App\Audit;

enum AuditActorType: string
{
    case User = 'user';
    case System = 'system';
    case Device = 'device';
}
