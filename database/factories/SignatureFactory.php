<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\LifetimeStatus;
use App\Models\MapSolarsystem;
use App\Models\Signature;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Signature>
 */
final class SignatureFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'map_solarsystem_id' => MapSolarsystem::factory(),
            'signature_id' => sprintf('%s-%03d', mb_strtoupper($this->faker->lexify('???')), $this->faker->numberBetween(0, 999)),
            'lifetime' => LifetimeStatus::Healthy,
        ];
    }
}
