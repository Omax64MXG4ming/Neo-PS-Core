<?php
declare(strict_types=1);

/*
 * ============================================================
 * NEO PS - STANDALONE LEVEL REUPLOAD
 * ============================================================
 *
 * Flujo:
 *
 * 1. Check Dashboard session
 * 2. If it doesn't exist, try checking with the existing one from 
 *    the cookie by "auth".
 * 3. check that the account exists
 * 4. Check if Re-Upload is blocked
 * 5. check rate-limit
 * 6. check CSRF
 * 7. check CAPTCHA.
 * 8. receive geometry dash levelID
 * 9. consult to the external Worker/API
 * 10. Validates response
 * 11. decodes Base64 / gzip / zlib when it responded
 * 12. check if that level is already Re-uploaded.
 * 13. Insert the level into the GDPS
 * 14. Saves the level data on /data/levels/<NEW_ID>.
 * 15. Sends Validation for log/webhook/Automod.
 * 16. Redirect from POST/Redirect/GET.
 *
 * IMPORTANTE:
 *
 * The Worker/API is responsible for the processing/API of the level

* and the integration with /data/levels according to 
the current Neo PS architecture
 *
 * ============================================================
 */


/*
 * ============================================================
 * SESSION
 * ============================================================
 */

session_start();


/*
 * ============================================================
 * CONFIGURATION
 * ============================================================
 */

const REUPLOAD_API =
    'https://.hid54190.workers.dev/levels/';

const ORIGINAL_SERVER =
    '.hid54190.workers.dev';

const CURL_CONNECT_TIMEOUT =
    5;

const CURL_TIMEOUT =
    20;

const MAX_API_RESPONSE =
    30 * 1024 * 1024;

const MAX_LEVEL_DATA =
    25 * 1024 * 1024;


/*
 * Rate limit.
 *
 * 5 reupload attempts every 10 minutes.
 *
 * This is intentionally conservative.
 * You can change these values later.
 */
const REUPLOAD_RATE_LIMIT =
    5;

const REUPLOAD_RATE_WINDOW =
    600;


/*
 * ============================================================
 * NEO PS LIBRARIES
 * ============================================================
 */

require_once __DIR__ . '/../../incl/lib/connection.php';
require_once __DIR__ . '/../../incl/lib/mainLib.php';
require_once __DIR__ . '/../../incl/lib/Captcha.php';
require_once __DIR__ . '/../../incl/lib/exploitPatch.php';
require_once __DIR__ . '/../../incl/lib/XORCipher.php';
require_once __DIR__ . '/../../incl/lib/automod.php';
require_once __DIR__ . '/../../config/security.php';


/*
 * ============================================================
 * MAIN LIB
 * ============================================================
 */

$gs =
    new mainLib();


/*
 * ============================================================
 * HELPERS
 * ============================================================
 */


/*
 * HTML escape.
 */
function h($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}


/*
 * Safely obtain a level field.
 */
function lv(
    array $data,
    string $key,
    $default = ''
) {
    return array_key_exists(
        $key,
        $data
    )
        ? $data[$key]
        : $default;
}


/*
 * Current page.
 */
function currentPage(): string
{
    return $_SERVER['PHP_SELF']
        ?? 'levelReupload.php';
}


/*
 * Current IP.
 *
 * Do not trust forwarded headers here.
 */
function currentIP(): string
{
    return (string)(
        $_SERVER['REMOTE_ADDR']
        ?? ''
    );
}


/*
 * ============================================================
 * AUTHENTICATION
 * ============================================================
 *
 * Compatible with the Dashboard login:
 *
 * $_SESSION["accountID"]
 *
 * and:
 *
 * cookie "auth"
 *
 * The cookie contains the random value stored in:
 *
 * accounts.auth
 *
 * ============================================================
 */

function getAuthenticatedAccount(
    PDO $db
): int {

    /*
     * --------------------------------------------------------
     * SESSION
     * --------------------------------------------------------
     */

    if(
        isset($_SESSION['accountID']) &&
        (int)$_SESSION['accountID'] > 0
    ) {

        $accountID =
            (int)$_SESSION['accountID'];


        /*
         * Verify the account still exists.
         */
        $query =
            $db->prepare(
                "
                SELECT
                    accountID,
                    isActive
                FROM accounts
                WHERE accountID = :id
                LIMIT 1
                "
            );


        $query->execute([
            ':id' =>
                $accountID
        ]);


        $account =
            $query->fetch(
                PDO::FETCH_ASSOC
            );


        if(
            $account &&
            (int)$account['accountID'] > 0
        ) {

            /*
             * A disabled account should not
             * be allowed to use the page.
             *
             * Do not enforce isActive here if
             * your Neo PS allows preactivation accounts
             * to use authenticated dashboard pages.
             *
             * The Dashboard login itself handles this.
             */
            return $accountID;
        }


        unset(
            $_SESSION['accountID']
        );
    }


    /*
     * --------------------------------------------------------
     * PERSISTENT AUTH COOKIE
     * --------------------------------------------------------
     */

    $auth =
        isset($_COOKIE['auth'])
        ? (string)$_COOKIE['auth']
        : '';


    if(
        $auth === ''
    ) {

        return 0;

    }


    /*
     * Prevent unreasonable cookie values.
     */
    if(
        strlen($auth) > 255
    ) {

        return 0;

    }


    $query =
        $db->prepare(
            "
            SELECT
                accountID,
                isActive
            FROM accounts
            WHERE auth = :auth
            LIMIT 1
            "
        );


    $query->execute([
        ':auth' =>
            $auth
    ]);


    $account =
        $query->fetch(
            PDO::FETCH_ASSOC
        );


    if(
        !$account ||
        (int)$account['accountID'] <= 0
    ) {

        return 0;

    }


    $accountID =
        (int)$account['accountID'];


    /*
     * Recreate the Dashboard session.
     */
    session_regenerate_id(true);

    $_SESSION['accountID'] =
        $accountID;


    return $accountID;
}


