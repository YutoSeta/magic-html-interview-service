<?php

namespace Database\Factories;

use App\Models\InterviewSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InterviewSession>
 */
final class InterviewSessionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => fake()->slug(2),
            'locale' => 'ja',
            'status' => InterviewSession::STATUS_ACTIVE,
            'current_step' => 0,
            'messages' => [],
            'structured_data' => null,
        ];
    }
}
