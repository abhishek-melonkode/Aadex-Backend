<?php

namespace Database\Factories;

use App\Domain\Tenancy\Models\HotelChain;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HotelChain>
 */
class HotelChainFactory extends Factory
{
    protected $model = HotelChain::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company().' Hotels',
            'status' => 'active',
        ];
    }
}
