<?php

namespace App\Console\Commands;

use App\Exceptions\AiUnavailableException;
use App\Exceptions\ModelCatalogException;
use App\Services\Ai\ModelCatalog;
use Illuminate\Console\Command;

/**
 * Validates a chat model id against `GET /v1/models` (spec §5.4.0a, E29).
 *
 * Exit 0 when the model exists and is not a Combo; exit 1 otherwise. With no
 * argument the configured `ai.openai_compatible.chat_model` is validated.
 */
class AiValidateModel extends Command
{
    protected $signature = 'ai:validate-model {model? : Model id in `provider/model` form; defaults to ai.openai_compatible.chat_model}';

    protected $description = 'Validate that a chat model exists in the provider catalogue (GET /v1/models).';

    public function handle(): int
    {
        $catalog = ModelCatalog::fromConfig();
        $model = $this->argument('model') ?? config('ai.openai_compatible.chat_model');

        if (! is_string($model) || trim($model) === '') {
            $this->error('Model tidak ditentukan dan ai.openai_compatible.chat_model kosong.');

            return self::FAILURE;
        }

        try {
            $catalog->assertChatModel($model);
        } catch (ModelCatalogException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (AiUnavailableException $e) {
            $this->error('Katalog model tidak dapat dibaca: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Model "'.$model.'" tersedia di katalog provider.');

        return self::SUCCESS;
    }
}
