<?php

namespace App\Http\Requests\SuperAdmin;

use App\Domain\SuperAdmin\Enums\SubscriptionPlanCurrency;
use App\Domain\SuperAdmin\Enums\SubscriptionPlanDuration;
use App\Domain\SuperAdmin\Enums\SubscriptionPlanStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateSubscriptionPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'modules_count' => ['sometimes', 'integer', 'min:0'],
            'ota_enabled_count' => ['sometimes', 'integer', 'min:0'],
            'duration' => ['sometimes', new Enum(SubscriptionPlanDuration::class)],
            'currency' => ['sometimes', new Enum(SubscriptionPlanCurrency::class)],
            'amount' => ['sometimes', 'numeric', 'min:0'],
            'status' => ['sometimes', new Enum(SubscriptionPlanStatus::class)],
        ];
    }
}
