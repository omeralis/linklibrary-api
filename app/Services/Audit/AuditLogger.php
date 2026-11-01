<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Throwable;

/**
 * Writes security-relevant audit events. Metadata must never contain secrets, tokens, raw credentials,
 * private notes or full URLs with query strings. Client IPs are stored as a keyed hash.
 */
class AuditLogger
{
    /** Keys that are always stripped from metadata as a defence in depth. */
    private const FORBIDDEN_KEYS = ['password', 'token', 'secret', 'notes', 'current_password', 'api_key', 'authorization'];

    public function __construct(private readonly ?Request $request = null) {}

    /** @param array<string, mixed> $metadata */
    public function log(string $action, ?User $actor = null, ?string $targetType = null, ?string $targetId = null, array $metadata = []): void
    {
        try {
            AuditLog::query()->create([
                'actor_user_id' => $actor?->id,
                'action' => $action,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'ip_hash' => $this->ipHash(),
                'metadata' => $this->sanitize($metadata) ?: null,
            ]);
        } catch (Throwable $e) {
            // Auditing must not break the user-facing operation; failures are reported to the log.
            report($e);
        }
    }

    private function ipHash(): ?string
    {
        $ip = $this->request?->ip();
        if ($ip === null) {
            return null;
        }

        return hash_hmac('sha256', $ip, (string) config('app.key'));
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function sanitize(array $metadata): array
    {
        $clean = [];
        foreach ($metadata as $key => $value) {
            if (in_array(strtolower((string) $key), self::FORBIDDEN_KEYS, true)) {
                continue;
            }
            $clean[$key] = is_array($value) ? $this->sanitize($value) : $value;
        }

        return $clean;
    }
}
