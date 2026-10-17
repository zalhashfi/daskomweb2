<?php

namespace Tests\Support;

use App\Services\Ai\Contracts\AiClient;
use RuntimeException;
use Throwable;

/**
 * Test double for the AI transport: every chat() call consumes the next
 * scripted step — either a throwable to throw or a response array to return —
 * and the last step is repeated once the script runs out. No HTTP request is
 * ever made through it.
 */
final class FakeChatClient implements AiClient
{
    /** @var list<Throwable|array<string, mixed>> */
    private array $steps = [];

    /**
     * Number of chat() calls received so far.
     */
    public int $calls = 0;

    /**
     * @param  Throwable|array<string, mixed>|list<Throwable|array<string, mixed>>  $script
     */
    public function __construct(Throwable|array $script)
    {
        if ($script instanceof Throwable) {
            $this->steps = [$script];

            return;
        }

        // A single response shape ({content: ...}) is one step; a list is a script.
        $this->steps = array_is_list($script) && ! array_key_exists('content', $script)
            ? array_values($script)
            : [$script];
    }

    /**
     * Script: throw, throw, ... then answer successfully.
     *
     * @param  list<Throwable>  $failures
     * @param  array<string, mixed>  $response
     */
    public static function thenSucceed(array $failures, array $response): self
    {
        return new self([...array_values($failures), $response]);
    }

    public function chat(array $messages): array
    {
        $step = $this->steps[min($this->calls, count($this->steps) - 1)] ?? null;

        $this->calls++;

        if ($step instanceof Throwable) {
            throw $step;
        }

        if (! is_array($step)) {
            throw new RuntimeException('FakeChatClient tidak punya langkah untuk chat().');
        }

        return $step;
    }

    public function embed(array $texts): array
    {
        throw new RuntimeException('embed() tidak dipakai EvaluateAnswer.');
    }
}
