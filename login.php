<?php
// login.php
session_start();
require_once __DIR__ . '/db.php'; // Kết nối cơ sở dữ liệu
require_once __DIR__ . '/inc/helpers.php'; // Các hàm trợ giúp (nếu có)

// Chỉ cho phép phương thức POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'index.php'));
    exit;
}

// Lấy dữ liệu từ form
$email = trim($_POST['email'] ?? '');
$password = $_POST['mat_khau'] ?? '';
$back = $_POST['back'] ?? ($_SERVER['HTTP_REFERER'] ?? 'index.php');

// Kiểm tra xem email và mật khẩu có trống hay không
if ($email === '' || $password === '') {
    $_SESSION['flash_error'] = 'Vui lòng nhập email và mật khẩu.';
    header('Location: ' . $back);
    exit;
}

try {
    // Truy vấn dữ liệu người dùng từ cơ sở dữ liệu
    $stmt = $conn->prepare("SELECT id_nguoi_dung, ten, email, mat_khau, trang_thai FROM nguoi_dung WHERE email = :email LIMIT 1");
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Lỗi cơ sở dữ liệu
    $_SESSION['flash_error'] = 'Có lỗi hệ thống, vui lòng thử lại sau.';
    header('Location: ' . $back);
    exit;
}

// Kiểm tra nếu không có người dùng
if (!$user) {
    $_SESSION['flash_error'] = 'Email hoặc mật khẩu không đúng.';
    header('Location: ' . $back);
    exit;
}

// Kiểm tra nếu tài khoản bị khóa
if (isset($user['trang_thai']) && $user['trang_thai'] == 0) {
    $_SESSION['flash_error'] = 'Tài khoản của bạn đã bị khóa.';
    header('Location: ' . $back);
    exit;
}

// Kiểm tra mật khẩu
$ok = false;
$stored = $user['mat_khau'] ?? '';

if ($stored !== '') {
    // Kiểm tra xem mật khẩu đã được mã hóa chưa (bcrypt/argon2)
    if (strpos($stored, '$2y$') === 0 || strpos($stored, '$2a$') === 0 || stripos($stored, 'argon2') !== false) {
        if (password_verify($password, $stored)) $ok = true;
    } else {
        // Mật khẩu dạng văn bản thuần túy hoặc MD5
        if (password_verify($password, $stored)) {
            $ok = true;
        } else {
            if ($password === $stored) $ok = true;
            if (!$ok && md5($password) === $stored) $ok = true;
        }
    }
}

if (!$ok) {
    $_SESSION['flash_error'] = 'Email hoặc mật khẩu không đúng.';
    header('Location: ' . $back);
    exit;
}

// Đăng nhập thành công, thiết lập thông tin người dùng vào session
$_SESSION['user'] = [
    'id_nguoi_dung' => $user['id_nguoi_dung'],
    'ten' => $user['ten'],
    'email' => $user['email']
];

// Tùy chọn: tái tạo ID phiên để bảo mật
session_regenerate_id(true);

// Gửi thông báo đăng nhập thành công
$_SESSION['flash_success'] = 'Đăng nhập thành công. Xin chào ' . ($user['ten'] ?? 'khách') . '!';

// Chuyển hướng về trang chủ
header('Location: index.php');
exit;
