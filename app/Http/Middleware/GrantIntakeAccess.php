<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Yutoseta\InterviewEngine\Models\Interview;

final class GrantIntakeAccess
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->hasValidSignature(), 403);

        $uuid = $this->interviewUuid($request);
        $expiresAt = (int) $request->query('expires', 0);
        abort_if($uuid === '' || $expiresAt <= now()->timestamp, 403);

        $request->session()->regenerate();
        $request->session()->put("intake_access.{$uuid}", $expiresAt);

        return $next($request);
    }

    private function interviewUuid(Request $request): string
    {
        $interview = $request->route('interview');

        return $interview instanceof Interview ? $interview->uuid : (string) $interview;
    }
}
