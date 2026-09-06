<?php

declare(strict_types=1);

namespace App\Actions;

use App\Services\IdempotencyService;
use App\Support\OperationResult;
use Illuminate\Support\Facades\URL;
use Yutoseta\InterviewEngine\Models\Interview;
use Yutoseta\InterviewEngine\Models\InterviewTemplate;

final class StartIntakeSession
{
    public function __construct(private readonly IdempotencyService $idempotency) {}

    public function execute(
        InterviewTemplate $template,
        string $mode,
        string $idempotencyKey,
    ): OperationResult {
        return $this->idempotency->execute(
            $idempotencyKey,
            'intake.session.create',
            ['template' => $template->slug],
            [
                'contract_version' => '1.0',
                'template' => $template->slug,
                'mode' => $mode,
            ],
            function () use ($template, $mode): OperationResult {
                $expiresAt = now()->addMinutes(
                    max(1, (int) config('interview.intake_share_ttl_minutes')),
                );
                $interview = Interview::query()->create([
                    'interview_template_id' => $template->getKey(),
                    'meta' => [
                        'mode' => $mode,
                        'share_expires_at' => $expiresAt->toIso8601String(),
                    ],
                ]);

                return new OperationResult(201, [
                    'contract_version' => '1.0',
                    'uuid' => $interview->uuid,
                    'template' => $template->slug,
                    'url' => URL::temporarySignedRoute(
                        'interviews.show',
                        $expiresAt,
                        ['interview' => $interview->uuid],
                    ),
                    'expires_at' => $expiresAt->toIso8601String(),
                ], $interview->uuid);
            },
        );
    }
}
