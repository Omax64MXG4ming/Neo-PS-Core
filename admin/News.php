<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/dashboard.php';
require_once __DIR__ . '/../news/newsLib.php';
require_once __DIR__ . '/../incl/lib/connection.php';

if (!isset($db) || !($db instanceof PDO)) {
    http_response_code(500);
    exit('Error: PDO connection not found.');
}

$accountID = (int)($_SESSION['accountID'] ?? 0);

if ($accountID < 1) {
    http_response_code(401);
    exit('Log in to GDPS before entering the panel.');
}

$stmt = $db->prepare(
    'SELECT isAdmin FROM accounts WHERE accountID = ? LIMIT 1'
);

$stmt->execute([$accountID]);
$account = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$account || (int)$account['isAdmin'] !== 1) {
    http_response_code(403);
    exit('You do not have permission to manage the news.');
}

if (empty($_SESSION['news_admin_csrf'])) {
    $_SESSION['news_admin_csrf'] = bin2hex(random_bytes(32));
}

$jsonPath = __DIR__ . '/../data/news-source.json';
$datPath = __DIR__ . '/../news/news.dat';
$versionPath = __DIR__ . '/../news/news_version.txt';
$imageDir = __DIR__ . '/../news';
$imageBaseUrl = 'https://neops.x10.mx/database/news/';

$message = '';
$error = '';
$title = '';
$date = date('F j, Y');
$editorBlocks = [
    ['type' => 'text', 'text' => '']
];

$editID = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);
$editID = ($editID !== false && $editID !== null && $editID > 0)
    ? $editID
    : 0;

/* -------------------------------------------------------
   Utilities
------------------------------------------------------- */

