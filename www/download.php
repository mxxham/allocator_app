<?php
/**
 * Download Picklist File
 */

session_start();

// Standalone — no auth required

// Get file path and optional filename from request
$file = $_GET['file'] ?? '';
$customName = $_GET['filename'] ?? '';

if (empty($file) || !file_exists($file)) {
    http_response_code(404);
    echo 'File not found';
    exit;
}

// Security: only allow temp files
$realPath = realpath($file);
$tempDir = realpath(sys_get_temp_dir());

if ($realPath === false || strpos($realPath, $tempDir) !== 0) {
    http_response_code(403);
    echo 'Access denied';
    exit;
}

// Download file
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
$dlName = $customName !== '' ? $customName : 'picklist_' . date('Y-m-d_His');
header('Content-Disposition: attachment;filename="' . $dlName . '.xlsx"');
header('Cache-Control: max-age=0');

readfile($realPath);
unlink($realPath);
exit;
