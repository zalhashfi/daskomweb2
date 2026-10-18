<?php

namespace Tests\Feature;

use App\Models\AiEvaluation;
use App\Models\AiProviderStatus;
use App\Services\Ai\ProviderHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `php artisan ai:health-check` — provider health with a 60s TTL.
 *
 * Idle means zero provider requests; queued work costs one request per TTL
 * window; 200 → `up`; three consecutive failures → `down`; a 404 health
 * endpoint falls back to the last provider failure timestamp without
 * crashing. Results are cached and stored in `ai_provider_status`.
 */
class AiHealthCheckTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_URL = 'https://gateway.test';

    private int $queuedEvaluations = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::preventStrayRequests();

        config([
            'ai.openai_compatible.base_url' => self::BASE_URL,
            'ai.openai_compatible.api_key' => 'sk-test-secret-health',
        ]);
    }

    private function healthUrl(): string
    {
        return self::BASE_URL.'/api/health';
    }

    // ---------------------------------------------------------------------
    // Acceptance
    // ---------------------------------------------------------------------

    public function test_idle_provider_makes_zero_health_requests_and_exits_zero(): void
    {
        Http::fake();

        $this->artisan('ai:health-check')->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_provider_status', 0);
        $this->assertSame('idle', Cache::get(ProviderHealth::CACHE_KEY)['state']);
    }

    public function test_queued_evaluation_triggers_exactly_one_health_request(): void
    {
        Http::fake([$this->healthUrl() => Http::response(['status' => 'ok'], 200)]);
        $this->queueEvaluation();

        $this->artisan('ai:health-check')->assertExitCode(0);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === $this->healthUrl());
    }

    public function test_second_run_inside_the_ttl_makes_no_additional_request(): void
    {
        Http::fake([$this->healthUrl() => Http::response(['status' => 'ok'], 200)]);
        $this->queueEvaluation();

        $this->artisan('ai:health-check')->assertExitCode(0);
        $this->travel(59)->seconds();
        $this->artisan('ai:health-check')->assertExitCode(0);

        Http::assertSentCount(1);
    }

    public function test_ttl_expiry_triggers_a_new_request(): void
    {
        Http::fake([$this->healthUrl() => Http::response(['status' => 'ok'], 200)]);
        $this->queueEvaluation();

        $this->artisan('ai:health-check')->assertExitCode(0);
        $this->travel(61)->seconds();
        $this->artisan('ai:health-check')->assertExitCode(0);

        Http::assertSentCount(2);
    }

    public function test_successful_probe_marks_provider_up(): void
    {
        Http::fake([$this->healthUrl() => Http::response(['status' => 'ok'], 200)]);
        $this->queueEvaluation();

        $this->artisan('ai:health-check')->assertExitCode(0);

        $status = AiProviderStatus::query()->latest('id')->firstOrFail();

        $this->assertSame('up', $status->status);
        $this->assertSame(0, $status->consecutive_failures);
        $this->assertNull($status->last_error);
        $this->assertNotNull($status->checked_at);
    }

    public function test_three_consecutive_failures_mark_provider_down(): void
    {
        Http::fake([$this->healthUrl() => Http::response('boom', 503)]);
        $this->queueEvaluation();

        $this->artisan('ai:health-check')->assertExitCode(0);
        $status = AiProviderStatus::query()->latest('id')->firstOrFail();
        $this->assertSame('up', $status->status);
        $this->assertSame(1, $status->consecutive_failures);

        $this->travel(61)->seconds();
        $this->artisan('ai:health-check')->assertExitCode(0);
        $status = AiProviderStatus::query()->latest('id')->firstOrFail();
        $this->assertSame('up', $status->status);
        $this->assertSame(2, $status->consecutive_failures);

        $this->travel(61)->seconds();
        $this->artisan('ai:health-check')->assertExitCode(0);
        $status = AiProviderStatus::query()->latest('id')->firstOrFail();
        $this->assertSame('down', $status->status);
        $this->assertSame(3, $status->consecutive_failures);
        $this->assertNotNull($status->last_error);

        Http::assertSentCount(3);
    }

    public function test_success_resets_the_failure_streak(): void
    {
        Http::fakeSequence($this->healthUrl())
            ->push('boom', 503)
            ->push('boom', 503)
            ->push(['status' => 'ok'], 200);
        $this->queueEvaluation();

        $this->artisan('ai:health-check')->assertExitCode(0);
        $this->travel(61)->seconds();
        $this->artisan('ai:health-check')->assertExitCode(0);
        $this->travel(61)->seconds();
        $this->artisan('ai:health-check')->assertExitCode(0);

        Http::assertSentCount(3);

        $status = AiProviderStatus::query()->latest('id')->firstOrFail();

        $this->assertSame('up', $status->status);
        $this->assertSame(0, $status->consecutive_failures);
        $this->assertNull($status->last_error);
    }

    public function test_connection_error_counts_as_a_failure(): void
    {
        Http::fake(fn (): never => throw new ConnectionException('connection refused'));
        $this->queueEvaluation();

        $this->artisan('ai:health-check')->assertExitCode(0);

        $status = AiProviderStatus::query()->latest('id')->firstOrFail();

        $this->assertSame(1, $status->consecutive_failures);
        $this->assertSame('up', $status->status);
    }

    public function test_404_health_endpoint_activates_the_fallback_without_crashing(): void
    {
        Http::fake([$this->healthUrl() => Http::response('not found', 404)]);
        $evaluation = $this->queueEvaluation();
        $evaluation->attempts()->create([
            'attempt_no' => 1,
            'outcome' => 'failed',
            'error_code' => 'EXTERNAL_UNAVAILABLE',
        ]);

        $this->artisan('ai:health-check')->assertExitCode(0);

        Http::assertSentCount(1);

        $status = AiProviderStatus::query()->latest('id')->firstOrFail();

        $this->assertSame('down', $status->status);
        $this->assertNotNull($status->checked_at);
        $this->assertStringContainsString('404', (string) $status->last_error);

        $this->assertSame('down', Cache::get(ProviderHealth::CACHE_KEY)['status']);
    }

    public function test_404_fallback_without_recent_failure_reports_up(): void
    {
        Http::fake([$this->healthUrl() => Http::response('not found', 404)]);
        $this->queueEvaluation();

        $this->artisan('ai:health-check')->assertExitCode(0);

        $status = AiProviderStatus::query()->latest('id')->firstOrFail();

        $this->assertSame('up', $status->status);
        $this->assertSame(0, $status->consecutive_failures);
        $this->assertNull($status->last_error);
    }

    public function test_status_table_has_the_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('ai_provider_status', [
            'id',
            'status',
            'checked_at',
            'consecutive_failures',
            'last_error',
        ]));
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * A non-terminal evaluation (only its status matters to the health
     * check).
     */
    private function queueEvaluation(): AiEvaluation
    {
        $this->queuedEvaluations++;

        return AiEvaluation::query()->create([
            'jawaban_id' => $this->queuedEvaluations,
            'jawaban_type' => 'tp',
            'answer_hash' => hash('sha256', 'health-'.$this->queuedEvaluations),
            'status' => 'queued',
        ]);
    }
}
