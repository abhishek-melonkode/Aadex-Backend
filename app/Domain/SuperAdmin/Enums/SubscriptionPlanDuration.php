<?php

namespace App\Domain\SuperAdmin\Enums;

enum SubscriptionPlanDuration: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case HalfYearly = 'half_yearly';
    case Yearly = 'yearly';
}
