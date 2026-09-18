<?php

/*
 * =
 * Neo PS - registerGJAccount.php
 * =
 *
 * endpoint used by the original GDPS and cocos2dx client, QUERY Array 
 *
 * Results by Operations :
 *
 *  1  = Registration Successfully
 * -1  = Need more Data / error
 * -2  = Existing User with That Data
 * -3  = Existing Email
 * -4  = Invalid Name / not available
 * -6  = Invalid Email
 * -8  = Password so Short
 * -9  = userName so Short
 *
 * ==========
 */

require "../config/security.php";
require "../config/mail.php";
require "../incl/lib/connection.php";

require_once "../incl/lib/mainLib.php";
require_once "../incl/lib/exploitPatch.php";
require_once "../incl/lib/generatePass.php";
require_once "../incl/lib/automod.php";


/*
 * ========================================
 * INITIALIZE
 * ========================================
 */

$gs = new mainLib();


/*
 * ========================================
 * CONFIG DEFAULTS
 * ========================================
 *
 * Avoid undefined variables if any configuration does not exist.

*
 */

if (!isset($preactivateAccounts)) {
    $preactivateAccounts = true;
}

if (!isset($filterUsernames)) {
    $filterUsernames = 0;
}

if (!isset($mailEnabled)) {
    $mailEnabled = false;
}


/*
 * ========================================
 * ACCOUNTS DISABLED?
 * ========================================
 */

if (Automod::isAccountsDisabled(0)) {
    exit("-1");
}


/*
 * ========================================
 * CHECK REQUIRED FIELDS
 * ========================================
 */

if (
    !isset($_POST["userName"]) ||
    !isset($_POST["password"]) ||
    !isset($_POST["email"]) ||
    $_POST["userName"] === "" ||
    $_POST["password"] === "" ||
    $_POST["email"] === ""
) {
    exit("-1");
}


/*
 * ========================================
 * GET DATA
 * ========================================
 */

$userName = str_replace(
    " ",
    "",
    ExploitPatch::charclean($_POST["userName"])
);

$password = $_POST["password"];

$email = ExploitPatch::rucharclean($_POST["email"]);


/*
 * ========================================
 * USERNAME FILTER
 * ========================================
 */

if ($filterUsernames >= 1) {

    /*
     * Let's make sure the list exists.
     */
    if (!isset($bannedUsernames) || !is_array($bannedUsernames)) {
        $bannedUsernames = array();
    }

    $bannedUsernamesList = array_map(
        "strtolower",
        $bannedUsernames
    );


    switch ($filterUsernames) {

        /*
         * exact match
         */
        case 1:

            if (
                in_array(
                    strtolower($userName),
                    $bannedUsernamesList
                )
            ) {
                exit("-4");
            }

            break;


        /*

* Contains a forbidden word
*/
        case 2:

            foreach ($bannedUsernamesList as $bannedUsername) {

                if (
                    !empty($bannedUsername) &&
                    mb_strpos(
                        strtolower($userName),
                        $bannedUsername
                    ) !== false
                ) {
                    exit("-4");
                }
            }

            break;
    }
}


/*
 * ========================================
 * USERNAME VALIDATION
 * ========================================
 */

if (strlen($userName) > 20) {
    exit("-4");
}

if (strlen($userName) < 3) {
    exit("-9");
}


/*
 * ========================================
 * PASSWORD VALIDATION
 * ========================================
 */

if (strlen($password) < 6) {
    exit("-8");
}


/*
 * ========================================
 * EMAIL VALIDATION
 * ========================================
 */

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    exit("-6");
}


/*
 * ========================================
 * CHECK DUPLICATE EMAIL
 * ========================================
 *
 * This only works if the email system

is enabled, just like in the original code.

*
 */

if ($mailEnabled) {

    $checkMail = $db->prepare("
        SELECT COUNT(*)
        FROM accounts
        WHERE email = :mail
    ");

    $checkMail->execute(array(
        ":mail" => $email
    ));

    if ($checkMail->fetchColumn() > 0) {
        exit("-3");
    }
}


/*
 * ========================================
 * CHECK DUPLICATE USERNAME
 * ========================================
 *
 * We use "=" because we want an

exact match.

*/

$query2 = $db->prepare("
    SELECT COUNT(*)
    FROM accounts
    WHERE userName = :userName
");

$query2->execute(array(
    ":userName" => $userName
));

$regusrs = $query2->fetchColumn();

if ($regusrs > 0) {
    exit("-2");
}


/*
 * ========================================
 * GENERATE PASSWORDS
 * ========================================
 */

/*
 * internal Password in the Server
 */
$hashpass = password_hash(
    $password,
    PASSWORD_DEFAULT
);


/*

* GJP2 used by the Actual Core.

*/
$gjp2 = GeneratePass::GJP2hash(
    $password
);


/*
 * ========================================
 * REGISTER ACCOUNT
 * ========================================
 */

$query = $db->prepare("
    INSERT INTO accounts
    (
        userName,
        password,
        email,
        registerDate,
        isActive,
        gjp2
    )
    VALUES
    (
        :userName,
        :password,
        :email,
        :time,
        :isActive,
        :gjp
    )
");


/*
 * ========================================
 * EXECUTE INSERT
 * ========================================
 */

$success = $query->execute(array(
    ":userName" => $userName,
    ":password" => $hashpass,
    ":email" => $email,
    ":time" => time(),
    ":isActive" => $preactivateAccounts ? 1 : 0,
    ":gjp" => $gjp2
));


/*
 * ========================================
 * DATABASE ERROR
 * ========================================
 */

if (!$success) {
    exit("-1");
}


/*
 * ========================================
 * GET ACCOUNT ID
 * ========================================
 */

$accountID = $db->lastInsertId();

if (!$accountID) {
    exit("-1");
}


/*
 * ========================================
 * SUCCESS
 * ========================================
 *
 * IMPORTANT:

*
* The client expects "1" when registration

was successful.

*/

echo "1";


/*
 * ========================================
 * LOG REGISTER
 * ========================================
 */

try {

    $gs->logAction(
        $accountID,
        1,
        $userName,
        $email,
        $gs->getUserID(
            $accountID,
            $userName
        )
    );

} catch (Exception $e) {

    /*

* A logging error should not cause

account registration to fail.

*/
}


/*
 * ========================================
 * DISCORD / WEBHOOK LOG
 * ========================================
 */

try {

    $gs->sendLogsRegisterWebhook(
        $accountID
    );

} catch (Exception $e) {

    /*
* The webhook must not affect
* the registry.

*/
}

/*
 * ========================================
 * REGISTRATION EMAIL
 * ========================================
 */

if ($mailEnabled) {

    try {

        $gs->mail(
            $email,
            $userName
        );

    } catch (Exception $e) {

        /*

* Registration has already been completed.

* An email failure should not

turn the registration into an error.

*/
    }
}

?>
