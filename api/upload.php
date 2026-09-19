<?php
// Must match admin.php so the admin session cookie is recognized on fetch() to this endpoint.
require_once __DIR__ . '/../includes/session.php';
kits_session_start('Lax');

// Only logged-in admin can upload
if (empty($_SESSION['admin'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$csrfHeader = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
$csrfSession = (string)($_SESSION['csrf_token'] ?? '');
if ($csrfSession === '' || $csrfHeader === '' || !hash_equals($csrfSession, $csrfHeader)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}

header('Content-Type: application/json');

if (empty($_FILES['image'])) {
    echo json_encode(['error' => 'No file received']);
    exit;
}

$file    = $_FILES['image'];
$maxSize = 5 * 1024 * 1024; // 5 MB

if ($file['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['error' => 'Upload error code ' . $file['error']]);
    exit;
}

if ($file['size'] > $maxSize) {
    echo json_encode(['error' => 'File too large — max 5 MB']);
    exit;
}

// Verify actual mime type (not just extension)
$finfo    = new finfo(FILEINFO_MIME_TYPE);
$mime     = $finfo->file($file['tmp_name']);
$allowed  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

if (!isset($allowed[$mime])) {
    echo json_encode(['error' => 'Only JPG, PNG and WebP images are allowed']);
    exit;
}

$ext       = $allowed[$mime];
$filename  = 'kit_' . time() . '_' . bin2hex(random_bytes(4)) . '.webp';
$uploadDir = __DIR__ . '/../uploads/products/';

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Try to optimize/resize with GD and save as WebP for faster loading.
$targetPath = $uploadDir . $filename;
$optimized = false;

if (extension_loaded('gd')) {
    $srcImg = null;
    if ($mime === 'image/jpeg') $srcImg = @imagecreatefromjpeg($file['tmp_name']);
    if ($mime === 'image/png')  $srcImg = @imagecreatefrompng($file['tmp_name']);
    if ($mime === 'image/webp') $srcImg = @imagecreatefromwebp($file['tmp_name']);

    if ($srcImg !== false && $srcImg !== null) {
        $w = imagesx($srcImg);
        $h = imagesy($srcImg);
        $maxDim = 1600;
        $ratio = min(1, $maxDim / max($w, $h));
        $newW = (int)max(1, round($w * $ratio));
        $newH = (int)max(1, round($h * $ratio));

        $dst = imagecreatetruecolor($newW, $newH);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $newW, $newH, $transparent);
        imagecopyresampled($dst, $srcImg, 0, 0, 0, 0, $newW, $newH, $w, $h);

        $optimized = @imagewebp($dst, $targetPath, 82);
        imagedestroy($dst);

        // Kaart-thumbnail (~500px WebP in thumbs/) voor snelle grids/kaartjes (LCP/mobiel).
        if ($optimized) {
            $thumbDir = $uploadDir . 'thumbs/';
            if (!is_dir($thumbDir)) { @mkdir($thumbDir, 0755, true); }
            $tRatio = min(1, 500 / max(1, $w));
            $tW = (int)max(1, round($w * $tRatio));
            $tH = (int)max(1, round($h * $tRatio));
            $tDst = imagecreatetruecolor($tW, $tH);
            imagealphablending($tDst, false);
            imagesavealpha($tDst, true);
            imagecopyresampled($tDst, $srcImg, 0, 0, 0, 0, $tW, $tH, $w, $h);
            @imagewebp($tDst, $thumbDir . preg_replace('/\.[^.]+$/', '.webp', $filename), 78);
            imagedestroy($tDst);
        }
        imagedestroy($srcImg);
    }
}

// Fallback: keep original file type if optimization is unavailable.
if (!$optimized) {
    $filename = 'kit_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $targetPath = $uploadDir . $filename;
    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        echo json_encode(['error' => 'Could not save file — check folder permissions']);
        exit;
    }
}

echo json_encode([
    'ok'       => true,
    'filename' => $filename,
    'url'      => 'uploads/products/' . $filename,
]);
