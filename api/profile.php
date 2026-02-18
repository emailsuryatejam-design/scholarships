<?php
/**
 * User Profile API
 * GET  /api/profile.php - Get full profile with student_profiles data
 * POST /api/profile.php - Update profile (progressive form)
 */
require_once __DIR__ . '/_bootstrap.php';

$user = require_bearer_auth();
$pdo = get_db_connection();
$uid = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Get user data
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $uid]);
    $user_data = $stmt->fetch();

    // Get student profile
    $stmt = $pdo->prepare('SELECT * FROM student_profiles WHERE user_id = :uid LIMIT 1');
    $stmt->execute([':uid' => $uid]);
    $profile = $stmt->fetch();

    // Get countries list for dropdowns
    $countries = $pdo->query('SELECT id, name, iso_code FROM countries ORDER BY name')->fetchAll();

    // Get fields of study for dropdowns
    $fields = $pdo->query('SELECT id, name FROM fields_of_study ORDER BY name')->fetchAll();

    json_response([
        'user'    => format_user($user_data),
        'profile' => $profile ?: null,
        'options' => [
            'countries'       => $countries,
            'fields_of_study' => $fields,
        ],
        'profile_complete' => is_profile_complete($profile),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = get_json_body();

    // Check if profile exists
    $stmt = $pdo->prepare('SELECT id FROM student_profiles WHERE user_id = :uid LIMIT 1');
    $stmt->execute([':uid' => $uid]);
    $existing = $stmt->fetch();

    // Allowed fields for student_profiles
    $allowed = [
        'nationality_id'       => 'int',
        'residence_country_id' => 'int',
        'date_of_birth'        => 'date',
        'gender'               => 'enum:male,female,other,prefer_not_to_say',
        'current_education_level' => 'enum:secondary,undergraduate,postgraduate_masters,postgraduate_phd,postdoctoral,vocational,completed',
        'desired_education_level' => 'enum:secondary,undergraduate,postgraduate_masters,postgraduate_phd,postdoctoral,vocational',
        'gpa'                  => 'decimal',
        'gpa_scale'            => 'decimal',
        'primary_field_id'     => 'int',
        'secondary_field_id'   => 'int',
        'preferred_countries'  => 'json',
        'preferred_language'   => 'string',
        'financial_need_level' => 'enum:none,low,medium,high,critical',
        'has_disability'       => 'bool',
        'is_first_generation'  => 'bool',
        'bio'                  => 'text',
    ];

    $fields = [];
    $values = [];

    foreach ($allowed as $field => $type) {
        if (!array_key_exists($field, $body)) continue;

        $val = $body[$field];

        switch ($type) {
            case 'int':
                $val = (int)$val ?: null;
                break;
            case 'decimal':
                $val = $val !== null && $val !== '' ? (float)$val : null;
                break;
            case 'bool':
                $val = $val ? 1 : 0;
                break;
            case 'date':
                $val = preg_match('/^\d{4}-\d{2}-\d{2}$/', $val) ? $val : null;
                break;
            case 'json':
                $val = is_array($val) ? json_encode($val) : null;
                break;
            case 'text':
            case 'string':
                $val = trim($val) ?: null;
                break;
            default:
                // enum validation
                if (str_starts_with($type, 'enum:')) {
                    $options = explode(',', substr($type, 5));
                    $val = in_array($val, $options) ? $val : null;
                }
        }

        $fields[$field] = $val;
        $values[":$field"] = $val;
    }

    if (empty($fields)) {
        json_response(['error' => 'No valid fields provided.'], 422);
    }

    if ($existing) {
        // Update
        $set_parts = array_map(fn($f) => "$f = :$f", array_keys($fields));
        $sql = 'UPDATE student_profiles SET ' . implode(', ', $set_parts) . ', updated_at = NOW() WHERE user_id = :uid';
        $values[':uid'] = $uid;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);
    } else {
        // Insert
        $fields['user_id'] = $uid;
        $values[':user_id'] = $uid;
        $cols = implode(', ', array_keys($fields));
        $placeholders = implode(', ', array_map(fn($f) => ":$f", array_keys($fields)));
        $sql = "INSERT INTO student_profiles ($cols, created_at, updated_at) VALUES ($placeholders, NOW(), NOW())";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);
    }

    // Also update user's first/last name if provided
    if (!empty($body['first_name']) || !empty($body['last_name'])) {
        $update_parts = [];
        $update_params = [':uid' => $uid];
        if (!empty($body['first_name'])) {
            $update_parts[] = 'first_name = :fn';
            $update_params[':fn'] = trim($body['first_name']);
        }
        if (!empty($body['last_name'])) {
            $update_parts[] = 'last_name = :ln';
            $update_params[':ln'] = trim($body['last_name']);
        }
        if ($update_parts) {
            $sql = 'UPDATE users SET ' . implode(', ', $update_parts) . ', updated_at = NOW() WHERE id = :uid';
            $pdo->prepare($sql)->execute($update_params);
        }
    }

    // Fetch updated profile
    $stmt = $pdo->prepare('SELECT * FROM student_profiles WHERE user_id = :uid LIMIT 1');
    $stmt->execute([':uid' => $uid]);
    $profile = $stmt->fetch();

    json_response([
        'message'          => 'Profile updated successfully!',
        'profile'          => $profile,
        'profile_complete' => is_profile_complete($profile),
    ]);
}

json_response(['error' => 'Method not allowed'], 405);


function is_profile_complete(?array $profile): bool {
    if (!$profile) return false;

    $required = ['nationality_id', 'current_education_level', 'desired_education_level', 'primary_field_id'];
    foreach ($required as $field) {
        if (empty($profile[$field])) return false;
    }
    return true;
}
