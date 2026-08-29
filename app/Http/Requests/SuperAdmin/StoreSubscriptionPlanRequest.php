<?php

namespace App\Http\Requests\SuperAdmin;

use App\Domain\SuperAdmin\Enums\SubscriptionPlanCurrency;
use App\Domain\SuperAdmin\Enums\SubscriptionPlanDuration;
use App\Domain\SuperAdmin\Enums\SubscriptionPlanStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreSubscriptionPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'modules_count' => ['required', 'integer', 'min:0'],
            'ota_enabled_count' => ['required', 'integer', 'min:0'],
            'duration' => ['required', new Enum(SubscriptionPlanDuration::class)],
            'currency' => ['required', new Enum(SubscriptionPlanCurrency::class)],
            'amount' => ['required', 'numeric', 'min:0'],
            'status' => ['sometimes', new Enum(SubscriptionPlanStatus::class)],
        ];
    }
}
