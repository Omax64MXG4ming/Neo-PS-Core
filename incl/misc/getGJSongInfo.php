<?php

chdir(dirname(__FILE__));

require "../lib/connection.php";
require_once "../lib/songReup.php";
require_once "../lib/exploitPatch.php";
require_once "../lib/mainLib.php";

$gs = new mainLib();

require "../../config/proxy.php";


// ======================================================
// SONG ID
// ======================================================

if(empty($_POST["songID"])) {
	exit("-1");
}

$songid = ExploitPatch::remove($_POST["songID"]);

if(!is_numeric($songid)) {
	exit("-1");
}

$songid = (int)$songid;


// ======================================================
// LOCAL SONG
// ======================================================
//
// Neo PS songs are stored in the songs table.
//
// We check local songs first so that a GDPS song
// cannot accidentally be treated as an external
// library song.
//

$query3 = $db->prepare(
	"SELECT
		ID,
		name,
		authorID,
		authorName,
		size,
		isDisabled,
		download
	FROM songs
	WHERE ID = :songid
	LIMIT 1"
);

$query3->execute([
	':songid' => $songid
]);


// ======================================================
// LIBRARY SONG
// ======================================================
//
// This handles:
//
// - Geometry Dash library
// - Other custom libraries
// - Neo PS ids.json
//

$librarySong = $gs->getLibrarySongInfo(
	$songid,
	'music'
);


// ======================================================
// OFFICIAL GEOMETRY DASH MUSIC
// ======================================================
//
// Official newer Geometry Dash music IDs are kept
// exactly as their original IDs.
//
// Example:
//
// 10013011
//      ↓
// 10013011
//
// They are NOT converted into 8xxxxxx aliases.
//
// The actual .ogg file is handled by:
// database/music/handler.php
//

$isOfficialMusicID = (
	$songid >= 10000000 &&
	$query3->rowCount() == 0 &&
	!$librarySong
);


// ======================================================
// OFFICIAL MUSIC RESPONSE
// ======================================================

if($isOfficialMusicID) {

	$token = $gs->randomString(11);
	$expires = time() + 3600;

	$download =
		"/music/" .
		$songid .
		".ogg?token=" .
		$token .
		"&expires=" .
		$expires;

	echo
		"1~|~".$songid.
		"~|~2~|~Geometry Dash Music".
		"~|~3~|~0".
		"~|~4~|~RobTop".
		"~|~5~|~0".
		"~|~6~|~~".
		"~|~7~|~~".
		"~|~8~|~0".
		"~|~10~|~".$download;

	exit;
}


// ======================================================
// SONG NOT FOUND
// ======================================================
//
// If the song isn't local and isn't in ids.json,
// try the external Newgrounds reupload system.
//

if(
	$query3->rowCount() == 0 &&
	!$librarySong
) {

	$url = "https://ng.dimisaio.workers.dev";

	$data = [
		'songID' => $songid,
		'secret' => 'Wmfd2893gb7'
	];

	$ch = curl_init($url);


	// --------------------------------------------------
	// PROXY
	// --------------------------------------------------

	if($proxytype == 1) {

		curl_setopt(
			$ch,
			CURLOPT_PROXY,
			$host
		);

	}
	elseif($proxytype == 2) {

		curl_setopt(
			$ch,
			CURLOPT_PROXY,
			$host
		);

		curl_setopt(
			$ch,
			CURLOPT_PROXYTYPE,
			CURLPROXY_SOCKS5
		);
	}


	// --------------------------------------------------
	// PROXY AUTH
	// --------------------------------------------------

	if(!empty($auth)) {

		curl_setopt(
			$ch,
			CURLOPT_PROXYUSERPWD,
			$auth
		);
	}


	// --------------------------------------------------
	// CURL
	// --------------------------------------------------

	curl_setopt(
		$ch,
		CURLOPT_RETURNTRANSFER,
		true
	);

	curl_setopt(
		$ch,
		CURLOPT_POSTFIELDS,
		http_build_query($data)
	);

	curl_setopt(
		$ch,
		CURLOPT_USERAGENT,
		''
	);

	curl_setopt(
		$ch,
		CURLOPT_PROTOCOLS,
		CURLPROTO_HTTP | CURLPROTO_HTTPS
	);


	$result = curl_exec($ch);

	curl_close($ch);


	// --------------------------------------------------
	// REUPLOAD ERROR
	// --------------------------------------------------

	if(
		$result == "-2" ||
		$result == "-1" ||
		$result == ""
	) {
		exit;
	}


	// --------------------------------------------------
	// REUPLOAD
	// --------------------------------------------------

	$reup = SongReup::reup($result);

	exit;
}


// ======================================================
// LOCAL OR LIBRARY SONG
// ======================================================

$result4 = !$librarySong
	? $query3->fetch()
	: $librarySong;


// ======================================================
// SAFETY CHECK
// ======================================================

if(!$result4) {
	exit("-1");
}


// ======================================================
// DISABLED SONG
// ======================================================

if(
	isset($result4["isDisabled"]) &&
	$result4["isDisabled"] == 1
) {
	exit("-2");
}


// ======================================================
// DOWNLOAD URL
// ======================================================

$dl = $result4["download"];

if(
	!empty($dl) &&
	strpos($dl, ':') !== false
) {
	$dl = urlencode($dl);
}


// ======================================================
// STANDARD GD RESPONSE
// ======================================================

echo
	"1~|~".$result4["ID"].
	"~|~2~|~".
	ExploitPatch::translit(
		str_replace(
			"#",
			"",
			$result4["name"]
		)
	).
	"~|~3~|~".$result4["authorID"].
	"~|~4~|~".
	ExploitPatch::translit(
		$result4["authorName"]
	).
	"~|~5~|~".$result4["size"].
	"~|~6~|~~".
	"~|~7~|~~".
	"~|~8~|~0".
	"~|~10~|~".$dl;


// ======================================================
// LIBRARY EXTRA DATA
// ======================================================
//
// Only custom/library music has these extra fields.
//

if($librarySong) {

	$artistsNames = [];

	$artistsArray = explode(
		'.',
		$result4['artists']
	);

	if(count($artistsArray) > 0) {

		foreach(
			$artistsArray AS $artistID
		) {

			if(empty($artistID)) {
				continue;
			}

			$artistData =
				$gs->getLibrarySongAuthorInfo(
					$artistID
				);

			if(!$artistData) {
				continue;
			}

			$artistsNames[] = $artistID;
			$artistsNames[] = $artistData['name'];
		}
	}

	$artistsNames = implode(
		',',
		$artistsNames
	);


	echo
		'~|~9~|~'.$result4['priorityOrder'].
		'~|~11~|~'.$result4['ncs'].
		'~|~12~|~'.$result4['artists'].
		'~|~13~|~'.($result4['new'] ? 1 : 0).
		'~|~14~|~'.$result4['new'].
		'~|~15~|~'.$artistsNames;
}

?>
