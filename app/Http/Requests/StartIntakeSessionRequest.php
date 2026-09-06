<?php

declare(strict_types=1);

namespace App\Http\Requests;

final class StartIntakeSessionRequest extends ContractRequest
{
    /** @return array<string,array<mixed>> */
    public function rules(): array
    {
        return [
            'contract_version' => ['required', 'string', 'in:1.0'],
            'mode' => ['sometimes', 'string', 'in:chat,form'],
        ];
    }

    protected function requiresIdempotency(): bool
    {
        return true;
    }
}
