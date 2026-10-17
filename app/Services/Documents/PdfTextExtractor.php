<?php

namespace App\Services\Documents;

use App\Exceptions\PdfExtractionException;

/**
 * Minimal, dependency-free text extraction for PDF documents.
 *
 * No PDF library is installed (and none may be added), so the file is read
 * the way a reader would:
 *
 *  1. indirect objects (`N G obj … endobj`) are indexed by object number;
 *  2. compressed streams (FlateDecode, optionally behind ASCII85Decode /
 *     ASCIIHexDecode) are inflated with zlib;
 *  3. `/ObjStm` object streams are expanded so the objects they carry take
 *     part in page discovery and resource lookup;
 *  4. pages come from the `/Pages` tree (falling back to file order), and
 *     each page's `/Contents` streams are tokenised: text-showing operators
 *     (`Tj`, `TJ`, `'`, `"`) are read and `Td`/`TD`/`T*` start a new line;
 *  5. ToUnicode CMaps map the shown character codes back to Unicode (both
 *     single- and two-byte fonts); fonts without a CMap fall back to
 *     Windows-1252.
 *
 * Anything outside that subset (encrypted files, damaged structures, ...) is
 * never guessed at: unreadable input throws PdfExtractionException.
 */
class PdfTextExtractor
{
    /**
     * Upper bound for one inflated stream, as a decompression-bomb guard.
     */
    private const MAX_DECODED_STREAM_BYTES = 64 * 1024 * 1024;

    /**
     * Maximum depth walked in the `/Pages` tree.
     */
    private const MAX_PAGE_TREE_DEPTH = 64;

    /**
     * Extract the visible text of every page, in page order.
     *
     * @throws PdfExtractionException when the bytes are not a readable PDF.
     */
    public function extract(string $bytes): string
    {
        $this->assertReadablePdf($bytes);

        $objects = $this->indexObjects($bytes);
        $this->expandObjectStreams($objects);

        $pages = $this->pages($objects);

        $texts = [];

        foreach ($pages as $page) {
            $text = trim($this->pageText($page['body'], $objects));

            if ($text !== '') {
                $texts[] = $text;
            }
        }

        return implode("\n\n", $texts);
    }

    /**
     * @throws PdfExtractionException
     */
    private function assertReadablePdf(string $bytes): void
    {
        if (! str_contains(substr($bytes, 0, 1024), '%PDF-')) {
            throw new PdfExtractionException('Berkas bukan PDF: header %PDF- tidak ditemukan.');
        }

        if (strpos($bytes, '%%EOF') === false) {
            throw new PdfExtractionException('PDF rusak/terpotong: penanda %%EOF tidak ditemukan.');
        }

        if (strpos($bytes, 'startxref') === false) {
            throw new PdfExtractionException('PDF rusak: tabel referensi silang (startxref) tidak ditemukan.');
        }
    }

    /**
     * Direct objects keyed by object number.
     *
     * A stream body is cut by the declared `/Length` (or its `endstream`
     * marker), never at a raw `endobj` search, so compressed bytes that
     * happen to spell "endobj" cannot truncate the stream.
     *
     * @return array<int, array{header: string, body: string}>
     *
     * @throws PdfExtractionException
     */
    private function indexObjects(string $bytes): array
    {
        $objects = [];

        preg_match_all('/(\d+)\s+\d+\s+obj\b/', $bytes, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as $index => $match) {
            [$token, $offset] = $match;
            $number = (int) $matches[1][$index][0];

            if (isset($objects[$number])) {
                continue;
            }

            $bodyStart = $offset + strlen($token);
            $bodyEnd = $this->objectBodyEnd($bytes, $bodyStart);

            if ($bodyEnd === null) {
                continue;
            }

            $objects[$number] = [
                'header' => '',
                'body' => substr($bytes, $bodyStart, $bodyEnd - $bodyStart),
            ];
        }

        if ($objects === []) {
            throw new PdfExtractionException('PDF rusak: tidak ada objek yang dapat dibaca.');
        }

        return $objects;
    }

