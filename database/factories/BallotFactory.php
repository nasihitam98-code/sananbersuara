<?php

namespace Database\Factories;

use App\Enums\BallotScope;
use App\Models\Ballot;
use App\Models\Election;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ballot>
 */
class BallotFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'election_id' => Election::factory(),
            'title' => 'Calon Ketua RW',
            'scope' => BallotScope::DaftarHadir,
            'sort' => 0,
            'max_candidates' => null,
        ];
    }
}
