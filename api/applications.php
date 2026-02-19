<?php
/**
 * Applications API endpoint
 * GET  /api/applications.php           - List user's applications
 * POST /api/applications.php           - Create new application / Update / Change status
 */
require_once __DIR__ . '/_bootstrap.php';

$user = require_bearer_auth();
$pdo  = get_db_connection();

// ─── GET: List applications ───
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $page     = max(1, (int)($_GET['page'] ?? 1));
    $per_page = min(50, max(1, (int)($_GET['per_page'] ?? 20)));
    $offset   = ($page - 1) * $per_page;

    $where  = ['a.user_id = :uid'];
    $params = [':uid' => $user['id']];

    // Status filter
    $status = trim($_GET['status'] ?? '');
    $valid_statuses = ['draft', 'ready', 'submitted', 'under_review', 'accepted', 'rejected', 'waitlisted', 'withdrawn'];
    if ($status !== '' && in_array($status, $valid_statuses)) {
        $where[]  = 'a.status = :status';
        $params[':status'] = $status;
    }

    // "decided" meta-filter (accepted, rejected, waitlisted)
    if ($status === 'decided') {
        // Override the status filter
        array_pop($where);
        unset($params[':status']);
        $where[] = "a.status IN ('accepted', 'rejected', 'waitlisted')";
    }

    $where_sql = implode(' AND ', $where);

    // Count total
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM applications a WHERE $where_sql");
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    // Fetch applications with scholarship info
    $sql = "
        SELECT a.id, a.scholarship_id, a.status, a.submitted_at, a.submitted_via,
               a.created_at, a.updated_at,
               s.title AS scholarship_title, s.slug AS scholarship_slug,
               s.deadline, s.deadline_type, s.application_url,
               s.academic_level, s.award_type,
               sp.name AS provider_name, sp.logo_url AS provider_logo,
               c.name AS host_country_name, c.iso_code AS host_country_code
        FROM applications a
        INNER JOIN scholarships s ON s.id = a.scholarship_id
        LEFT JOIN scholarship_providers sp ON sp.id = s.provider_id
        LEFT JOIN countries c ON c.id = s.host_country_id
        WHERE $where_sql
        ORDER BY a.updated_at DESC
        LIMIT :limit OFFSET :offset
    ";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val);
    }
    $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    // Format applications
    $applications = array_map(function ($a) {
        $levels = [];
        if (!empty($a['academic_level'])) {
            $levels = array_map(fn($l) => ucfirst(str_replace('_', ' ', trim($l))), explode(',', $a['academic_level']));
        }
        return [
            'id'                => (int)$a['id'],
            'scholarship_id'    => (int)$a['scholarship_id'],
            'scholarship_title' => $a['scholarship_title'],
            'scholarship_slug'  => $a['scholarship_slug'],
            'provider_name'     => $a['provider_name'] ?? '',
            'provider_logo'     => $a['provider_logo'] ?? '',
            'host_country'      => $a['host_country_name'] ?? '',
            'host_country_code' => $a['host_country_code'] ?? '',
            'academic_levels'   => $levels,
            'award_type'        => $a['award_type'] ?? '',
            'deadline'          => $a['deadline'],
            'deadline_type'     => $a['deadline_type'] ?? 'fixed',
            'application_url'   => $a['application_url'] ?? '',
            'status'            => $a['status'],
            'submitted_at'      => $a['submitted_at'],
            'submitted_via'     => $a['submitted_via'] ?? 'external_link',
            'created_at'        => $a['created_at'],
            'updated_at'        => $a['updated_at'],
        ];
    }, $rows);

    // Get status counts for filter badges
    $stmt = $pdo->prepare("
        SELECT status, COUNT(*) as count
        FROM applications
        WHERE user_id = :uid
        GROUP BY status
    ");
    $stmt->execute([':uid' => $user['id']]);
    $status_counts = [];
    while ($row = $stmt->fetch()) {
        $status_counts[$row['status']] = (int)$row['count'];
    }

    json_response([
        'applications'  => $applications,
        'pagination'    => [
            'page'     => $page,
            'per_page' => $per_page,
            'total'    => $total,
            'pages'    => (int)ceil($total / $per_page),
        ],
        'status_counts' => $status_counts,
    ]);
}

