<?php

namespace App\Http\Resources\Tenancy;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HotelResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'chain_id' => $this->chain_id,
            'name' => $this->name,
            'admin_name' => $this->admin_name,
            'admin_email' => $this->admin_email,
            'phone' => $this->phone,
            'subscription_plan_id' => $this->subscription_plan_id,
            'ota_status' => $this->ota_status,
            'status' => $this->status,
            'plan_duration' => $this->plan_duration,
            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state,
            'country' => $this->country,
            'gst_tax_id' => $this->gst_tax_id,
            'currency' => $this->currency,
            'timezone' => $this->timezone,
            'website_slug' => $this->website_slug,
            'registered_at' => $this->registered_at?->toIso8601String(),
        ];
    }
}
