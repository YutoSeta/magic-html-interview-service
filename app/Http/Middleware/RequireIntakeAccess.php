<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Yutoseta\InterviewEngine\Models\Interview;

final class RequireIntakeAccess
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $uuid = $this->interviewUuid($request);
        $sessionKey = "intake_access.{$uuid}";
        $expiresAt = (int) $request->session()->get($sessionKey, 0);

        if ($uuid === '' || $expiresAt <= now()->timestamp) {
            $request->session()->forget($sessionKey);
            abort(403);
        }

        return $next($request);
    }

    private function interviewUuid(Request $request): string
    {
        $interview = $request->route('interview');

        return $interview instanceof Interview ? $interview->uuid : (string) $interview;
    }
}
