<?php

namespace Tests\Unit;

use App\Services\ImageKitService;
use ImageKit\ImageKit;
use RuntimeException;
use Tests\TestCase;

class ImageKitServiceTest extends TestCase
{
    private function clearImageKitConfig(): void
    {
        config([
            'services.imagekit.public_key' => null,
            'services.imagekit.private_key' => null,
            'services.imagekit.endpoint_url' => null,
        ]);
    }

    public function test_it_constructs_without_credentials(): void
    {
        $this->clearImageKitConfig();

        $this->assertInstanceOf(ImageKitService::class, new ImageKitService());
    }

    public function test_get_client_without_credentials_throws_runtime_exception(): void
    {
        $this->clearImageKitConfig();

        $service = new ImageKitService();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ImageKit credentials are not configured.');

        $service->getClient();
    }

    public function test_get_client_with_credentials_returns_imagekit_instance(): void
    {
        config([
            'services.imagekit.public_key' => 'public_test_key',
            'services.imagekit.private_key' => 'private_test_key',
            'services.imagekit.endpoint_url' => 'https://ik.imagekit.io/test',
        ]);

        $client = (new ImageKitService())->getClient();

        $this->assertInstanceOf(ImageKit::class, $client);
    }

    public function test_generate_auth_parameters_returns_public_key_and_url_endpoint(): void
    {
        config([
            'services.imagekit.public_key' => 'public_test_key',
            'services.imagekit.private_key' => 'private_test_key',
            'services.imagekit.endpoint_url' => 'https://ik.imagekit.io/test',
        ]);

        $parameters = (new ImageKitService())->generateAuthParameters();

        $this->assertIsArray($parameters);
        $this->assertSame('public_test_key', $parameters['publicKey']);
        $this->assertSame('https://ik.imagekit.io/test', $parameters['urlEndpoint']);
    }

    public function test_normalize_metadata_maps_keys(): void
    {
        $service = new ImageKitService();

        $normalized = $service->normalizeMetadata([
            'fileId' => 'file_123',
            'url' => 'https://ik.imagekit.io/test/image.png',
            'filePath' => '/uploads/image.png',
            'thumbnailUrl' => 'https://ik.imagekit.io/test/tr:n-thumb/image.png',
            'unrelated' => 'ignored',
        ]);

        $this->assertSame([
            'file_id' => 'file_123',
            'url' => 'https://ik.imagekit.io/test/image.png',
            'file_path' => '/uploads/image.png',
            'thumbnail_url' => 'https://ik.imagekit.io/test/tr:n-thumb/image.png',
        ], $normalized);
    }

    public function test_normalize_metadata_defaults_missing_keys_to_null(): void
    {
        $normalized = (new ImageKitService())->normalizeMetadata([]);

        $this->assertSame([
            'file_id' => null,
            'url' => null,
            'file_path' => null,
            'thumbnail_url' => null,
        ], $normalized);
    }
}
