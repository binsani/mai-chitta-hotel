<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class LogSlowRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        $start = defined('LARAVEL_START') ? LARAVEL_START : microtime(true);

        $response = $next($request);

        if (! config('observability.slow_requests.enabled', false)) {
            return $response;
        }

        $durationMs = (microtime(true) - $start) * 1000;
        $thresholdMs = (int) config('observability.slow_requests.threshold_ms', 2000);

        if ($durationMs >= $thresholdMs) {
            Log::channel(config('observability.slow_requests.channel', 'stack'))->warning('Slow request detected', [
                'request_id'   => $request->attributes->get('request_id'),
                'method'       => $request->method(),
                'url'          => $request->url(), // Strips query parameters
                'path'         => $request->path(),
                'status'       => $response->getStatusCode(),
                'duration_ms'  => round($durationMs, 2),
                'threshold_ms' => $thresholdMs,
                'ip'           => $request->ip(),
                'user_agent'   => $request->userAgent(),
            ]);
        }

        return $response;
    }
}