function newsAdminEscape($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function newsAdminIncrementVersion(string $path): void
{
    $version = is_file($path)
        ? (int)trim((string)file_get_contents($path))
        : 0;

    if (
        file_put_contents(
            $path,
            (string)($version + 1),
            LOCK_EX
        ) === false
    ) {
        throw new RuntimeException(
            'The news was saved, but the version could not be updated.'
        );
    }
}
/**
 * Interprets existing blocks to display them in the editor.
 * It maintains the original order of text, image, and link.
 */
function newsAdminDecodeBlock(string $block): array
{
    $parts = explode(',', $block, 6);
    $type = $parts[0] ?? '';

    if ($type === '0' && isset($parts[1])) {
        $decoded = base64_decode($parts[1], true);

        return [
            'type' => 'text',
            'text' => $decoded === false ? '' : $decoded
        ];
    }
    if ($type === '2' && isset($parts[1], $parts[2])) {
        $label = base64_decode($parts[1], true);
        $url = base64_decode($parts[2], true);

        return [
            'type' => 'link',
            'label' => $label === false ? '' : $label,
            'url' => $url === false ? '' : $url
        ];
    }

    if ($type === '1' && count($parts) === 6) {
        $destination = '';

        if (
            $parts[5] !== '' &&
            $parts[5] !== '0' &&
            $parts[5] !== 'MA=='
        ) {
            $decoded = base64_decode($parts[5], true);
            $destination = $decoded === false ? '' : $decoded;
        }

        return [
            'type' => 'image',
            'filename' => basename($parts[1]),
            'width' => (int)$parts[2],
            'height' => (int)$parts[3],
            'indicator' => (int)$parts[4],
            'url' => $destination
        ];
    }

    return ['type' => 'text', 'text' => ''];
}

function newsAdminEditorBlocks(array $item): array
{
    $result = [];

    foreach (($item['blocks'] ?? []) as $block) {
        if (is_array($block)) {
            $type = (string)($block['type'] ?? '');

            if ($type === 'text') {
                $result[] = [
                    'type' => 'text',
                    'text' => (string)($block['text'] ?? '')
                ];
            } elseif ($type === 'link') {
                $result[] = [
                    'type' => 'link',
                    'label' => (string)($block['label'] ?? ''),
                    'url' => (string)($block['url'] ?? '')
                ];
            } elseif ($type === 'image') {
                $result[] = [
                    'type' => 'image',
                    'filename' => basename(
                        (string)($block['filename'] ?? '')
                    ),
                    'width' => (int)($block['width'] ?? 0),
                    'height' => (int)($block['height'] ?? 0),
                    'indicator' => (int)($block['indicator'] ?? 0),
                    'url' => (string)($block['url'] ?? '')
                ];
            }
            continue;
        }
        if (is_string($block)) {
            $decoded = newsAdminDecodeBlock($block);

            // Do not create empty blocks for unknown formats.
            if (in_array(
                $decoded['type'] ?? '',
                ['text', 'link', 'image'],
                true
            )) {
                $result[] = $decoded;
            }
        }
    }

    return $result ?: [['type' => 'text', 'text' => '']];
}

function newsAdminImageUrl(string $filename): string
{
    return $filename !== ''
        ? 'https://neops.x10.mx/database/news/' .
            rawurlencode(basename($filename))
        : '';
}

/**
 * Returns the uploaded file for a specific block.
 */
function newsAdminUploadedFile(int $index): array
{
    if (
        !isset($_FILES['blocks']) ||
        !is_array($_FILES['blocks']['name'] ?? null) ||
        !isset($_FILES['blocks']['name'][$index]['image'])
    ) {
        return ['error' => UPLOAD_ERR_NO_FILE];
    }

    $file = [];

    foreach (
        ['name', 'type', 'tmp_name', 'error', 'size'] as $key
    ) {
        $file[$key] = $_FILES['blocks'][$key][$index]['image']
            ?? ($key === 'error'
                ? UPLOAD_ERR_NO_FILE
                : '');
    }

    return $file;
}

/**
 * Upload a JPG/PNG image and generate the Geometry Dash block.
 */
function newsAdminUploadImage(
    array $file,
    int $newsID,
    string $imageDir,
    int $indicator,
    string $destinationUrl
): string {
    $errorCode = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($errorCode === UPLOAD_ERR_NO_FILE) {
        return '';
    }

    if ($errorCode !== UPLOAD_ERR_OK) {
        throw new RuntimeException('An image could not be uploaded.');
    }

    if (
        !isset($file['tmp_name'], $file['size']) ||
        (int)$file['size'] < 1 ||
        (int)$file['size'] > 5 * 1024 * 1024 ||
        !is_uploaded_file((string)$file['tmp_name'])
    ) {
        throw new RuntimeException(
            'The image must be valid and weigh a maximum of 5 MiB.'
        );
    }

    $info = @getimagesize((string)$file['tmp_name']);

    if ($info === false) {
        throw new RuntimeException('The file is not a valid image.');
    }

    $mime = (string)($info['mime'] ?? '');

    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png'
    ];

    if (!isset($extensions[$mime])) {
        throw new RuntimeException('Only JPG or PNG images are accepted.');
    }

    $width = (int)$info[0];
    $height = (int)$info[1];

    if (
        $width < 1 || $height < 1 ||
        $width > 4096 || $height > 4096
    ) {
        throw new RuntimeException(
            'The image cannot exceed 4096 × 4096 pixels.'
        );
    }

    if (
        !is_dir($imageDir) &&
        !mkdir($imageDir, 0755, true) &&
        !is_dir($imageDir)
    ) {
        throw new RuntimeException(
            'The image folder could not be created.'
        );
    }

    $filename = 'news_' . $newsID . '_' .
        bin2hex(random_bytes(6)) . '.' . $extensions[$mime];

    $destination = $imageDir . '/' . $filename;

    if (!move_uploaded_file((string)$file['tmp_name'], $destination)) {
        throw new RuntimeException('The image could not be saved.');
    }

    /*
     * If indicator = 1, the dimensions of the DAT block will be
     * 800 × 454 using newsImage()
     */
    return newsImage(
        $filename,
        $width,
        $height,
        $indicator,
        $destinationUrl
    );
}

/* -------------------------------------------------------
   WebHook: only runs for new news
------------------------------------------------------- */

