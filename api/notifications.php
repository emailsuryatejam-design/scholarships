<?php
/**
 * Notifications API endpoint
 * GET  /api/notifications.php - List user notifications
 * POST /api/notifications.php - Mark as read / Mark all read
 */
require_once __DIR__ . '/_bootstrap.php';

$user = require_bearer_auth();
$pdo  = get_db_connection();

// ─── GET: List notifications ───
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $page      = max(1, (int)($_GET['page'] ?? 1));
    $per_page  = min(50, max(1, (int)($_GET['per_page'] ?? 20)));
    $offset    = ($page - 1) * $per_page;
    $unread_only = ($_GET['unread_only'] ?? '0') === '1';

    $where  = ['user_id = :uid'];
    $params = [':uid' => $user['id']];

    if ($unread_only) {
        $where[] = 'is_read = 0';
    }

    $where_sql = implode(' AND ', $where);

    // Count total
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE $where_sql");
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    // Get unread count (always, regardless of filter)
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND is_read = 0');
    $stmt->execute([':uid' => $user['id']]);
    $unread_count = (int)$stmt->fetchColumn();

    // Fetch notifications
    $sql = "
        SELECT id, type, title, message, link, related_type, related_id,
               is_read, read_at, created_at
        FROM notifications
        WHERE $where_sql
        ORDER BY created_at DESC
        LIMIT :limit OFFSET :offset
    ";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val);
    }
    $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $notifications = array_map(function ($n) {
        return [
            'id'           => (int)$n['id'],
            'type'         => $n['type'],
            'title'        => $n['title'],
            'message'      => $n['message'],
            'link'         => $n['link'] ?? '',
            'related_type' => $n['related_type'],
            'related_id'   => $n['related_id'] ? (int)$n['related_id'] : null,
            'is_read'      => (bool)$n['is_read'],
            'read_at'      => $n['read_at'],
            'created_at'   => $n['created_at'],
        ];
    }, $stmt->fetchAll());

    json_response([
        'notifications' => $notifications,
        'pagination'    => [
            'page'     => $page,
            'per_page' => $per_page,
            'total'    => $total,
            'pages'    => (int)ceil($total / $per_page),
        ],
        'unread_count'  => $unread_count,
    ]);
}

// ─── POST: Mark as read ───
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body   = get_json_body();
    $action = $body['action'] ?? '';

    // Mark specific notifications as read
    if ($action === 'mark_read') {
        $ids = $body['notification_ids'] ?? [];
        if (!is_array($ids) || empty($ids)) {
            json_response(['error' => 'notification_ids array is required.'], 422);
        }

        // Filter to integers only
        $ids = array_filter(array_map('intval', $ids), fn($id) => $id > 0);
        if (empty($ids)) {
            json_response(['error' => 'No valid notification IDs provided.'], 422);
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("
            UPDATE notifications
            SET is_read = 1, read_at = NOW()
            WHERE id IN ($placeholders) AND user_id = ? AND is_read = 0
        ");
        $stmt->execute([...$ids, $user['id']]);

        json_response([
            'message' => 'Notifications marked as read.',
            'updated' => $stmt->rowCount(),
        ]);
    }

    // Mark all as read
    if ($action === 'mark_all_read') {
        $stmt = $pdo->prepare('
            UPDATE notifications
            SET is_read = 1, read_at = NOW()
            WHERE user_id = :uid AND is_read = 0
        ');
        $stmt->execute([':uid' => $user['id']]);

        json_response([
            'message' => 'All notifications marked as read.',
            'updated' => $stmt->rowCount(),
        ]);
    }

    json_response(['error' => 'Invalid action. Use mark_read or mark_all_read.'], 422);
}

json_response(['error' => 'Method not allowed'], 405);
