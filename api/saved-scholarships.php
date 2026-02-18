<?php
/**
 * Saved Scholarships API
 * POST /api/saved-scholarships.php   - Save a scholarship
 * DELETE via POST with action=unsave - Unsave a scholarship
 * GET /api/saved-scholarships.php    - List saved scholarships
 */
require_once __DIR__ . '/_bootstrap.php';

$user = require_bearer_auth();
$pdo = get_db_connection();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // List saved scholarships
    $stmt = $pdo->prepare('
        SELECT s.id, s.title, s.slug, s.description, s.academic_level, s.award_type,
               s.award_amount_min, s.award_amount_max, s.award_currency,
               s.covers_tuition, s.covers_living, s.covers_travel, s.covers_books,
               s.deadline, s.deadline_type, s.application_url, s.eligibility_summary,
               s.host_institution, s.gender_requirement, s.financial_need_required, s.merit_based,
               s.view_count, s.is_verified, s.direction,
               sp.name AS provider_name, sp.type AS provider_type, sp.logo_url AS provider_logo,
               c.name AS host_country_name, c.iso_code AS host_country_code,
               ss.notes AS user_notes, ss.created_at AS saved_at
        FROM saved_scholarships ss
        INNER JOIN scholarships s ON s.id = ss.scholarship_id
        LEFT JOIN scholarship_providers sp ON sp.id = s.provider_id
        LEFT JOIN countries c ON c.id = s.host_country_id
        WHERE ss.user_id = :uid AND s.is_active = 1
        ORDER BY ss.created_at DESC
    ');
    $stmt->execute([':uid' => $user['id']]);
    $rows = $stmt->fetchAll();

    $scholarships = array_map(function($row) {
        $row['is_saved'] = true;
        return format_scholarship_brief($row);
    }, $rows);

    json_response(['scholarships' => $scholarships, 'total' => count($scholarships)]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = get_json_body();
    $action = $body['action'] ?? 'save';
    $scholarship_id = (int)($body['scholarship_id'] ?? 0);

    if ($scholarship_id <= 0) {
        json_response(['error' => 'Invalid scholarship ID.'], 422);
    }

    // Verify scholarship exists
    $stmt = $pdo->prepare('SELECT id FROM scholarships WHERE id = :id AND is_active = 1 LIMIT 1');
    $stmt->execute([':id' => $scholarship_id]);
    if (!$stmt->fetch()) {
        json_response(['error' => 'Scholarship not found.'], 404);
    }

    if ($action === 'unsave') {
        $stmt = $pdo->prepare('DELETE FROM saved_scholarships WHERE user_id = :uid AND scholarship_id = :sid');
        $stmt->execute([':uid' => $user['id'], ':sid' => $scholarship_id]);
        json_response(['message' => 'Scholarship removed from saved.', 'is_saved' => false]);
    } else {
        // Check if already saved
        $stmt = $pdo->prepare('SELECT id FROM saved_scholarships WHERE user_id = :uid AND scholarship_id = :sid LIMIT 1');
        $stmt->execute([':uid' => $user['id'], ':sid' => $scholarship_id]);
        if ($stmt->fetch()) {
            json_response(['message' => 'Already saved.', 'is_saved' => true]);
        }

        $notes = trim($body['notes'] ?? '');
        $stmt = $pdo->prepare('INSERT INTO saved_scholarships (user_id, scholarship_id, notes, created_at) VALUES (:uid, :sid, :notes, NOW())');
        $stmt->execute([':uid' => $user['id'], ':sid' => $scholarship_id, ':notes' => $notes ?: null]);
        json_response(['message' => 'Scholarship saved!', 'is_saved' => true], 201);
    }
}

json_response(['error' => 'Method not allowed'], 405);


function format_scholarship_brief(array $s): array {
    $levels = [];
    if (!empty($s['academic_level'])) {
        $levels = array_map(fn($l) => ucfirst(str_replace('_', ' ', trim($l))), explode(',', $s['academic_level']));
    }

    $amount = '';
    if (!empty($s['award_amount_min']) || !empty($s['award_amount_max'])) {
        $cur = $s['award_currency'] ?? 'USD';
        if (!empty($s['award_amount_min']) && !empty($s['award_amount_max'])) {
            $amount = ($s['award_amount_min'] == $s['award_amount_max'])
                ? "$cur " . number_format($s['award_amount_min'])
                : "$cur " . number_format($s['award_amount_min']) . ' - ' . number_format($s['award_amount_max']);
        } elseif (!empty($s['award_amount_max'])) {
            $amount = "Up to $cur " . number_format($s['award_amount_max']);
        }
    }

    $covers = [];
    if (!empty($s['covers_tuition'])) $covers[] = 'Tuition';
    if (!empty($s['covers_living']))  $covers[] = 'Living';
    if (!empty($s['covers_travel']))  $covers[] = 'Travel';
    if (!empty($s['covers_books']))   $covers[] = 'Books';

    return [
        'id'              => (int)$s['id'],
        'title'           => $s['title'],
        'description'     => $s['description'],
        'provider_name'   => $s['provider_name'] ?? '',
        'host_country'    => $s['host_country_name'] ?? '',
        'host_institution'=> $s['host_institution'] ?? '',
        'academic_levels' => $levels,
        'award_amount'    => $amount,
        'covers'          => $covers,
        'deadline'        => $s['deadline'],
        'is_verified'     => (bool)($s['is_verified'] ?? false),
        'is_saved'        => $s['is_saved'] ?? false,
        'saved_at'        => $s['saved_at'] ?? null,
        'user_notes'      => $s['user_notes'] ?? '',
    ];
}
