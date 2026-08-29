<?php

namespace App\Domain\SuperAdmin\Enums;

enum SubscriptionPlanCurrency: string
{
    case INR = 'inr';
    case USD = 'usd';
    case EUR = 'eur';
}
