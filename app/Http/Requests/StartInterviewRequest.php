<?php

namespace App\Http\Requests;

final class StartInterviewRequest extends ContractRequest
{
    /** @return array<string,array<mixed>|string> */
    public function rules(): array
    {
        return [
            'contract_version' => ['required', 'in:1.0'],
            'site_id' => ['required', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]{0,99}$/'],
            'locale' => ['sometimes', 'string', 'min:2', 'max:20'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['locale' => $this->input('locale', 'ja')]);
    }

    protected function requiresIdempotency(): bool
    {
        return true;
    }
}
