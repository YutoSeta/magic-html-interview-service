<?php

namespace App\Http\Requests;

final class AnswerInterviewRequest extends ContractRequest
{
    /** @return array<string,array<mixed>|string> */
    public function rules(): array
    {
        return [
            'contract_version' => ['required', 'in:1.0'],
            'expected_step' => ['required', 'integer', 'between:0,6'],
            'answer' => ['required', 'string', 'min:1', 'max:8000'],
        ];
    }

    protected function requiresIdempotency(): bool
    {
        return true;
    }
}
