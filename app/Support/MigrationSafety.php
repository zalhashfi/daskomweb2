<?php

namespace App\Support;

/**
 * DB-free inspection of migration FILE CONTENTS.
 *
 * Classifies a migration as irreversible (data-destructive) when its `up()`
 * body drops or renames columns/tables. Foreign keys and indexes are not
 * data, so `dropForeign` / `dropUnique` / `dropIndex` on their own stay
 * reversible.
 */
class MigrationSafety
{
    public const REVERSIBLE = 'reversible';

    public const IRREVERSIBLE = 'irreversible';

    /**
     * Destructive patterns keyed by reason label. Order determines report order.
     *
     * @var array<string, string>
     */
    protected const PATTERNS = [
        'dropColumn' => '/->\s*dropColumn\s*\(/',
        'dropTable' => '/->\s*dropTable\s*\(/',
        'dropIfExists' => '/->\s*dropIfExists\s*\(/',
        'renameColumn' => '/->\s*renameColumn\s*\(/',
        'raw drop column' => '/DB::\s*statement\s*\((?:[^()]|\([^()]*\))*DROP\s+COLUMN/is',
    ];

    /**
     * Classify a single migration file.
     *
     * @return array{path: string, verdict: string, reversible: bool, reasons: array<int, string>}
     */
    public function inspectFile(string $path): array
    {
        $reasons = [];

        if (is_file($path)) {
            $up = $this->extractMethod((string) file_get_contents($path), 'up');

            if ($up !== null) {
                $reasons = $this->matchedPatterns($up);
            }
        }

        return $this->verdict($path, $reasons);
    }

    /**
     * Scan a migrations directory and return an overall verdict plus per file detail.
     *
     * @return array{directory: string, verdict: string, reversible: bool, irreversible_count: int, reversible_count: int, reasons: array<string, array<int, string>>, files: array<int, array{path: string, verdict: string, reversible: bool, reasons: array<int, string>}>}
     */
    public function inspectDirectory(string $directory): array
    {
        $files = [];

        foreach ($this->migrationFiles($directory) as $path) {
            $files[] = $this->inspectFile($path);
        }

        $irreversibleCount = count(array_filter($files, static fn (array $file): bool => ! $file['reversible']));
        $reasonMap = [];

        foreach ($files as $file) {
            if (! $file['reversible']) {
                $reasonMap[$file['path']] = $file['reasons'];
            }
        }

        return [
            'directory' => $directory,
            'verdict' => $irreversibleCount > 0 ? self::IRREVERSIBLE : self::REVERSIBLE,
            'reversible' => $irreversibleCount === 0,
            'irreversible_count' => $irreversibleCount,
            'reversible_count' => count($files) - $irreversibleCount,
            'reasons' => $reasonMap,
            'files' => $files,
        ];
    }

    /**
     * @param  array<int, string>  $reasons
     * @return array{path: string, verdict: string, reversible: bool, reasons: array<int, string>}
     */
    protected function verdict(string $path, array $reasons): array
    {
        return [
            'path' => $path,
            'verdict' => $reasons === [] ? self::REVERSIBLE : self::IRREVERSIBLE,
            'reversible' => $reasons === [],
            'reasons' => $reasons,
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function matchedPatterns(string $body): array
    {
        $reasons = [];

        foreach (self::PATTERNS as $label => $pattern) {
            if (preg_match($pattern, $body) === 1) {
                $reasons[] = $label;
            }
        }

        return $reasons;
    }

    /**
     * Extract the body of a method, or null when it cannot be located.
     */
    protected function extractMethod(string $source, string $method): ?string
    {
        if (preg_match('/function\s+'.preg_quote($method, '/').'\s*\([^)]*\)[^{;]*\{/i', $source, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $start = $match[0][1] + strlen($match[0][0]);
        $length = strlen($source);
        $depth = 1;

        for ($i = $start; $i < $length; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;

                if ($depth === 0) {
                    return substr($source, $start, $i - $start);
                }
            }
        }

        return substr($source, $start);
    }

    /**
     * @return array<int, string>
     */
    protected function migrationFiles(string $directory): array
    {
        $matches = glob(rtrim($directory, '/\\').'/*.php');
        $files = $matches === false ? [] : $matches;
        sort($files);

        return $files;
    }
}
