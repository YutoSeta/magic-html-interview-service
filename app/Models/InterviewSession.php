<?php

namespace App\Models;

use Database\Factories\InterviewSessionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class InterviewSession extends Model
{
    /** @use HasFactory<InterviewSessionFactory> */
    use HasFactory;

    use HasUuids;

    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_COMPLETED = 'completed';

    /** @var list<string> */
    protected $fillable = [
        'site_id',
        'locale',
        'status',
        'current_step',
        'messages',
        'structured_data',
    ];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return [
            'current_step' => 'integer',
            'messages' => 'array',
            'structured_data' => 'array',
        ];
    }
}
