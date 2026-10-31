<?php
declare(strict_types=1);

/**
 * Neo PS News Library
 *
 * editable JSON:
 *   /data/news-source.json
 *
 * generated DAT:
 *   /news/news.dat
 *
 * DAT format:
 *   5,<latestID>|1:<id>;2:<date>;3:<title>;4:<blocks>
 *
 * Records: separated by |
 * Blocks: separated by ~
 * Compression: GZIP
 * External encoding: Base64 URL-safe without padding
 */

/**
 * Encode a string as Base64 URL-safe without padding.
 */
function newsBase64UrlEncode(string $value): string
{
    return rtrim(
        strtr(base64_encode($value), '+/', '-_'),
        '='
    );
}

/**
 * Decodes Base64 URL-safe.
 */
function newsBase64UrlDecode(string $value): string|false
{
    $value = strtr($value, '-_', '+/');
    $padding = strlen($value) % 4;

    if ($padding !== 0) {
        $value .= str_repeat('=', 4 - $padding);
    }

    return base64_decode($value, true);
}

/**
 * Encode text for block 0 of Geometry Dash.
 */
function newsText(string $text): string
{
    return '0,' . base64_encode($text);
}

/**
 * Code a link for block 2 of Geometry Dash.
 */
function newsLink(string $label, string $url): string
{
    return '2,'
        . base64_encode($label)
        . ','
        . base64_encode($url);
}

/**
 * Encode an image for block 1.
 *
 * The name, dimensions, and indicator must correspond.
 * to the image format that your client supports.
 */

function newsImage(
    string $filename,
    int $width,
    int $height,
    int $indicator,
    string|int $url = ''
): string {
    $filename = basename($filename);

    if (
        $filename === '' ||
        preg_match('/^[a-zA-Z0-9_.-]+$/', $filename) !== 1 ||
        in_array($filename, ['.', '..'], true)
    ) {
        throw new InvalidArgumentException(
            'Invalid image name.'
        );
    }

    if ($width < 1 || $height < 1) {
        throw new InvalidArgumentException(
            'The dimensions must be greater than zero.'
        );
    }

    if (!in_array($indicator, [0, 1], true)) {
        throw new InvalidArgumentException(
            'The image indicator should be 0 or 1.'
        );
    }

    if ($indicator === 1) {
        $width = 800;
        $height = 454;
    }

    $url = trim((string)$url);

    if ($url !== '') {
        $scheme = strtolower(
            (string)parse_url($url, PHP_URL_SCHEME)
        );

        if (
            !filter_var($url, FILTER_VALIDATE_URL) ||
            !in_array($scheme, ['http', 'https'], true)
        ) {
            throw new InvalidArgumentException(
                'The image URL is invalid.'
            );
        }
    }

    $encodedUrl = $url === ''
        ? ''
        : base64_encode($url);

    return '1,'
        . $filename . ','
        . $width . ','
        . $height . ','
        . $indicator . ','
        . $encodedUrl;
}

/**
 * Read the news JSON log.
 */
