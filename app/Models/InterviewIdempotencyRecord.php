<?php

namespace App\Models;

use Database\Factories\InterviewIdempotencyRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class InterviewIdempotencyRecord extends Model
{
    /** @use HasFactory<InterviewIdempotencyRecordFactory> */
    use HasFactory;

    protected $primaryKey = 'scoped_key_hash';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'scoped_key_hash',
        'request_hash',
        'operation',
        'interview_session_id',
        'response_status',
        'response_body',
        'created_at',
        'completed_at',
    ];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return [
            'response_body' => 'array',
            'created_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
