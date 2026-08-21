<?php

namespace Database\Factories;

use App\Domain\Tenancy\Models\Hotel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Hotel>
 */
class HotelFactory extends Factory
{
    protected $model = Hotel::class;

    public function definition(): array
    {
        return [
            'chain_id' => null,
            'name' => fake()->unique()->company().' Hotel',
            'admin_name' => fake()->name(),
            'admin_email' => fake()->unique()->safeEmail(),
            'status' => 'active',
            'city' => fake()->city(),
            'state' => 'Maharashtra',
            'country' => 'India',
            'currency' => 'INR',
            'timezone' => 'Asia/Kolkata',
            'website_slug' => fake()->unique()->slug(2),
            'registered_at' => now(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => 'pending']);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => 'inactive']);
    }
}