    /**
     * Offset just past the object's `endobj`, or null when it never closes.
     */
    private function objectBodyEnd(string $bytes, int $bodyStart): ?int
    {
        $endObject = strpos($bytes, 'endobj', $bodyStart);
        $stream = strpos($bytes, 'stream', $bodyStart);

        if ($stream !== false && ($endObject === false || $stream < $endObject)) {
            $dictionary = substr($bytes, $bodyStart, $stream - $bodyStart);
            $dataStart = $this->streamDataStart($bytes, $stream);
            $length = $this->directStreamLength($dictionary);

            if ($length !== null && $dataStart + $length <= strlen($bytes)) {
                $endStream = strpos($bytes, 'endstream', $dataStart + $length);

                if ($endStream !== false) {
                    $endObject = strpos($bytes, 'endobj', $endStream);

                    return $endObject === false ? $endStream : $endObject + strlen('endobj');
                }
            }
        }

        return $endObject === false ? null : $endObject + strlen('endobj');
    }

    /**
     * `/Length` when it is written inline (an indirect `/Length` is left to
     * the `endstream`/`endobj` markers).
     */
    private function directStreamLength(string $dictionary): ?int
    {
        if (! preg_match('#/Length\s+(\d+)(?!\s+\d+\s+R)#', $dictionary, $match)) {
            return null;
        }

        return (int) $match[1];
    }

    /**
     * First data byte of a stream, skipping the EOL after the keyword.
     */
    private function streamDataStart(string $bytes, int $streamKeyword): int
    {
        $start = $streamKeyword + strlen('stream');

        if (substr($bytes, $start, 2) === "\r\n") {
            return $start + 2;
        }

        $first = substr($bytes, $start, 1);

        return in_array($first, ["\n", "\r"], true) ? $start + 1 : $start;
    }

    /**
     * Add (in place) the objects carried by `/Type /ObjStm` streams.
     *
     * Added objects are compressed members without a stream of their own, so
     * a single pass over the direct objects is enough.
     *
     * @param  array<int, array{header: string, body: string}>  $objects
     */
    private function expandObjectStreams(array &$objects): void
    {
        foreach ($this->directObjectIds($objects) as $objectNumber) {
            $object = $objects[$objectNumber];
            $full = $object['header'].$object['body'];

            if (! preg_match('#/Type\s*/ObjStm#', $full)) {
                continue;
            }

            $decoded = $this->decodeStream($full);

            if ($decoded === null
                || ! preg_match('#/N\s+(\d+)#', $full, $countMatch)
                || ! preg_match('#/First\s+(\d+)#', $full, $firstMatch)) {
                continue;
            }

            $first = (int) $firstMatch[1];
            $count = (int) $countMatch[1];

            if ($count <= 0 || $first <= 0 || $first > strlen($decoded)) {
                continue;
            }

            preg_match_all('/(\d+)\s+(\d+)/', substr($decoded, 0, $first), $pairs);

            /** @var array<int, int> $offsets object number => offset in $decoded */
            $offsets = [];

            foreach ($pairs[1] as $index => $objectNumber) {
                $offsets[(int) $objectNumber] = (int) $pairs[2][$index];
            }

            $starts = array_keys($offsets);
            sort($starts);

            foreach ($starts as $position => $objectNumber) {
                if (isset($objects[$objectNumber])) {
                    continue;
                }

                $start = $first + $offsets[$objectNumber];
                $end = isset($starts[$position + 1])
                    ? $first + $offsets[$starts[$position + 1]]
                    : strlen($decoded);

                $objects[$objectNumber] = [
                    'header' => '',
                    'body' => substr($decoded, $start, max(0, $end - $start)),
                ];
            }
        }
    }

    /**
     * Object numbers present before any object stream is expanded.
     *
     * @param  array<int, array{header: string, body: string}>  $objects
     * @return list<int>
     */
    private function directObjectIds(array $objects): array
    {
        return array_keys($objects);
    }