function loadNewsList(string $jsonPath): array
{
    if (!is_file($jsonPath)) {
        return [];
    }

    $json = file_get_contents($jsonPath);

    if ($json === false) {
        throw new RuntimeException(
            'The news-source.json file could not be read.'
        );
    }

    if (trim($json) === '') {
        return [];
    }

    $data = json_decode(
        $json,
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    if (!is_array($data)) {
        throw new RuntimeException(
            'The news log does not contain a valid list.'
        );
    }

    $newsList = [];

    foreach ($data as $item) {
        if (!is_array($item)) {
            continue;
        }

        // The IDs must be positive.
        $id = filter_var(
            $item['id'] ?? null,
            FILTER_VALIDATE_INT
        );

        if ($id === false || $id === null || $id < 1) {
            continue;
        }

        $item['id'] = $id;
        $item['date'] = (string) ($item['date'] ?? '');
        $item['title'] = (string) ($item['title'] ?? '');
        $item['blocks'] = is_array($item['blocks'] ?? null)
            ? $item['blocks']
            : [];

        $newsList[] = $item;
    }

    return $newsList;
}

/**
 * Converts a block stored as a string or array
 * to the syntax that Geometry Dash expects in news.dat.
 */
function newsNormalizeBlock($block): string
{
    // The blocks that are already encoded are preserved.
    if (is_string($block)) {
        if (
            preg_match('/^[012],/', $block) !== 1
        ) {
            throw new RuntimeException(
                'An incompatible news block was found.'
            );
        }

        return $block;
    }

    // Compatibility with structured JSON blocks.
    if (!is_array($block)) {
        throw new RuntimeException(
            'The news block is invalid.'
        );
    }

    $type = (string) ($block['type'] ?? '');

    if ($type === 'text') {
        return newsText((string) ($block['text'] ?? ''));
    }

    if ($type === 'link') {
        return newsLink(
            (string) ($block['label'] ?? ''),
            (string) ($block['url'] ?? '')
        );
    }

    if ($type === 'image') {
        return newsImage(
            (string) ($block['filename'] ?? ''),
            (int) ($block['width'] ?? 0),
            (int) ($block['height'] ?? 0),
            (int) ($block['indicator'] ?? 0),
            (string) ($block['url'] ?? '')
        );
    }

    throw new RuntimeException(
        'Unknown news block type.'
    );
}

/**
 * Sort the news from newest to oldest.
 * It does not modify the ID of any news item.
 */
function newsSort(array &$newsList): void
{
    usort(
        $newsList,
        static function (array $a, array $b): int {
            return (int) $b['id'] <=> (int) $a['id'];
        }
    );
}

/**
 * Generates the uncompressed content of news.dat.
 *
 * Example:
 * 5,9|1:9;2:...;3:...;4:0,...|1:8;2:...;3:...;4:0,...
 */
function newsEncodeRaw(array $newsList): string
{
    if (!$newsList) {
        return '5,0';
    }

    // Descending order by ID.
    newsSort($newsList);

    $records = [];
    $usedIDs = [];

    foreach ($newsList as $item) {
        $id = (int) ($item['id'] ?? 0);

        if ($id < 1) {
            throw new RuntimeException(
                'An invalid news ID was found.'
            );
        }

        if (isset($usedIDs[$id])) {
            throw new RuntimeException(
                'There are duplicate news IDs: ' . $id
            );
        }

        $usedIDs[$id] = true;

        $date = (string) ($item['date'] ?? '');
        $title = (string) ($item['title'] ?? '');
        $blocks = $item['blocks'] ?? [];

        if (!is_array($blocks)) {
            throw new RuntimeException(
                'The news blocks #' . $id . ' They are not valid.'
            );
        }

        $encodedBlocks = [];

foreach ($blocks as $block) {
    // Encode the block without splitting the line breaks.
    $normalized = newsNormalizeBlock($block);

    // Validate links of the format 2,label,url.
    if (str_starts_with($normalized, '2,')) {
        $parts = explode(',', $normalized, 3);

        if (count($parts) !== 3) {
            continue;
        }

        $label = base64_decode($parts[1], true);
        $url = base64_decode($parts[2], true);

        if (
            $label === false ||
            $url === false ||
            trim($label) === '' ||
            trim($url) === ''
        ) {
            continue;
        }

        $scheme = strtolower(
            (string) parse_url($url, PHP_URL_SCHEME)
        );

        if (
            !filter_var($url, FILTER_VALIDATE_URL) ||
            !in_array($scheme, ['http', 'https'], true)
        ) {
            continue;
        }
    }

    $encodedBlocks[] = $normalized;
}

        // Do not leave the content field empty.
        if (!$encodedBlocks) {
            $encodedBlocks[] = newsText('');
        }

        $records[] =
            '1:' . $id
            . ';2:' . base64_encode($date)
            . ';3:' . base64_encode($title)
            . ';4:' . implode('~', $encodedBlocks);
    }

    // The header uses the highest ID.
    $latestID = max(array_keys($usedIDs));

    return '5,' . $latestID . '|' . implode('|', $records);
}

/**
 * Generate the complete DAT file:
 * content -> GZIP -> Base64 URL-safe without padding.
 */
function newsEncodeDat(array $newsList): string
{
    $raw = newsEncodeRaw($newsList);

    $compressed = gzencode($raw, 9, FORCE_GZIP);

    if ($compressed === false) {
        throw new RuntimeException(
            'News.dat could not be compressed with GZIP.'
        );
    }

    return newsBase64UrlEncode($compressed);
}

/**
 * Save the JSON and generate the DAT with all the news.
 *
 * The two files are prepared before replacing the
* existing files.
 */
function saveNewsList(
    array $newsList,
    string $jsonPath,
    string $datPath
): void {
    $jsonDir = dirname($jsonPath);
    $datDir = dirname($datPath);

    if (!is_dir($jsonDir) || !is_dir($datDir)) {
        throw new RuntimeException(
            'There is no news directory.'
        );
    }

    // Validate IDs and generate the DAT before writing.
    $ids = [];

    foreach ($newsList as $item) {
        if (!is_array($item)) {
            throw new RuntimeException(
                'The list contains an invalid news item.'
            );
        }

        $id = filter_var(
            $item['id'] ?? null,
            FILTER_VALIDATE_INT
        );

        if ($id === false || $id === null || $id < 1) {
            throw new RuntimeException(
                'Invalid news ID.'
            );
        }

        if (isset($ids[$id])) {
            throw new RuntimeException(
                'Duplicate news ID: ' . $id
            );
        }

        $ids[$id] = true;
    }

    $datContent = newsEncodeDat($newsList);

    // JSON in descending order as well.
    newsSort($newsList);

    $jsonContent = json_encode(
        array_values($newsList),
        JSON_PRETTY_PRINT
        | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_THROW_ON_ERROR
    ) . PHP_EOL;

    $jsonTemp = tempnam($jsonDir, '.news-json-');
    $datTemp = tempnam($datDir, '.news-dat-');

    if ($jsonTemp === false || $datTemp === false) {
        if (is_string($jsonTemp)) {
            @unlink($jsonTemp);
        }

        if (is_string($datTemp)) {
            @unlink($datTemp);
        }

        throw new RuntimeException(
            'The temporary files could not be prepared.'
        );
    }

    try {
        if (
            file_put_contents(
                $jsonTemp,
                $jsonContent,
                LOCK_EX
            ) === false
        ) {
            throw new RuntimeException(
                'The temporary JSON could not be written.'
            );
        }

        if (
            file_put_contents(
                $datTemp,
                $datContent,
                LOCK_EX
            ) === false
        ) {
            throw new RuntimeException(
                'The temporary DAT file could not be written.'
            );
        }

        // Reemplazar los archivos existentes.
        if (!rename($jsonTemp, $jsonPath)) {
            throw new RuntimeException(
                'The news-source.json file could not be updated.'
            );
        }

        if (!rename($datTemp, $datPath)) {
            throw new RuntimeException(
                'The JSON was saved, but news.dat could not be replaced.'
            );
        }
    } finally {
        if (is_file($jsonTemp)) {
            @unlink($jsonTemp);
        }

        if (is_file($datTemp)) {
            @unlink($datTemp);
        }
    }
}

/**
 * Import a news.dat file already compressed in Base64 URL-safe.
 *
 * Returns records with blocks in their original format.
 * It is used for diagnosis or migration; it is not necessary
 * Call it when creating news from the panel.
 */
function newsImportDat(string $encoded): array
{
    $compressed = newsBase64UrlDecode(trim($encoded));

    if ($compressed === false) {
        throw new RuntimeException(
            'The Base64 of news.dat is not valid.'
        );
    }

    $raw = gzdecode($compressed);

    if ($raw === false) {
        throw new RuntimeException(
            'The news.dat file could not be decompressed.'
        );
    }

    $parts = explode('|', $raw);

    if (count($parts) < 1 ||
        preg_match('/^5,(\d+)$/', $parts[0], $header) !== 1) {
        throw new RuntimeException(
            'The header of news.dat is invalid.'
        );
    }

    $result = [];

    foreach (array_slice($parts, 1) as $record) {
        if ($record === '') {
            continue;
        }

        $fields = [];

        foreach (explode(';', $record) as $field) {
            $position = strpos($field, ':');

            if ($position === false) {
                continue;
            }

            $key = substr($field, 0, $position);
            $value = substr($field, $position + 1);

            $fields[$key] = $value;
        }

        $id = filter_var(
            $fields['1'] ?? null,
            FILTER_VALIDATE_INT
        );

        if ($id === false || $id === null || $id < 1) {
            continue;
        }

        $date = base64_decode($fields['2'] ?? '', true);
        $title = base64_decode($fields['3'] ?? '', true);

        $result[] = [
            'id' => $id,
            'date' => $date === false ? '' : $date,
            'title' => $title === false ? '' : $title,
            'blocks' => isset($fields['4'])
                ? explode('~', $fields['4'])
                : []
        ];
    }

    newsSort($result);

    return $result;
}