/*
 * ============================================================
 * RATE LIMIT
 * ============================================================
 */

function checkReuploadRateLimit(): bool
{
    $now =
        time();


    if(
        !isset(
            $_SESSION['neo_reupload_attempts']
        ) ||
        !is_array(
            $_SESSION['neo_reupload_attempts']
        )
    ) {

        $_SESSION['neo_reupload_attempts'] =
            [];

    }


    $attempts =
        $_SESSION['neo_reupload_attempts'];


    /*
     * Remove old attempts.
     */
    foreach(
        $attempts as $key => $timestamp
    ) {

        if(
            !is_numeric($timestamp) ||
            ((int)$timestamp + REUPLOAD_RATE_WINDOW) <= $now
        ) {

            unset(
                $attempts[$key]
            );

        }

    }


    /*
     * If limit reached, reject.
     */
    if(
        count($attempts) >= REUPLOAD_RATE_LIMIT
    ) {

        $_SESSION['neo_reupload_attempts'] =
            array_values(
                $attempts
            );

        return false;

    }


    /*
     * Register this attempt.
     */
    $attempts[] =
        $now;


    $_SESSION['neo_reupload_attempts'] =
        array_values(
            $attempts
        );


    return true;
}


/*
 * ============================================================
 * PAGE
 * ============================================================
 */

function page(
    string $title,
    string $content
): void {

    $self =
        h(
            currentPage()
        );

    ?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<meta
    name="theme-color"
    content="#1768d1"
>

<title>
    <?= h($title) ?> - Neo PS
</title>

<style>

    * {
        box-sizing: border-box;
    }

    html,
    body {
        margin: 0;
        padding: 0;
        min-height: 100%;
    }

    body {

        min-height: 100vh;

        display: flex;

        align-items: center;

        justify-content: center;

        padding: 20px;

        color: #fff;

        font-family:
            Arial,
            Helvetica,
            sans-serif;

        background:
            linear-gradient(
                180deg,
                #62b5ff 0%,
                #3189e9 42%,
                #174fa5 100%
            );

    }


    .container {

        width: 100%;

        max-width: 650px;

    }


    .card {

        width: 100%;

        padding: 30px;

        border-radius: 22px;

        background:
            rgba(0, 46, 115, .80);

        border:
            2px solid
            rgba(255,255,255,.20);

        box-shadow:

            0 20px 55px
            rgba(0,0,0,.30),

            inset 0 1px
            rgba(255,255,255,.12);

        backdrop-filter:
            blur(8px);

        -webkit-backdrop-filter:
            blur(8px);

    }


    .logo {

        text-align: center;

        margin-bottom: 25px;

    }


    .logo h1 {

        margin: 0;

        font-size: 40px;

        font-weight: 900;

        letter-spacing: 1px;

        text-shadow:

            0 3px 0 #123d83,

            0 6px 12px
            rgba(0,0,0,.30);

    }


    .logo p {

        margin: 8px 0 0;

        font-size: 13px;

        opacity: .78;

    }


    h2 {

        margin:
            0 0 18px;

        text-align: center;

        font-size: 26px;

    }


    .description {

        text-align: center;

        line-height: 1.55;

        margin-bottom: 25px;

        opacity: .90;

    }


    .field {

        margin-bottom: 18px;

    }


    .field label {

        display: block;

        margin-bottom: 7px;

        font-size: 14px;

        font-weight: bold;

    }


    .field input {

        width: 100%;

        padding: 14px 15px;

        border-radius: 11px;

        border:
            2px solid
            rgba(255,255,255,.18);

        outline: none;

        background:
            rgba(0,0,0,.18);

        color: white;

        font-size: 17px;

    }


    .field input:focus {

        border-color:
            rgba(255,255,255,.60);

        background:
            rgba(0,0,0,.25);

    }


    .field input::placeholder {

        color:
            rgba(255,255,255,.55);

    }


    .captcha {

        margin:
            20px 0;

    }


    .button {

        width: 100%;

        border: 0;

        border-radius: 12px;

        padding: 15px;

        color: #fff;

        font-size: 17px;

        font-weight: bold;

        cursor: pointer;

        background:
            linear-gradient(
                180deg,
                #3b9aff,
                #1461cb
            );

        box-shadow:

            0 4px 0 #0b418a,

            0 8px 18px
            rgba(0,0,0,.25);

        transition:
            filter .15s,
            transform .10s;

    }


    .button:hover {

        filter:
            brightness(1.08);

    }


    .button:active {

        transform:
            translateY(3px);

        box-shadow:
            0 1px 0 #0b418a;

    }


    .message {

        padding: 15px;

        margin: 20px 0;

        border-radius: 12px;

        line-height: 1.5;

        text-align: center;

    }


    .error {

        background:
            rgba(150,20,30,.62);

        border:
            1px solid
            rgba(255,150,150,.25);

    }


    .success {

        background:
            rgba(15,125,65,.62);

        border:
            1px solid
            rgba(150,255,190,.25);

    }


    .info {

        background:
            rgba(20,90,170,.60);

        border:
            1px solid
            rgba(150,210,255,.25);

    }


    .level-id {

        text-align: center;

        font-size: 44px;

        font-weight: 900;

        margin: 15px 0;

        text-shadow:
            0 3px 0
            rgba(0,0,0,.25);

    }


    .details {

        margin-top: 20px;

        padding: 15px;

        border-radius: 12px;

        background:
            rgba(0,0,0,.15);

    }


    .row {

        display: flex;

        justify-content:
            space-between;

        align-items: flex-start;

        gap: 15px;

        padding: 9px 0;

        border-bottom:
            1px solid
            rgba(255,255,255,.10);

    }


    .row:last-child {

        border-bottom: 0;

    }


    .row span:first-child {

        opacity: .65;

        flex-shrink: 0;

    }


    .row span:last-child {

        font-weight: bold;

        text-align: right;

        overflow-wrap:
            anywhere;

    }


    .back {

        display: block;

        margin-top: 20px;

        text-align: center;

        color: white;

        opacity: .75;

        text-decoration: none;

    }


    .back:hover {

        opacity: 1;

        text-decoration:
            underline;

    }


    .footer {

        margin-top: 22px;

        text-align: center;

        font-size: 12px;

        opacity: .55;

    }


    @media(max-width:520px) {

        body {

            padding: 10px;

        }

        .card {

            padding:
                22px 17px;

        }

        .logo h1 {

            font-size: 32px;

        }

        h2 {

            font-size: 22px;

        }

        .level-id {

            font-size: 36px;

        }

    }

</style>

</head><body><main class="container"><section class="card"><div class="logo">

    <h1>Neo PS</h1>

    <p>
        Geometry Dash Private Server
    </p>

</div>

<?= $content ?>

<div class="footer">
    Neo PS Level Reupload
</div>

</section></main></body></html>
<?phpexit;

}