    /**
     * Page bodies in reading order.
     *
     * @param  array<int, array{header: string, body: string}>  $objects
     * @return list<array{id: int, body: string}>
     *
     * @throws PdfExtractionException when the file contains no page at all.
     */
    private function pages(array $objects): array
    {
        $pages = [];
        $visited = [];
        $root = $this->pagesRootId($objects);

        if ($root !== null) {
            $this->collectPages($root, $objects, $pages, $visited, 0);
        }

        if ($pages === []) {
            foreach ($objects as $number => $object) {
                $full = $object['header'].$object['body'];

                if (preg_match('#/Type\s*/Page(?![s\w])#', $full)) {
                    $pages[] = ['id' => $number, 'body' => $full];
                }
            }
        }

        if ($pages === []) {
            throw new PdfExtractionException('PDF tidak memuat halaman yang dapat dibaca.');
        }

        return $pages;
    }

    /**
     * @param  array<int, array{header: string, body: string}>  $objects
     */
    private function pagesRootId(array $objects): ?int
    {
        foreach ($objects as $object) {
            $full = $object['header'].$object['body'];

            if (preg_match('#/Type\s*/Catalog#', $full)
                && preg_match('#/Pages\s+(\d+)\s+\d+\s+R#', $full, $match)) {
                return (int) $match[1];
            }
        }

        return null;
    }

    /**
     * @param  array<int, array{header: string, body: string}>  $objects
     * @param  list<array{id: int, body: string}>  $pages
     * @param  array<int, true>  $visited
     */
    private function collectPages(int $objectId, array $objects, array &$pages, array &$visited, int $depth): void
    {
        if ($depth > self::MAX_PAGE_TREE_DEPTH || isset($visited[$objectId]) || ! isset($objects[$objectId])) {
            return;
        }

        $visited[$objectId] = true;
        $full = $objects[$objectId]['header'].$objects[$objectId]['body'];

        if (preg_match('#/Type\s*/Page(?![s\w])#', $full) && ! preg_match('#/Type\s*/Pages#', $full)) {
            $pages[] = ['id' => $objectId, 'body' => $full];

            return;
        }

        preg_match_all('#(\d+)\s+\d+\s+R#', $this->valueSlice($full, 'Kids'), $kids);

        foreach ($kids[1] as $kid) {
            $this->collectPages((int) $kid, $objects, $pages, $visited, $depth + 1);
        }
    }

    /**
     * The dictionary of `/Key` (a `<< … >>` block inside `$dictionary`), with
     * nested dictionaries balanced; an empty string when the key is absent.
     */
    private function nestedDictionary(string $dictionary, string $key): string
    {
        if (! preg_match('#/'.$key.'\s*<<#', $dictionary, $match, PREG_OFFSET_CAPTURE)) {
            return '';
        }

        $start = $match[0][1] + strlen($match[0][0]) - 2;
        $depth = 0;
        $length = strlen($dictionary);

        for ($index = $start; $index < $length - 1; $index++) {
            $pair = substr($dictionary, $index, 2);

            if ($pair === '<<') {
                $depth++;
                $index++;
            } elseif ($pair === '>>') {
                $depth--;

                if ($depth === 0) {
                    return substr($dictionary, $start, $index + 2 - $start);
                }

                $index++;
            }
        }

        return '';
    }

    /**
     * The scalar / array value of `/Key` up to the next `/Key` or the end of
     * the dictionary. Used for keys holding a reference (`/Contents 4 0 R`)
     * or a flat array (`/Kids [ … ]`); never for a `<< … >>` value.
     */
    private function valueSlice(string $dictionary, string $key): string
    {
        if (! preg_match('#/'.$key.'\s+(.+?)(?=\s*/[A-Za-z0-9]|\s*>>|$)#s', $dictionary, $match)) {
            return '';
        }

        return $match[1];
    }

    /**
     * Resolve `/Resources` of a page to its dictionary, following one
     * indirect reference when the page only points at it.
     *
     * @param  array<int, array{header: string, body: string}>  $objects
     */
    private function resourcesDictionary(string $pageBody, array $objects): string
    {
        $resources = $this->nestedDictionary($pageBody, 'Resources');

        if (preg_match('#^\s*(\d+)\s+\d+\s+R#', $this->valueSlice($pageBody, 'Resources'), $match)) {
            return $this->objectBody((int) $match[1], $objects);
        }

        return $resources;
    }

