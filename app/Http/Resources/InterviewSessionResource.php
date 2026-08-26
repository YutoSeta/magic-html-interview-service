<?php

namespace App\Http\Resources;

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
        $messages = is_array($this->messages) ? $this->messages : [];
        $last = $messages === [] ? null : $messages[array_key_last($messages)];

        return [
            'contract_version' => '1.0',
            'id' => $this->id,
            'site_id' => $this->site_id,
            'locale' => $this->locale,
            'status' => $this->status,
            'current_step' => $this->current_step,
            'messages' => $messages,
            'next_question' => $this->status === 'active' && ($last['role'] ?? null) === 'assistant' ? $last : null,
            'structured_data' => $this->structured_data,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