/*

* ============================================================
* ERROR PAGE
* ============================================================
  */

function errorPage(
string $message
): void {

page(
    'Reupload Error',

    '
    <h2>Reupload Error</h2>

    <div class="message error">
        ' .
        h($message) .
        '
    </div>

    <a
        class="back"
        href="' .
        h(currentPage()) .
        '"
    >
        Try again
    </a>
    '
);

}

/*

* ============================================================
* LOGIN PAGE
* ============================================================
  */

function loginRequiredPage(): void
{
page(
'Login Required',

    '
    <h2>Login Required</h2>

    <div class="message error">

        You must be logged in to
        reupload a level.

    </div>

    <a
        class="back"
        href="/dashboard/login/login.php"
    >
        Go to Login
    </a>
    '
);

}

/*

* ============================================================
* SUCCESS PAGE
* ============================================================
  */

function successPage(
int $newID,
string $name,
int $originalID
): void {

page(
    'Level Reuploaded',

    '
    <h2>Level Reuploaded!</h2>

    <div class="message success">

        The level was successfully
        added to Neo PS.

    </div>

    <div class="level-id">

        #' .
        h($newID) .
        '

    </div>

    <div class="details">

        <div class="row">

            <span>Name</span>

            <span>' .
                h($name) .
            '</span>

        </div>

        <div class="row">

            <span>Original ID</span>

            <span>' .
                h($originalID) .
            '</span>

        </div>

        <div class="row">

            <span>Neo PS ID</span>

            <span>' .
                h($newID) .
            '</span>

        </div>

    </div>

    <a
        class="back"
        href="' .
        h(currentPage()) .
        '"
    >
        Reupload another level
    </a>
    '
);

}

/*

* ============================================================
* GD BASE64 DECODE
* ============================================================
  */

function gdBase64Decode(
string $value
): string|false {

$value =
    trim($value);


if(
    $value === ''
) {

    return false;

}


/*
 * Geometry Dash URL-safe Base64.
 */
$value =
    strtr(
        $value,
        '-_',
        '+/'
    );


/*
 * Add missing padding.
 */
$padding =
    strlen($value) % 4;


if(
    $padding !== 0
) {

    $value .=
        str_repeat(
            '=',
            4 - $padding
        );

}


return base64_decode(
    $value,
    true
);

}

/*

* ============================================================
* DECODE LEVEL STRING
* ============================================================
  */

function decodeLevelString(
string $value
): string|false {

if(
    $value === ''
) {

    return false;

}


/*
 * --------------------------------------------------------
 * GZIP / H4sI
 * --------------------------------------------------------
 */

if(
    str_starts_with(
        $value,
        'H4sI'
    )
) {

    $decoded =
        gdBase64Decode(
            $value
        );


    if(
        $decoded === false
    ) {

        return false;

    }


    /*
     * Normal gzip.
     */
    $result =
        @gzdecode(
            $decoded
        );


    if(
        $result !== false
    ) {

        return $result;

    }


    /*
     * Compatibility fallback.
     */
    $result =
        @gzuncompress(
            $decoded
        );


    if(
        $result !== false
    ) {

        return $result;

    }


    return false;
}


/*
 * --------------------------------------------------------
 * ZLIB / eJ
 * --------------------------------------------------------
 */

if(
    str_starts_with(
        $value,
        'eJ'
    )
) {

    $decoded =
        gdBase64Decode(
            $value
        );


    if(
        $decoded === false
    ) {

        return false;

    }


    $result =
        @gzuncompress(
            $decoded
        );


    if(
        $result !== false
    ) {

        return $result;

    }


    /*
     * Compatibility fallback.
     */
    $result =
        @gzdecode(
            $decoded
        );


    if(
        $result !== false
    ) {

        return $result;

    }


    return false;
}


/*
 * --------------------------------------------------------
 * Already decoded.
 * --------------------------------------------------------
 */

return $value;

}

/*

* ============================================================
* PARSE RAW GD LEVEL
* ============================================================
  */

