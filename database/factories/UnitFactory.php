<?php

namespace Database\Factories;

use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Unit>
 */
class UnitFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $number = fake()->unique()->numberBetween(1, 99);

        return [
            'code' => str_pad((string) $number, 2, '0', STR_PAD_LEFT),
            'name' => 'RT '.str_pad((string) $number, 2, '0', STR_PAD_LEFT),
            'sort' => $number,
        ];
    }
}
