<?php

namespace App\Support\Ai;

use Illuminate\Http\Client\ConnectionException;

/**
 * Normalisation and diagnostics shared by every place that builds a provider
 * URL or reports a transport failure.
 *
 * `normalizeBaseUrl()` exists because operators paste the full OpenAI-style
 * endpoint (`https://host/v1`) while the client appends `/v1/...` itself; the
 * naive concatenation produced `https://host/v1/v1/models`.
 */
class ProviderEndpoint
{
    /**
     * Suffixes an operator may paste that the client already adds.
     */
    private const REDUNDANT_SUFFIXES = ['/v1/chat/completions', '/v1/embeddings', '/v1/models', '/v1'];

    /**
     * Reduce a pasted URL to the gateway root: no trailing slash, no `/v1`
     * (the client appends it), and only http/https schemes.
     */
    public static function normalizeBaseUrl(?string $url): string
    {
        $normalized = trim((string) $url);

        // Strip repeated trailing slashes before matching a suffix.
        $normalized = rtrim($normalized, '/');

        foreach (self::REDUNDANT_SUFFIXES as $suffix) {
            if (str_ends_with(strtolower($normalized), $suffix)) {
                $normalized = substr($normalized, 0, -strlen($suffix));

                break;
            }
        }

        return rtrim($normalized, '/');
    }

    /**
     * Human-readable reason for a transport failure. TLS verification and a
     * refused connection look identical to a user ("server problem"), so they
     * are told apart here.
     */
    public static function describeConnectionFailure(ConnectionException $exception): string
    {
        $message = $exception->getMessage();

        return match (true) {
            str_contains($message, 'cURL error 60'), str_contains($message, 'certificate') => 'Sertifikat SSL gateway gagal diverifikasi. Pasang CA bundle pada PHP (curl.cainfo) atau gunakan gateway dengan sertifikat yang valid.',
            str_contains($message, 'cURL error 6'), str_contains($message, 'Could not resolve host') => 'Nama host gateway tidak dapat ditemukan. Periksa kembali Base URL.',
            str_contains($message, 'cURL error 7'), str_contains($message, 'Connection refused'), str_contains($message, 'timed out') => 'Tidak dapat menghubungi gateway. Pastikan host aktif dan port benar.',
            default => 'Koneksi ke gateway gagal: '.$message,
        };
    }
}
