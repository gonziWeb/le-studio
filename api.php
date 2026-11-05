<?php
/**
 * Le Studio — API (PHP 7.4+, sans base de données)
 *
 * Les données (morceaux audio + fichier JSON) sont rangées HORS du dossier
 * du site, pour ne pas être effacées par un déploiement Git et ne pas être
 * accessibles directement depuis le web.
 */

// Dossier des données. Par défaut : un dossier "le-studio-data" à côté du dossier du site.
// Si ce dossier n'est pas accessible en écriture, on se rabat sur ./data (protégé par .htaccess).
$DATA_DIR = dirname(__DIR__) . '/le-studio-data';

const MAX_TITLE = 80;
const MAX_NOTE = 1000;
const MAX_COMMENT = 1000;
const ALLOWED_EXT = ['mp3', 'm4a', 'mp4', 'aac', 'wav', 'ogg', 'oga', 'opus', 'webm', 'flac', 'caf', 'mov', '3gp', 'amr'];

// ---------------------------------------------------------------------------

if (!is_dir($DATA_DIR) && !@mkdir($DATA_DIR, 0750, true)) {
    $DATA_DIR = __DIR__ . '/data';
    if (!is_dir($DATA_DIR)) @mkdir($DATA_DIR, 0750, true);
}
if (!is_writable($DATA_DIR)) fail(500, "Le dossier de données n'est pas accessible en écriture.");
$AUDIO_DIR = $DATA_DIR . '/audio';
if (!is_dir($AUDIO_DIR)) @mkdir($AUDIO_DIR, 0750, true);
$CONF = $DATA_DIR . '/config.json';
$DB = $DATA_DIR . '/db.json';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$a = $_GET['a'] ?? '';

// ---------- lecture audio (GET, avec Range pour iPhone) ----------
if ($a === 'audio') {
    requireAuth();
    $id = $_GET['id'] ?? '';
    if (!preg_match('/^[a-f0-9]{24}$/', $id)) fail(404, 'Introuvable');
    $db = readDb();
    $t = null;
    foreach ($db['tracks'] as $x) if ($x['id'] === $id) { $t = $x; break; }
    if (!$t) fail(404, 'Introuvable');
    streamFile($AUDIO_DIR . '/' . $t['file'], $t['mime'] ?: 'application/octet-stream');
    exit;
}

header('Content-Type: application/json; charset=utf-8');

// Protection CSRF simple : toutes les requêtes de l'API viennent de notre page.
if (($_SERVER['HTTP_X_STUDIO'] ?? '') !== '1') fail(403, 'Requête refusée');

$conf = readConf();

