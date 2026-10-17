<?php

namespace Tests\Feature;

use App\Exceptions\DuplicateSoalException;
use App\Exceptions\JawabanSubmissionLockedException;
use App\Models\JawabanSubmission;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

/**
 * Covers T11: every `api-v1` route shares one error envelope.
 *
 * - unhandled exception      => 500 {"status":"error","code":"INTERNAL","error_id":<uuid>}
 *                               without the exception message in the body
 * - the same error_id is logged together with class/message/short trace/path
 * - payload & headers logged with api_key/apikey/authorization/token/password/secret redacted
 * - 404 => NOT_FOUND, 409 => the exception's own code, 422 => Laravel's {message, errors}
 */
class ApiErrorEnvelopeTest extends TestCase
{
    private const SECRET = 'RAHASIA_KUNCI';

    /**
     * @var list<array<string, mixed>>
     */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();

        config(['app.url' => 'http://localhost', 'app.debug' => false]);

        $this->registerProbeRoutes();
    }

    public function test_unhandled_exception_renders_500_envelope_without_leaking_message(): void
    {
        $this->spyLog();

        $response = $this->getJson($this->apiUrl('/__probe/boom'));

        $response->assertStatus(500)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('code', 'INTERNAL')
            ->assertJsonMissingPath('message');

        $this->assertStringNotContainsString(self::SECRET, $response->getContent());

        $errorId = $response->json('error_id');
        $this->assertIsString($errorId);
        $this->assertTrue(
            preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $errorId) === 1,
            "error_id bukan UUID: {$errorId}"
        );

        $logged = $this->loggedErrors();
        $this->assertGreaterThanOrEqual(1, $logged->count(), 'Exception api-v1 harus tercatat di log.');
        $this->assertStringContainsString(self::SECRET, $logged->toJson());
        $this->assertStringContainsString($errorId, $logged->toJson());
    }

    public function test_log_keeps_detail_and_redacts_sensitive_values(): void
    {
        $this->spyLog();

        $response = $this->withHeaders([
            'Accept' => 'application/json',
            'X-Forwarded-Prefix' => 'api-v1',
            'Authorization' => 'Bearer header-token-rahasia',
        ])->postJson('http://localhost/api-v1/__probe/boom', [
            'api_key' => 'payload-api-key-rahasia',
            'apikey' => 'payload-apikey-rahasia',
            'token' => 'payload-token-rahasia',
            'password' => 'payload-password-rahasia',
            'secret' => 'payload-secret-rahasia',
            'nested' => ['token' => 'nested-token-rahasia', 'jawaban' => 'jawaban-biasa'],
        ]);

        $response->assertStatus(500)->assertJsonPath('code', 'INTERNAL');
        $errorId = $response->json('error_id');

        $entry = $this->loggedErrors()
            ->first(fn (array $context): bool => str_contains((string) json_encode($context), self::SECRET));

        $this->assertNotNull($entry, 'Log harus memuat pesan exception.');

        $this->assertSame($errorId, $entry['error_id'] ?? null);
        $this->assertSame(RuntimeException::class, $entry['exception'] ?? null);
        $this->assertSame(self::SECRET, $entry['message'] ?? null);
        $this->assertSame('api-v1/__probe/boom', $entry['path'] ?? null);
        $this->assertNotEmpty($entry['trace'] ?? null);
        $this->assertSame('jawaban-biasa', $entry['payload']['nested']['jawaban'] ?? null);

        $serialized = json_encode($entry);

        foreach ([
            'payload-api-key-rahasia',
            'payload-apikey-rahasia',
            'payload-token-rahasia',
            'payload-password-rahasia',
            'payload-secret-rahasia',
            'nested-token-rahasia',
            'header-token-rahasia',
        ] as $leakedValue) {
            $this->assertStringNotContainsString($leakedValue, $serialized, "Nilai sensitif tidak di-redaksi: {$leakedValue}");
        }

        $this->assertSame('[REDACTED]', $entry['payload']['api_key'] ?? null);
        $this->assertSame('[REDACTED]', $entry['headers']['authorization'] ?? null);
    }

    public function test_response_does_not_leak_message_and_uuid_is_valid_when_json_not_requested(): void
    {
        $this->spyLog();

        $response = $this->get('http://localhost/api-v1/__probe/boom');

        $response->assertStatus(500)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('code', 'INTERNAL');

        $this->assertStringNotContainsString(self::SECRET, $response->getContent());
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            (string) $response->json('error_id')
        );
    }

    public function test_not_found_renders_not_found_code(): void
    {
        $response = $this->getJson($this->apiUrl('/__probe/tidak-ada'));

        $response->assertNotFound()
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('code', 'NOT_FOUND')
            ->assertJsonMissingPath('error_id');
    }

    public function test_conflict_keeps_its_specific_code(): void
    {
        $response = $this->getJson($this->apiUrl('/__probe/conflict'));

        $response->assertStatus(409)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('code', 'SUBMISSION_LOCKED');
    }

    public function test_validation_exception_keeps_laravel_shape(): void
    {
        $response = $this->getJson($this->apiUrl('/__probe/invalid'));

        $response->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['jawaban.0']])
            ->assertJsonMissingPath('status')
            ->assertJsonMissingPath('code')
            ->assertJsonPath('message', 'Wajib diisi.');
    }

    public function test_duplicate_soal_exception_is_a_standard_422(): void
    {
        $response = $this->getJson($this->apiUrl('/__probe/duplicate'));

        $response->assertStatus(422)->assertJsonStructure(['message', 'errors' => ['soal_id']]);
    }

    public function test_500_from_another_api_endpoint_uses_the_same_envelope(): void
    {
        $this->spyLog();

        $response = $this->getJson($this->apiUrl('/__probe/jawaban-tp-store-boom'));

        $response->assertStatus(500)->assertJsonPath('code', 'INTERNAL');
        $this->assertStringNotContainsString(self::SECRET, $response->getContent());
    }

    public function test_web_routes_outside_api_prefix_are_not_json_rewritten(): void
    {
        $this->get('/__probe/web-boom', ['Accept' => 'text/html'])->assertStatus(500);
    }

    public function test_web_routes_still_receive_html_error_pages(): void
    {
        config(['app.debug' => false]);

        $this->withoutVite();

        $this->get('/__probe/web-boom')->assertStatus(500);
    }

    /**
     * Capture every Log call (before the request runs) so assertions do not
     * depend on the log driver.
     */
    private function spyLog(): void
    {
        $this->logged = [];

        Log::shouldReceive('log')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function (string $level, string $message, array $context = []): void {
                $this->logged[] = $context;
            });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function loggedErrors(): Collection
    {
        return collect($this->logged);
    }

    private function apiUrl(string $path): string
    {
        return 'http://localhost/api-v1'.$path;
    }

    /**
     * Throw-away routes that reproduce deterministic failures inside the
     * `api-v1` prefix (plus one outside it) for the assertions above.
     */
    private function registerProbeRoutes(): void
    {
        $router = $this->app->make('router');

        $boom = fn (): never => throw new RuntimeException(self::SECRET);

        $router->get('/api-v1/__probe/boom', $boom);
        $router->get('/api-v1/__probe/jawaban-tp-store-boom', $boom);
        $router->post('/api-v1/__probe/boom', fn (): never => throw new RuntimeException(self::SECRET));
        $router->get('/__probe/web-boom', $boom);
        $router->get('/api-v1/__probe/conflict', function (): never {
            throw JawabanSubmissionLockedException::fromSubmission(
                new JawabanSubmission(['id' => 41, 'locked_at' => now()])
            );
        });
        $router->get('/api-v1/__probe/invalid', function (): never {
            throw ValidationException::withMessages(['jawaban.0' => ['Wajib diisi.']]);
        });
        $router->get('/api-v1/__probe/duplicate', function (): never {
            throw DuplicateSoalException::make([1, 1]);
        });
    }
}
