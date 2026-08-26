<?php

namespace App\Http\Requests;

final class AnswerInterviewRequest extends ContractRequest
{
    /** @return array<string,array<mixed>|string> */
    public function rules(): array
    {
        return [
            'contract_version' => ['required', 'in:1.0'],
            'answer' => ['required', 'string', 'min:1', 'max:8000'],
        ];
    }
}