    /**
     * Text of one page: its `/Contents` streams concatenated and tokenised,
     * using the `/Resources /Font` mappings of the same page.
     *
     * @param  array<int, array{header: string, body: string}>  $objects
     */
    private function pageText(string $pageBody, array $objects): string
    {
        $contents = $this->valueSlice($pageBody, 'Contents');
        $streams = [];

        preg_match_all('#(\d+)\s+\d+\s+R#', $contents, $references);

        foreach ($references[1] as $reference) {
            $object = $objects[(int) $reference] ?? null;

            if ($object !== null) {
                $streams[] = $this->decodeStream($object['header'].$object['body']);
            }
        }

        if ($streams === [] && str_contains($contents, 'stream')) {
            // Inline `/Contents << … /Length n >> stream … endstream`.
            $streams[] = $this->decodeStream($contents.' endstream');
        }

        $streams = array_values(array_filter($streams, static fn (?string $stream): bool => $stream !== null));

        if ($streams === []) {
            return '';
        }

        return $this->decodeContent(implode("\n", $streams), $this->fontMaps($pageBody, $objects));
    }

    /**
     * Character-code → Unicode maps of the page's fonts, keyed by the font
     * resource name used in the content stream (`F1`, ...).
     *
     * @param  array<int, array{header: string, body: string}>  $objects
     * @return array<string, array<int, string>>
     */
    private function fontMaps(string $pageBody, array $objects): array
    {
        $fonts = $this->nestedDictionary($this->resourcesDictionary($pageBody, $objects), 'Font');

        if ($fonts === '' && preg_match('#^\s*(\d+)\s+\d+\s+R#', $this->valueSlice($pageBody, 'Font'), $inline)) {
            $fonts = $this->objectBody((int) $inline[1], $objects);
        }

        $maps = [];

        preg_match_all('#/([A-Za-z0-9_.+-]+)\s+(\d+)\s+\d+\s+R#', $fonts, $fontReferences);

        foreach ($fontReferences[1] as $index => $name) {
            $maps[$name] = $this->fontUnicodeMap($this->objectBody((int) $fontReferences[2][$index], $objects), $objects);
        }

        return $maps;
    }

    /**
     * @param  array<int, array{header: string, body: string}>  $objects
     */
    private function objectBody(int $objectId, array $objects): string
    {
        $object = $objects[$objectId] ?? null;

        return $object === null ? '' : $object['header'].$object['body'];
    }

    /**
     * @param  array<int, array{header: string, body: string}>  $objects
     * @return array<int, string>
     */
    private function fontUnicodeMap(string $fontBody, array $objects): array
    {
        if (! preg_match('#/ToUnicode\s+(\d+)\s+\d+\s+R#', $fontBody, $match)) {
            return [];
        }

        $cmap = $this->decodeStream($this->objectBody((int) $match[1], $objects));

        return $cmap === null ? [] : $this->parseToUnicode($cmap);
    }

    /**
     * Parse the `beginbfchar` / `beginbfrange` sections of a ToUnicode CMap.
     *
     * @return array<int, string>
     */
    private function parseToUnicode(string $cmap): array
    {
        $map = [];

        if (preg_match_all('#beginbfchar(.*?)endbfchar#s', $cmap, $sections)) {
            foreach ($sections[1] as $section) {
                preg_match_all('#<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]*)>#', $section, $pairs);

                foreach ($pairs[1] as $index => $code) {
                    $map[hexdec($code)] = $this->hexToUtf8($pairs[2][$index]);
                }
            }
        }

        if (preg_match_all('#beginbfrange(.*?)endbfrange#s', $cmap, $sections)) {
            foreach ($sections[1] as $section) {
                preg_match_all('#<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*(<([0-9A-Fa-f]+)>|\[(.*?)\])#s', $section, $ranges, PREG_SET_ORDER);

                foreach ($ranges as $range) {
                    $start = hexdec($range[1]);
                    $end = hexdec($range[2]);

                    if ($end < $start || $end - $start > 65535) {
                        continue;
                    }

                    if (($range[4] ?? '') !== '') {
                        $base = hexdec($range[4]);

                        for ($code = $start; $code <= $end; $code++) {
                            $map[$code] = $this->codePointToUtf8($base + ($code - $start));
                        }

                        continue;
                    }

                    preg_match_all('#<([0-9A-Fa-f]+)>#', $range[5], $targets);

                    foreach ($targets[1] as $offset => $target) {
                        if ($start + $offset > $end) {
                            break;
                        }

                        $map[$start + $offset] = $this->hexToUtf8($target);
                    }
                }
            }
        }

        return $map;
    }

