<?php

namespace App\Domain\SuperAdmin\Enums;

enum SubscriptionPlanStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';
}
