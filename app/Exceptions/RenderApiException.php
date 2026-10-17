<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Uniform error envelope for every route under `api-v1`.
 *
 * | Status | Body                                                            |
 * |--------|-----------------------------------------------------------------|
 * | 404    | `{"status":"error","code":"NOT_FOUND"}`                         |
 * | 409    | `{"status":"error","code":<kode khusus>}`                       |
 * | 422    | `{"message":"...","errors":{...}}` (format bawaan Laravel)     |
 * | 5xx    | `{"status":"error","code":"INTERNAL","error_id":"<uuid>"}`     |
 *
 * A 5xx body never carries the exception message: the operator gets the
 * `error_id`, the full detail (message, class, short stack, redacted
 * payload/headers) goes to the log under that same id.
 */
class RenderApiException
{
    public const PATH_PREFIX = 'api-v1';

    public const CODE_INTERNAL = 'INTERNAL';

    public const CODE_NOT_FOUND = 'NOT_FOUND';

    public const MAX_STACK_FRAMES = 15;

    /**
     * nginx/proxy header used to restore the original path (tests set
     * `X-Forwarded-Prefix` so `expectsJson()` sees a convincing URL).
     */
    private const FORWARDED_PREFIX_HEADER = 'X-Forwarded-Prefix';

    /**
     * @var list<string>
     */
    private const REDACTED_KEYS = ['api_key', 'apikey', 'authorization', 'token', 'password', 'secret'];

    /**
     * Stable `error_id` per exception instance, so the same throwable always
     * logs and renders with one id even if it is reported twice.
     *
     * @var array<int, string>
     */
    private array $errorIds = [];

    /**
     * Normalise the request so proxies, prefixes and non-JSON clients still
     * reach `api-v1` handlers as JSON requests.
     */
    public function prepareRequest(Request $request): void
    {
        if (! $this->isApiRequest($request)) {
            return;
        }

        $request->headers->set('Accept', 'application/json');
    }

    public function isApiRequest(Request $request): bool
    {
        $path = trim($request->path(), '/');

        if ($path === self::PATH_PREFIX || str_starts_with($path, self::PATH_PREFIX.'/')) {
            return true;
        }

        $prefix = trim((string) $request->headers->get(self::FORWARDED_PREFIX_HEADER, ''), '/');

        if ($prefix === '') {
            return false;
        }

        return $path === $prefix || str_starts_with($path, $prefix.'/');
    }

    /**
     * Log an `api-v1` exception with its redacted context.
     *
     * Called from the `report` pipeline so the detail is logged exactly once;
     * the render callback only reuses the generated `error_id`.
     */
    public function report(Throwable $e, Request $request): void
    {
        $context = [
            'error_id' => $this->errorIdFor($e),
            'exception' => $e::class,
            'message' => $e->getMessage(),
            'path' => $request->path(),
            'method' => $request->method(),
            'trace' => $this->shortTrace($e),
            'payload' => $this->redact($request->input()),
            'headers' => $this->redact($request->headers->all()),
        ];

        $level = $e instanceof ValidationException || $this->statusOf($e) < 500 ? 'warning' : 'error';

        Log::log($level, 'api-v1 exception', $context);
    }

    /**
     * @return Response|null `null` hands the exception back to Laravel
     */
    public function render(Throwable $e, Request $request): ?Response
    {
        if (! $this->isApiRequest($request)) {
            return null;
        }

        if ($e instanceof HttpResponseException) {
            return $e->getResponse();
        }

        $status = $this->statusOf($e);

        if ($e instanceof ValidationException) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], $e->status);
        }

        return match (true) {
            $e instanceof AuthenticationException => response()->json([
                'status' => 'error',
                'code' => 'UNAUTHENTICATED',
            ], 401),
            $e instanceof AuthorizationException,
            $e instanceof AccessDeniedHttpException => response()->json([
                'status' => 'error',
                'code' => 'FORBIDDEN',
            ], 403),
            $e instanceof NotFoundHttpException => response()->json([
                'status' => 'error',
                'code' => self::CODE_NOT_FOUND,
            ], 404),
            $status === 409 => response()->json([
                'status' => 'error',
                'code' => $e->getMessage() !== '' ? $e->getMessage() : 'CONFLICT',
                ...$this->errorContextOf($e),
            ], 409, $e instanceof HttpExceptionInterface ? $e->getHeaders() : []),
            $e instanceof ThrottleRequestsException => response()->json([
                'status' => 'error',
                'code' => 'RATE_LIMITED',
            ], 429, $e->getHeaders()),
            $status >= 500 || ! $e instanceof HttpExceptionInterface => response()->json([
                'status' => 'error',
                'code' => self::CODE_INTERNAL,
                'error_id' => $this->errorIdFor($e),
            ], 500),
            default => response()->json([
                'status' => 'error',
                'code' => $status === 404 ? self::CODE_NOT_FOUND : Str::upper(Str::snake(Response::$statusTexts[$status] ?? 'ERROR')),
            ], $status, $e->getHeaders()),
        };
    }

    /**
     * Additional fields a domain exception wants in its error body, exposed
     * through `errorContext()` so the handler does not need one branch per
     * exception class.
     *
     * @return array<string, mixed>
     */
    private function errorContextOf(Throwable $e): array
    {
        return method_exists($e, 'errorContext') ? $e->errorContext() : [];
    }

    public function errorIdFor(Throwable $e): string
    {
        return $this->errorIds[spl_object_id($e)] ??= (string) Str::uuid();
    }

    private function statusOf(Throwable $e): int
    {
        if ($e instanceof HttpResponseException) {
            return $e->getResponse()->getStatusCode();
        }

        if ($e instanceof HttpExceptionInterface) {
            return $e->getStatusCode();
        }

        return $e instanceof AuthorizationException ? 403 : 500;
    }

    /**
     * @return list<string>
     */
    private function shortTrace(Throwable $e): array
    {
        return array_slice(
            array_map(
                fn (string $frame): string => trim($frame),
                explode("\n", $e->getTraceAsString()),
            ),
            0,
            self::MAX_STACK_FRAMES,
        );
    }

    /**
     * Recursively blank the value of every sensitive key.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function redact(array $data): array
    {
        $redacted = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && in_array($key, self::REDACTED_KEYS, true)) {
                $redacted[$key] = '[REDACTED]';

                continue;
            }

            $redacted[$key] = match (true) {
                is_array($value) => $this->redact($value),
                is_string($value) => $this->redactUrl($value),
                default => $value,
            };
        }

        return $redacted;
    }

    /**
     * Redact credentials embedded in a URL (`?token=...`, `user:pass@host`).
     */
    private function redactUrl(string $value): string
    {
        if (! preg_match('~^[a-z][a-z0-9+.-]*://~i', $value)) {
            return $value;
        }

        return preg_replace([
            '~([?&](?:'.implode('|', self::REDACTED_KEYS).')=)[^&#]*~i',
            '~//[^/@\s:]+:[^/@\s]*@~',
        ], ['$1[REDACTED]', '//[REDACTED]@'], $value) ?? $value;
    }
}
