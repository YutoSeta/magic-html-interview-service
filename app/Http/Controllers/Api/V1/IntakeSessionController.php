<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\StartIntakeSession;
use App\Http\Controllers\Controller;
use App\Http\Requests\StartIntakeSessionRequest;
use App\Support\Problem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Yutoseta\InterviewEngine\Models\Interview;
use Yutoseta\InterviewEngine\Models\InterviewTemplate;

final class IntakeSessionController extends Controller
{
    public function store(
        StartIntakeSessionRequest $request,
        string $template,
        StartIntakeSession $startIntake,
    ): JsonResponse {
        $definition = InterviewTemplate::query()
            ->where('slug', $template)
            ->where('is_active', true)
            ->first();
        if ($definition === null) {
            return Problem::response(
                $request,
                404,
                'intake_template_not_found',
                'The intake template was not found.',
            );
        }

        $configuredMode = (string) data_get($definition->persona, 'mode', 'chat');
        $mode = (string) ($request->validated('mode') ?? $configuredMode);
        if (! in_array($mode, ['chat', 'form'], true)) {
            $mode = 'chat';
        }
        $result = $startIntake->execute($definition, $mode, $request->idempotencyKey());

        return response()->json($result->body, $result->replayed ? 200 : $result->status, [
            'Idempotent-Replayed' => $result->replayed ? 'true' : 'false',
        ]);
    }

    public function show(Request $request, string $interview): JsonResponse
    {
        $record = Interview::query()->where('uuid', $interview)->first();
        if ($record === null) {
            return Problem::response(
                $request,
                404,
                'intake_session_not_found',
                'The intake session was not found.',
            );
        }

        $values = collect((array) data_get($record->live_state, 'values', []))
            ->mapWithKeys(static fn (mixed $entry, mixed $path): array => [
                (string) $path => is_array($entry) ? ($entry['value'] ?? null) : null,
            ])
            ->all();

        return response()->json([
            'contract_version' => '1.0',
            'uuid' => $record->uuid,
            'status' => $record->status->value,
            'values' => (object) $values,
            'confirmed_at' => $record->confirmed_at?->toIso8601String(),
        ]);
    }
}
