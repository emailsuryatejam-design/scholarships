<?php
require_once __DIR__ . '/../config/app.php';

/**
 * Build a formal application email body from student data
 */
function build_application_email(array $student, array $scholarship, string $personal_statement): array {
    $student_name = $student['first_name'] . ' ' . $student['last_name'];
    $subject = "Scholarship Application: $student_name - " . $scholarship['title'];

    $body = "Dear Admissions Team,\n\n";
    $body .= "I am writing to formally apply for the " . $scholarship['title'] . ".\n\n";
    $body .= "=== APPLICANT INFORMATION ===\n\n";
    $body .= "Name: $student_name\n";
    $body .= "Email: " . $student['email'] . "\n";

    if (!empty($student['nationality'])) {
        $body .= "Nationality: " . $student['nationality'] . "\n";
    }
    if (!empty($student['date_of_birth'])) {
        $body .= "Date of Birth: " . $student['date_of_birth'] . "\n";
    }
    if (!empty($student['current_education_level'])) {
        $body .= "Current Education Level: " . ucfirst(str_replace('_', ' ', $student['current_education_level'])) . "\n";
    }
    if (!empty($student['desired_education_level'])) {
        $body .= "Desired Education Level: " . ucfirst(str_replace('_', ' ', $student['desired_education_level'])) . "\n";
    }
    if (!empty($student['primary_field'])) {
        $body .= "Field of Study: " . $student['primary_field'] . "\n";
    }
    if (!empty($student['gpa']) && !empty($student['gpa_scale'])) {
        $body .= "GPA: " . $student['gpa'] . " / " . $student['gpa_scale'] . "\n";
    }

    $body .= "\n=== PERSONAL STATEMENT ===\n\n";
    $body .= $personal_statement . "\n";

    $body .= "\n=== APPLICATION DETAILS ===\n\n";
    $body .= "Scholarship: " . $scholarship['title'] . "\n";
    $body .= "Submitted via: " . APP_NAME . " (https://" . parse_url(APP_URL, PHP_URL_HOST) . ")\n";
    $body .= "Date: " . date('F j, Y') . "\n\n";

    $body .= "Thank you for considering my application.\n\n";
    $body .= "Sincerely,\n";
    $body .= "$student_name\n";
    $body .= $student['email'] . "\n";

    return [
        'subject' => $subject,
        'body'    => $body,
    ];
}

/**
 * Send application email
 */
function send_application_email(string $to_email, string $from_name, string $from_email, string $subject, string $body): bool {
    $headers = [
        "From: $from_name <noreply@" . parse_url(APP_URL, PHP_URL_HOST) . ">",
        "Reply-To: $from_email",
        'Content-Type: text/plain; charset=UTF-8',
        'X-Mailer: PHP/' . phpversion(),
        'X-Application: ' . APP_NAME,
    ];

    return mail($to_email, $subject, $body, implode("\r\n", $headers));
}
