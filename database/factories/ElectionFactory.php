<?php

namespace Database\Factories;

use App\Enums\ElectionMode;
use App\Enums\ElectionStatus;
use App\Models\Election;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Election>
 */
class ElectionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Penjaringan Calon RW '.fake()->year(),
            'mode' => ElectionMode::Dadakan,
            'settings' => null,
        ];
    }

    public function status(ElectionStatus $status): static
    {
        return $this->afterMaking(function (Election $election) use ($status): void {
            $election->status = $status;
        });
    }
}
