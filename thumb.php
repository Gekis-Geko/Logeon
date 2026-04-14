<?php
/**
 * Thumbnail resizer con cache su disco.
 *
 * Uso: /thumb.php?src=/assets/imgs/uploads/characters/1/avatar.jpg&w=46&h=46
 *
 * - src  : percorso relativo all'interno di /assets/imgs/ (obbligatorio)
 * - w    : larghezza target in px  (default 100, max 400)
 * - h    : altezza target in px    (default = w, max 400)
 * - fit  : "cover" (default) | "contain"
 *          cover   = ridimensiona e ritaglia al centro per riempire esattamente w×h
 *          contain = ridimensiona a contenere in w×h mantenendo le proporzioni
 *
 * Il file è servito direttamente da Apache (bypassa il router) perché esiste fisicamente.
 * I thumbnail vengono salvati in tmp/thumbs/ con Cache-Control di 7 giorni.
 */

define('THUMB_MAX_DIM',      400);
define('THUMB_CACHE_DIR',    __DIR__ . '/tmp/thumbs');
define('THUMB_CACHE_TTL',    604800);   // 7 giorni in secondi
define('THUMB_JPEG_QUALITY', 82);

// ─── Input ────────────────────────────────────────────────────────────────────

$src = trim((string) ($_GET['src'] ?? ''));
$w   = max(1, min(THUMB_MAX_DIM, (int) ($_GET['w'] ?? 100)));
$h   = max(1, min(THUMB_MAX_DIM, (int) ($_GET['h'] ?? $w)));
$fit = (($_GET['fit'] ?? 'cover') === 'contain') ? 'contain' : 'cover';

// ─── Validazione sicurezza ────────────────────────────────────────────────────

// src deve essere un percorso relativo sotto /assets/imgs/ senza traversal
if ($src === ''
    || strpos($src, '/assets/imgs/') !== 0
    || strpos($src, '..') !== false
    || preg_match('#[\\\\<>:"|?*\x00-\x1f]#', $src)
) {
    http_response_code(400);
    exit;
}

$ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
    http_response_code(400);
    exit;
}

$absPath = realpath(__DIR__ . '/' . ltrim($src, '/'));
$allowedBase = realpath(__DIR__ . '/assets/imgs');

if ($absPath === false || $allowedBase === false || strpos($absPath, $allowedBase) !== 0) {
    http_response_code(404);
    exit;
}

if (!is_file($absPath)) {
    http_response_code(404);
    exit;
}

// ─── Cache ────────────────────────────────────────────────────────────────────

$cacheKey  = hash('sha256', $src . '|' . $w . 'x' . $h . '|' . $fit);
$cacheFile = THUMB_CACHE_DIR . '/' . $cacheKey . '.jpg';

if (!is_dir(THUMB_CACHE_DIR)) {
    @mkdir(THUMB_CACHE_DIR, 0755, true);
}

function thumb_send(string $file): void
{
    $etag = '"' . hash('crc32b', (string) filemtime($file) . (string) filesize($file)) . '"';
    $ifNoneMatch = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));

    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=' . THUMB_CACHE_TTL . ', immutable');
    header('ETag: ' . $etag);

    if ($ifNoneMatch === $etag) {
        http_response_code(304);
        return;
    }

    header('Content-Length: ' . filesize($file));
    readfile($file);
}

if (is_file($cacheFile)) {
    thumb_send($cacheFile);
    exit;
}

// ─── Carica immagine originale ────────────────────────────────────────────────

$info = @getimagesize($absPath);
if (!$info) {
    // File non leggibile come immagine — serve l'originale
    header('Content-Type: ' . mime_content_type($absPath));
    header('Cache-Control: public, max-age=3600');
    header('Content-Length: ' . filesize($absPath));
    readfile($absPath);
    exit;
}

[$origW, $origH, $imgType] = $info;

switch ($imgType) {
    case IMAGETYPE_JPEG: $srcImg = @imagecreatefromjpeg($absPath); break;
    case IMAGETYPE_PNG:  $srcImg = @imagecreatefrompng($absPath);  break;
    case IMAGETYPE_GIF:  $srcImg = @imagecreatefromgif($absPath);  break;
    case IMAGETYPE_WEBP: $srcImg = @imagecreatefromwebp($absPath); break;
    default:
        // Formato non supportato — serve l'originale
        header('Content-Type: ' . $info['mime']);
        header('Cache-Control: public, max-age=3600');
        header('Content-Length: ' . filesize($absPath));
        readfile($absPath);
        exit;
}

if (!$srcImg) {
    http_response_code(500);
    exit;
}

// ─── Resize ───────────────────────────────────────────────────────────────────

if ($fit === 'contain') {
    // Scala per stare dentro w×h mantenendo le proporzioni
    $scale   = min($w / $origW, $h / $origH);
    $dstW    = max(1, (int) round($origW * $scale));
    $dstH    = max(1, (int) round($origH * $scale));
    $dst     = imagecreatetruecolor($dstW, $dstH);
    // Sfondo bianco per JPEG finale
    $white   = imagecolorallocate($dst, 255, 255, 255);
    imagefill($dst, 0, 0, $white);
    imagecopyresampled($dst, $srcImg, 0, 0, 0, 0, $dstW, $dstH, $origW, $origH);
} else {
    // cover: scala per riempire w×h, poi ritaglia al centro
    $scale   = max($w / $origW, $h / $origH);
    $scaledW = max(1, (int) round($origW * $scale));
    $scaledH = max(1, (int) round($origH * $scale));
    $cropX   = (int) round(($scaledW - $w) / 2);
    $cropY   = (int) round(($scaledH - $h) / 2);

    // Resize in un canvas intermedio
    $scaled  = imagecreatetruecolor($scaledW, $scaledH);
    $white   = imagecolorallocate($scaled, 255, 255, 255);
    imagefill($scaled, 0, 0, $white);
    imagecopyresampled($scaled, $srcImg, 0, 0, 0, 0, $scaledW, $scaledH, $origW, $origH);

    // Ritaglia al centro
    $dst     = imagecreatetruecolor($w, $h);
    imagecopy($dst, $scaled, 0, 0, $cropX, $cropY, $w, $h);
    imagedestroy($scaled);
}

imagedestroy($srcImg);

// ─── Salva in cache e servi ───────────────────────────────────────────────────

imagejpeg($dst, $cacheFile, THUMB_JPEG_QUALITY);
imagedestroy($dst);

thumb_send($cacheFile);
exit;
