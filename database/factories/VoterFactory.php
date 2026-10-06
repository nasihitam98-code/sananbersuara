<?php

namespace Database\Factories;

use App\Models\Unit;
use App\Models\Voter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Voter>
 */
class VoterFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'unit_id' => Unit::factory(),
            'name' => fake()->name(),
            'address' => fake()->streetAddress(),
            'gender' => fake()->randomElement(['L', 'P']),
            'birth_date' => fake()->dateTimeBetween('-70 years', '-18 years')->format('Y-m-d'),
            'is_active' => true,
        ];
    }
}