function parseRawLevel(
string $raw
): array|false {

$raw =
    trim($raw);


if(
    $raw === ''
) {

    return false;

}


/*
 * Remove # metadata.
 */
$raw =
    explode(
        '#',
        $raw,
        2
    )[0];


$parts =
    explode(
        ':',
        $raw
    );


if(
    count($parts) < 4
) {

    return false;

}


$data = [];


for(
    $i = 0;
    $i + 1 < count($parts);
    $i += 2
) {

    $key =
        trim(
            $parts[$i]
        );


    $value =
        $parts[$i + 1];


    if(
        $key === ''
    ) {

        continue;

    }


    $data[$key] =
        $value;

}


if(
    !isset($data['1']) ||
    $data['1'] === ''
) {

    return false;

}


return $data;

}

/*

* ============================================================
* FETCH WORKER LEVEL
* ============================================================
  */

function fetchWorkerLevel(
int $levelID
): array {

$url =
    REUPLOAD_API .
    rawurlencode(
        (string)$levelID
    );


$ch =
    curl_init(
        $url
    );


if(
    $ch === false
) {

    return [
        'ok' =>
            false,

        'error' =>
            'Unable to initialize cURL.'
    ];

}


curl_setopt_array(
    $ch,
    [

        CURLOPT_RETURNTRANSFER =>
            true,

        CURLOPT_HTTPGET =>
            true,

        CURLOPT_CONNECTTIMEOUT =>
            CURL_CONNECT_TIMEOUT,

        CURLOPT_TIMEOUT =>
            CURL_TIMEOUT,

        CURLOPT_FOLLOWLOCATION =>
            false,

        CURLOPT_MAXREDIRS =>
            0,

        CURLOPT_PROTOCOLS =>
            CURLPROTO_HTTPS,

        CURLOPT_USERAGENT =>
            'NeoPS-LevelReupload/4.0',

        CURLOPT_HTTPHEADER => [

            'Accept: application/json'

        ],

        CURLOPT_ENCODING =>
            ''

    ]
);


$response =
    curl_exec(
        $ch
    );


$curlError =
    curl_error(
        $ch
    );


$curlErrno =
    curl_errno(
        $ch
    );


$httpCode =
    (int)curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );


curl_close(
    $ch
);


if(
    $response === false
) {

    return [

        'ok' =>
            false,

        'error' =>
            $curlError !== ''
            ? $curlError
            : 'Worker request failed.',

        'errno' =>
            $curlErrno,

        'httpCode' =>
            $httpCode

    ];

}


if(
    strlen($response) >
    MAX_API_RESPONSE
) {

    return [

        'ok' =>
            false,

        'error' =>
            'The Worker returned too much data.',

        'httpCode' =>
            $httpCode

    ];

}


if(
    $httpCode < 200 ||
    $httpCode >= 300
) {

    return [

        'ok' =>
            false,

        'error' =>
            'The level service is currently unavailable.',

        'httpCode' =>
            $httpCode

    ];

}


$json =
    json_decode(
        $response,
        true
    );


if(
    !is_array($json) ||
    json_last_error() !== JSON_ERROR_NONE
) {

    return [

        'ok' =>
            false,

        'error' =>
            'The level service returned invalid data.',

        'httpCode' =>
            $httpCode

    ];

}


return [

    'ok' =>
        true,

    'data' =>
        $json,

    'httpCode' =>
        $httpCode

];

}

/*

* ============================================================
* AUTHENTICATION
* ============================================================
  */

try {

$accountID =
    getAuthenticatedAccount(
        $db
    );

} catch(Throwable $e) {

error_log(
    '[NeoPS Reupload] Authentication error: ' .
    $e->getMessage()
);

errorPage(
    'Unable to verify your account.'
);

}

if(
$accountID <= 0
) {

loginRequiredPage();

}

/*

* ============================================================
* LOAD USER INFORMATION
* ============================================================
  */

try {

$personUserID =
    $gs->getUserID(
        $accountID
    );


$personName =
    $gs->getAccountName(
        $accountID
    );

} catch(Throwable $e) {

error_log(
    '[NeoPS Reupload] Account lookup error: ' .
    $e->getMessage()
);

errorPage(
    'Unable to load your account.'
);

}

/*

* ============================================================
* BAN CHECK
* ============================================================
  */

try {

$ban =
    $gs->getPersonBan(
        $accountID,
        $personUserID,
        2
    );


if($ban) {

    $reason = '';


    if(
        isset($ban['reason'])
    ) {

        $decoded =
            base64_decode(
                (string)$ban['reason'],
                true
            );


        $reason =
            $decoded !== false
            ? $decoded
            : (string)$ban['reason'];

    }


    $expires = '';


    if(
        isset($ban['expires']) &&
        (int)$ban['expires'] > 0
    ) {

        $expires =
            date(
                'Y-m-d H:i',
                (int)$ban['expires']
            );

    }


    page(
        'Account Restricted',

        '
        <h2>Account Restricted</h2>

        <div class="message error">

            You are currently banned
            from using level reupload.

            <br><br>

            <strong>Reason:</strong><br>

            ' .
                h($reason) .
            '

            <br><br>

            <strong>Expires:</strong><br>

            ' .
                h($expires) .
            '

        </div>
        '
    );

}

} catch(Throwable $e) {

error_log(
    '[NeoPS Reupload] Ban lookup error: ' .
    $e->getMessage()
);

errorPage(
    'Unable to verify your account restrictions.'
);

}

/*

* ============================================================
* CSRF TOKEN
* ============================================================
  */