// ─── POST: Create / Update / Change status ───
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body   = get_json_body();
    $action = $body['action'] ?? 'create';

    // ── Create new application ──
    if ($action === 'create') {
        $scholarship_id = (int)($body['scholarship_id'] ?? 0);
        if ($scholarship_id <= 0) {
            json_response(['error' => 'Valid scholarship_id is required.'], 422);
        }

        // Check scholarship exists
        $stmt = $pdo->prepare('SELECT id, title FROM scholarships WHERE id = :id AND is_active = 1 LIMIT 1');
        $stmt->execute([':id' => $scholarship_id]);
        $scholarship = $stmt->fetch();
        if (!$scholarship) {
            json_response(['error' => 'Scholarship not found.'], 404);
        }

        // Check if already applied
        $stmt = $pdo->prepare('SELECT id, status FROM applications WHERE user_id = :uid AND scholarship_id = :sid LIMIT 1');
        $stmt->execute([':uid' => $user['id'], ':sid' => $scholarship_id]);
        $existing = $stmt->fetch();
        if ($existing) {
            json_response(['error' => 'You already have an application for this scholarship.', 'application_id' => (int)$existing['id'], 'status' => $existing['status']], 409);
        }

        // Create application
        $stmt = $pdo->prepare('
            INSERT INTO applications (user_id, scholarship_id, status, personal_statement, additional_info, notes, created_at, updated_at)
            VALUES (:uid, :sid, :status, :ps, :ai, :notes, NOW(), NOW())
        ');
        $stmt->execute([
            ':uid'    => $user['id'],
            ':sid'    => $scholarship_id,
            ':status' => 'draft',
            ':ps'     => trim($body['personal_statement'] ?? ''),
            ':ai'     => trim($body['additional_info'] ?? ''),
            ':notes'  => trim($body['notes'] ?? ''),
        ]);
        $app_id = (int)$pdo->lastInsertId();

        // Create timeline entry
        $pdo->prepare('
            INSERT INTO application_timeline (application_id, from_status, to_status, note, changed_by, created_at)
            VALUES (:aid, NULL, :to, :note, :by, NOW())
        ')->execute([
            ':aid'  => $app_id,
            ':to'   => 'draft',
            ':note' => 'Application started',
            ':by'   => 'user',
        ]);

        json_response([
            'message'     => 'Application created as draft.',
            'application' => ['id' => $app_id, 'status' => 'draft', 'scholarship_id' => $scholarship_id],
        ], 201);
    }

    // ── Update application content ──
    if ($action === 'update') {
        $app_id = (int)($body['application_id'] ?? 0);
        if ($app_id <= 0) {
            json_response(['error' => 'Valid application_id is required.'], 422);
        }

        // Verify ownership and draft status
        $stmt = $pdo->prepare('SELECT id, status FROM applications WHERE id = :id AND user_id = :uid LIMIT 1');
        $stmt->execute([':id' => $app_id, ':uid' => $user['id']]);
        $app = $stmt->fetch();
        if (!$app) {
            json_response(['error' => 'Application not found.'], 404);
        }
        if (!in_array($app['status'], ['draft', 'ready'])) {
            json_response(['error' => 'Cannot edit a submitted application.'], 422);
        }

        // Build update
        $updates = [];
        $params  = [':id' => $app_id];

        if (isset($body['personal_statement'])) {
            $updates[] = 'personal_statement = :ps';
            $params[':ps'] = trim($body['personal_statement']);
        }
        if (isset($body['additional_info'])) {
            $updates[] = 'additional_info = :ai';
            $params[':ai'] = trim($body['additional_info']);
        }
        if (isset($body['notes'])) {
            $updates[] = 'notes = :notes';
            $params[':notes'] = trim($body['notes']);
        }

        if (empty($updates)) {
            json_response(['error' => 'No fields to update.'], 422);
        }

        $updates[] = 'updated_at = NOW()';
        $sql = "UPDATE applications SET " . implode(', ', $updates) . " WHERE id = :id";
        $pdo->prepare($sql)->execute($params);

        json_response(['message' => 'Application updated.']);
    }

    // ── Change status ──
    if ($action === 'update_status') {
        $app_id    = (int)($body['application_id'] ?? 0);
        $new_status = trim($body['status'] ?? '');
        $note       = trim($body['note'] ?? '');

        if ($app_id <= 0) {
            json_response(['error' => 'Valid application_id is required.'], 422);
        }

        $valid_statuses = ['draft', 'ready', 'submitted', 'under_review', 'accepted', 'rejected', 'waitlisted', 'withdrawn'];
        if (!in_array($new_status, $valid_statuses)) {
            json_response(['error' => 'Invalid status.'], 422);
        }

        // Verify ownership
        $stmt = $pdo->prepare('SELECT id, status FROM applications WHERE id = :id AND user_id = :uid LIMIT 1');
        $stmt->execute([':id' => $app_id, ':uid' => $user['id']]);
        $app = $stmt->fetch();
        if (!$app) {
            json_response(['error' => 'Application not found.'], 404);
        }

        $old_status = $app['status'];
        if ($old_status === $new_status) {
            json_response(['error' => 'Application is already in this status.'], 422);
        }

        // Update status
        $update_fields = ['status = :status', 'updated_at = NOW()'];
        $update_params = [':status' => $new_status, ':id' => $app_id];

        if ($new_status === 'submitted' && empty($app['submitted_at'])) {
            $update_fields[] = 'submitted_at = NOW()';
        }
        if (in_array($new_status, ['accepted', 'rejected', 'waitlisted'])) {
            $update_fields[] = 'result_at = NOW()';
        }

        $sql = "UPDATE applications SET " . implode(', ', $update_fields) . " WHERE id = :id";
        $pdo->prepare($sql)->execute($update_params);

        // Create timeline entry
        $pdo->prepare('
            INSERT INTO application_timeline (application_id, from_status, to_status, note, changed_by, created_at)
            VALUES (:aid, :from, :to, :note, :by, NOW())
        ')->execute([
            ':aid'  => $app_id,
            ':from' => $old_status,
            ':to'   => $new_status,
            ':note' => $note ?: "Status changed to $new_status",
            ':by'   => 'user',
        ]);

        // Create notification
        $status_labels = [
            'submitted'    => 'Application Submitted',
            'under_review' => 'Application Under Review',
            'accepted'     => 'Application Accepted!',
            'rejected'     => 'Application Not Selected',
            'waitlisted'   => 'Application Waitlisted',
            'withdrawn'    => 'Application Withdrawn',
        ];
        if (isset($status_labels[$new_status])) {
            // Get scholarship title for notification
            $stmt = $pdo->prepare('SELECT s.title FROM applications a INNER JOIN scholarships s ON s.id = a.scholarship_id WHERE a.id = :id');
            $stmt->execute([':id' => $app_id]);
            $sch_title = $stmt->fetchColumn();

            $pdo->prepare('
                INSERT INTO notifications (user_id, type, title, message, link, related_type, related_id, created_at)
                VALUES (:uid, :type, :title, :msg, :link, :rt, :rid, NOW())
            ')->execute([
                ':uid'   => $user['id'],
                ':type'  => 'application_update',
                ':title' => $status_labels[$new_status],
                ':msg'   => "Your application to \"$sch_title\" has been updated to: $new_status.",
                ':link'  => "/applications/$app_id",
                ':rt'    => 'application',
                ':rid'   => $app_id,
            ]);
        }

        json_response([
            'message'    => "Status updated to $new_status.",
            'old_status' => $old_status,
            'new_status' => $new_status,
        ]);
    }

    json_response(['error' => 'Invalid action.'], 422);
}

json_response(['error' => 'Method not allowed'], 405);