function sendNewsCreatedWebhook(
    int $newsID,
    string $title,
    string $date,
    int $accountID
): bool {
    $file = __DIR__ . '/../config/news-webhook.php';

    if (!is_file($file)) {
        error_log('GDPS News: news-webhook.php missing');
        return false;
    }

    $url = require $file;
    $host = strtolower((string)parse_url(
        is_string($url) ? $url : '',
        PHP_URL_HOST
    ));

    if (
        !is_string($url) ||
        !filter_var($url, FILTER_VALIDATE_URL) ||
        strtolower((string)parse_url($url, PHP_URL_SCHEME)) !== 'https' ||
        !in_array($host, ['discord.com', 'discordapp.com'], true)
    ) {
        error_log('GDPS News: Invalid webhook URL');
        return false;
    }

    $payload = [
        'username' => 'GDPS | News Logs',
        'allowed_mentions' => ['parse' => []],
        'embeds' => [[
            'title' => '📰 New News Published',
            'description' => 'A new message has been sent to the game.',
            'color' => 0x1672DC,
            'fields' => [
                [
                    'name' => 'ID',
                    'value' => (string)$newsID,
                    'inline' => true
                ],
                [
                    'name' => 'Title',
                    'value' => mb_substr($title, 0, 200),
                    'inline' => false
                ],
                [
                    'name' => 'Publication Date',
                    'value' => mb_substr($date, 0, 100),
                    'inline' => true
                ],
                [
                    'name' => 'Account ID',
                    'value' => (string)$accountID,
                    'inline' => true
                ]
            ],
            'footer' => ['text' => 'GDPS • News System'],
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z')
        ]]
    ];

    $json = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    if ($json === false) {
        return false;
    }

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 5
    ]);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($response === false) {
        error_log('GDPS News: error cURL: ' . curl_error($ch));
    }

    curl_close($ch);

    return $response !== false && $httpCode >= 200 && $httpCode < 300;
}

/* -------------------------------------------------------
   Cargar noticias
------------------------------------------------------- */

try {
    $newsList = loadNewsList($jsonPath);
} catch (Throwable $e) {
    http_response_code(500);
    exit('The news log could not be read.');
}

if ($editID > 0) {
    $found = false;

    foreach ($newsList as $item) {
        if ((int)$item['id'] === $editID) {
            $found = true;
            $title = (string)($item['title'] ?? '');
            $date = (string)($item['date'] ?? date('F j, Y'));
            $editorBlocks = newsAdminEditorBlocks($item);
            break;
        }
    }

    if (!$found) {
        $editID = 0;
        $error = 'The requested news item does not exist.';
    }
}

