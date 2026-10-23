<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

require_sales_access();

$docId = (int)($_GET['id'] ?? 0);

if ($docId <= 0) {
    http_response_code(400);
    exit('Invalid document.');
}


/*
|--------------------------------------------------------------------------
| Get document
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare(
    "SELECT
        d.*,
        l.name AS lead_name,
        l.company
     FROM documents d
     JOIN leads l
        ON l.id = d.lead_id
     WHERE d.id = ?
     LIMIT 1"
);

$stmt->execute([$docId]);

$doc = $stmt->fetch(
    PDO::FETCH_ASSOC
);


if (!$doc) {

    http_response_code(404);

    exit('Document not found.');
}


/*
|--------------------------------------------------------------------------
| Check lead access
|--------------------------------------------------------------------------
*/

assert_lead_access(
    $pdo,
    (int)$doc['lead_id']
);


/*
|--------------------------------------------------------------------------
| Check physical file
|--------------------------------------------------------------------------
*/

$filePath = $doc['file_path'];

if (
    empty($filePath) ||
    !is_file($filePath)
) {

    http_response_code(404);

    exit('Document file not found.');
}


/*
|--------------------------------------------------------------------------
| Security check
|--------------------------------------------------------------------------
|
| Only allow files inside storage/documents.
|
*/

$storageRoot = realpath(
    __DIR__ . '/../storage/documents'
);

$realFile = realpath(
    $filePath
);

if (
    !$storageRoot ||
    !$realFile ||
    !is_file($realFile)
) {

    http_response_code(404);

    exit('Document file not found.');
}


$storageRootWithSlash =
    rtrim(
        $storageRoot,
        DIRECTORY_SEPARATOR
    )
    . DIRECTORY_SEPARATOR;


if (
    strpos(
        $realFile,
        $storageRootWithSlash
    ) !== 0
) {

    http_response_code(403);

    exit('Access denied.');
}


/*
|--------------------------------------------------------------------------
| Determine extension
|--------------------------------------------------------------------------
*/

$extension = strtolower(
    pathinfo(
        $doc['file_name'],
        PATHINFO_EXTENSION
    )
);


/*
|--------------------------------------------------------------------------
| Browser-previewable MIME types
|--------------------------------------------------------------------------
*/

$mimeTypes = [

    'pdf' =>
        'application/pdf',

    'txt' =>
        'text/plain',

    'jpg' =>
        'image/jpeg',

    'jpeg' =>
        'image/jpeg',

    'png' =>
        'image/png',

    'gif' =>
        'image/gif',

    'webp' =>
        'image/webp'
];


if (!isset($mimeTypes[$extension])) {

    http_response_code(415);

    exit(
        'Preview is not supported for this file type. Please download the document.'
    );
}


$mime = $mimeTypes[$extension];


/*
|--------------------------------------------------------------------------
| Headers
|--------------------------------------------------------------------------
*/

header(
    'Content-Type: ' . $mime
);

header(
    'Content-Length: ' . filesize($realFile)
);

header(
    'Content-Disposition: inline; filename="' .
    basename($doc['file_name']) .
    '"'
);

header(
    'X-Content-Type-Options: nosniff'
);

header(
    'Cache-Control: private, max-age=0, no-cache, no-store, must-revalidate'
);

header(
    'Pragma: no-cache'
);


/*
|--------------------------------------------------------------------------
| Output file
|--------------------------------------------------------------------------
*/

readfile($realFile);

exit;
