<?php
require_once __DIR__ . '/../functions/sell-functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed.');
}

if (!hmd_verify_csrf_token($_POST['csrf_token'] ?? null)) {
    hmd_redirect('../signup.php', 'Your form session expired. Please try again.');
}

$name = trim((string) ($_POST['name'] ?? ''));
$phone = trim((string) ($_POST['number'] ?? ''));
$email = strtolower(trim((string) ($_POST['email'] ?? '')));
$address = trim((string) ($_POST['address'] ?? ''));
$hostel = trim((string) ($_POST['hostel'] ?? ''));
$course = trim((string) ($_POST['course'] ?? ''));
$semester = trim((string) ($_POST['semester'] ?? ''));
$password = $_POST['pwd'] ?? '';
$confirmation = $_POST['rpwd'] ?? '';
$emailDomain = substr(strrchr($email, '@') ?: '', 1);
$length = static function ($value) {
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
};

if ($name === '' || $length($name) > 50
    || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100
    || !in_array($emailDomain, HMD_COLLEGE_EMAIL_DOMAINS, true)
    || $phone === '' || strlen($phone) > 15
    || $address === '' || $length($address) > 2000
    || $hostel === '' || $length($hostel) > 100
    || $course === '' || $length($course) > 100
    || $semester === '' || $length($semester) > 50) {
    hmd_redirect('../signup.php', 'Enter valid details and use an approved campus email address.');
}
if (!is_string($password) || strlen($password) < 10 || strlen($password) > 72
    || !is_string($confirmation) || !hash_equals($password, $confirmation)) {
    hmd_redirect('../signup.php', 'Use a password of 10–72 characters and make sure both entries match.');
}

$passwordHash = password_hash($password, PASSWORD_DEFAULT);
if ($passwordHash === false) {
    error_log('Unable to hash a new HandMeDown customer password.');
    hmd_redirect('../signup.php', 'Registration is temporarily unavailable. Please try again.');
}

try {
    $statement = hmd_prepare($conn,
        "INSERT INTO customer
         (customer_fname, customer_email, customer_pwd, customer_phone, customer_address,
          customer_role, hostel, course, semester)
         VALUES (?, ?, ?, ?, ?, 'normal', ?, ?, ?)");
    $statement->bind_param('ssssssss', $name, $email, $passwordHash, $phone, $address, $hostel, $course, $semester);
    hmd_execute($statement);
    $statement->close();
} catch (mysqli_sql_exception $exception) {
    error_log('HandMeDown signup database error: ' . $exception->getMessage());
    hmd_redirect('../signup.php', 'That email address is already registered, or registration could not be completed.');
} catch (RuntimeException $exception) {
    error_log('HandMeDown signup unavailable: ' . $exception->getMessage());
    hmd_redirect('../signup.php', 'Registration is unavailable. Check that the HandMeDown schema has been applied.');
}

hmd_redirect('../signup.php', '1', 'success');