/* -------------------------------------------------------
   Procesar formularios
------------------------------------------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $csrf = (string)($_POST['csrf'] ?? '');

        if (!hash_equals((string)$_SESSION['news_admin_csrf'], $csrf)) {
            throw new RuntimeException(
                'Invalid form session. Please reload the page.'
            );
        }

        $action = (string)($_POST['action'] ?? 'create');

        if ($action === 'delete') {
            $deleteID = filter_var(
                $_POST['id'] ?? null,
                FILTER_VALIDATE_INT
            );

            if (!$deleteID || $deleteID < 1) {
                throw new RuntimeException('Invalid news ID.');
            }

            $newList = array_values(array_filter(
                $newsList,
                static fn(array $item): bool =>
                    (int)($item['id'] ?? 0) !== $deleteID
            ));

            if (count($newList) === count($newsList)) {
                throw new RuntimeException('The news does not exist.');
            }

            saveNewsList($newList, $jsonPath, $datPath);
            newsAdminIncrementVersion($versionPath);

            $_SESSION['news_admin_csrf'] = bin2hex(random_bytes(32));

            header(
                'Location: ' . strtok($_SERVER['REQUEST_URI'], '?') .
                '?status=deleted'
            );
            exit;
        }

        if (!in_array($action, ['create', 'update'], true)) {
            throw new RuntimeException('Invalid action.');
        }

        $title = trim((string)($_POST['title'] ?? ''));
        $date = trim((string)($_POST['date'] ?? ''));

        if ($title === '' || strlen($title) > 200) {
            throw new RuntimeException(
                'The title is mandatory and must not exceed 200 characters.'
            );
        }

        if ($date === '') {
            $date = date('F j, Y');
        }

        if (strlen($date) > 100) {
            throw new RuntimeException('The date is too far off.');
        }

        $updateID = 0;

        if ($action === 'update') {
            $updateID = filter_var(
                $_POST['id'] ?? null,
                FILTER_VALIDATE_INT
            );

            if (!$updateID || $updateID < 1) {
                throw new RuntimeException('Invalid news ID.');
            }

            $exists = false;

            foreach ($newsList as $item) {
                if ((int)$item['id'] === $updateID) {
                    $exists = true;
                    break;
                }
            }

            if (!$exists) {
                throw new RuntimeException('The news doesnt exist.');
            }

            $savedID = $updateID;
        } else {
            $ids = array_map(
                static fn(array $item): int => (int)($item['id'] ?? 0),
                $newsList
            );
            $savedID = $ids ? max($ids) + 1 : 1;
        }

        $postedBlocks = $_POST['blocks'] ?? [];

        if (!is_array($postedBlocks) || count($postedBlocks) > 50) {
            throw new RuntimeException(
                'The block list is invalid or exceeds the limit of 50.'
            );
        }

        ksort($postedBlocks, SORT_NUMERIC);
        $blocks = [];

        foreach ($postedBlocks as $index => $block) {
            if (!is_array($block)) {
                continue;
            }

            $type = (string)($block['type'] ?? '');

if ($type === 'text') {
    $text = (string)($block['text'] ?? '');

    if ($text !== '') {
        $blocks[] = newsText($text);
    }

    continue;
}

            if ($type === 'link') {
                $label = trim((string)($block['label'] ?? ''));
                $url = trim((string)($block['url'] ?? ''));

                if ($label === '' && $url === '') {
                    continue;
                }

                $scheme = strtolower(
                    (string)parse_url($url, PHP_URL_SCHEME)
                );

                if (
                    $label === '' ||
                    strlen($label) > 200 ||
                    strlen($url) > 2048 ||
                    !filter_var($url, FILTER_VALIDATE_URL) ||
                    !in_array($scheme, ['http', 'https'], true)
                ) {
                    throw new RuntimeException(
                        'There is an incomplete or invalid link.'
                    );
                }

               $blocks[] = newsLink($label, $url);

continue;
            }

            if ($type === 'image') {
                $indicator = (int)($block['indicator'] ?? 0);

                if (!in_array($indicator, [0, 1], true)) {
                    throw new RuntimeException(
                        'Invalid image indicator.'
                    );
                }

                $destinationUrl = trim(
                    (string)($block['url'] ?? '')
                );

                if (
                    $destinationUrl !== '' &&
                    (
                        !filter_var($destinationUrl, FILTER_VALIDATE_URL) ||
                        !in_array(
                            strtolower((string)parse_url(
                                $destinationUrl,
                                PHP_URL_SCHEME
                            )),
                            ['http', 'https'],
                            true
                        )
                    )
                ) {
                    throw new RuntimeException(
                        'The destination URL for an image is invalid.'
                    );
                }

                $file = newsAdminUploadedFile((int)$index);

                $imageBlock = newsAdminUploadImage(
                    $file,
                    $savedID,
                    $imageDir,
                    $indicator,
                    $destinationUrl
                );

                if ($imageBlock !== '') {
                    // Save as a coded block, in its position.
                    $blocks[] = $imageBlock;
                    continue;
                }

                // If a new image was not uploaded, keep the existing one.
                $filename = basename(
                    (string)($block['existing_filename'] ?? '')
                );
                $width = (int)($block['existing_width'] ?? 0);
                $height = (int)($block['existing_height'] ?? 0);

                if ($filename !== '' && $width > 0 && $height > 0) {
                    $blocks[] = newsImage(
                        $filename,
                        $width,
                        $height,
                        $indicator,
                        $destinationUrl
                    );
                }

                continue;
            }

            throw new RuntimeException('Unknown block type.');
        }

        if (!$blocks) {
            throw new RuntimeException(
                'Add at least one block of text, image, or link.'
            );
        }

        if ($action === 'update') {
            foreach ($newsList as &$item) {
                if ((int)$item['id'] === $updateID) {
                    $item['date'] = $date;
                    $item['title'] = $title;
                    $item['blocks'] = $blocks;
                    $item['updatedBy'] = $accountID;
                    $item['updatedAt'] = date(DATE_ATOM);
                    break;
                }
            }
            unset($item);

            saveNewsList($newsList, $jsonPath, $datPath);
            newsAdminIncrementVersion($versionPath);

            $_SESSION['news_admin_csrf'] = bin2hex(random_bytes(32));

            header(
                'Location: ' . strtok($_SERVER['REQUEST_URI'], '?') .
                '?status=updated'
            );
            exit;
        }

        $newsList[] = [
            'id' => $savedID,
            'date' => $date,
            'title' => $title,
            'blocks' => $blocks,
            'createdBy' => $accountID,
            'createdAt' => date(DATE_ATOM)
        ];

        saveNewsList($newsList, $jsonPath, $datPath);
        newsAdminIncrementVersion($versionPath);

        // The WebHook only runs when creating new news.
        sendNewsCreatedWebhook($savedID, $title, $date, $accountID);

        $_SESSION['news_admin_csrf'] = bin2hex(random_bytes(32));

        header(
            'Location: ' . strtok($_SERVER['REQUEST_URI'], '?') .
            '?status=created'
        );
        exit;

    } catch (Throwable $e) {
        $error = $e instanceof RuntimeException
            ? $e->getMessage()
            : 'An error occurred while processing the news.';

        // Recover the text if validation fails.
        $title = (string)($_POST['title'] ?? $title);
        $date = (string)($_POST['date'] ?? $date);

        if (is_array($_POST['blocks'] ?? null)) {
            $editorBlocks = [];

            foreach ($_POST['blocks'] as $block) {
                if (!is_array($block)) {
                    continue;
                }

                $type = (string)($block['type'] ?? 'text');

                if ($type === 'text') {
                    $editorBlocks[] = [
                        'type' => 'text',
                        'text' => (string)($block['text'] ?? '')
                    ];
                } elseif ($type === 'link') {
                    $editorBlocks[] = [
                        'type' => 'link',
                        'label' => (string)($block['label'] ?? ''),
                        'url' => (string)($block['url'] ?? '')
                    ];
                } elseif ($type === 'image') {
                    $editorBlocks[] = [
                        'type' => 'image',
                        'filename' => basename(
                            (string)($block['existing_filename'] ?? '')
                        ),
                        'width' => (int)($block['existing_width'] ?? 0),
                        'height' => (int)($block['existing_height'] ?? 0),
                        'indicator' => (int)($block['indicator'] ?? 0),
                        'url' => (string)($block['url'] ?? '')
                    ];
                }
            }

            if (!$editorBlocks) {
                $editorBlocks = [['type' => 'text', 'text' => '']];
            }
        }
    }
}

$statusMessages = [
    'created' => 'News item created and news.dat updated.',
    'updated' => 'News edited and news.dat updated.',
    'deleted' => 'News item removed and news.dat updated.'
];

$status = (string)($_GET['status'] ?? '');

if (isset($statusMessages[$status])) {
    $message = $statusMessages[$status];
}

$version = is_file($versionPath)
    ? (int)trim((string)file_get_contents($versionPath))
    : 0;

/* -------------------------------------------------------
   Prepare news for viewing
------------------------------------------------------- */

