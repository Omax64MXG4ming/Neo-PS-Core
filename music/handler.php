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
 * MUSIC LIBRARY DATA
 * ==========================================================
 *
 * Geometry Dash originalmente utiliza:
 *
 * musiclibrary_02.dat
 *
 * Pero Neo PS genera:
 *
 * gdps.dat
 *
 * gdps.dat contiene:
 *
 * - música oficial
 * - música local del GDPS
 * - mappings de Neo PS
 *
 * Por eso el cliente recibe gdps.dat.
 */
if(
	$file === 'musiclibrary.dat' ||
	$file === 'musiclibrary_02.dat'
) {

	$datFile = isset($_GET['dashboard'])
		? 'standalone.dat'
		: 'gdps.dat';

	/*
	 * Si todavía no existe la librería generada,
	 * generarla.
	 */
	if(!file_exists($datFile)) {

		$time = $db->prepare(
			'SELECT reuploadTime
			 FROM songs
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
			1
		);
	}

	/*
	 * Enviar la librería generada por Neo PS.
	 */
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
 * MUSIC LIBRARY VERSION
 * ==========================================================
 *
 * Neo PS utiliza su propia versión para determinar
 * cuándo debe regenerar/actualizar la librería.
 */
if(
	$file === 'musiclibrary_version.txt' ||
	$file === 'musiclibrary_version_02.txt'
) {

	$time = $db->prepare(
		'SELECT reuploadTime
		 FROM songs
		 WHERE reuploadTime > 0
		 ORDER BY reuploadTime DESC
		 LIMIT 1'
	);

	$time->execute();

	$time = $time->fetchColumn();

	if(!$time) {
		$time = 1;
	}

	/*
	 * Actualizar las librerías externas y generar
	 * gdps.dat si es necesario.
	 */
	$gs->updateLibraries(
		$_GET['token'] ?? '',
		$_GET['expires'] ?? '',
		$time,
		1
	);

	/*
	 * La versión que recibe el juego es la versión
	 * de nuestra librería generada.
	 */
	$gdpsVersionFile = 'gdps.txt';

	if(file_exists($gdpsVersionFile)) {

		$versionData = trim(
			file_get_contents($gdpsVersionFile)
		);

		/*
		 * gdps.txt normalmente contiene:
		 *
		 * timestamp
		 */
		if($versionData !== '') {

			header('Content-Type: text/plain');

			echo $versionData;

			exit;
		}
	}

	/*
	 * Fallback.
	 */
	header('Content-Type: text/plain');

	echo $time;

	exit;
}


/*
 * ==========================================================
 * MUSIC OGG
 * ==========================================================
 */
$musicID = explode('.', $file)[0];

if(!is_numeric($musicID)) {

	http_response_code(400);

	exit("Invalid music ID");
}

$musicID = (int)$musicID;


/*
 * ==========================================================
 * OFFICIAL GEOMETRY DASH MUSIC
 * ==========================================================
 *
 * Las canciones oficiales modernas conservan
 * su ID original.
 *
 * Ejemplo:
 *
 * 10013011
 *     ↓
 * 10013011.ogg
 */
if($musicID >= 10000000) {

	$token = $gs->randomString(11);

	$expires = time() + 3600;

	$url =
		"https://geometrydashfiles.b-cdn.net/music/" .
		$musicID .
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
 * IDS.JSON
 * ==========================================================
 *
 * for older songs or songs from other libraries,
 * o IDs internos de Neo PS.
 */
if(!file_exists('ids.json')) {

	$time = $db->prepare(
		'SELECT reuploadTime
		 FROM songs
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
		1
	);
}


/*
 * ==========================================================
 * LIBRARY SONG
 * ==========================================================
 */
$song = $gs->getLibrarySongInfo(
	$musicID,
	'music'
);

if($song) {

	$url = urldecode(
		$song['download']
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
 * LOCAL SONG
 * ==========================================================
 *
 * Fallback for the music stored in this GDPS via Database
 */
$download = $gs->getSongInfo(
	$musicID,
	'download'
);

if(!empty($download)) {

	$url = urldecode(
		$download
	);

	header(
		"Location: https://neops.x10.mx/database/music/"
		
	);

	exit;
}


/*
 * ==========================================================
 * NEWGROUNDS FALLBACK
 * ==========================================================
 Not added, fixing bugs to download music from any URL
 */


?>