if(
empty(
$_SESSION['neo_reupload_csrf']
)
) {

$_SESSION['neo_reupload_csrf'] =
    bin2hex(
        random_bytes(32)
    );

}

$csrf =
$_SESSION['neo_reupload_csrf'];

/*

* ============================================================
* POST
* ============================================================
  */

if(
$_SERVER['REQUEST_METHOD'] === 'POST'
) {

/*
 * --------------------------------------------------------
 * CSRF
 * --------------------------------------------------------
 */

$postedCSRF =
    (string)(
        $_POST['csrf']
        ?? ''
    );


if(
    $postedCSRF === '' ||
    !hash_equals(
        $csrf,
        $postedCSRF
    )
) {

    errorPage(
        'Invalid security token. Please reload the page.'
    );

}


/*
 * --------------------------------------------------------
 * RATE LIMIT
 * --------------------------------------------------------
 */

if(
    !checkReuploadRateLimit()
) {

    errorPage(
        'Too many reupload attempts. Please wait a few minutes and try again.'
    );

}


/*
 * --------------------------------------------------------
 * CAPTCHA
 * --------------------------------------------------------
 */

try {

    if(
        !Captcha::validateCaptcha()
    ) {

        errorPage(
            'Invalid CAPTCHA.'
        );

    }

} catch(Throwable $e) {

    error_log(
        '[NeoPS Reupload] CAPTCHA error: ' .
        $e->getMessage()
    );

    errorPage(
        'CAPTCHA validation failed.'
    );

}


/*
 * --------------------------------------------------------
 * LEVEL ID
 * --------------------------------------------------------
 */

$rawID =
    trim(
        (string)(
            $_POST['levelid']
            ?? ''
        )
    );


if(
    $rawID === '' ||
    !preg_match(
        '/^[0-9]+$/',
        $rawID
    )
) {

    errorPage(
        'Please enter a valid level ID.'
    );

}


if(
    strlen($rawID) > 10
) {

    errorPage(
        'The level ID is too large.'
    );

}


$requestedID =
    (int)$rawID;


if(
    $requestedID <= 0 ||
    $requestedID > 2147483647
) {

    errorPage(
        'Invalid level ID.'
    );

}


/*
 * --------------------------------------------------------
 * FETCH SOURCE LEVEL
 * --------------------------------------------------------
 */

$worker =
    fetchWorkerLevel(
        $requestedID
    );


if(
    !$worker['ok']
) {

    error_log(
        '[NeoPS Reupload] Worker error: ' .
        ($worker['error'] ?? 'unknown')
    );

    errorPage(
        'Unable to retrieve the level from the reupload service.'
    );

}


$api =
    $worker['data'];


/*
 * Worker can return:
 *
 * {
 *     "error": "..."
 * }
 */

if(
    isset($api['error']) &&
    !isset($api['raw'])
) {

    errorPage(
        'The source server could not provide this level.'
    );

}


/*
 * --------------------------------------------------------
 * RAW RESPONSE
 * --------------------------------------------------------
 */

$raw =
    isset($api['raw'])
    ? (string)$api['raw']
    : '';


if(
    $raw === ''
) {

    errorPage(
        'The reupload service did not return level data.'
    );

}


$rawTrimmed =
    trim($raw);


/*
 * Geometry Dash "level not found".
 */

if(
    $rawTrimmed === '-1'
) {

    errorPage(
        'That level was not found on the source server.'
    );

}


/*
 * Source server rejection.
 */

if(
    $rawTrimmed === 'No no no'
) {

    errorPage(
        'The source server rejected the request.'
    );

}


/*
 * --------------------------------------------------------
 * PARSE LEVEL
 * --------------------------------------------------------
 */

$level =
    parseRawLevel(
        $raw
    );


if(
    $level === false
) {

    errorPage(
        'The reupload service returned an invalid Geometry Dash level.'
    );

}


/*
 * --------------------------------------------------------
 * ORIGINAL ID
 * --------------------------------------------------------
 */

$originalID =
    (int)lv(
        $level,
        '1',
        0
    );


if(
    $originalID <= 0
) {

    errorPage(
        'The source level has an invalid ID.'
    );

}


/*
 * Make sure the Worker returned the requested level.
 */

if(
    $originalID !== $requestedID
) {

    errorPage(
        'The source server returned a different level ID.'
    );

}


/*
 * --------------------------------------------------------
 * LEVEL NAME
 * --------------------------------------------------------
 */

$levelName =
    strip_tags(
        (string)lv(
            $level,
            '2',
            'Unknown Level'
        )
    );


$levelName =
    trim(
        $levelName
    );


if(
    $levelName === ''
) {

    $levelName =
        'Unknown Level';

}


$levelName =
    mb_substr(
        $levelName,
        0,
        255
    );


/*
 * --------------------------------------------------------
 * DESCRIPTION
 * --------------------------------------------------------
 */

$levelDesc =
    strip_tags(
        (string)lv(
            $level,
            '3',
            ''
        )
    );


$levelDesc =
    mb_substr(
        $levelDesc,
        0,
        1024
    );


/*
 * --------------------------------------------------------
 * LEVEL STRING
 * --------------------------------------------------------
 */

$encodedLevelString =
    trim(
        (string)lv(
            $level,
            '4',
            ''
        )
    );


if(
    $encodedLevelString === ''
) {

    errorPage(
        'The source level does not contain level data.'
    );

}


/*
 * Decode:
 *
 * H4sI...
 * eJ...
 * plain data
 */

$levelString =
    decodeLevelString(
        $encodedLevelString
    );


if(
    $levelString === false
) {

    errorPage(
        'The level data could not be decompressed.'
    );

}


if(
    strlen($levelString) >
    MAX_LEVEL_DATA
) {

    errorPage(
        'The level data is too large.'
    );

}


if(
    $levelString === ''
) {

    errorPage(
        'The level contains empty level data.'
    );

}


/*
 * --------------------------------------------------------
 * OTHER GD FIELDS
 * --------------------------------------------------------
 */

$levelVersion =
    (int)lv(
        $level,
        '5',
        1
    );


$originalUserID =
    (int)lv(
        $level,
        '6',
        0
    );


$gameVersion =
    (int)lv(
        $level,
        '13',
        22
    );


$levelLength =
    (int)lv(
        $level,
        '15',
        0
    );


$twoPlayer =
    (int)lv(
        $level,
        '31',
        0
    );


$songID =
    (int)lv(
        $level,
        '35',
        0
    );


$extraString =
    (string)lv(
        $level,
        '36',
        ''
    );


$coins =
    (int)lv(
        $level,
        '37',
        0
    );


$requestedStars =
    (int)lv(
        $level,
        '39',
        0
    );


$isLDM =
    (int)lv(
        $level,
        '40',
        0
    );


$songIDs =
    (string)lv(
        $level,
        '52',
        ''
    );


$sfxIDs =
    (string)lv(
        $level,
        '53',
        ''
    );


$ts =
    (string)lv(
        $level,
        '57',
        ''
    );


/*
 * Keep the variable available for future
 * metadata/verification integrations.
 */
unset(
    $originalUserID
);


/*
 * --------------------------------------------------------
 * PASSWORD
 * --------------------------------------------------------
 */

$password =
    (string)lv(
        $level,
        '27',
        '0'
    );


if(
    $password !== '' &&
    $password !== '0'
) {

    try {

        $decodedPassword =
            gdBase64Decode(
                $password
            );


        if(
            $decodedPassword !== false
        ) {

            $password =
                XORCipher::cipher(
                    $decodedPassword,
                    26364
                );

        } else {

            $password =
                '0';

        }

    } catch(Throwable $e) {

        error_log(
            '[NeoPS Reupload] Password decode error: ' .
            $e->getMessage()
        );

        $password =
            '0';

    }

}


/*
 * --------------------------------------------------------
 * SOURCE SERVER
 * --------------------------------------------------------
 */

$originalServer =
    ORIGINAL_SERVER;


/*
 * --------------------------------------------------------
 * DUPLICATE CHECK
 * --------------------------------------------------------
 */

try {

    $check =
        $db->prepare(
            "
            SELECT
                levelID,
                levelName
            FROM levels
            WHERE originalReup = :originalReup
              AND originalServer = :originalServer
            LIMIT 1
            "
        );


    $check->execute([

        ':originalReup' =>
            $originalID,

        ':originalServer' =>
            $originalServer

    ]);


    $existing =
        $check->fetch(
            PDO::FETCH_ASSOC
        );


    if(
        $existing
    ) {

        page(
            'Already Reuploaded',

            '
            <h2>Already Reuploaded</h2>

            <div class="message info">

                This level has already been
                reuploaded to Neo PS.

            </div>

            <div class="level-id">

                #' .
                    h(
                        $existing['levelID']
                    ) .
                '

            </div>

            <div class="details">

                <div class="row">

                    <span>Name</span>

                    <span>' .
                        h(
                            $existing['levelName']
                        ) .
                    '</span>

                </div>

                <div class="row">

                    <span>Original ID</span>

                    <span>' .
                        h(
                            $originalID
                        ) .
                    '</span>

                </div>

            </div>

            <a
                class="back"
                href="' .
                    h(currentPage()) .
                '"
            >
                Reupload another level
            </a>
            '
        );

    }

} catch(Throwable $e) {

    error_log(
        '[NeoPS Reupload] Duplicate check error: ' .
        $e->getMessage()
    );

    errorPage(
        'Unable to check for an existing reupload.'
    );

}


/*
 * ========================================================
 * NEO PS UPLOADER
 * ========================================================
 */

try {

    $reuploadUserID =
        (int)$gs->getUserID(
            $accountID
        );

} catch(Throwable $e) {

    error_log(
        '[NeoPS Reupload] User ID lookup error: ' .
        $e->getMessage()
    );

    $reuploadUserID =
        0;

}


/*
 * Account ID of the person performing
 * the reupload.
 */
$reuploadAccountID =
    $accountID;


/*
 * --------------------------------------------------------
 * HOSTNAME / IP
 * --------------------------------------------------------
 */

try {

    $hostname =
        $gs->getIP();

} catch(Throwable $e) {

    $hostname =
        currentIP();

}


$hostname =
    mb_substr(
        (string)$hostname,
        0,
        255
    );


/*
 * --------------------------------------------------------
 * UPLOAD DATE
 * --------------------------------------------------------
 */

$uploadDate =
    time();


/*
 * Temporary/final paths.
 */

$temporaryFile =
    null;

$finalFile =
    null;


/*
 * ========================================================
 * DATABASE + FILE
 * ========================================================
 */

try {

    $db->beginTransaction();


    /*
     * Re-check duplicate inside transaction.
     */

    $check =
        $db->prepare(
            "
            SELECT
                levelID,
                levelName
            FROM levels
            WHERE originalReup = :originalReup
              AND originalServer = :originalServer
            LIMIT 1
            "
        );


    $check->execute([

        ':originalReup' =>
            $originalID,

        ':originalServer' =>
            $originalServer

    ]);


    $existing =
        $check->fetch(
            PDO::FETCH_ASSOC
        );


    if(
        $existing
    ) {

        $db->rollBack();


        page(
            'Already Reuploaded',

            '
            <h2>Already Reuploaded</h2>

            <div class="message info">

                This level has already been
                reuploaded to Neo PS.

            </div>

            <div class="level-id">

                #' .
                    h(
                        $existing['levelID']
                    ) .
                '

            </div>

            <div class="details">

                <div class="row">

                    <span>Name</span>

                    <span>' .
                        h(
                            $existing['levelName']
                        ) .
                    '</span>

                </div>

                <div class="row">

                    <span>Original ID</span>

                    <span>' .
                        h(
                            $originalID
                        ) .
                    '</span>

                </div>

            </div>

            <a
                class="back"
                href="' .
                    h(currentPage()) .
                '"
            >
                Reupload another level
            </a>
            '
        );

    }


    /*
     * ----------------------------------------------------
     * INSERT
     * ----------------------------------------------------
     */

    $insert =
        $db->prepare(
            "
            INSERT INTO levels
            (
                levelName,
                gameVersion,
                binaryVersion,
                userName,
                levelDesc,
                levelVersion,
                levelLength,
                audioTrack,
                auto,
                password,
                original,
                twoPlayer,
                songID,
                objects,
                coins,
                requestedStars,
                extraString,
                levelString,
                levelInfo,
                secret,
                uploadDate,
                updateDate,
                originalReup,
                originalServer,
                userID,
                extID,
                unlisted,
                hostname,
                starStars,
                starCoins,
                starDifficulty,
                starDemon,
                starAuto,
                isLDM,
                songIDs,
                sfxIDs,
                ts,
                settingsString
            )
            VALUES
            (
                :levelName,
                :gameVersion,
                :binaryVersion,
                :userName,
                :levelDesc,
                :levelVersion,
                :levelLength,
                :audioTrack,
                :auto,
                :password,
                :original,
                :twoPlayer,
                :songID,
                :objects,
                :coins,
                :requestedStars,
                :extraString,
                :levelString,
                :levelInfo,
                :secret,
                :uploadDate,
                :updateDate,
                :originalReup,
                :originalServer,
                :userID,
                :extID,
                :unlisted,
                :hostname,
                :starStars,
                :starCoins,
                :starDifficulty,
                :starDemon,
                :starAuto,
                :isLDM,
                :songIDs,
                :sfxIDs,
                :ts,
                :settingsString
            )
            "
        );


    $insert->execute([

        ':levelName' =>
            $levelName,

        ':gameVersion' =>
            $gameVersion,

        /*
         * Neo PS level format.
         */
        ':binaryVersion' =>
            27,

        /*
         * Attribute the level to the
         * Neo PS user performing the reupload.
         */
        ':userName' =>
            mb_substr(
                (string)$personName,
                0,
                255
            ),

        ':levelDesc' =>
            $levelDesc,

        ':levelVersion' =>
            $levelVersion,

        ':levelLength' =>
            $levelLength,

        ':audioTrack' =>
            (int)lv(
                $level,
                '12',
                0
            ),

        ':auto' =>
            0,

        ':password' =>
            $password,

        /*
         * Original GD level ID.
         */
        ':original' =>
            $originalID,

        ':twoPlayer' =>
            $twoPlayer,

        ':songID' =>
            $songID,

        /*
         * The actual object data is stored
         * separately by the Neo PS level system.
         */
        ':objects' =>
            0,

        ':coins' =>
            $coins,

        ':requestedStars' =>
            $requestedStars,

        ':extraString' =>
            $extraString,

        /*
         * Actual level data is saved into:
         *
         * /data/levels/<NEW_LEVEL_ID>
         */
        ':levelString' =>
            '',

        ':levelInfo' =>
            '',

        ':secret' =>
            '',

        ':uploadDate' =>
            $uploadDate,

        ':updateDate' =>
            $uploadDate,

        ':originalReup' =>
            $originalID,

        ':originalServer' =>
            $originalServer,

        /*
         * Neo PS user ID.
         */
        ':userID' =>
            $reuploadUserID,

        /*
         * Neo PS account ID.
         */
        ':extID' =>
            $reuploadAccountID,

        ':unlisted' =>
            0,

        ':hostname' =>
            $hostname,
        /*
         * Do NOT copy source rating.
         */
        ':starStars' =>
            0,

        ':starCoins' =>
            0,

        ':starDifficulty' =>
            0,

        ':starDemon' =>
            0,

        ':starAuto' =>
            0,

        ':isLDM' =>
            $isLDM,

        ':songIDs' =>
            $songIDs,

        ':sfxIDs' =>
            $sfxIDs,

        ':ts' =>
            $ts,

        ':settingsString' =>
            ''

    ]);

    /*
     * ----------------------------------------------------
     * NEW LEVEL ID
     * ----------------------------------------------------
     */

    $newLevelID =
        (int)$db->lastInsertId();


    if(
        $newLevelID <= 0
    ) {

        throw new RuntimeException(
            'Invalid new level ID.'
        );

    }

    /*
     * ----------------------------------------------------
     * LEVEL DATA DIRECTORY
     * ----------------------------------------------------
     */

    $levelsDirectory =
        __DIR__ .
        '/data/levels';


    if(
        !is_dir(
            $levelsDirectory
        )
    ) {

        if(
            !mkdir(
                $levelsDirectory,
                0755,
                true
            )
        ) {

            throw new RuntimeException(
                'Unable to create data/levels.'
            );

        }

    }


    if(
        !is_writable(
            $levelsDirectory
        )
    ) {

        throw new RuntimeException(
            'data/levels is not writable.'
        );

    }

    /*
     * ----------------------------------------------------
     * TEMPORARY FILE
     * ----------------------------------------------------
     */

    $temporaryFile =
        $levelsDirectory .
        '/.' .
        $newLevelID .
        '.' .
        bin2hex(
            random_bytes(8)
        ) .
        '.tmp';

    $finalFile =
        $levelsDirectory .
        '/' .
        $newLevelID;

    /*
     * Write atomically.
     */

    $written =
        file_put_contents(
            $temporaryFile,
            $levelString,
            LOCK_EX
        );

    if(
        $written === false
    ) {

        throw new RuntimeException(
            'Unable to write level data.'
        );

    }


    if(
        $written !== strlen($levelString)
    ) {

        throw new RuntimeException(
            'Incomplete level data write.'
        );

    }

    /*
     * Move temp -> final.
     */

    if(
        !rename(
            $temporaryFile,
            $finalFile
        )
    ) {

        throw new RuntimeException(
            'Unable to finalize level data.'
        );

    }


    $temporaryFile =
        null;

    /*
     * ----------------------------------------------------
     * COMMIT
     * ----------------------------------------------------
     */

    $db->commit();


} catch(Throwable $e) {

    /*
     * Rollback DB.
     */

    if(
        $db instanceof PDO &&
        $db->inTransaction()
    ) {

        try {

            $db->rollBack();

        } catch(Throwable $rollbackError) {

            error_log(
                '[NeoPS Reupload] Rollback error: ' .
                $rollbackError->getMessage()
            );

        }

    }

    /*
     * Delete temporary file.
     */

    if(
        $temporaryFile !== null &&
        file_exists(
            $temporaryFile
        )
    ) {

        @unlink(
            $temporaryFile
        );

    }

    /*
     * Delete final file if the database
     * operation failed.
     */

    if(
        $finalFile !== null &&
        file_exists(
            $finalFile
        )
    ) {

        @unlink(
            $finalFile
        );

    }


    error_log(
        '[NeoPS Reupload] Save error: ' .
        $e->getMessage()
    );


    errorPage(
        'Neo PS could not save the level.'
    );

}


/*
 * ========================================================
 * LOG ACTION
 * ========================================================
 */

try {

    $gs->logAction(
        $accountID,
        22,
        $levelName,
        $levelDesc,
        $newLevelID
    );

} catch(Throwable $e) {

    error_log(
        '[NeoPS Reupload] logAction error: ' .
        $e->getMessage()
    );

}


/*
 * ========================================================
 * WEBHOOK
 * ========================================================
 */

try {

    $webhookData = [

        'levelID' =>
            $newLevelID,

        'levelName' =>
            $levelName,

        'levelDesc' =>
            $levelDesc,

        'originalReup' =>
            $originalID,

        'originalServer' =>
            $originalServer,

        'userID' =>
            $reuploadUserID,

        'extID' =>
            $reuploadAccountID

    ];

    $gs->sendLogsLevelChangeWebhook(
        $newLevelID,
        $accountID,
        $webhookData
    );

} catch(Throwable $e) {

    error_log(
        '[NeoPS Reupload] Webhook error: ' .
        $e->getMessage()
    );

}

/*
 * ========================================================
 * AUTOMOD
 * ========================================================
 */

try {

    Automod::checkLevelsCount();

} catch(Throwable $e) {

    error_log(
        '[NeoPS Reupload] Automod error: ' .
        $e->getMessage()
    );

}

/*
 * ========================================================
 * POST / REDIRECT / GET
 * ========================================================
 *
 * Save the success information in the session.
 * The browser then performs a GET request.
 */

$_SESSION['neo_reupload_success'] = [

    'levelID' =>
        $newLevelID,

    'levelName' =>
        $levelName,

    'originalID' =>
        $originalID

];

/*
 * Rotate CSRF token after a successful action.
 */
$_SESSION['neo_reupload_csrf'] =
    bin2hex(
        random_bytes(32)
    );


header(
    'Location: ' .
    currentPage()
);

exit;

}