$displayList = array_reverse($newsList);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= newsAdminEscape($gdps ?? 'GDPS') ?> | Noticias</title>

<style>
:root {
    --blue: #1672dc;
    --blue-dark: #1058b5;
    --bg: #f1f6fd;
    --text: #193454;
    --muted: #647b98;
    --border: #dce7f5;
    --red: #dc3545;
    --green-bg: #e5f8ed;
    --green: #17683a;
}

* { box-sizing: border-box; }

body {
    margin: 0;
    padding: 18px;
    background: var(--bg);
    color: var(--text);
    font-family: Arial, sans-serif;
    line-height: 1.55;
}

main {
    max-width: 1000px;
    margin: 15px auto;
    padding: 28px;
    background: #fff;
    border: 1px solid #e4edf8;
    border-radius: 18px;
    box-shadow: 0 10px 35px #18467d12;
}

h1 { color: var(--blue-dark); margin-top: 0; }
h2 { margin-top: 28px; }
h3 { margin-top: 0; }

label {
    display: block;
    margin-top: 14px;
    font-size: 14px;
    font-weight: bold;
}

input, textarea, select {
    width: 100%;
    margin-top: 6px;
    padding: 12px;
    border: 1px solid #cad9ed;
    border-radius: 9px;
    background: #fbfdff;
    color: var(--text);
    font: inherit;
}

input:focus, textarea:focus, select:focus {
    outline: 2px solid #a8ceff;
    border-color: var(--blue);
}

textarea {
    min-height: 130px;
    resize: vertical;
}

button, .button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    min-height: 40px;
    padding: 10px 14px;
    margin-top: 8px;
    border: 0;
    border-radius: 9px;
    background: var(--blue);
    color: #fff;
    font: inherit;
    font-weight: bold;
    text-decoration: none;
    cursor: pointer;
}

button:hover, .button:hover { background: var(--blue-dark); }
button:focus-visible, .button:focus-visible {
    outline: 3px solid #a8ceff;
    outline-offset: 2px;
}

