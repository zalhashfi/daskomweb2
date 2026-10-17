<?php

namespace Tests\Support;

use App\Services\Ai\Contracts\AiClient;
use RuntimeException;
use Throwable;

/**
 * Test double for the AI transport: chat() always throws the injected
 * failure. No HTTP request is ever made through it.
 */
final class FakeChatClient implements AiClient
{
    public function __construct(private readonly Throwable $failure) {}

    public function chat(array $messages): array
    {
        throw $this->failure;
    }

    public function embed(array $texts): array
    {
        throw new RuntimeException('embed() tidak dipakai EvaluateAnswer.');
    }
}
