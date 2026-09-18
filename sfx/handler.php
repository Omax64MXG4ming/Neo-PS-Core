<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header("Access-Control-Allow-Headers: X-Requested-With");
require_once "../incl/lib/connection.php";
require_once "../incl/lib/mainLib.php";

require "../config/dashboard.php";
require "../config/proxy.php";

$gs = new mainLib();

$file = trim(basename($_GET['request'] ?? ''));

if(empty($file)) {
	http_response_code(400);
	exit("Invalid request");
}


/*
 * ==========================================================
 * SFX LIBRARY
 * ==========================================================
 */

if($file === 'sfxlibrary.dat') {

	$datFile = isset($_GET['dashboard'])
		? 'standalone.dat'
		: 'gdps.dat';

	if(!file_exists($datFile)) {

		$time = $db->prepare(
			'SELECT reuploadTime
			 FROM sfxs
			 WHERE reuploadTime > 0
			 ORDER BY reuploadTime DESC
			 LIMIT 1'
		);

		$time->execute();

		$time = $time->fetchColumn();

		if(!$time) {
			$time = time();
		}

		$gs->updateLibraries(
			$_GET['token'] ?? '',
			$_GET['expires'] ?? '',
			$time,
			0
		);
	}

	if(file_exists($datFile)) {

		header(
			'Content-Type: application/octet-stream'
		);

		header(
			'Content-Length: ' . filesize($datFile)
		);

		readfile($datFile);

		exit;
	}

	http_response_code(404);
	exit("-1");
}


/*
 * ==========================================================
 * SFX LIBRARY VERSION
 * ==========================================================
 */

if($file === 'sfxlibrary_version.txt') {

	$time = $db->prepare(
		'SELECT reuploadTime
		 FROM sfxs
		 WHERE reuploadTime > 0
		 ORDER BY reuploadTime DESC
		 LIMIT 1'
	);

	$time->execute();

	$time = $time->fetchColumn();

	if(!$time) {
		$time = 1;
	}

	$gs->updateLibraries(
		$_GET['token'] ?? '',
		$_GET['expires'] ?? '',
		$time,
		0
	);

	$times = [];

	foreach($customLibrary AS $library) {

		if($library[2] !== null) {

			$versionFile = 's'.$library[0].'.txt';

			if(file_exists($versionFile)) {

				$data = explode(
					', ',
					file_get_contents($versionFile)
				);

				if(isset($data[1])) {
					$times[] = $data[1];
				}
			}
		}
	}

	$times[] = $time;

	rsort($times);

	header('Content-Type: text/plain');

	echo $times[0];

	exit;
}


/*
 * ==========================================================
 * SFX FILE
 * ==========================================================
 */

if(!preg_match('/^s([0-9]+)\.ogg$/', $file, $match)) {

	http_response_code(400);
	exit("Invalid SFX ID");
}

$sfxID = (int)$match[1];


/*
 * ==========================================================
 * LOAD IDS.JSON
 * ==========================================================
 */

if(!file_exists('ids.json')) {

	$time = $db->prepare(
		'SELECT reuploadTime
		 FROM sfxs
		 WHERE reuploadTime > 0
		 ORDER BY reuploadTime DESC
		 LIMIT 1'
	);

	$time->execute();

	$time = $time->fetchColumn();

	if(!$time) {
		$time = time();
	}

	$gs->updateLibraries(
		$_GET['token'] ?? '',
		$_GET['expires'] ?? '',
		$time,
		0
	);
}


$library = [];

if(file_exists('ids.json')) {

	$library = json_decode(
		file_get_contents('ids.json'),
		true
	);

	if(!is_array($library)) {
		$library = [];
	}
}


/*
 * ==========================================================
 * GEOMETRY DASH SFX
 * ==========================================================
 *
 * IMPORTANT:
 *
 * originalIDs:
 *
 *     original SFX ID -> internal ID
 *
 * Example:
 *
 *     108 -> 8000108
 *
 * We must NOT send 8000108 to the official CDN.
 *
 * The CDN needs:
 *
 *     s108.ogg
 *
 * Therefore we resolve the internal ID back to
 * its originalID before creating the CDN URL.
 */