switch ($a) {
    case 'status':
        out([
            'setup' => $conf !== null,
            'auth' => $conf !== null && isAuthed($conf),
            'maxUpload' => maxUpload(),
        ]);

    case 'setup': // premier lancement : choisir le mot de passe partagé
        if ($conf !== null) fail(403, 'Déjà configuré');
        $pw = (string)($_POST['password'] ?? '');
        if (mb_strlen($pw) < 6) fail(400, 'Mot de passe trop court (6 caractères minimum).');
        $conf = ['hash' => password_hash($pw, PASSWORD_DEFAULT), 'secret' => bin2hex(random_bytes(32))];
        writeJson($CONF, $conf);
        setAuthCookie($conf);
        out(['ok' => true]);

    case 'login':
        if ($conf === null) fail(400, 'Pas encore configuré');
        usleep(400000); // freine les essais en boucle
        if (!password_verify((string)($_POST['password'] ?? ''), $conf['hash'])) fail(401, 'Mot de passe incorrect.');
        setAuthCookie($conf);
        out(['ok' => true]);

    case 'logout':
        setcookie('studio', '', ['expires' => time() - 3600, 'path' => cookiePath(), 'samesite' => 'Strict', 'secure' => isHttps(), 'httponly' => true]);
        out(['ok' => true]);

    case 'state':
        requireAuth();
        $db = readDb();
        $since = (int)($_GET['since'] ?? -1);
        if ($since === $db['rev']) out(['same' => true, 'rev' => $db['rev']]);
        out($db);

    case 'project':
        requireAuth();
        $name = clean($_POST['name'] ?? '', 50);
        if ($name === '') fail(400, 'Nom manquant');
        $p = ['id' => newId(), 'name' => $name, 'author' => author(), 'createdAt' => nowMs()];
        mutate(function (&$db) use ($p) { $db['projects'][] = $p; });
        out($p);

    case 'upload':
        requireAuth();
        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $code = $_FILES['file']['error'] ?? -1;
            if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE || empty($_FILES))
                fail(413, 'Fichier trop gros pour le serveur (max ' . round(maxUpload() / 1048576) . ' Mo).');
            fail(400, "L'envoi du fichier a échoué.");
        }
        $f = $_FILES['file'];
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ALLOWED_EXT, true)) fail(400, 'Format non pris en charge (' . $ext . ').');
        $mime = audioMime($f['tmp_name'], $ext);
        if ($mime === null) fail(400, "Ce fichier ne ressemble pas à de l'audio.");
        $id = newId();
        $file = $id . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], $AUDIO_DIR . '/' . $file)) fail(500, "Impossible d'enregistrer le fichier.");
        $peaks = json_decode((string)($_POST['peaks'] ?? '[]'), true);
        $peaks = is_array($peaks) ? array_slice(array_map(function ($v) { return round(max(0, min(1, (float)$v)), 2); }, $peaks), 0, 400) : [];
        $projectId = (string)($_POST['projectId'] ?? '');
        $t = [
            'id' => $id,
            'title' => clean($_POST['title'] ?? '', MAX_TITLE) ?: 'Sans titre',
            'note' => clean($_POST['note'] ?? '', MAX_NOTE, true),
            'projectId' => preg_match('/^[a-f0-9]{24}$/', $projectId) ? $projectId : null,
            'file' => $file,
            'mime' => $mime,
            'size' => (int)$f['size'],
            'duration' => round(max(0, (float)($_POST['duration'] ?? 0)), 1),
            'peaks' => $peaks,
            'author' => author(),
            'createdAt' => nowMs(),
        ];
        mutate(function (&$db) use ($t) { $db['tracks'][] = $t; });
        out($t);

    case 'comment':
        requireAuth();
        $trackId = (string)($_POST['trackId'] ?? '');
        $text = clean($_POST['text'] ?? '', MAX_COMMENT, true);
        if ($text === '' || !preg_match('/^[a-f0-9]{24}$/', $trackId)) fail(400, 'Commentaire vide');
        $c = ['id' => newId(), 'trackId' => $trackId, 'text' => $text, 'author' => author(), 'createdAt' => nowMs()];
        if (isset($_POST['t']) && $_POST['t'] !== '') $c['t'] = round(max(0, (float)$_POST['t']), 1);
        mutate(function (&$db) use ($c) { $db['comments'][] = $c; });
        out($c);

    case 'delete':
        requireAuth();
        $type = $_POST['type'] ?? '';
        $id = (string)($_POST['id'] ?? '');
        if (!in_array($type, ['track', 'comment', 'project'], true) || !preg_match('/^[a-f0-9]{24}$/', $id)) fail(400, 'Requête invalide');
        $files = [];
        mutate(function (&$db) use ($type, $id, &$files) {
            if ($type === 'comment') {
                $db['comments'] = array_values(array_filter($db['comments'], function ($c) use ($id) { return $c['id'] !== $id; }));
            } elseif ($type === 'track') {
                foreach ($db['tracks'] as $t) if ($t['id'] === $id) $files[] = $t['file'];
                $db['tracks'] = array_values(array_filter($db['tracks'], function ($t) use ($id) { return $t['id'] !== $id; }));
                $db['comments'] = array_values(array_filter($db['comments'], function ($c) use ($id) { return $c['trackId'] !== $id; }));
            } else {
                $db['projects'] = array_values(array_filter($db['projects'], function ($p) use ($id) { return $p['id'] !== $id; }));
                foreach ($db['tracks'] as &$t) if ($t['projectId'] === $id) $t['projectId'] = null;
                unset($t);
            }
        });
        foreach ($files as $fn) @unlink($AUDIO_DIR . '/' . basename($fn));
        out(['ok' => true]);

    default:
        fail(404, 'Action inconnue');
}

// ===========================================================================

