<?php
/**
 * Application Detail API endpoint
 * GET /api/application-detail.php?id=X - Get full application with timeline, documents, requirements
 */
require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Method not allowed'], 405);
}

$user = require_bearer_auth();
$pdo  = get_db_connection();

$app_id = (int)($_GET['id'] ?? 0);
if ($app_id <= 0) {
    json_response(['error' => 'Valid application id is required.'], 422);
}

// Fetch application with scholarship details
$stmt = $pdo->prepare('
    SELECT a.*,
           s.title AS scholarship_title, s.slug AS scholarship_slug,
           s.description AS scholarship_description,
           s.deadline, s.deadline_type, s.application_url,
           s.academic_level, s.award_type, s.award_amount_min, s.award_amount_max, s.award_currency,
           s.covers_tuition, s.covers_living, s.covers_travel, s.covers_books,
           s.eligibility_summary, s.direction, s.gender_requirement,
           s.host_institution,
           sp.name AS provider_name, sp.type AS provider_type,
           sp.website_url AS provider_url, sp.logo_url AS provider_logo,
           c.name AS host_country_name, c.iso_code AS host_country_code
    FROM applications a
    INNER JOIN scholarships s ON s.id = a.scholarship_id
    LEFT JOIN scholarship_providers sp ON sp.id = s.provider_id
    LEFT JOIN countries c ON c.id = s.host_country_id
    WHERE a.id = :id AND a.user_id = :uid
    LIMIT 1
');
$stmt->execute([':id' => $app_id, ':uid' => $user['id']]);
$app = $stmt->fetch();

if (!$app) {
    json_response(['error' => 'Application not found.'], 404);
}

// Build academic levels
$levels = [];
if (!empty($app['academic_level'])) {
    $levels = array_map(fn($l) => ucfirst(str_replace('_', ' ', trim($l))), explode(',', $app['academic_level']));
}

// Build covers array
$covers = [];
if (!empty($app['covers_tuition'])) $covers[] = 'Tuition';
if (!empty($app['covers_living']))  $covers[] = 'Living Expenses';
if (!empty($app['covers_travel']))  $covers[] = 'Travel';
if (!empty($app['covers_books']))   $covers[] = 'Books & Materials';

// Format award amount
$amount = '';
if (!empty($app['award_amount_min']) || !empty($app['award_amount_max'])) {
    $currency = $app['award_currency'] ?? 'USD';
    if (!empty($app['award_amount_min']) && !empty($app['award_amount_max'])) {
        if ($app['award_amount_min'] == $app['award_amount_max']) {
            $amount = $currency . ' ' . number_format($app['award_amount_min']);
        } else {
            $amount = $currency . ' ' . number_format($app['award_amount_min']) . ' - ' . number_format($app['award_amount_max']);
        }
    } elseif (!empty($app['award_amount_max'])) {
        $amount = 'Up to ' . $currency . ' ' . number_format($app['award_amount_max']);
    }
}

$award_labels = [
    'full_tuition' => 'Full Tuition', 'partial_tuition' => 'Partial Tuition',
    'stipend' => 'Stipend', 'travel' => 'Travel Grant',
    'full_ride' => 'Full Ride', 'other' => 'Other',
];

// Format application response
$application = [
    'id'                  => (int)$app['id'],
    'status'              => $app['status'],
    'personal_statement'  => $app['personal_statement'] ?? '',
    'additional_info'     => $app['additional_info'] ?? '',
    'notes'               => $app['notes'] ?? '',
    'submitted_at'        => $app['submitted_at'],
    'submitted_via'       => $app['submitted_via'] ?? 'external_link',
    'external_url'        => $app['external_url'] ?? '',
    'email_sent_to'       => $app['email_sent_to'] ?? '',
    'email_sent_at'       => $app['email_sent_at'],
    'response_received_at' => $app['response_received_at'],
    'response_summary'    => $app['response_summary'] ?? '',
    'result_at'           => $app['result_at'],
    'created_at'          => $app['created_at'],
    'updated_at'          => $app['updated_at'],
    'scholarship' => [
        'id'              => (int)$app['scholarship_id'],
        'title'           => $app['scholarship_title'],
        'slug'            => $app['scholarship_slug'],
        'description'     => $app['scholarship_description'] ?? '',
        'provider_name'   => $app['provider_name'] ?? '',
        'provider_type'   => $app['provider_type'] ?? '',
        'provider_url'    => $app['provider_url'] ?? '',
        'provider_logo'   => $app['provider_logo'] ?? '',
        'host_country'    => $app['host_country_name'] ?? '',
        'host_country_code' => $app['host_country_code'] ?? '',
        'host_institution' => $app['host_institution'] ?? '',
        'academic_levels' => $levels,
        'award_type'      => $award_labels[$app['award_type'] ?? ''] ?? '',
        'award_amount'    => $amount,
        'covers'          => $covers,
        'deadline'        => $app['deadline'],
        'deadline_type'   => $app['deadline_type'] ?? 'fixed',
        'application_url' => $app['application_url'] ?? '',
        'eligibility'     => $app['eligibility_summary'] ?? '',
        'direction'       => $app['direction'] ?? 'any',
        'gender'          => $app['gender_requirement'] ?? 'any',
    ],
];

// Fetch timeline
$stmt = $pdo->prepare('
    SELECT id, from_status, to_status, note, changed_by, created_at
    FROM application_timeline
    WHERE application_id = :aid
    ORDER BY created_at ASC
');
$stmt->execute([':aid' => $app_id]);
$timeline = array_map(function ($t) {
    return [
        'id'          => (int)$t['id'],
        'from_status' => $t['from_status'],
        'to_status'   => $t['to_status'],
        'note'        => $t['note'] ?? '',
        'changed_by'  => $t['changed_by'],
        'created_at'  => $t['created_at'],
    ];
}, $stmt->fetchAll());

// Fetch documents
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

// Fetch scholarship requirements
$stmt = $pdo->prepare('
    SELECT id, requirement_type, label, description, is_required, sort_order
    FROM scholarship_requirements
    WHERE scholarship_id = :sid
    ORDER BY sort_order ASC, id ASC
');
$stmt->execute([':sid' => $app['scholarship_id']]);
$requirements = array_map(function ($r) {
    return [
        'id'              => (int)$r['id'],
        'requirement_type' => $r['requirement_type'],
        'label'           => $r['label'],
        'description'     => $r['description'] ?? '',
        'is_required'     => (bool)$r['is_required'],
    ];
}, $stmt->fetchAll());

json_response([
    'application'  => $application,
    'timeline'     => $timeline,
    'documents'    => $documents,
    'requirements' => $requirements,
]);