    /**
     * Decode a content stream into text.
     *
     * @param  array<string, array<int, string>>  $fontMaps
     */
    private function decodeContent(string $content, array $fontMaps): string
    {
        $output = '';
        $currentLine = '';
        $lineOpen = false;
        $operands = [];
        $currentFont = '';

        $startLine = function () use (&$output, &$currentLine, &$lineOpen): void {
            if ($lineOpen) {
                $output .= rtrim($currentLine)."\n";
            }

            $currentLine = '';
            $lineOpen = true;
        };

        foreach ($this->tokenize($content) as [$type, $value]) {
            if ($type !== 'operator') {
                $operands[] = [$type, $value];

                continue;
            }

            switch ($value) {
                case 'Tf':
                    foreach ($operands as [$operandType, $operandValue]) {
                        if ($operandType === 'name') {
                            $currentFont = ltrim($operandValue, '/');
                        }
                    }
                    break;

                case 'Tj':
                    $string = $this->lastString($operands);

                    if ($string !== null) {
                        $currentLine .= $this->decodeShownString($string, $fontMaps[$currentFont] ?? []);
                    }
                    break;

                case 'TJ':
                    $array = $this->lastArray($operands);

                    if ($array !== null) {
                        $currentLine .= $this->decodeTextArray($array, $fontMaps[$currentFont] ?? []);
                    }
                    break;

                case "'":
                case '"':
                    $string = $this->lastString($operands);

                    if ($string !== null) {
                        $startLine();
                        $currentLine .= $this->decodeShownString($string, $fontMaps[$currentFont] ?? []);
                    }
                    break;

                case 'Td':
                case 'TD':
                case 'T*':
                    $startLine();
                    break;

                case 'ET':
                    if ($currentLine !== '') {
                        $startLine();
                    }
                    break;
            }

            $operands = [];
        }

        if ($currentLine !== '') {
            $output .= rtrim($currentLine)."\n";
        }

        return $output;
    }

    /**
     * @param  list<array{0: string, 1: mixed}>  $operands
     */
    private function lastString(array $operands): ?string
    {
        for ($index = count($operands) - 1; $index >= 0; $index--) {
            if ($operands[$index][0] === 'string') {
                return $operands[$index][1];
            }
        }

        return null;
    }

    /**
     * @param  list<array{0: string, 1: mixed}>  $operands
     * @return list<array{0: string, 1: mixed}>|null
     */
    private function lastArray(array $operands): ?array
    {
        for ($index = count($operands) - 1; $index >= 0; $index--) {
            if ($operands[$index][0] === 'array') {
                return $operands[$index][1];
            }
        }

        return null;
    }

    /**
     * @param  list<array{0: string, 1: mixed}>  $items
     * @param  array<int, string>  $cmap
     */
    private function decodeTextArray(array $items, array $cmap): string
    {
        $text = '';

        foreach ($items as [$type, $value]) {
            if ($type === 'string') {
                $text .= $this->decodeShownString($value, $cmap);
            } elseif ($type === 'number' && (float) $value <= -200.0) {
                $text .= ' ';
            }
        }

        return $text;
    }

    /**
     * Map the character codes of one shown string through the font map.
     *
     * @param  array<int, string>  $cmap
     */
    private function decodeShownString(string $bytes, array $cmap): string
    {
        $twoByte = $cmap !== [] && max(array_keys($cmap)) > 0xFF;
        $length = strlen($bytes);
        $text = '';

        for ($index = 0; $index < $length; $index++) {
            if ($twoByte) {
                $code = (ord($bytes[$index]) << 8) | ($index + 1 < $length ? ord($bytes[$index + 1]) : 0);
                $index++;
            } else {
                $code = ord($bytes[$index]);
            }

            if ($cmap === []) {
                $text .= $this->windows1252($code);
            } else {
                // A glyph the CMap does not cover carries no text.
                $text .= $cmap[$code] ?? '';
            }
        }

        return $text;
    }

