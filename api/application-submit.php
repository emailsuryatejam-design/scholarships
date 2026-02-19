<?php
/**
 * Application Submit API endpoint
 * POST /api/application-submit.php - Submit an application (freeze snapshot, optionally send email)
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/application_email.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$user = require_bearer_auth();
$pdo  = get_db_connection();
$body = get_json_body();

$app_id        = (int)($body['application_id'] ?? 0);
$submit_method = trim($body['submit_method'] ?? 'external_link');
$email_to      = trim($body['email_to'] ?? '');

if ($app_id <= 0) {
    json_response(['error' => 'Valid application_id is required.'], 422);
}

if (!in_array($submit_method, ['platform_email', 'external_link'])) {
    json_response(['error' => 'Invalid submit_method. Use platform_email or external_link.'], 422);
}

if ($submit_method === 'platform_email' && (empty($email_to) || !filter_var($email_to, FILTER_VALIDATE_EMAIL))) {
    json_response(['error' => 'Valid email_to is required for platform email submission.'], 422);
}

// Fetch the application
$stmt = $pdo->prepare('
    SELECT a.*, s.title AS scholarship_title, s.application_url
    FROM applications a
    INNER JOIN scholarships s ON s.id = a.scholarship_id
    WHERE a.id = :id AND a.user_id = :uid
    LIMIT 1
');
$stmt->execute([':id' => $app_id, ':uid' => $user['id']]);
$app = $stmt->fetch();

if (!$app) {
    json_response(['error' => 'Application not found.'], 404);
}

if (!in_array($app['status'], ['draft', 'ready'])) {
    json_response(['error' => 'Application has already been submitted.'], 422);
}

// Build applicant snapshot from current profile
$stmt = $pdo->prepare('
    SELECT sp.*,
           nc.name AS nationality, rc.name AS residence_country,
           pf.name AS primary_field, sf.name AS secondary_field
    FROM student_profiles sp
    LEFT JOIN countries nc ON nc.id = sp.nationality_id
    LEFT JOIN countries rc ON rc.id = sp.residence_country_id
    LEFT JOIN fields_of_study pf ON pf.id = sp.primary_field_id
    LEFT JOIN fields_of_study sf ON sf.id = sp.secondary_field_id
    WHERE sp.user_id = :uid
    LIMIT 1
');
$stmt->execute([':uid' => $user['id']]);
$profile = $stmt->fetch();

$snapshot = [
    'user' => [
        'first_name' => $user['first_name'],
        'last_name'  => $user['last_name'],
        'email'      => $user['email'],
    ],
    'profile' => $profile ? [
        'nationality'             => $profile['nationality'] ?? '',
        'residence_country'       => $profile['residence_country'] ?? '',
        'date_of_birth'           => $profile['date_of_birth'] ?? '',
        'gender'                  => $profile['gender'] ?? '',
        'current_education_level' => $profile['current_education_level'] ?? '',
        'desired_education_level' => $profile['desired_education_level'] ?? '',
        'gpa'                     => $profile['gpa'] ?? '',
        'gpa_scale'               => $profile['gpa_scale'] ?? '',
        'primary_field'           => $profile['primary_field'] ?? '',
        'secondary_field'         => $profile['secondary_field'] ?? '',
        'financial_need_level'    => $profile['financial_need_level'] ?? '',
    ] : null,
    'submitted_at' => date('Y-m-d H:i:s'),
];

// Update application
$update_params = [
    ':id'       => $app_id,
    ':status'   => 'submitted',
    ':snapshot' => json_encode($snapshot),
    ':via'      => $submit_method,
];

$update_sql = "
    UPDATE applications SET
        status = :status,
        submitted_at = NOW(),
        applicant_snapshot = :snapshot,
        submitted_via = :via,
        updated_at = NOW()
";

// Handle platform email submission
$email_sent = false;
if ($submit_method === 'platform_email') {
    $student_data = array_merge(
        $snapshot['user'],
        $snapshot['profile'] ?? []
    );
    $scholarship_data = [
        'title' => $app['scholarship_title'],
    ];

    $email = build_application_email(
        $student_data,
        $scholarship_data,
        $app['personal_statement'] ?? ''
    );

    $email_sent = send_application_email(
        $email_to,
        $user['first_name'] . ' ' . $user['last_name'],
        $user['email'],
        $email['subject'],
        $email['body']
    );

    $update_sql .= ", email_sent_to = :email_to, email_sent_at = NOW()";
    $update_params[':email_to'] = $email_to;
} elseif ($submit_method === 'external_link') {
    // Mark the external URL
    $external_url = $body['external_url'] ?? $app['application_url'] ?? '';
    if (!empty($external_url)) {
        $update_sql .= ", external_url = :ext_url";
        $update_params[':ext_url'] = $external_url;
    }
}

$update_sql .= " WHERE id = :id";
$pdo->prepare($update_sql)->execute($update_params);

// Create timeline entry
$note = $submit_method === 'platform_email'
    ? "Application submitted via platform email to $email_to"
    : "Application submitted (tracked from external portal)";

$pdo->prepare('
    INSERT INTO application_timeline (application_id, from_status, to_status, note, changed_by, created_at)
    VALUES (:aid, :from, :to, :note, :by, NOW())
')->execute([
    ':aid'  => $app_id,
    ':from' => $app['status'],
    ':to'   => 'submitted',
    ':note' => $note,
    ':by'   => 'user',
]);

// Create notification
$pdo->prepare('
    INSERT INTO notifications (user_id, type, title, message, link, related_type, related_id, created_at)
    VALUES (:uid, :type, :title, :msg, :link, :rt, :rid, NOW())
')->execute([
    ':uid'   => $user['id'],
    ':type'  => 'application_update',
    ':title' => 'Application Submitted',
    ':msg'   => "Your application to \"{$app['scholarship_title']}\" has been submitted successfully.",
    ':link'  => "/applications/$app_id",
    ':rt'    => 'application',
    ':rid'   => $app_id,
]);

$response = [
    'message'    => 'Application submitted successfully.',
    'status'     => 'submitted',
    'method'     => $submit_method,
];

if ($submit_method === 'platform_email') {
    $response['email_sent']    = $email_sent;
    $response['email_sent_to'] = $email_to;
}

json_response($response);
