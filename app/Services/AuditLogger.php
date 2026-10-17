<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class AuditLogger
{
    /**
     * Log an action performed through an HTTP request, attributing it to the
     * currently authenticated assistant and capturing request details.
     *
     * @param  array<string, mixed>  $context
     */
    public function logFromRequest(Request $request, string $action, ?string $description = null, array $context = []): void
    {
        $route = $request->route();
        $actorId = auth('asisten')->id();

        AuditLog::create([
            'asisten_id' => $actorId,
            'action' => $action,
            'route' => $route?->getName() ?? $route?->uri(),
            'method' => strtoupper($request->getMethod()),
            'description' => $description,
            'subject_type' => null,
            'subject_id' => null,
            'actor_type' => 'asisten',
            'actor_id' => $actorId,
            'metadata' => $this->filterMetadata($this->buildMetadata($request, $context)),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }

    /**
     * Log an action independently of an HTTP request.
     *
     * A null $actorId means "unknown". For actorType 'system' the actor is
     * always stored as null.
     *
     * @param  array<string, mixed>  $context
     */
    public function record(
        string $action,
        ?string $description = null,
        array $context = [],
        ?string $subjectType = null,
        ?int $subjectId = null,
        string $actorType = 'asisten',
        ?int $actorId = null,
    ): void {
        $isAsisten = $actorType === 'asisten';

        AuditLog::create([
            // Keep asisten_id consistent with a request-originated log for assistants.
            'asisten_id' => $isAsisten ? $actorId : null,
            'action' => $action,
            'route' => null,
            'method' => 'SYSTEM',
            'description' => $description,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'actor_type' => $actorType,
            'actor_id' => $actorType === 'system' ? null : $actorId,
            'metadata' => $this->filterMetadata($this->sanitizeArray($context)),
            'ip_address' => null,
            'user_agent' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>|null
     */
    private function filterMetadata(array $metadata): ?array
    {
        return empty($metadata) ? null : $metadata;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function buildMetadata(Request $request, array $context = []): array
    {
        $payload = $this->sanitizeArray($request->all());
        $query = $this->sanitizeArray($request->query());

        if (! empty($payload)) {
            $context['payload'] = $payload;
        }

        if (! empty($query)) {
            $context['query'] = $query;
        }

        return Arr::where($context, static fn ($value) => $value !== [] && $value !== null);
    }

    /**
     * @param  array<mixed>  $values
     * @return array<mixed>
     */
    private function sanitizeArray(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = $this->sanitizeArray($value);

                continue;
            }

            if (is_string($key) && $this->isSensitiveKey($key)) {
                $values[$key] = '[REDACTED]';
            }
        }

        return $values;
    }

    private function isSensitiveKey(string $key): bool
    {
        return Str::contains($key, ['password', 'token', 'secret']);
    }
}
