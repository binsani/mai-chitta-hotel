<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes append-only audit records. Sensitive keys are stripped from context.
 * Audit failures are reported but never break the user's request.
 */
class AuditLogger
{
    private const REDACT = [
        'password', 'password_confirmation', 'current_password',
        'token', 'public_access_token', 'access_token', 'hold_token',
        'secret', 'webhook_secret', 'verif-hash', 'verif_hash',
        'api_key', 'remember_token', 'card', 'cvv', 'id_document_number',
    ];

    public function log(string $action, ?Model $subject = null, array $context = [], string $outcome = 'success', ?int $userId = null): void
    {
        try {
            $request = request();
            AuditLog::create([
                'user_id' => $userId ?? $request?->user()?->getKey(),
                'action' => $action,
                'outcome' => $outcome,
                'subject_type' => $subject ? class_basename($subject) : null,
                'subject_id' => $subject?->getKey() !== null ? (string) $subject->getKey() : null,
                'request_id' => $request?->attributes->get('request_id'),
                'ip_address' => $request?->ip(),
                'user_agent' => $request ? substr((string) $request->userAgent(), 0, 255) : null,
                'context' => $this->scrub($context) ?: null,
            ]);
        } catch (Throwable $e) {
            Log::error('Audit log write failed', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }

    private function scrub(array $context): array
    {
        foreach ($context as $key => $value) {
            if (is_string($key) && Arr::first(self::REDACT, fn ($r) => str_contains(strtolower($key), $r))) {
                unset($context[$key]);
            } elseif (is_array($value)) {
                $context[$key] = $this->scrub($value);
            }
        }

        return $context;
    }
}
