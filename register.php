<?php
// register.php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inc/helpers.php';

// tạo CSRF token nếu chưa có
if (!isset($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

$error = null;
$old = [
  'ho' => '',
  'ten' => '',
  'email' => '',
  'dien_thoai' => '',
  'gioi_tinh' => '',
  'ngay_sinh' => ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // kiểm tra CSRF
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        $error = 'Yêu cầu không hợp lệ (CSRF). Vui lòng thử lại.';
    } else {
        // Lấy input
        $ho = trim($_POST['ho'] ?? '');
        $ten = trim($_POST['ten'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $mat_khau = $_POST['mat_khau'] ?? '';
        $mat_khau2 = $_POST['mat_khau2'] ?? '';
        $dien_thoai = trim($_POST['dien_thoai'] ?? '');
        $gioi_tinh = $_POST['gioi_tinh'] ?? '';
        $ngay_sinh = $_POST['ngay_sinh'] ?? '';

        $old = compact('ho','ten','email','dien_thoai','gioi_tinh','ngay_sinh');

        // validate đơn giản
        $errs = [];
        if ($ho === '' && $ten === '') $errs[] = 'Vui lòng nhập họ hoặc tên.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errs[] = 'Email không hợp lệ.';
        if (strlen($mat_khau) < 6) $errs[] = 'Mật khẩu ít nhất 6 ký tự.';
        if ($mat_khau !== $mat_khau2) $errs[] = 'Mật khẩu nhập lại không khớp.';

        // kiểm tra tồn tại email
        if (!$errs) {
            $stmt = $conn->prepare("SELECT COUNT(*) FROM nguoi_dung WHERE email = :email");
            $stmt->execute(['email'=>$email]);
            if ($stmt->fetchColumn() > 0) $errs[] = 'Email đã được đăng ký. Nếu là bạn, hãy đăng nhập.';
        }

        if ($errs) {
            $error = implode('<br>', $errs);
        } else {
            // chèn user
            $fullname = trim(($ho ? ($ho . ' ') : '') . $ten);
            if ($fullname === '') $fullname = $email;
            $hash = password_hash($mat_khau, PASSWORD_DEFAULT);

            $ins = $conn->prepare("INSERT INTO nguoi_dung (ten, email, mat_khau, dien_thoai, ngay_sinh, gioi_tinh, created_at) 
                                   VALUES (:ten, :email, :mat_khau, :dien_thoai, :ngay_sinh, :gioi_tinh, NOW())");
            $ins->execute([
                'ten' => $fullname,
                'email' => $email,
                'mat_khau' => $hash,
                'dien_thoai' => $dien_thoai ?: null,
                'ngay_sinh' => $ngay_sinh ?: null,
                'gioi_tinh' => $gioi_tinh ?: null
            ]);

            // --- TỰ ĐĂNG NHẬP NGAY SAU KHI ĐĂNG KÝ ---
            $userId = $conn->lastInsertId();
            $_SESSION['user'] = [
                'id_nguoi_dung' => (int)$userId,
                'ten' => $fullname,
                'email' => $email
            ];

            // flash success (nếu có hệ thống flash)
            $_SESSION['flash_success'] = 'Chúc mừng! Bạn đã đăng ký và được đăng nhập tự động.';

            // CHUYỂN THẲNG VỀ TRANG TÀI KHOẢN (account.php)
            header('Location: account.php');
            exit;
        }
    }
}
?>
<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Đăng ký - <?= esc(site_name($conn)) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
<div class="container py-5" style="max-width:720px;">
  <h3 class="text-center mb-4">Đăng ký tài khoản</h3>

  <?php if($error): ?>
    <div class="alert alert-danger"><?= $error ?></div>
  <?php endif; ?>

  <form method="post" action="register.php" class="needs-validation" novalidate>
    <input type="hidden" name="csrf" value="<?= esc($_SESSION['csrf']) ?>">
    <div class="row g-2 mb-2">
      <div class="col-md-6">
        <input name="ho" class="form-control" placeholder="Họ" value="<?= esc($old['ho']) ?>">
      </div>
      <div class="col-md-6">
        <input name="ten" class="form-control" placeholder="Tên" value="<?= esc($old['ten']) ?>">
      </div>
    </div>

    <div class="mb-2">
      <input name="email" type="email" required class="form-control" placeholder="Email" value="<?= esc($old['email']) ?>">
    </div>

    <div class="row g-2 mb-2">
      <div class="col-md-6"><input name="mat_khau" type="password" required class="form-control" placeholder="Mật khẩu"></div>
      <div class="col-md-6"><input name="mat_khau2" type="password" required class="form-control" placeholder="Nhập lại mật khẩu"></div>
    </div>

    <div class="row g-2 mb-2">
      <div class="col-md-6"><input name="dien_thoai" class="form-control" placeholder="Số điện thoại" value="<?= esc($old['dien_thoai']) ?>"></div>
      <div class="col-md-6"><input name="ngay_sinh" type="date" class="form-control" value="<?= esc($old['ngay_sinh']) ?>"></div>
    </div>

    <div class="mb-3">
      <select name="gioi_tinh" class="form-select" >
        <option value="">-- Giới tính (không bắt buộc) --</option>
        <option value="Nam" <?= $old['gioi_tinh']=='Nam' ? 'selected' : '' ?>>Nam</option>
        <option value="Nữ" <?= $old['gioi_tinh']=='Nữ' ? 'selected' : '' ?>>Nữ</option>
      </select>
    </div>

    <div class="d-grid">
      <button class="btn btn-primary">Đăng ký và vào tài khoản</button>
    </div>

    <div class="mt-3 text-center small">
      Đã có tài khoản? <a href="login.php">Đăng nhập</a>
    </div>
  </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
