<?php

namespace App\Providers;

use App\Adapter\ImageKitAdapter;
use App\Models\Nilai;
use App\Observers\NilaiObserver;
use App\Services\Ai\AiClientManager;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use ImageKit\ImageKit;
use League\Flysystem\Filesystem;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Stateless driver resolver: singleton, never touches the DB. AiClient
        // itself is intentionally not bound; the manager picks the concrete
        // client per call from the chat / embed driver column.
        $this->app->singleton(AiClientManager::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Nilai::observe(NilaiObserver::class);

        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
        Storage::extend('imagekit', function ($app, $config) {
            $adapter = new ImageKitAdapter(
                new ImageKit(
                    env('IMAGEKIT_PUBLIC_KEY'),
                    env('IMAGEKIT_PRIVATE_KEY'),
                    env('IMAGEKIT_ENDPOINT_URL')
                ),
            );

            return new FilesystemAdapter(
                new Filesystem($adapter, $config),
                $adapter,
                $config
            );
        });
    }
}
