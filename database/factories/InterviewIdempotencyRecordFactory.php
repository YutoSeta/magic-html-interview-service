<?php

namespace Database\Factories;

use App\Models\InterviewIdempotencyRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InterviewIdempotencyRecord>
 */
final class InterviewIdempotencyRecordFactory extends Factory
{
    /** @var class-string<InterviewIdempotencyRecord> */
    protected $model = InterviewIdempotencyRecord::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'scoped_key_hash' => hash('sha256', fake()->uuid()),
            'request_hash' => hash('sha256', fake()->uuid()),
            'operation' => 'interview.start',
            'interview_session_id' => null,
            'response_status' => null,
            'response_body' => null,
            'created_at' => now(),
            'completed_at' => null,
        ];
    }
}
