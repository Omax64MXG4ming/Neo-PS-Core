<?php

chdir(dirname(__FILE__));

require "../lib/connection.php";
require "../../config/misc.php";
require_once "../lib/mainLib.php";
require_once "../lib/exploitPatch.php";
require_once "../lib/XORCipher.php";
require_once "../lib/generateHash.php";
require_once "../lib/cron.php";

$gs = new mainLib();
$gh = new generateHash();

$current = time();

/*
 * ========================================
 * TYPE
 * ========================================
 *
 * 0 = Daily
 * 1 = Weekly
 * 2 = Event
 *
 * some old clients or cores sends
 * "weekly" from if "type".
 */

$type = 0;

if (isset($_POST["type"]) && $_POST["type"] !== "") {
    $type = intval($_POST["type"]);
} elseif (isset($_POST["weekly"]) && $_POST["weekly"] !== "") {
    $type = intval($_POST["weekly"]);
}

/*
 * ========================================
 * VALIDATE TYPE
 * ========================================
 */

if ($type < 0 || $type > 2) {
    exit("-1");
}

/*
 * ========================================
 * GET DAILY / WEEKLY / EVENT
 * ========================================
 */

$daily = false;
$dailyTable = "";
$isEvent = false;

switch ($type) {

    /*
     * DAILY / WEEKLY
     */
    case 0:
    case 1:

        $dailyTable = "dailyfeatures";

        /*
         * i search the Daily/Weekly
         * when his timestamp is already started.
         */
        $query = $db->prepare("
            SELECT *
            FROM dailyfeatures
            WHERE timestamp <= :current
              AND type = :type
            ORDER BY timestamp DESC
            LIMIT 1
        ");

        $query->execute(array(
            ":current" => $current,
            ":type" => $type
        ));

        $daily = $query->fetch(PDO::FETCH_ASSOC);

        break;


    /*
     * EVENTS
     */
    case 2:

        $dailyTable = "events";
        $isEvent = true;

        /*
         * Event activo:
         *
         * timestamp = start
         * duration  = end
         */
        $query = $db->prepare("
            SELECT *
            FROM events
            WHERE timestamp <= :current
              AND duration >= :current
            ORDER BY duration ASC
            LIMIT 1
        ");

        $query->execute(array(
            ":current" => $current
        ));

        $daily = $query->fetch(PDO::FETCH_ASSOC);

        break;
}

/*
 * ========================================
 * NO DAILY FOUND
 * ========================================
 */

if (!$daily) {
    exit("-1");
}

/*
 * ========================================
 * REQUIRED DATA
 * ========================================
 */

if (!isset($daily["feaID"]) || !isset($daily["levelID"])) {
    exit("-1");
}

$feaID = intval($daily["feaID"]);
$levelID = intval($daily["levelID"]);

/*
 * ========================================
 * DAILY ID
 * ========================================
 *
 * Daily:
 *      feaID
 *
 * Weekly:
 *      feaID + 100000
 *
 * Event:
 *      feaID + 200000
 */

$dailyID = $feaID + ($type * 100000);

/*
 * ========================================
 * TIME LEFT
 * ========================================
 */

if ($isEvent) {

    /*
     * Events used "duration"
     * for timestamp by ending.
     */
    if (!isset($daily["duration"])) {
        exit("-1");
    }

    $timeleft = intval($daily["duration"]) - $current;

} else {

    /*
     * Daily/Weekly:
     *
     * timestamp represents the time
     * on the level is added with !daily in game.
     *
     * Normally the client waits 
     * the remain time of the daily.
     *
     * if the table have duration, can i use
     * duration for timestamp long when is available  */

    if (isset($daily["duration"]) && intval($daily["duration"]) > $current) {
        $timeleft = intval($daily["duration"]) - $current;
    } else {
        /*
         * Compatibility with Cores with
         * timestamp + 86400/604800 represents
         * the duration.
         */
        if ($type == 1) {
            $timeleft = ($daily["timestamp"] + 604800) - $current;
        } else {
            $timeleft = ($daily["timestamp"] + 86400) - $current;
        }
    }
}

/*
 * prevent negative time ($timeleft = -27493; or minus or more minus 
 */
if ($timeleft < 0) {
    $timeleft = 0;
}

/*
 * ========================================
 * WEBHOOK
 * ========================================
 */

$webhookSent = false;

if (isset($daily["webhookSent"])) {
    $webhookSent = intval($daily["webhookSent"]) == 1;
}

if (!$webhookSent) {

    /*
     * send advise of Daily/Weekly.
     */
    try {

        $gs->sendDailyWebhook(
            $levelID,
            $type
        );

    } catch (Exception $e) {

        /*
         * I dont made of daily Levels fail by wrong response 
         * only if Discord/webhook
         * have an problem to send it
         */

    }

    /*
     *  Mark when is sended the webhook Embed 
     */
    if ($dailyTable == "dailyfeatures") {

        $sent = $db->prepare("
            UPDATE dailyfeatures
            SET webhookSent = 1
            WHERE feaID = :feaID
              AND type = :type
        ");

        $sent->execute(array(
            ":feaID" => $feaID,
            ":type" => $type
        ));

    } elseif ($dailyTable == "events") {

        $sent = $db->prepare("
            UPDATE events
            SET webhookSent = 1
            WHERE feaID = :feaID
        ");

        $sent->execute(array(
            ":feaID" => $feaID
        ));
    }

    /*

* Creator Points
*
* The original code used
* $accountID, but it doesn't exist here.
* To avoid an undefined variable,
* we only execute this if it exists.
  */
    if (
        isset($automaticCron) &&
        $automaticCron &&
        isset($accountID)
    ) {
        Cron::updateCreatorPoints(
            $accountID,
            false
        );
    }
}

/*
 * ========================================
 * EVENT RESPONSE
 * ========================================
 *
 * Events use an Special Function
 * Sa1nt:XOR...
 */

$stringToAdd = "";

if ($isEvent) {

    /*
     * "chk" is necessary to generate
     * the token of the event.
     */
    if (
        !isset($_POST["chk"]) ||
        $_POST["chk"] === ""
    ) {
        exit("-1");
    }

    /*
     * clean chk.
     */
    $chkInput = ExploitPatch::charclean(
        $_POST["chk"]
    );

    if (strlen($chkInput) <= 5) {
        exit("-1");
    }

    $chk = XORCipher::cipher(
        ExploitPatch::url_base64_decode(
            substr($chkInput, 5)
        ),
        59182
    );

    /*
     * create event string 
     */
    $eventString =
        "Sa1nt:" .
        $chk .
        ":" .
        ($feaID + 19) .
        ":3:" .
        (isset($daily["rewards"]) ? $daily["rewards"] : 0);

    $string = ExploitPatch::url_base64_encode(
        XORCipher::cipher(
            $eventString,
            59182
        )
    );

    /*
     * Use the 10-second protocol
     */
    $timeleft = 10;

    /*
     * Generatehash.
     */
    $hash = $gh->genSolo4($string);

    $stringToAdd =
        "|Sa1nt" .
        $string .
        "|" .
        $hash;
}

/*
 * ========================================
 * RESPONSE
 * ========================================
 *
 * Daily:
 *
 *      dailyID|timeleft
 *
 * Event:
 *
 *      dailyID|10|Sa1nt...|hash
 */

echo $dailyID . "|" . $timeleft . $stringToAdd;

?>