.danger { background: var(--red); }
.danger:hover { background: #b92333; }
.secondary { background: #647b98; }
.secondary:hover { background: #475d78; }

.notice {
    padding: 13px 15px;
    margin: 15px 0;
    border-radius: 10px;
    overflow-wrap: anywhere;
}

.success { background: var(--green-bg); color: var(--green); }
.failure { background: #fff0f0; color: #a32735; }

small { color: var(--muted); }

hr {
    margin: 28px 0;
    border: 0;
    border-top: 1px solid var(--border);
}

.block-list {
    display: flex;
    flex-direction: column;
    gap: 14px;
    margin-top: 16px;
}

.editor-block {
    padding: 17px;
    border: 1px solid var(--border);
    border-radius: 13px;
    background: #fbfdff;
    min-width: 0;
}

.block-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    padding-bottom: 10px;
    border-bottom: 1px solid var(--border);
}

.block-title { font-weight: bold; }
.block-tools { display: flex; flex-wrap: wrap; gap: 5px; }
.block-tools button { margin: 0; min-height: 34px; padding: 7px 10px; }
.block-tools .danger { background: var(--red); }

.block-content { padding-top: 8px; }
.block-content[hidden] { display: none; }

.current-image {
    display: block;
    max-width: 100%;
    max-height: 240px;
    margin-top: 12px;
    border: 1px solid var(--border);
    border-radius: 10px;
    object-fit: contain;
}

.add-tools {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 14px;
    padding: 14px;
    background: #f3f7fd;
    border-radius: 12px;
}

.add-tools button { margin: 0; }

.news-item {
    padding: 18px;
    margin: 14px 0;
    border: 1px solid var(--border);
    border-radius: 13px;
    overflow-wrap: anywhere;
}

.news-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 12px;
}

.news-actions form { margin: 0; }

.news-actions button, .news-actions .button { margin: 0; }

@media (max-width: 640px) {
    body { padding: 9px; }
    main { padding: 17px 13px; margin: 5px auto; }
    .editor-block { padding: 12px; }
    .block-header { align-items: flex-start; }
    .block-tools button { min-height: 40px; }
    .add-tools button { flex: 1 1 120px; }
    .news-actions > * { flex: 1 1 120px; }
    .news-actions form { display: flex; }
    .news-actions form button, .news-actions > .button { width: 100%; }
}
</style>
</head>

<body>
<main>
    <h1>GDPS — Noticias</h1>
    <p>Agrega texto, imágenes y enlaces, y ordénalos antes de publicar.</p>

    <?php if ($message !== ''): ?>
        <div class="notice success"><?= newsAdminEscape($message) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="notice failure"><?= newsAdminEscape($error) ?></div>
    <?php endif; ?>

    <h2><?= $editID > 0
        ? 'Editar noticia #' . $editID
        : 'Crear noticia'
    ?></h2>

    <form method="post" enctype="multipart/form-data" id="news-form">
        <input type="hidden" name="csrf"
               value="<?= newsAdminEscape($_SESSION['news_admin_csrf']) ?>">

        <input type="hidden" name="action"
               value="<?= $editID > 0 ? 'update' : 'create' ?>">

        <?php if ($editID > 0): ?>
            <input type="hidden" name="id" value="<?= $editID ?>">
        <?php endif; ?>

        <label for="title">Título</label>
        <input id="title" name="title" maxlength="200" required
               value="<?= newsAdminEscape($title) ?>">

        <label for="date">Fecha</label>
        <input id="date" name="date" maxlength="100"
               value="<?= newsAdminEscape($date) ?>">

        <h3 style="margin-top:25px">Contenido de la noticia</h3>
        <p><small>Usa ↑ y ↓ para cambiar el orden. El orden aquí será el orden del news.dat.</small></p>

        <div id="block-list" class="block-list">
            <?php foreach ($editorBlocks as $index => $block): ?>
                <?php
                $type = (string)($block['type'] ?? 'text');
                $filename = basename((string)($block['filename'] ?? ''));
                $width = (int)($block['width'] ?? 0);
                $height = (int)($block['height'] ?? 0);
                $indicator = (int)($block['indicator'] ?? 0);
                $url = (string)($block['url'] ?? '');
                ?>
                <section class="editor-block" data-type="<?= newsAdminEscape($type) ?>">
                    <div class="block-header">
                        <span class="block-title"></span>
                        <div class="block-tools">
                            <button type="button" class="move-up" aria-label="Mover arriba">↑</button>
                            <button type="button" class="move-down" aria-label="Mover abajo">↓</button>
                            <button type="button" class="remove-block danger">Eliminar</button>
                        </div>
                    </div>

                    <div class="block-content">
                        <input type="hidden" class="block-type"
                               name="blocks[<?= $index ?>][type]"
                               value="<?= newsAdminEscape($type) ?>">

                        <?php if ($type === 'text'): ?>
                            <label>Texto</label>
                            <textarea name="blocks[<?= $index ?>][text]"
                                      maxlength="10000"
                                      placeholder="Escribe el contenido..."><?= newsAdminEscape($block['text'] ?? '') ?></textarea>

                        <?php elseif ($type === 'link'): ?>
                            <label>Texto del enlace</label>
                            <input name="blocks[<?= $index ?>][label]"
                                   maxlength="200"
                                   value="<?= newsAdminEscape($block['label'] ?? '') ?>"
                                   placeholder="Visitar página">

                            <label>URL del enlace</label>
                            <input name="blocks[<?= $index ?>][url]"
                                   type="url" maxlength="2048"
                                   value="<?= newsAdminEscape($block['url'] ?? '') ?>"
                                   placeholder="https://...">

                        <?php elseif ($type === 'image'): ?>
                            <input type="hidden"
                                   name="blocks[<?= $index ?>][existing_filename]"
                                   value="<?= newsAdminEscape($filename) ?>">

                            <input type="hidden"
                                   name="blocks[<?= $index ?>][existing_width]"
                                   value="<?= $width ?>">

                            <input type="hidden"
                                   name="blocks[<?= $index ?>][existing_height]"
                                   value="<?= $height ?>">

                            <label>Imagen actual</label>

                            <?php if ($filename !== ''): ?>
                                <img class="current-image"
                                     src="<?= newsAdminEscape(newsAdminImageUrl($filename)) ?>"
                                     alt="Imagen actual">
                            <?php else: ?>
                                <p><small>No hay imagen guardada en este bloque.</small></p>
                            <?php endif; ?>

                            <label>Subir otra imagen (opcional)</label>
                            <input type="file"
                                   name="blocks[<?= $index ?>][image]"
                                   accept="image/jpeg,image/png,.jpg,.jpeg,.png">

                            <label>Indicador de imagen</label>
                            <select name="blocks[<?= $index ?>][indicator]">
                                <option value="0" <?= $indicator === 0 ? 'selected' : '' ?>>0</option>
                                <option value="1" <?= $indicator === 1 ? 'selected' : '' ?>>1 — dimensiones 800 × 454</option>
                            </select>

                            <label>URL de destino (opcional)</label>
                            <input type="url"
                                   name="blocks[<?= $index ?>][url]"
                                   maxlength="2048"
                                   value="<?= newsAdminEscape($url) ?>"
                                   placeholder="https://...">
                            <small>Deja la URL vacía si la imagen no tiene destino.</small>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>

        <div class="add-tools">
            <button type="button" data-add="text">+ Texto</button>
            <button type="button" data-add="image">+ Imagen</button>
            <button type="button" data-add="link">+ Enlace</button>
        </div>

        <button type="submit">
            <?= $editID > 0 ? 'Guardar cambios' : 'Crear noticia' ?>
        </button>

        <?php if ($editID > 0): ?>
            <a class="button secondary"
               href="<?= newsAdminEscape(strtok($_SERVER['REQUEST_URI'], '?')) ?>">
                Cancelar
            </a>
        <?php endif; ?>
    </form>

    <hr>

    <h2>Noticias registradas</h2>

    <?php if (!$displayList): ?>
        <p>Aún no hay noticias registradas.</p>
    <?php endif; ?>

    <?php foreach ($displayList as $item): ?>
        <?php $itemID = (int)$item['id']; ?>
        <section class="news-item">
            <strong>
                #<?= $itemID ?> —
                <?= newsAdminEscape($item['title'] ?? '') ?>
            </strong>
            <br>
            <small><?= newsAdminEscape($item['date'] ?? '') ?></small>

            <div class="news-actions">
                <a class="button" href="?edit=<?= $itemID ?>">Editar</a>

                <form method="post"
                      onsubmit="return confirm('¿Eliminar la noticia #<?= $itemID ?>? Esta acción no se puede deshacer.');">
                    <input type="hidden" name="csrf"
                           value="<?= newsAdminEscape($_SESSION['news_admin_csrf']) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= $itemID ?>">
                    <button type="submit" class="danger">Eliminar</button>
                </form>
            </div>
        </section>
    <?php endforeach; ?>

    <hr>

    <small>
        News Version: <?= $version ?><br>
        Archivo generado: /news/news.dat<br>
        Imágenes: /news/
    </small>
</main>

<script>
(() => {
    const list = document.getElementById('block-list');
    const form = document.getElementById('news-form');

    const templates = {
        text: `
            <label>Texto</label>
            <textarea data-field="text" maxlength="10000"
                placeholder="Escribe el contenido..."></textarea>
        `,
        link: `
            <label>Texto del enlace</label>
            <input data-field="label" maxlength="200"
                placeholder="Visitar página">

            <label>URL del enlace</label>
            <input data-field="url" type="url" maxlength="2048"
                placeholder="https://...">
        `,
        image: `
            <label>Seleccionar imagen</label>
            <input data-field="image" type="file"
                accept="image/jpeg,image/png,.jpg,.jpeg,.png">

            <label>Indicador de imagen</label>
            <select data-field="indicator">
                <option value="0">0</option>
                <option value="1">1 — dimensiones 800 × 454</option>
            </select>

            <label>URL de destino (opcional)</label>
            <input data-field="url" type="url" maxlength="2048"
                placeholder="https://...">
            <small>La imagen se guarda como un bloque independiente.</small>
        `
    };

    function renumber() {
        [...list.querySelectorAll('.editor-block')].forEach((card, index) => {
            const type = card.dataset.type;
            card.querySelector('.block-title').textContent =
                (index + 1) + '. ' +
                ({text: 'Texto', image: 'Imagen', link: 'Enlace'}[type] || type);

            card.querySelector('.block-type').name =
                `blocks[${index}][type]`;

            card.querySelectorAll('[data-field]').forEach(field => {
                field.name = `blocks[${index}][${field.dataset.field}]`;
            });

            card.querySelectorAll('input[name], textarea[name], select[name]')
                .forEach(field => {
                    const match = field.name.match(
                        /^blocks\[\d+\]\[(existing_filename|existing_width|existing_height)\]$/
                    );

                    if (match) {
                        field.name = `blocks[${index}][${match[1]}]`;
                    }
                });

            card.querySelector('.move-up').disabled = index === 0;
            card.querySelector('.move-down').disabled =
                index === list.children.length - 1;
        });
    }

    function addBlock(type, values = {}) {
        if (!templates[type]) return;

        if (list.children.length >= 50) {
            alert('Puedes añadir un máximo de 50 bloques por noticia.');
            return;
        }

        const card = document.createElement('section');
        card.className = 'editor-block';
        card.dataset.type = type;

        card.innerHTML = `
            <div class="block-header">
                <span class="block-title"></span>
                <div class="block-tools">
                    <button type="button" class="move-up" aria-label="Mover arriba">↑</button>
                    <button type="button" class="move-down" aria-label="Mover abajo">↓</button>
                    <button type="button" class="remove-block danger">Eliminar</button>
                </div>
            </div>
            <div class="block-content">
                <input type="hidden" class="block-type" value="${type}">
                ${templates[type]}
            </div>
        `;

        card.querySelectorAll('[data-field]').forEach(field => {
            const key = field.dataset.field;
            if (key === 'image') return;

            if (values[key] !== undefined) {
                field.value = values[key];
            }
        });

        list.appendChild(card);
        renumber();
    }

    list.addEventListener('click', event => {
        const button = event.target.closest('button');
        if (!button) return;

        const card = button.closest('.editor-block');
        if (!card) return;

        if (button.classList.contains('remove-block')) {
            card.remove();

            if (list.children.length === 0) {
                addBlock('text');
            }

            renumber();
        } else if (button.classList.contains('move-up')) {
            const previous = card.previousElementSibling;
            if (previous) {
                list.insertBefore(card, previous);
                renumber();
            }
        } else if (button.classList.contains('move-down')) {
            const next = card.nextElementSibling;
            if (next) {
                list.insertBefore(next, card);
                renumber();
            }
        }
    });

    document.querySelectorAll('[data-add]').forEach(button => {
        button.addEventListener('click', () => {
            addBlock(button.dataset.add);
        });
    });

    form.addEventListener('submit', () => {
        renumber();
    });

    renumber();
})();
</script>
</body>
</html>
