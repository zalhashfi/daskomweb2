<?php

namespace Tests\Unit;

use App\Support\Ai\ProviderEndpoint;
use Illuminate\Http\Client\ConnectionException;
use PHPUnit\Framework\TestCase;

/**
 * URL normalisation and transport diagnostics shared by the AI clients.
 *
 * The regression this guards: an operator pastes `https://host/v1`, the
 * client appends `/v1/models`, and the probe hits `https://host/v1/v1/models`.
 */
class ProviderEndpointTest extends TestCase
{
    public function test_a_plain_host_is_left_untouched(): void
    {
        $this->assertSame('https://router.test', ProviderEndpoint::normalizeBaseUrl('https://router.test'));
    }

    public function test_a_trailing_slash_is_removed(): void
    {
        $this->assertSame('https://router.test', ProviderEndpoint::normalizeBaseUrl('https://router.test/'));
        $this->assertSame('https://router.test', ProviderEndpoint::normalizeBaseUrl('https://router.test///'));
    }

    public function test_a_pasted_v1_suffix_is_removed(): void
    {
        $this->assertSame('https://router.test', ProviderEndpoint::normalizeBaseUrl('https://router.test/v1'));
        $this->assertSame('https://router.test', ProviderEndpoint::normalizeBaseUrl('https://router.test/v1/'));
    }

    public function test_a_pasted_full_endpoint_is_reduced_to_the_root(): void
    {
        $this->assertSame('https://router.test', ProviderEndpoint::normalizeBaseUrl('https://router.test/v1/chat/completions'));
        $this->assertSame('https://router.test', ProviderEndpoint::normalizeBaseUrl('https://router.test/v1/embeddings'));
        $this->assertSame('https://router.test', ProviderEndpoint::normalizeBaseUrl('https://router.test/v1/models'));
    }

    public function test_a_path_prefix_is_preserved(): void
    {
        $this->assertSame(
            'https://router.test/gateway',
            ProviderEndpoint::normalizeBaseUrl('https://router.test/gateway/v1'),
        );
    }

    public function test_surrounding_whitespace_is_trimmed(): void
    {
        $this->assertSame('https://router.test', ProviderEndpoint::normalizeBaseUrl('  https://router.test/v1  '));
    }

    public function test_an_empty_value_normalises_to_an_empty_string(): void
    {
        $this->assertSame('', ProviderEndpoint::normalizeBaseUrl(null));
        $this->assertSame('', ProviderEndpoint::normalizeBaseUrl(''));
    }

    public function test_ssl_failure_is_reported_as_a_certificate_problem(): void
    {
        $message = ProviderEndpoint::describeConnectionFailure(
            new ConnectionException('cURL error 60: SSL certificate OpenSSL verify result: unable to get local issuer certificate (20)'),
        );

        $this->assertStringContainsString('Sertifikat SSL', $message);
        $this->assertStringContainsString('curl.cainfo', $message);
    }

    public function test_an_unresolvable_host_is_named(): void
    {
        $message = ProviderEndpoint::describeConnectionFailure(
            new ConnectionException('cURL error 6: Could not resolve host: router.test'),
        );

        $this->assertStringContainsString('tidak dapat ditemukan', $message);
    }

    public function test_a_refused_connection_is_named(): void
    {
        $message = ProviderEndpoint::describeConnectionFailure(
            new ConnectionException('cURL error 7: Failed to connect: Connection refused'),
        );

        $this->assertStringContainsString('Tidak dapat menghubungi gateway', $message);
    }

    public function test_an_unrecognised_failure_keeps_the_original_detail(): void
    {
        $message = ProviderEndpoint::describeConnectionFailure(new ConnectionException('something unexpected'));

        $this->assertStringContainsString('something unexpected', $message);
    }
}
