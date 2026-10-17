<?php

namespace Tests\Support;

use App\Models\AiConfig;
use App\Services\Ai\AiClientManager;
use App\Services\Ai\Contracts\AiClient;

/**
 * Manager that resolves every chat client to the injected fake.
 */
final class FakeChatClientManager extends AiClientManager
{
    public function __construct(private readonly AiClient $client) {}

    public function forChat(AiConfig $config): AiClient
    {
        return $this->client;
    }
}
