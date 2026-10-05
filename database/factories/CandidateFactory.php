<?php

namespace Database\Factories;

use App\Enums\CandidateStatus;
use App\Models\Ballot;
use App\Models\Candidate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Candidate>
 */
class CandidateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ballot_id' => Ballot::factory(),
            'unit_id' => null,
            'number' => fake()->unique()->numberBetween(1, 999),
            'name' => fake()->name(),
            'status' => CandidateStatus::Aktif,
        ];
    }
}