    /**
     * @return list<array{0: string, 1: mixed}>
     */
    private function tokenize(string $content): array
    {
        $tokens = [];
        $length = strlen($content);
        $index = 0;

        while ($index < $length) {
            $char = $content[$index];

            if (ctype_space($char)) {
                $index++;

                continue;
            }

            if ($char === '%') {
                $newline = strpos($content, "\n", $index);
                $index = $newline === false ? $length : $newline + 1;

                continue;
            }

            if ($char === '(') {
                [$string, $index] = $this->readLiteralString($content, $index);
                $tokens[] = ['string', $string];

                continue;
            }

            if ($char === '<' && substr($content, $index, 2) !== '<<') {
                $end = strpos($content, '>', $index);
                $end = $end === false ? $length : $end;
                $tokens[] = ['string', $this->hexBytes(substr($content, $index + 1, $end - $index - 1))];
                $index = $end + 1;

                continue;
            }

            if ($char === '[') {
                [$items, $index] = $this->readArray($content, $index + 1);
                $tokens[] = ['array', $items];

                continue;
            }

            if ($char === '/') {
                if (preg_match('/\G\/([^\s\/\[\]()<>{}%]*)/', $content, $match, 0, $index)) {
                    $tokens[] = ['name', $match[0]];
                    $index += strlen($match[0]);
                } else {
                    $index++;
                }

                continue;
            }

            if (preg_match('/\G(<<|>>|[+-]?(?:\d+\.?\d*|\.\d+)|[A-Za-z\'"*]+)/', $content, $match, 0, $index)) {
                $word = $match[0];

                if ($word !== '<<' && $word !== '>>') {
                    $tokens[] = is_numeric($word) ? ['number', (float) $word] : ['operator', $word];
                }

                $index += strlen($word);

                continue;
            }

            $index++;
        }

        return $tokens;
    }

    /**
     * Read a `( … )` literal string, honouring escapes and nested parens.
     *
     * @return array{0: string, 1: int} the string and the offset after `)`
     */
    private function readLiteralString(string $content, int $start): array
    {
        $length = strlen($content);
        $index = $start + 1;
        $depth = 1;
        $string = '';

        while ($index < $length) {
            $char = $content[$index];

            if ($char === '\\') {
                $next = $content[$index + 1] ?? '';
                $index += 2;

                $escaped = match ($next) {
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    'b' => "\x08",
                    'f' => "\f",
                    "\n" => '',
                    "\r" => $index + 1 < $length && $content[$index] === "\n" ? '' : '',
                    default => $next,
                };

                if ($next >= '0' && $next <= '7') {
                    preg_match('/^[0-7]{0,2}/', substr($content, $index, 2), $extra);
                    $digits = $next.($extra[0] ?? '');
                    $index += strlen($extra[0] ?? '');
                    $escaped = chr(octdec($digits) & 0xFF);
                }

                $string .= $escaped;

                continue;
            }

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;

                if ($depth === 0) {
                    return [$string, $index + 1];
                }
            }

            $string .= $char;
            $index++;
        }