function out($data) { echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
function fail($code, $msg) {
    http_response_code($code);
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}
function nowMs() { return (int)round(microtime(true) * 1000); }
function newId() { return bin2hex(random_bytes(12)); }
function clean($s, $max, $multiline = false) {
    $s = trim((string)$s);
    $s = $multiline ? preg_replace('/[^\P{C}\n]/u', '', $s) : preg_replace('/\p{C}/u', '', $s);
    return mb_substr((string)$s, 0, $max);
}
function author() { return clean($_POST['author'] ?? '', 30) ?: 'Quelqu\'un'; }

function readJson($path) {
    if (!is_file($path)) return null;
    $j = json_decode((string)file_get_contents($path), true);
    return is_array($j) ? $j : null;
}
function writeJson($path, $data) {
    $tmp = $path . '.tmp' . getmypid();
    file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE));
    rename($tmp, $path);
}
function readConf() { global $CONF; return readJson($CONF); }
function emptyDb() { return ['rev' => 0, 'projects' => [], 'tracks' => [], 'comments' => []]; }
function readDb() { global $DB; return readJson($DB) ?: emptyDb(); }
function mutate(callable $fn) {
    global $DB;
    $lock = fopen($DB . '.lock', 'c');
    flock($lock, LOCK_EX);
    $db = readJson($DB) ?: emptyDb();
    $fn($db);
    $db['rev'] = ($db['rev'] ?? 0) + 1;
    writeJson($DB, $db);
    flock($lock, LOCK_UN);
    fclose($lock);
}

function isHttps() {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443);
}
function cookiePath() { $d = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/'); return $d === '' ? '/' : $d . '/'; }
function token($conf) { return hash_hmac('sha256', 'studio-v1', $conf['secret']); }
function setAuthCookie($conf) {
    setcookie('studio', token($conf), [
        'expires' => time() + 365 * 86400, 'path' => cookiePath(),
        'secure' => isHttps(), 'httponly' => true, 'samesite' => 'Strict',
    ]);
}
function isAuthed($conf) { return isset($_COOKIE['studio']) && hash_equals(token($conf), (string)$_COOKIE['studio']); }
function requireAuth() { $c = readConf(); if ($c === null || !isAuthed($c)) fail(401, 'Non connecté'); }

function iniBytes($v) {
    $v = trim((string)$v); if ($v === '') return 0;
    $n = (float)$v; $u = strtolower(substr($v, -1));
    if ($u === 'g') $n *= 1073741824; elseif ($u === 'm') $n *= 1048576; elseif ($u === 'k') $n *= 1024;
    return (int)$n;
}
function maxUpload() {
    $a = iniBytes(ini_get('upload_max_filesize')); $b = iniBytes(ini_get('post_max_size'));
    $m = min($a ?: PHP_INT_MAX, $b ?: PHP_INT_MAX);
    return $m === PHP_INT_MAX ? 0 : $m;
}

function audioMime($tmp, $ext) {
    $map = [
        'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'mp4' => 'audio/mp4', 'aac' => 'audio/aac', 'wav' => 'audio/wav',
        'ogg' => 'audio/ogg', 'oga' => 'audio/ogg', 'opus' => 'audio/ogg', 'webm' => 'audio/webm', 'flac' => 'audio/flac',
        'caf' => 'audio/x-caf', 'mov' => 'video/quicktime', '3gp' => 'audio/3gpp', 'amr' => 'audio/amr',
    ];
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $real = $fi ? (string)finfo_file($fi, $tmp) : '';
        if ($real !== '' && !preg_match('#^(audio/|video/|application/octet-stream$|application/ogg$)#', $real)) return null;
    }
    return $map[$ext] ?? null;
}

function streamFile($path, $mime) {
    if (!is_file($path)) fail(404, 'Fichier introuvable');
    $size = filesize($path);
    $start = 0; $end = $size - 1;
    header('Content-Type: ' . $mime);
    header('Accept-Ranges: bytes');
    header('Cache-Control: private, max-age=31536000, immutable');
    if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
        if ($m[1] === '' && $m[2] !== '') { $start = max(0, $size - (int)$m[2]); }
        else { $start = (int)$m[1]; if ($m[2] !== '') $end = min((int)$m[2], $size - 1); }
        if ($start > $end || $start >= $size) { header("Content-Range: bytes */$size"); http_response_code(416); exit; }
        http_response_code(206);
        header("Content-Range: bytes $start-$end/$size");
    }
    header('Content-Length: ' . ($end - $start + 1));
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'HEAD') exit;
    $fp = fopen($path, 'rb'); fseek($fp, $start);
    $left = $end - $start + 1;
    while ($left > 0 && !feof($fp) && !connection_aborted()) {
        $chunk = fread($fp, (int)min(262144, $left)); echo $chunk; $left -= strlen($chunk); flush();
    }
    fclose($fp);
}
