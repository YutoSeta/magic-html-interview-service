<?php

namespace App\Http\Resources;

use App\Models\InterviewSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class InterviewSessionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return self::present($this->resource);
    }

    /** @return array<string,mixed> */
    public static function present(InterviewSession $session): array
    {
        $messages = is_array($session->messages) ? $session->messages : [];
        $last = $messages === [] ? null : $messages[array_key_last($messages)];

        return [
            'contract_version' => '1.0',
            'id' => $session->id,
            'site_id' => $session->site_id,
            'locale' => $session->locale,
            'status' => $session->status,
            'current_step' => $session->current_step,
            'messages' => $messages,
            'next_question' => $session->status === InterviewSession::STATUS_ACTIVE && ($last['role'] ?? null) === 'assistant' ? $last : null,
            'structured_data' => $session->structured_data,
            'created_at' => $session->created_at?->toIso8601String(),
            'updated_at' => $session->updated_at?->toIso8601String(),
        ];
    }
}
