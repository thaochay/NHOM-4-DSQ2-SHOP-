<?php
// login.php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inc/helpers.php';

// only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'index.php'));
    exit;
}

$email = trim($_POST['email'] ?? '');
$password = $_POST['mat_khau'] ?? '';
$back = $_POST['back'] ?? ($_SERVER['HTTP_REFERER'] ?? 'index.php');

if ($email === '' || $password === '') {
    $_SESSION['flash_error'] = 'Vui lòng nhập email và mật khẩu.';
    header('Location: ' . $back);
    exit;
}

try {
    $stmt = $conn->prepare("SELECT id_nguoi_dung, ten, email, mat_khau, trang_thai FROM nguoi_dung WHERE email = :email LIMIT 1");
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // DB error -> show generic message
    $_SESSION['flash_error'] = 'Có lỗi hệ thống, vui lòng thử lại sau.';
    header('Location: ' . $back);
    exit;
}

if (!$user) {
    $_SESSION['flash_error'] = 'Email hoặc mật khẩu không đúng.';
    header('Location: ' . $back);
    exit;
}

// if user is deactivated
if (isset($user['trang_thai']) && $user['trang_thai'] == 0) {
    $_SESSION['flash_error'] = 'Tài khoản của bạn đã bị khóa.';
    header('Location: ' . $back);
    exit;
}

// verify password:
// try password_verify first (for hashed passwords), otherwise fallback to plain compare
$ok = false;
$stored = $user['mat_khau'] ?? '';

if ($stored !== '') {
    // detect if stored looks like a bcrypt/argon hash (starts with $2y$ or $2a$ or $argon)
    if (strpos($stored, '$2y$') === 0 || strpos($stored, '$2a$') === 0 || stripos($stored, 'argon2') !== false) {
        if (password_verify($password, $stored)) $ok = true;
    } else {
        // maybe stored plaintext or md5 — try password_verify anyway (works if hash), else compare plain
        if (password_verify($password, $stored)) {
            $ok = true;
        } else {
            // fallback plain compare (not recommended)
            if ($password === $stored) $ok = true;
            // also check md5 possibility
            if (!$ok && md5($password) === $stored) $ok = true;
        }
    }
}

if (!$ok) {
    $_SESSION['flash_error'] = 'Email hoặc mật khẩu không đúng.';
    header('Location: ' . $back);
    exit;
}

// success: set minimal user session (avoid storing password)
$_SESSION['user'] = [
    'id_nguoi_dung' => $user['id_nguoi_dung'],
    'ten' => $user['ten'],
    'email' => $user['email']
];

// optional: regenerate session id
session_regenerate_id(true);

$_SESSION['flash_success'] = 'Đăng nhập thành công. Xin chào ' . ($user['ten'] ?? 'khách') . '!';
header('Location: ' . $back);
exit;