/*

* ============================================================
* SUCCESS RESULT AFTER REDIRECT
* ============================================================
  */

if(
isset(
$_SESSION['neo_reupload_success']
) &&
is_array(
$_SESSION['neo_reupload_success']
)
) {

$success =
    $_SESSION['neo_reupload_success'];


unset(
    $_SESSION['neo_reupload_success']
);


successPage(

    (int)(
        $success['levelID']
        ?? 0
    ),

    (string)(
        $success['levelName']
        ?? 'Unknown Level'
    ),

    (int)(
        $success['originalID']
        ?? 0
    )
);

}

/*

* ============================================================
* GET - FORM
* ============================================================
  */

page(
'Level Reupload',

'
<h2>Level Reupload</h2>
<div class="description">
    Enter a Geometry Dash level ID
    to reupload it to Neo PS.
    <br><br>
    The source server is handled
    automatically by Neo PS.

</div>

<form
    method="post"
    action="' .
        h(currentPage()) .
    '"
>


    <input
        type="hidden"
        name="csrf"
        value="' .
            h($csrf) .
        '"
    >


    <div class="field">
        <label for="levelid">
            Geometry Dash Level ID
        </label>


        <input
            type="number"
            id="levelid"
            name="levelid"
            min="1"
            max="2147483647"
            required
            autocomplete="off"
            inputmode="numeric"
            placeholder="Example: 146930394"
        >

    </div>


    <div class="captcha">

        ' .
            Captcha::displayCaptcha(true) .
        '

    </div>


    <button
        class="button"
        type="submit"
    >
        Reupload Level
    </button>


</form>
'
);
?>
