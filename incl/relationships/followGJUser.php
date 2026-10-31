<?php
chdir(dirname(__FILE__));

require "../lib/connection.php";
require "../../config/misc.php";
require_once "../lib/GJPCheck.php";
require_once "../lib/exploitPatch.php";
require_once "../lib/mainLib.php";
require_once "../lib/cron.php";

$gs = new mainLib();

if (empty($_POST["targetAccountID"])) {
    exit("-1");
}

$accountID = GJPCheck::getAccountIDOrDie();
$targetAccountID = ExploitPatch::remove($_POST["targetAccountID"]);

if (!is_numeric($targetAccountID) || $accountID == $targetAccountID) {
    exit("-1");
}

$targetAccountID = (int) $targetAccountID;

// Check if tracking already exists
$query = $db->prepare("
    SELECT 1
    FROM follows
    WHERE person1 = :accountID
      AND person2 = :targetAccountID
    LIMIT 1
");

$query->execute([
    ':accountID' => $accountID,
    ':targetAccountID' => $targetAccountID
]);

if ($query->fetchColumn()) {
    // It already exists: returning the same result without duplication
    exit("1");
}

// Insert the new tracking
$query = $db->prepare("
    INSERT INTO follows (person1, person2, timestamp)
    VALUES (:accountID, :targetAccountID, :timestamp)
");

$query->execute([
    ':accountID' => $accountID,
    ':targetAccountID' => $targetAccountID,
    ':timestamp' => time()
]);

if ($automaticCron) {
    Cron::updateFriendsCount($accountID, false);
}

$gs->logAction($accountID, 80, $targetAccountID);

echo "1";
