<?php

namespace App\Services\Documents;

/**
 * Splits extracted document text into overlapping chunks for embedding.
 *
 * Chunks are cut on natural boundaries — paragraph gaps first, then line
 * breaks, then sentence punctuation, then whitespace — and prefer as many
 * boundaries as possible so a chunk never ends mid-word. Normalised text is
 * expected in input; whitespace runs are collapsed here anyway so the
 * embedding input is deterministic.
 */
class TextChunker
{
    /**
     * Default characters per chunk; matches the embedding input budget.
     */
    public const DEFAULT_CHUNK_SIZE = 1200;

    /**
     * Default characters repeated from the previous chunk so a sentence
     * split across a boundary stays retrievable.
     */
    public const DEFAULT_OVERLAP = 150;

    /**
     * Cut points tried in order; the earliest tier that lands late enough
     * in the window wins, so paragraphs beat line breaks beat sentences.
     *
     * @var array<string, string>
     */
    private const BOUNDARIES = [
        'paragraph' => '/\n\s*\n/',
        'line' => '/\n/',
        'sentence' => '/(?<=[.!?]) +/u',
        'space' => '/\s+/u',
    ];

    /**
     * Share of the window a boundary must reach to be preferred over the
     * next, stricter tier (avoids tiny chunks from early paragraph breaks).
     */
    private const MIN_BOUNDARY_RATIO = 0.5;

    /**
     * How far a chunk may stop before the end of its window. Without this a
     * window that is mostly one long sentence can never be cut (no boundary
     * exists after the minimum), so chunks would only grow with the window.
     */
    private const MAX_LOOKAHEAD_CHARS = 100;

    public function __construct(
        public readonly int $chunkSize = self::DEFAULT_CHUNK_SIZE,
        public readonly int $overlap = self::DEFAULT_OVERLAP,
    ) {}

    /**
     * @return list<string> non-empty chunk texts in document order
     */
    public function chunk(string $text): array
    {
        $text = $this->normalise($text);

        if ($text === '') {
            return [];
        }

        $size = max(1, $this->chunkSize);
        $overlap = max(0, min($this->overlap, (int) ($size / 2)));
        $length = mb_strlen($text);
        $chunks = [];
        $start = 0;

        while ($start < $length) {
            $window = mb_substr($text, $start, $size);

            if ($start + $size >= $length) {
                $chunk = trim($window);
                $start = $length;
            } else {
                $cut = $this->boundaryOffset($window);
                $chunk = trim(mb_substr($text, $start, $cut));
                $start += max(1, $cut - $this->overlap);

                // A short remainder after the overlap is the tail of this
                // chunk, not a chunk of its own: keep it whole.
                if (mb_strlen($text) - $start <= $overlap) {
                    $start = $length;
                }
            }

            if ($chunk !== '') {
                $chunks[] = $chunk;
            }
        }

        return $chunks;
    }

    /**
     * Collapse whitespace and decode the ligatures some PDFs emit.
     */
    private function normalise(string $text): string
    {
        $text = str_replace(["\u{FB00}", "\u{FB01}", "\u{FB02}", "\u{FB03}", "\u{FB04}", "\u{FB05}", "\u{FB06}"], ['ff', 'fi', 'fl', 'ffi', 'ffl', 'st', 'st'], $text);
        $text = str_replace(["\r\n", "\r", "\u{00A0}", "\u{200B}"], ["\n", "\n", ' ', ''], $text);

        return trim(preg_replace('/[ \t\f\v]+/', ' ', $text) ?? '');
    }

    /**
     * Where to cut this window: the last boundary of the strictest tier that
     * leaves at least MIN_BOUNDARY_RATIO of the window filled.
     */
    private function boundaryOffset(string $window): int
    {
        $length = mb_strlen($window);
        $minimum = max(1, min(
            (int) floor($length * self::MIN_BOUNDARY_RATIO),
            $length - self::MAX_LOOKAHEAD_CHARS
        ));

        foreach (self::BOUNDARIES as $pattern) {
            preg_match_all($pattern, $window, $matches, PREG_OFFSET_CAPTURE);

            for ($index = count($matches[0]) - 1; $index >= 0; $index--) {
                $offset = mb_strlen(substr($window, 0, $matches[0][$index][1]));

                // Scanning backwards: the first hit is the latest boundary.
                if ($offset >= $minimum) {
                    return $offset;
                }
            }
        }

        return $length;
    }
}
