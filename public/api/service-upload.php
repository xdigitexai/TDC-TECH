<?php
require_once dirname(__DIR__,2).'/app/bootstrap.php';
$u = require_user();
require_method('POST');
require_csrf();

$root = dirname(__DIR__,2).'/storage/music';
$max = 200 * 1024 * 1024;

$file = $_FILES['file'] ?? $_FILES['media'] ?? null;
if (!$file) {
    $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    json_out(['error' => $length > $max ? 'File exceeds the 200 MB limit.' : 'Choose a file to upload.'], 422);
}
$error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
if ($error !== UPLOAD_ERR_OK) {
    $message = in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
        ? 'File exceeds the 200 MB limit.'
        : ($error === UPLOAD_ERR_NO_FILE ? 'Choose a file to upload.' : 'Upload failed. Check your connection and try again.');
    json_out(['error' => $message], 422);
}
$size = (int)($file['size'] ?? 0);
if ($size < 1 || $size > $max) json_out(['error' => 'File exceeds the 200 MB limit.'], 422);

$mime = (new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']) ?: '';
$allowed = [
    'image/jpeg' => 'image',
    'image/png' => 'image',
    'image/webp' => 'image',
    'image/gif' => 'image',
    'video/mp4' => 'video',
    'video/webm' => 'video',
    'video/quicktime' => 'video',
    'application/pdf' => 'pdf',
];
if (!isset($allowed[$mime])) json_out(['error' => 'Unsupported file type. Upload JPG, PNG, WebP, GIF, MP4, WebM, MOV or PDF.'], 422);
if ($allowed[$mime] === 'image' && !@getimagesize((string)$file['tmp_name'])) json_out(['error' => 'That image could not be read. Upload a valid JPG, PNG, WebP or GIF.'], 422);
if ($mime === 'application/pdf') {
    $handle = fopen((string)$file['tmp_name'], 'rb');
    $head = $handle ? fread($handle, 5) : '';
    if ($handle) fclose($handle);
    if ($head !== '%PDF-') json_out(['error' => 'That PDF could not be read.'], 422);
}

$id = bin2hex(random_bytes(16));
$stored = bin2hex(random_bytes(32));
$name = text_cut(basename(str_replace('\\', '/', (string)$file['name'])), 0, 200) ?: 'attachment';
$pdo = db();
$pdo->beginTransaction();
try {
    $q = $pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');
    $q->execute([$u['id']]);
    $q = $pdo->prepare('SELECT COALESCE(SUM(bytes),0) FROM customer_media WHERE user_id=?');
    $q->execute([$u['id']]);
    if ((int)$q->fetchColumn() + $size > 1024 * 1024 * 1024) {
        throw new RuntimeException('Your 1 GB attachment allowance is full. Remove files or contact support.');
    }
    if (!is_dir($root) && !mkdir($root, 0750, true)) throw new RuntimeException('Storage is unavailable right now.');
    if (!move_uploaded_file((string)$file['tmp_name'], $root.'/'.$stored)) throw new RuntimeException('Upload could not be stored.');
    chmod($root.'/'.$stored, 0640);
    $pdo->prepare('INSERT INTO customer_media(id,user_id,original_name,stored_name,mime,bytes,kind) VALUES(?,?,?,?,?,?,?)')
        ->execute([$id, $u['id'], $name, $stored, $mime, $size, 'service-asset']);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if (isset($stored) && is_file($root.'/'.$stored)) @unlink($root.'/'.$stored);
    json_out(['error' => $e->getMessage()], 422);
}
audit('service.asset_uploaded', (int)$u['id'], ['media_id' => $id, 'bytes' => $size, 'mime' => $mime]);
json_out(['ok' => true, 'id' => $id, 'name' => $name, 'mime' => $mime, 'bytes' => $size]);
