<?php
/**
 * Dashboard Stats API
 * GET /api/dashboard-stats.php - Returns counts for the logged-in user
 */
require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Method not allowed'], 405);
}

$user = require_bearer_auth();
$pdo = get_db_connection();
$uid = (int)$user['id'];

// Scholarship matches count
$stmt = $pdo->prepare('SELECT COUNT(*) FROM scholarship_matches WHERE user_id = :uid AND eligibility_met = 1');
$stmt->execute([':uid' => $uid]);
$matches = (int)$stmt->fetchColumn();

// Saved scholarships count
$stmt = $pdo->prepare('SELECT COUNT(*) FROM saved_scholarships WHERE user_id = :uid');
$stmt->execute([':uid' => $uid]);
$saved = (int)$stmt->fetchColumn();

// Applications count
$stmt = $pdo->prepare('SELECT COUNT(*) FROM applications WHERE user_id = :uid');
$stmt->execute([':uid' => $uid]);
$applications = (int)$stmt->fetchColumn();

// Total active scholarships in the platform
$stmt = $pdo->query('SELECT COUNT(*) FROM scholarships WHERE is_active = 1');
$total_scholarships = (int)$stmt->fetchColumn();

// Recent matches (top 5)
$stmt = $pdo->prepare('
    SELECT s.id, s.title, s.deadline, s.host_institution,
           sp.name AS provider_name,
           c.name AS host_country_name,
           sm.match_score
    FROM scholarship_matches sm
    INNER JOIN scholarships s ON s.id = sm.scholarship_id AND s.is_active = 1
    LEFT JOIN scholarship_providers sp ON sp.id = s.provider_id
    LEFT JOIN countries c ON c.id = s.host_country_id
    WHERE sm.user_id = :uid AND sm.eligibility_met = 1
    ORDER BY sm.match_score DESC
    LIMIT 5
');
$stmt->execute([':uid' => $uid]);
$recent_matches = $stmt->fetchAll();

json_response([
    'stats' => [
        'matches'            => $matches,
        'saved'              => $saved,
        'applications'       => $applications,
        'total_scholarships' => $total_scholarships,
    ],
    'recent_matches' => $recent_matches,
]);
