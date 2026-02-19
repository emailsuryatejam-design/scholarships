<?php
/**
 * Application Documents API endpoint
 * GET  /api/application-documents.php?application_id=X - List documents
 * POST /api/application-documents.php                  - Upload document or delete
 */
require_once __DIR__ . '/_bootstrap.php';

$user = require_bearer_auth();
$pdo  = get_db_connection();

// Upload directory
$upload_base = realpath(__DIR__ . '/..') . '/uploads/applications';

$max_file_size = 5 * 1024 * 1024; // 5MB
$allowed_types = [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'image/jpeg',
    'image/png',
];

// ─── GET: List documents ───
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $app_id = (int)($_GET['application_id'] ?? 0);
    if ($app_id <= 0) {
        json_response(['error' => 'Valid application_id is required.'], 422);
    }

    // Verify ownership
    $stmt = $pdo->prepare('SELECT id FROM applications WHERE id = :id AND user_id = :uid LIMIT 1');
    $stmt->execute([':id' => $app_id, ':uid' => $user['id']]);
    if (!$stmt->fetch()) {
        json_response(['error' => 'Application not found.'], 404);
    }

    $stmt = $pdo->prepare('
        SELECT id, doc_type, file_name, file_size, mime_type, uploaded_at
        FROM application_documents
        WHERE application_id = :aid
        ORDER BY uploaded_at DESC
    ');
    $stmt->execute([':aid' => $app_id]);

    $documents = array_map(function ($d) {
        return [
            'id'          => (int)$d['id'],
            'doc_type'    => $d['doc_type'],
            'file_name'   => $d['file_name'],
            'file_size'   => (int)$d['file_size'],
            'mime_type'   => $d['mime_type'],
            'uploaded_at' => $d['uploaded_at'],
        ];
    }, $stmt->fetchAll());

    json_response(['documents' => $documents]);
}

// ─── POST: Upload or delete ───
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if this is a JSON request (delete action) or multipart (file upload)
    $content_type = $_SERVER['CONTENT_TYPE'] ?? '';

    // ── Delete document ──
    if (str_contains($content_type, 'application/json')) {
        $body   = get_json_body();
        $action = $body['action'] ?? '';

        if ($action !== 'delete') {
            json_response(['error' => 'Invalid action.'], 422);
        }

        $doc_id = (int)($body['document_id'] ?? 0);
        if ($doc_id <= 0) {
            json_response(['error' => 'Valid document_id is required.'], 422);
        }

        // Verify ownership through application
        $stmt = $pdo->prepare('
            SELECT d.id, d.file_path
            FROM application_documents d
            INNER JOIN applications a ON a.id = d.application_id
            WHERE d.id = :did AND a.user_id = :uid
            LIMIT 1
        ');
        $stmt->execute([':did' => $doc_id, ':uid' => $user['id']]);
        $doc = $stmt->fetch();

        if (!$doc) {
            json_response(['error' => 'Document not found.'], 404);
        }

        // Delete file from disk
        $file_path = realpath(__DIR__ . '/..') . '/' . $doc['file_path'];
        if (file_exists($file_path)) {
            unlink($file_path);
        }

        // Delete from database
        $pdo->prepare('DELETE FROM application_documents WHERE id = :id')->execute([':id' => $doc_id]);

        json_response(['message' => 'Document deleted.']);
    }

    // ── Upload document ──
    $app_id   = (int)($_POST['application_id'] ?? 0);
    $doc_type = trim($_POST['doc_type'] ?? 'other');

    if ($app_id <= 0) {
        json_response(['error' => 'Valid application_id is required.'], 422);
    }

    $valid_types = ['personal_statement', 'transcript', 'recommendation_letter', 'cv_resume', 'research_proposal', 'portfolio', 'certificate', 'other'];
    if (!in_array($doc_type, $valid_types)) {
        json_response(['error' => 'Invalid doc_type.'], 422);
    }

    // Verify ownership
    $stmt = $pdo->prepare('SELECT id, status FROM applications WHERE id = :id AND user_id = :uid LIMIT 1');
    $stmt->execute([':id' => $app_id, ':uid' => $user['id']]);
    $app = $stmt->fetch();
    if (!$app) {
        json_response(['error' => 'Application not found.'], 404);
    }

    // Check file upload
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $error_messages = [
            UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit.',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds form upload limit.',
            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
        ];
        $err_code = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
        json_response(['error' => $error_messages[$err_code] ?? 'File upload failed.'], 422);
    }

    $file = $_FILES['file'];

    // Validate size
    if ($file['size'] > $max_file_size) {
        json_response(['error' => 'File is too large. Maximum size is 5MB.'], 422);
    }

    // Validate MIME type
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!in_array($mime, $allowed_types)) {
        json_response(['error' => 'File type not allowed. Accepted: PDF, DOC, DOCX, JPG, PNG.'], 422);
    }

    // Create upload directory
    $user_dir = $upload_base . '/' . $user['id'];
    if (!is_dir($user_dir)) {
        mkdir($user_dir, 0755, true);
    }

    // Generate safe filename
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $safe_name = $app_id . '_' . $doc_type . '_' . time() . '.' . $ext;
    $dest_path = $user_dir . '/' . $safe_name;
    $relative_path = 'uploads/applications/' . $user['id'] . '/' . $safe_name;

    if (!move_uploaded_file($file['tmp_name'], $dest_path)) {
        json_response(['error' => 'Failed to save uploaded file.'], 500);
    }

    // Save to database
    $stmt = $pdo->prepare('
        INSERT INTO application_documents (application_id, doc_type, file_name, file_path, file_size, mime_type, uploaded_at)
        VALUES (:aid, :type, :name, :path, :size, :mime, NOW())
    ');
    $stmt->execute([
        ':aid'  => $app_id,
        ':type' => $doc_type,
        ':name' => $file['name'],
        ':path' => $relative_path,
        ':size' => $file['size'],
        ':mime' => $mime,
    ]);

    json_response([
        'message'  => 'Document uploaded successfully.',
        'document' => [
            'id'        => (int)$pdo->lastInsertId(),
            'doc_type'  => $doc_type,
            'file_name' => $file['name'],
            'file_size' => (int)$file['size'],
            'mime_type' => $mime,
        ],
    ], 201);
}

json_response(['error' => 'Method not allowed'], 405);