/*
 * ----------------------------------------------------------
 * CASE 1
 * ----------------------------------------------------------
 *
 * The requested ID itself is an internal ID.
 *
 * Example:
 *
 * /s8000108.ogg
 *
 * ids.json:
 *
 * "8000108": {
 *     "server": 1,
 *     "originalID": "108"
 * }
 *
 */

$geometryOriginalID = null;

if(
	isset($library['IDs'][(string)$sfxID]) &&
	is_array($library['IDs'][(string)$sfxID])
) {

	$songData = $library['IDs'][(string)$sfxID];

	if(
		isset($songData['server']) &&
		(int)$songData['server'] === 1
	) {

		if(
			isset($songData['originalID']) &&
			is_numeric($songData['originalID'])
		) {

			$geometryOriginalID =
				(int)$songData['originalID'];
		}
	}
}


/*
 * ----------------------------------------------------------
 * CASE 2
 * ----------------------------------------------------------
 *
 * The requested ID is already the original
 * Geometry Dash SFX ID.
 *
 * Example:
 *
 * /s108.ogg
 *
 * ids.json:
 *
 * originalIDs:
 *
 * "1": {
 *     "108": 8000108
 * }
 *
 * We look up the internal ID and then retrieve
 * its originalID.
 *
 */

if($geometryOriginalID === null) {

	if(
		isset($library['originalIDs']['1']) &&
		isset(
			$library['originalIDs']['1'][
				(string)$sfxID
			]
		)
	) {

		$internalID =
			$library['originalIDs']['1'][
				(string)$sfxID
			];

		/*
		 * The mapping may be an integer/string ID.
		 */
		$internalID = (string)$internalID;

		if(
			isset($library['IDs'][$internalID]) &&
			is_array($library['IDs'][$internalID])
		) {

			$songData =
				$library['IDs'][$internalID];

			if(
				isset($songData['server']) &&
				(int)$songData['server'] === 1
			) {

				if(
					isset($songData['originalID']) &&
					is_numeric(
						$songData['originalID']
					)
				) {

					$geometryOriginalID =
						(int)$songData['originalID'];
				}
			}
		}
	}
}


/*
 * ----------------------------------------------------------
 * CASE 3
 * ----------------------------------------------------------
 *
 * If the ID is explicitly stored in ids.json as an
 * official Geometry Dash ID, use originalID.
 *
 */

if($geometryOriginalID === null) {

	if(
		isset($library['IDs'][(string)$sfxID]) &&
		is_array($library['IDs'][(string)$sfxID])
	) {

		$songData =
			$library['IDs'][(string)$sfxID];

		if(
			isset($songData['server']) &&
			(int)$songData['server'] === 1 &&
			isset($songData['originalID'])
		) {

			$geometryOriginalID =
				(int)$songData['originalID'];
		}
	}
}


/*
 * ==========================================================
 * REDIRECT OFFICIAL GEOMETRY DASH SFX
 * ==========================================================
 */

if($geometryOriginalID !== null) {

	$token = $gs->randomString(11);
	$expires = time() + 3600;

	$url =
		"https://geometrydashfiles.b-cdn.net/sfx/s" .
		$geometryOriginalID .
		".ogg?token=" .
		$token .
		"&expires=" .
		$expires;

	header(
		"Location: " . $url,
		true,
		302
	);

	exit;
}


/*
 * ==========================================================
 * OTHER LIBRARIES
 * ==========================================================
 *
 * For non-Geometry Dash libraries, use the library
 * information generated by mainLib.php.
 */

$sfx = $gs->getLibrarySongInfo(
	$sfxID,
	'sfx'
);

if($sfx) {

	$url = urldecode(
		$sfx['download']
	);

	if(!empty($url)) {

		header(
			"Location: " . $url,
			true,
			302
		);

		exit;
	}
}


/*
 * ==========================================================
 * LOCAL NEO PS SFX
 * ==========================================================
 */

$download = $gs->getSFXInfo(
	$sfxID,
	'download'
);

if(!empty($download)) {

	header(
		"Location: " . urldecode($download),
		true,
		302
	);

	exit;
}


/*
 * ==========================================================
 * NOT FOUND
 * ==========================================================
 */

http_response_code(404);

exit("-1");

?>