        return [$string, $length];
    }

    /**
     * @return array{0: list<array{0: string, 1: mixed}>, 1: int}
     */
    private function readArray(string $content, int $start): array
    {
        $length = strlen($content);
        $index = $start;
        $items = [];

        while ($index < $length) {
            $char = $content[$index];

            if (ctype_space($char)) {
                $index++;

                continue;
            }

            if ($char === ']') {
                return [$items, $index + 1];
            }

            if ($char === '(') {
                [$string, $index] = $this->readLiteralString($content, $index);
                $items[] = ['string', $string];

                continue;
            }

            if ($char === '<' && substr($content, $index, 2) !== '<<') {
                $end = strpos($content, '>', $index);
                $end = $end === false ? $length : $end;
                $items[] = ['string', $this->hexBytes(substr($content, $index + 1, $end - $index - 1))];
                $index = $end + 1;

                continue;
            }

            if (preg_match('/\G([+-]?(?:\d+\.?\d*|\.\d+)|[A-Za-z]+)', $content, $match, 0, $index)) {
                $items[] = is_numeric($match[0]) ? ['number', (float) $match[0]] : ['operator', $match[0]];
                $index += strlen($match[0]);

                continue;
            }

            $index++;
        }

        return [$items, $length];
    }

    private function hexBytes(string $hex): string
    {
        $clean = preg_replace('/[^0-9A-Fa-f]/', '', $hex) ?? '';

        if ($clean === '') {
            return '';
        }

        if (strlen($clean) % 2 === 1) {
            $clean .= '0';
        }

        return (string) hex2bin($clean);
    }

    /**
     * Body of the stream in an object body, decompressed; null when the body
     * carries no usable stream (image data, unsupported filter, bad zlib).
     */
    private function decodeStream(string $objectBody): ?string
    {
        $streamPos = strpos($objectBody, 'stream');

        if ($streamPos === false) {
            return null;
        }

        $dictionary = substr($objectBody, 0, $streamPos);

        if (preg_match('#/Subtype\s*/Image#', $dictionary) || preg_match('#/Filter\s*\[\s*/(DCT|JPX|CCITT|JBIG2)#', $dictionary)) {
            return null;
        }

        $dataStart = $this->streamDataStart($objectBody, $streamPos);
        $endStream = strrpos($objectBody, 'endstream');

        $data = $endStream !== false && $endStream > $dataStart
            ? substr($objectBody, $dataStart, $endStream - $dataStart)
            : substr($objectBody, $dataStart);

        $data = preg_replace('/\r?\n$/', '', $data) ?? $data;

        if (str_contains($dictionary, 'ASCIIHexDecode')) {
            $data = $this->hexBytes(explode('>', $data)[0]);
        }

        if (str_contains($dictionary, 'ASCII85Decode')) {
            $data = $this->ascii85Decode($data);
        }

        if (str_contains($dictionary, 'FlateDecode')) {
            $inflated = @gzuncompress($data);

            if ($inflated === false || strlen($inflated) > self::MAX_DECODED_STREAM_BYTES) {
                return null;
            }

            $data = $inflated;
        }

        return $data;
    }

    private function ascii85Decode(string $data): string
    {
        $data = preg_replace('/\s+/', '', $data) ?? '';

        if (str_starts_with($data, '<~')) {
            $data = substr($data, 2);
        }

        $end = strpos($data, '~>');

        if ($end !== false) {
            $data = substr($data, 0, $end);
        }

        $output = '';
        $group = '';

        foreach (str_split($data) as $char) {
            if ($char === 'z' && $group === '') {
                $output .= "\0\0\0\0";

                continue;
            }

            $group .= $char;

            if (strlen($group) === 5) {
                $output .= pack('N', $this->ascii85Value($group));
                $group = '';
            }
        }

        if ($group !== '') {
            $padding = 5 - strlen($group);
            $output .= substr(pack('N', $this->ascii85Value(str_pad($group, 5, 'u'))), 0, 4 - $padding);
        }

        return $output;
    }

    private function ascii85Value(string $group): int
    {
        $value = 0;

        foreach (str_split($group) as $char) {
            $value = (($value * 85) + (ord($char) - 33)) & 0xFFFFFFFF;
        }

        return $value;
    }

    private function hexToUtf8(string $hex): string
    {
        $bytes = $this->hexBytes($hex);

        if (strlen($hex) <= 4) {
            return $this->codePointToUtf8(hexdec($hex === '' ? '0' : $hex));
        }

        $text = @mb_convert_encoding($bytes, 'UTF-8', 'UTF-16BE');

        return is_string($text) ? preg_replace('/\x00/', '', $text) ?? '' : '';
    }

    private function codePointToUtf8(int $code): string
    {
        if ($code <= 0 || $code > 0x10FFFF) {
            return '';
        }

        return mb_chr($code, 'UTF-8') ?: '';
    }

    private function windows1252(int $code): string
    {
        if ($code < 0x20 || ($code >= 0x7F && $code < 0xA0)) {
            return $code === 0x0A ? "\n" : '';
        }

        return mb_chr($code, 'Windows-1252') ?: '';
    }
}
