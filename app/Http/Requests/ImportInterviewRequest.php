<?php

namespace App\Http\Requests;

final class ImportInterviewRequest extends ContractRequest
{
    /** @return array<string,array<mixed>|string> */
    public function rules(): array
    {
        return [
            'contract_version' => ['required', 'in:1.0'],
            'site_id' => ['required', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]{0,99}$/'],
            'locale' => ['sometimes', 'string', 'min:2', 'max:20'],
            'interview' => ['required', 'array:organization,goals,audience,tone,requirements,materials'],
            'interview.organization' => ['required', 'string', 'min:1', 'max:1000'],
            'interview.goals' => ['required', 'string', 'min:1', 'max:4000'],
            'interview.audience' => ['required', 'string', 'min:1', 'max:4000'],
            'interview.tone' => ['required', 'string', 'min:1', 'max:2000'],
            'interview.requirements' => ['sometimes', 'string', 'max:8000'],
            'interview.materials' => ['sometimes', 'array', 'max:30'],
            'interview.materials.*' => ['string', 'max:8000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['locale' => $this->input('locale', 'ja')]);
    }
}
