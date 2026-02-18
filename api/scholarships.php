<?php
/**
 * Scholarships API endpoint
 * GET /api/scholarships.php - List scholarships with filters
 * GET /api/scholarships.php?id=123 - Get single scholarship detail
 */
require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Method not allowed'], 405);
}

$pdo = get_db_connection();

// Single scholarship detail
if (!empty($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmt = $pdo->prepare('
        SELECT s.*,
               sp.name AS provider_name, sp.type AS provider_type, sp.website_url AS provider_url, sp.logo_url AS provider_logo,
               c.name AS host_country_name, c.iso_code AS host_country_code
        FROM scholarships s
        LEFT JOIN scholarship_providers sp ON sp.id = s.provider_id
        LEFT JOIN countries c ON c.id = s.host_country_id
        WHERE s.id = :id AND s.is_active = 1
        LIMIT 1
    ');
    $stmt->execute([':id' => $id]);
    $scholarship = $stmt->fetch();

    if (!$scholarship) {
        json_response(['error' => 'Scholarship not found'], 404);
    }

    // Get eligible nationalities
    $stmt = $pdo->prepare('
        SELECT c.name, c.iso_code
        FROM scholarship_eligible_nationalities sen
        INNER JOIN countries c ON c.id = sen.country_id
        WHERE sen.scholarship_id = :sid
        ORDER BY c.name
    ');
    $stmt->execute([':sid' => $id]);
    $scholarship['eligible_nationalities'] = $stmt->fetchAll();

    // Get eligible fields
    $stmt = $pdo->prepare('
        SELECT f.name
        FROM scholarship_eligible_fields sef
        INNER JOIN fields_of_study f ON f.id = sef.field_of_study_id
        WHERE sef.scholarship_id = :sid
        ORDER BY f.name
    ');
    $stmt->execute([':sid' => $id]);
    $scholarship['eligible_fields'] = array_column($stmt->fetchAll(), 'name');

    // Get tags
    $stmt = $pdo->prepare('
        SELECT t.name
        FROM scholarship_tags st
        INNER JOIN tags t ON t.id = st.tag_id
        WHERE st.scholarship_id = :sid
    ');
    $stmt->execute([':sid' => $id]);
    $scholarship['tags'] = array_column($stmt->fetchAll(), 'name');

    // Check if saved by current user (if authenticated)
    $scholarship['is_saved'] = false;
    $user = get_bearer_user();
    if ($user) {
        $stmt = $pdo->prepare('SELECT id FROM saved_scholarships WHERE user_id = :uid AND scholarship_id = :sid LIMIT 1');
        $stmt->execute([':uid' => $user['id'], ':sid' => $id]);
        $scholarship['is_saved'] = (bool)$stmt->fetch();
    }

    // Increment view count
    $pdo->prepare('UPDATE scholarships SET view_count = view_count + 1 WHERE id = :id')->execute([':id' => $id]);

    json_response(['scholarship' => format_scholarship($scholarship)]);
}

// --- List scholarships with filters ---

$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = min(50, max(1, (int)($_GET['per_page'] ?? 20)));
$offset   = ($page - 1) * $per_page;

// Build WHERE clauses
$where   = ['s.is_active = 1'];
$params  = [];

// Search
$search = trim($_GET['search'] ?? '');
if ($search !== '') {
    $where[]  = 'MATCH(s.title, s.description, s.eligibility_summary) AGAINST(:search IN BOOLEAN MODE)';
    $params[':search'] = $search;
}

// Academic level filter
$level = trim($_GET['level'] ?? '');
if ($level !== '' && in_array($level, ['secondary', 'undergraduate', 'postgraduate_masters', 'postgraduate_phd', 'postdoctoral', 'vocational'])) {
    $where[]  = 'FIND_IN_SET(:level, s.academic_level) > 0';
    $params[':level'] = $level;
}

// Host country filter
$country_id = (int)($_GET['country_id'] ?? 0);
if ($country_id > 0) {
    $where[]  = 's.host_country_id = :country_id';
    $params[':country_id'] = $country_id;
}

// Award type filter
$award_type = trim($_GET['award_type'] ?? '');
if ($award_type !== '' && in_array($award_type, ['full_tuition', 'partial_tuition', 'stipend', 'travel', 'full_ride', 'other'])) {
    $where[]  = 's.award_type = :award_type';
    $params[':award_type'] = $award_type;
}

// Direction filter
$direction = trim($_GET['direction'] ?? '');
if ($direction !== '' && in_array($direction, ['inbound', 'outbound', 'domestic', 'any'])) {
    $where[]  = 's.direction = :direction';
    $params[':direction'] = $direction;
}

// Gender filter
$gender = trim($_GET['gender'] ?? '');
if ($gender !== '' && in_array($gender, ['male', 'female'])) {
    $where[]  = '(s.gender_requirement = :gender OR s.gender_requirement = \'any\')';
    $params[':gender'] = $gender;
}

// Deadline: only future deadlines by default
$include_expired = ($_GET['include_expired'] ?? '0') === '1';
if (!$include_expired) {
    $where[] = '(s.deadline IS NULL OR s.deadline >= CURDATE())';
}

// Eligible nationality filter
$nationality_id = (int)($_GET['nationality_id'] ?? 0);
if ($nationality_id > 0) {
    $where[] = '(EXISTS (SELECT 1 FROM scholarship_eligible_nationalities sen WHERE sen.scholarship_id = s.id AND sen.country_id = :nat_id) OR NOT EXISTS (SELECT 1 FROM scholarship_eligible_nationalities sen2 WHERE sen2.scholarship_id = s.id))';
    $params[':nat_id'] = $nationality_id;
}

// Field of study filter
$field_id = (int)($_GET['field_id'] ?? 0);
if ($field_id > 0) {
    $where[] = '(EXISTS (SELECT 1 FROM scholarship_eligible_fields sef WHERE sef.scholarship_id = s.id AND sef.field_of_study_id = :field_id) OR NOT EXISTS (SELECT 1 FROM scholarship_eligible_fields sef2 WHERE sef2.scholarship_id = s.id))';
    $params[':field_id'] = $field_id;
}

$where_sql = implode(' AND ', $where);

// Sort
$sort_options = [
    'deadline_asc'  => 's.deadline ASC',
    'deadline_desc' => 's.deadline DESC',
    'newest'        => 's.created_at DESC',
    'popular'       => 's.view_count DESC',
    'title_asc'     => 's.title ASC',
];
$sort = $sort_options[$_GET['sort'] ?? 'deadline_asc'] ?? $sort_options['deadline_asc'];

// If fulltext search, add relevance sort option
if ($search !== '') {
    $sort = 'MATCH(s.title, s.description, s.eligibility_summary) AGAINST(:search_sort IN BOOLEAN MODE) DESC, ' . $sort;
    $params[':search_sort'] = $search;
}

// Count total
$count_sql = "SELECT COUNT(*) FROM scholarships s WHERE $where_sql";
$stmt = $pdo->prepare($count_sql);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();

// Fetch scholarships
$sql = "
    SELECT s.id, s.title, s.slug, s.description, s.direction,
           s.academic_level, s.award_type, s.award_amount_min, s.award_amount_max, s.award_currency,
           s.covers_tuition, s.covers_living, s.covers_travel, s.covers_books,
           s.deadline, s.deadline_type, s.application_url, s.eligibility_summary,
           s.gender_requirement, s.financial_need_required, s.merit_based,
           s.host_institution, s.view_count, s.is_verified,
           sp.name AS provider_name, sp.type AS provider_type, sp.logo_url AS provider_logo,
           c.name AS host_country_name, c.iso_code AS host_country_code
    FROM scholarships s
    LEFT JOIN scholarship_providers sp ON sp.id = s.provider_id
    LEFT JOIN countries c ON c.id = s.host_country_id
    WHERE $where_sql
    ORDER BY $sort
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

// Format scholarships
$scholarships = array_map('format_scholarship', $rows);

// Check saved status if user is authenticated
$user = get_bearer_user();
if ($user && !empty($scholarships)) {
    $ids = array_column($scholarships, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT scholarship_id FROM saved_scholarships WHERE user_id = ? AND scholarship_id IN ($placeholders)");
    $stmt->execute(array_merge([$user['id']], $ids));
    $saved_ids = array_column($stmt->fetchAll(), 'scholarship_id');

    foreach ($scholarships as &$s) {
        $s['is_saved'] = in_array($s['id'], $saved_ids);
    }
    unset($s);
}

// Get available filters for the UI
$filters = get_available_filters($pdo);

json_response([
    'scholarships' => $scholarships,
    'pagination' => [
        'page'      => $page,
        'per_page'  => $per_page,
        'total'     => $total,
        'pages'     => (int)ceil($total / $per_page),
    ],
    'filters' => $filters,
]);


// --- Helper functions ---

function format_scholarship(array $s): array {
    // Build covers array
    $covers = [];
    if (!empty($s['covers_tuition'])) $covers[] = 'Tuition';
    if (!empty($s['covers_living']))  $covers[] = 'Living Expenses';
    if (!empty($s['covers_travel']))  $covers[] = 'Travel';
    if (!empty($s['covers_books']))   $covers[] = 'Books & Materials';

    // Format award amount
    $amount = '';
    if (!empty($s['award_amount_min']) || !empty($s['award_amount_max'])) {
        $currency = $s['award_currency'] ?? 'USD';
        if (!empty($s['award_amount_min']) && !empty($s['award_amount_max'])) {
            if ($s['award_amount_min'] == $s['award_amount_max']) {
                $amount = $currency . ' ' . number_format($s['award_amount_min']);
            } else {
                $amount = $currency . ' ' . number_format($s['award_amount_min']) . ' - ' . number_format($s['award_amount_max']);
            }
        } elseif (!empty($s['award_amount_max'])) {
            $amount = 'Up to ' . $currency . ' ' . number_format($s['award_amount_max']);
        } elseif (!empty($s['award_amount_min'])) {
            $amount = 'From ' . $currency . ' ' . number_format($s['award_amount_min']);
        }
    }

    // Format academic levels
    $levels = [];
    if (!empty($s['academic_level'])) {
        $levels = array_map(function($l) {
            return ucfirst(str_replace('_', ' ', trim($l)));
        }, explode(',', $s['academic_level']));
    }

    // Format award type
    $award_labels = [
        'full_tuition' => 'Full Tuition',
        'partial_tuition' => 'Partial Tuition',
        'stipend' => 'Stipend',
        'travel' => 'Travel Grant',
        'full_ride' => 'Full Ride',
        'other' => 'Other',
    ];

    return [
        'id'                => (int)$s['id'],
        'title'             => $s['title'] ?? '',
        'slug'              => $s['slug'] ?? '',
        'description'       => $s['description'] ?? '',
        'provider_name'     => $s['provider_name'] ?? '',
        'provider_type'     => $s['provider_type'] ?? '',
        'provider_logo'     => $s['provider_logo'] ?? '',
        'host_country'      => $s['host_country_name'] ?? '',
        'host_country_code' => $s['host_country_code'] ?? '',
        'host_institution'  => $s['host_institution'] ?? '',
        'academic_levels'   => $levels,
        'award_type'        => $award_labels[$s['award_type'] ?? ''] ?? '',
        'award_amount'      => $amount,
        'covers'            => $covers,
        'deadline'          => $s['deadline'] ?? null,
        'deadline_type'     => $s['deadline_type'] ?? 'fixed',
        'application_url'   => $s['application_url'] ?? '',
        'eligibility'       => $s['eligibility_summary'] ?? '',
        'gender'            => $s['gender_requirement'] ?? 'any',
        'need_based'        => (bool)($s['financial_need_required'] ?? false),
        'merit_based'       => (bool)($s['merit_based'] ?? false),
        'is_verified'       => (bool)($s['is_verified'] ?? false),
        'views'             => (int)($s['view_count'] ?? 0),
        'direction'         => $s['direction'] ?? 'any',
        'is_saved'          => $s['is_saved'] ?? false,
        // Related data (only present in detail view)
        'eligible_nationalities' => $s['eligible_nationalities'] ?? [],
        'eligible_fields'        => $s['eligible_fields'] ?? [],
        'tags'                   => $s['tags'] ?? [],
    ];
}

function get_available_filters(PDO $pdo): array {
    // Get host countries that have active scholarships
    $stmt = $pdo->query('
        SELECT DISTINCT c.id, c.name, c.iso_code
        FROM countries c
        INNER JOIN scholarships s ON s.host_country_id = c.id AND s.is_active = 1
        ORDER BY c.name
    ');
    $countries = $stmt->fetchAll();

    // Get academic levels in use
    $stmt = $pdo->query("SELECT DISTINCT academic_level FROM scholarships WHERE is_active = 1 AND academic_level != ''");
    $level_set = [];
    while ($row = $stmt->fetch()) {
        foreach (explode(',', $row['academic_level']) as $l) {
            $l = trim($l);
            if ($l) $level_set[$l] = ucfirst(str_replace('_', ' ', $l));
        }
    }
    ksort($level_set);

    // Get fields of study that have scholarships
    $stmt = $pdo->query('
        SELECT DISTINCT f.id, f.name
        FROM fields_of_study f
        INNER JOIN scholarship_eligible_fields sef ON sef.field_of_study_id = f.id
        INNER JOIN scholarships s ON s.id = sef.scholarship_id AND s.is_active = 1
        ORDER BY f.name
    ');
    $fields = $stmt->fetchAll();

    // Get nationalities that are eligible for scholarships
    $stmt = $pdo->query('
        SELECT DISTINCT c.id, c.name, c.iso_code
        FROM countries c
        INNER JOIN scholarship_eligible_nationalities sen ON sen.country_id = c.id
        INNER JOIN scholarships s ON s.id = sen.scholarship_id AND s.is_active = 1
        ORDER BY c.name
    ');
    $nationalities = $stmt->fetchAll();

    return [
        'host_countries'  => $countries,
        'academic_levels' => $level_set,
        'fields_of_study' => $fields,
        'nationalities'   => $nationalities,
        'award_types'     => [
            'full_ride'       => 'Full Ride',
            'full_tuition'    => 'Full Tuition',
            'partial_tuition' => 'Partial Tuition',
            'stipend'         => 'Stipend',
            'travel'          => 'Travel Grant',
        ],
    ];
}
