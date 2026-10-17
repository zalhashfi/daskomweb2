<?php

namespace Tests\Feature;

use App\Exceptions\AiDisabledException;
use App\Exceptions\ExternalPayloadDisabledException;
use App\Exceptions\ProviderPolicyUnverifiedException;
use App\Models\AiConfig;
use App\Services\Ai\AiGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * T20f — AiGate is the single gate for every cost-incurring AI path: it runs
 * before any provider call, so each failure must leave Http with zero
 * requests sent. Checks are ordered (ai_enabled → allow_external_payload →
 * provider_policy_verified_at) and strict (`!== true`), so null/0/'false' are
 * all rejected.
 */
class AiGateTest extends TestCase
{
    use RefreshDatabase;

    private AiGate $gate;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->gate = new AiGate;
    }

    public function test_all_three_conditions_true_does_not_throw(): void
    {
        $config = $this->enabledConfig();

        $this->gate->assertEnabled($config);

        $this->assertTrue($config->ai_enabled);
        $this->assertTrue($config->allow_external_payload);
        $this->assertNotNull($config->provider_policy_verified_at);
        Http::assertNothingSent();
    }

    public function test_ai_enabled_false_throws_ai_disabled(): void
    {
        $config = $this->enabledConfig(['ai_enabled' => false]);

        $exception = $this->assertGateFailure($config, AiDisabledException::class);

        $this->assertSame('AI_DISABLED', $exception->getErrorCode());
    }

    public function test_external_payload_false_throws_external_payload_disabled(): void
    {
        $config = $this->enabledConfig(['allow_external_payload' => false]);

        $exception = $this->assertGateFailure($config, ExternalPayloadDisabledException::class);

        $this->assertSame('EXTERNAL_PAYLOAD_DISABLED', $exception->getErrorCode());
    }

    public function test_unverified_provider_policy_throws_provider_policy_unverified(): void
    {
        $config = $this->enabledConfig(['provider_policy_verified_at' => null]);

        $exception = $this->assertGateFailure($config, ProviderPolicyUnverifiedException::class);

        $this->assertSame('PROVIDER_POLICY_UNVERIFIED', $exception->getErrorCode());
    }

    /**
     * A missing (NULL) column on a raw row means "not yet allowed" for every
     * of the three columns, including the two NOT NULL booleans which are
     * written through a hand-built model instead of an INSERT.
     */
    public static function nullColumnProvider(): array
    {
        return [
            'ai_enabled' => ['ai_enabled', AiDisabledException::class, 'AI_DISABLED'],
            'allow_external_payload' => ['allow_external_payload', ExternalPayloadDisabledException::class, 'EXTERNAL_PAYLOAD_DISABLED'],
            'provider_policy_verified_at' => ['provider_policy_verified_at', ProviderPolicyUnverifiedException::class, 'PROVIDER_POLICY_UNVERIFIED'],
        ];
    }

    #[DataProvider('nullColumnProvider')]
    public function test_null_column_is_treated_as_not_allowed(string $column, string $exceptionClass, string $errorCode): void
    {
        $config = $this->enabledConfig();
        $config->setAttribute($column, null);

        $exception = $this->assertGateFailure($config, $exceptionClass);

        $this->assertSame($errorCode, $exception->getErrorCode());
    }

    /**
     * Boolean-column values that do not mean "enabled". A raw `0` / `false`
     * reaches the gate as PHP `false` (the model cast), and `'false'` is
     * rejected by MySQL itself for a TINYINT column, so the representative
     * value is `0`; the model-level cast is asserted alongside.
     */
    public static function forbiddenBooleanProvider(): array
    {
        return [
            'ai_enabled=0' => ['ai_enabled', 0, AiDisabledException::class, 'AI_DISABLED'],
            'allow_external_payload=0' => ['allow_external_payload', 0, ExternalPayloadDisabledException::class, 'EXTERNAL_PAYLOAD_DISABLED'],
        ];
    }

    #[DataProvider('forbiddenBooleanProvider')]
    public function test_falsy_stored_values_are_rejected(string $column, mixed $raw, string $exceptionClass, string $errorCode): void
    {
        $config = AiConfig::query()->findOrFail($this->insertRawConfig([$column => $raw])->getKey());

        $this->assertFalse($config->{$column});

        $exception = $this->assertGateFailure($config, $exceptionClass);

        $this->assertSame($errorCode, $exception->getErrorCode());
    }

    public function test_uncast_default_config_fails_closed(): void
    {
        // AiConfig::active() provisions an inactive default row with AI off.
        $config = AiConfig::active();

        $this->assertFalse($config->ai_enabled);
        $this->assertFalse($config->allow_external_payload);
        $this->assertNull($config->provider_policy_verified_at);

        $this->assertSame(
            'AI_DISABLED',
            $this->assertGateFailure($config, AiDisabledException::class)->getErrorCode(),
        );
    }

    public function test_checks_run_in_order_and_report_only_the_first_failure(): void
    {
        $allOff = $this->insertRawConfig([
            'ai_enabled' => 0,
            'allow_external_payload' => 0,
            'provider_policy_verified_at' => null,
        ]);
        $this->assertGateFailure(
            AiConfig::query()->findOrFail($allOff->getKey()),
            AiDisabledException::class,
        );

        // AI on, payload off, policy unverified → payload check wins.
        $payloadOff = $this->insertRawConfig([
            'ai_enabled' => 1,
            'allow_external_payload' => 0,
            'provider_policy_verified_at' => null,
        ]);
        $this->assertGateFailure(
            AiConfig::query()->findOrFail($payloadOff->getKey()),
            ExternalPayloadDisabledException::class,
        );
    }

    public function test_error_codes_are_distinct(): void
    {
        $codes = [
            (new AiDisabledException)->getErrorCode(),
            (new ExternalPayloadDisabledException)->getErrorCode(),
            (new ProviderPolicyUnverifiedException)->getErrorCode(),
        ];

        $this->assertSame([
            'AI_DISABLED',
            'EXTERNAL_PAYLOAD_DISABLED',
            'PROVIDER_POLICY_UNVERIFIED',
        ], $codes);
        $this->assertCount(3, array_unique($codes));
        $this->assertSame($codes, [
            AiDisabledException::ERROR_CODE,
            ExternalPayloadDisabledException::ERROR_CODE,
            ProviderPolicyUnverifiedException::ERROR_CODE,
        ]);
    }

    public function test_every_gate_exception_exposes_error_code_and_message(): void
    {
        foreach ([
            new AiDisabledException,
            new ExternalPayloadDisabledException,
            new ProviderPolicyUnverifiedException,
        ] as $exception) {
            $this->assertInstanceOf(RuntimeException::class, $exception);
            $this->assertNotSame('', $exception->getErrorCode());
            $this->assertNotSame('', $exception->getMessage());
        }
    }

    /**
     * Assert the gate throws exactly the expected exception for $config and
     * that no HTTP request was attempted.
     *
     * @param  class-string<RuntimeException>  $expected
     */
    private function assertGateFailure(AiConfig $config, string $expected): RuntimeException
    {
        try {
            $this->gate->assertEnabled($config);
        } catch (RuntimeException $exception) {
            $this->assertInstanceOf($expected, $exception);
            Http::assertNothingSent();

            return $exception;
        }

        $this->fail("AiGate harus melempar {$expected}.");
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function enabledConfig(array $overrides = []): AiConfig
    {
        // A brand-new (inactive) row is written as-is, so overrides stick.
        return AiConfig::query()->create(array_merge([
            'driver' => 'openai_compatible',
            'retrieval_top_k' => 4,
            'max_context_tokens' => 4000,
            'max_answer_chars' => 10000,
            'ai_enabled' => true,
            'allow_external_payload' => true,
            'provider_policy_verified_at' => now(),
            'budget_tokens_per_modul' => 0,
            'is_active' => false,
        ], $overrides));
    }

    /**
     * Insert raw column values (no model casts) to prove the gate inspects
     * the stored representation, not a cast truthy value.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function insertRawConfig(array $overrides): AiConfig
    {
        $columns = array_merge([
            'driver' => 'openai_compatible',
            'retrieval_top_k' => 4,
            'max_context_tokens' => 4000,
            'max_answer_chars' => 10000,
            'ai_enabled' => 1,
            'allow_external_payload' => 1,
            'provider_policy_verified_at' => now(),
            'budget_tokens_per_modul' => 0,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);

        $id = AiConfig::query()->getConnection()->table('ai_configs')->insertGetId($columns);

        return AiConfig::query()->findOrFail($id);
    }
}
