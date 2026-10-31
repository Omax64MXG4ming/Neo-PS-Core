<?php
declare(strict_types=1);

// generateNews.php
require_once __DIR__ . '/newsLib.php';

$datPath = __DIR__ . '/news.dat';
$jsonPath = __DIR__ . '/../data/news-source.json';

try {
    // Always load the updated list.
    $newsList = loadNewsList($jsonPath);

    // Generate the DAT file with all the news items.
    // newsEncodeDat sorts by descending ID and encodes
    // Content with GZIP + Base64 URL-safe.
    $dat = newsEncodeDat($newsList);

    if ($dat === '') {
        throw new RuntimeException(
            'The generator returned an empty file.'
        );
    }

    // Save the updated file.
    if (
        file_put_contents(
            $datPath,
            $dat,
            LOCK_EX
        ) === false
    ) {
        throw new RuntimeException(
            'News.dat could not be updated.'
        );
    }

    // Respond with the content of the DAT file, not with JSON.
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo $dat;
    exit;

} catch (Throwable $e) {
    error_log(
        'Neo PS generateNews.php: ' . $e->getMessage()
    );

    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');

    echo 'News.dat could not be generated.';
    exit;
}
