<?php
// register.php (giao diện nâng cấp + xử lý đăng ký như bạn cung cấp)
// Đã bổ sung nút "Quay lại trang chủ"
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

// chuẩn bị flash nếu có (hiển thị rồi xoá)
$flash_success = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_success']);

if (!function_exists('esc')) {
    function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
?>
<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Đăng ký - <?= esc(function_exists('site_name') ? site_name($conn) : 'AE Shop') ?></title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">

  <style>
    :root { --brand:#0d6efd; --muted:#6c757d; --card-radius:14px; --bg:#f6f8fb; }
    body { background: linear-gradient(180deg,#fff,#f6f8fb); min-height:100vh; display:flex; align-items:center; justify-content:center; padding:24px; }
    .card-register { width:100%; max-width:1000px; border-radius:var(--card-radius); overflow:hidden; box-shadow:0 20px 60px rgba(10,30,60,0.08); display:grid; grid-template-columns:1fr 420px; background:#fff; }
    .reg-left { padding:36px; display:flex; flex-direction:column; gap:18px; background:linear-gradient(180deg,#ffffff,#f9fbff); }
    .logo-mark { width:56px; height:56px; border-radius:12px; background:var(--brand); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:20px; }
    .reg-right { padding:28px; display:flex; flex-direction:column; gap:12px; background:linear-gradient(180deg,#fbfdff,#f6f9ff); }
    .small-muted { color:var(--muted); font-size:14px; }
    .pw-strength { height:8px; border-radius:6px; background:#e9eef8; overflow:hidden; }
    .pw-strength > i { display:block; height:100%; width:0%; transition:width .25s ease; background:linear-gradient(90deg,#ff4d4f,#ffa940); }
    .or-line { display:flex; align-items:center; gap:10px; color:var(--muted); font-size:13px; }
    .or-line::before, .or-line::after { content:''; flex:1; height:1px; background:#e9eef8; display:block; border-radius:2px; }
    @media (max-width:992px) { .card-register { grid-template-columns:1fr; } }
  </style>
</head>
<body>

<div class="card-register">
  <div class="reg-left">
    <div class="d-flex align-items-center gap-3">
      <div class="logo-mark">AE</div>
      <div>
        <div style="font-weight:800; font-size:18px;"><?= esc(function_exists('site_name') ? site_name($conn) : 'AE Shop') ?></div>
        <div class="small-muted">Thời trang nam cao cấp</div>
      </div>
    </div>

    <div>
      <h3 class="mb-1">Tạo tài khoản mới</h3>
      <p class="small-muted mb-0">Nhanh chóng và an toàn — đăng ký để nhận ưu đãi, theo dõi đơn hàng và nhiều tiện ích khác.</p>
    </div>

    <div class="mt-2">
      <div class="d-flex gap-2 flex-wrap">
        <div class="badge bg-light text-muted p-2"><i class="bi bi-truck me-1"></i> Giao nhanh</div>
        <div class="badge bg-light text-muted p-2"><i class="bi bi-arrow-repeat me-1"></i> Đổi trả dễ</div>
        <div class="badge bg-light text-muted p-2"><i class="bi bi-shield-check me-1"></i> Thanh toán an toàn</div>
      </div>
    </div>

    <div class="mt-auto small-muted">
      <div>Đã có tài khoản? <a href="login.php">Đăng nhập</a></div>
      <div class="mt-3 text-muted small">Bằng việc đăng ký, bạn đồng ý với <a href="terms.php">Điều khoản & Điều kiện</a>.</div>
    </div>
  </div>

  <div class="reg-right">
    <?php if ($flash_success): ?>
      <div class="alert alert-success"><?= esc($flash_success) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
      <div class="alert alert-danger"><?= $error ?></div>
    <?php endif; ?>

    <!-- Nút quay lại trang chủ -->
    <div class="d-flex justify-content-end">
      <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-house-door me-1"></i>Quay lại trang chủ</a>
    </div>

    <form method="post" action="register.php" class="needs-validation" novalidate id="regForm">
      <input type="hidden" name="csrf" value="<?= esc($_SESSION['csrf']) ?>">

      <div class="row g-2 mb-2">
        <div class="col-md-6">
          <label class="form-label small mb-1">Họ</label>
          <input name="ho" class="form-control" placeholder="Nguyễn" value="<?= esc($old['ho']) ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label small mb-1">Tên</label>
          <input name="ten" class="form-control" placeholder="Văn A" value="<?= esc($old['ten']) ?>">
        </div>
      </div>

      <div class="mb-2">
        <label class="form-label small mb-1">Email</label>
        <input name="email" type="email" required class="form-control form-control-lg" placeholder="you@example.com" value="<?= esc($old['email']) ?>">
      </div>

      <div class="row g-2 mb-2">
        <div class="col-md-6">
          <label class="form-label small mb-1">Mật khẩu</label>
          <div class="input-group">
            <input id="pw1" name="mat_khau" type="password" required class="form-control" placeholder="Ít nhất 6 ký tự">
            <button type="button" class="btn btn-outline-secondary" id="togglePw1" aria-label="Hiện mật khẩu"><i class="bi bi-eye"></i></button>
          </div>
          <div class="mt-2">
            <div class="pw-strength" aria-hidden="true"><i id="pwBar"></i></div>
            <div class="small text-muted mt-1" id="pwHelp">Độ mạnh mật khẩu: <span id="pwLabel">—</span></div>
          </div>
        </div>

        <div class="col-md-6">
          <label class="form-label small mb-1">Nhập lại mật khẩu</label>
          <div class="input-group">
            <input id="pw2" name="mat_khau2" type="password" required class="form-control" placeholder="Nhập lại mật khẩu">
            <button type="button" class="btn btn-outline-secondary" id="togglePw2" aria-label="Hiện mật khẩu"><i class="bi bi-eye"></i></button>
          </div>
        </div>
      </div>

      <div class="row g-2 mb-2">
        <div class="col-md-6">
          <label class="form-label small mb-1">Số điện thoại</label>
          <input name="dien_thoai" class="form-control" placeholder="0912xxxxxx" value="<?= esc($old['dien_thoai']) ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label small mb-1">Ngày sinh</label>
          <input name="ngay_sinh" type="date" class="form-control" value="<?= esc($old['ngay_sinh']) ?>">
        </div>
      </div>

      <div class="mb-3">
        <label class="form-label small mb-1">Giới tính</label>
        <select name="gioi_tinh" class="form-select">
          <option value="">-- Chọn (không bắt buộc) --</option>
          <option value="Nam" <?= $old['gioi_tinh']=='Nam' ? 'selected' : '' ?>>Nam</option>
          <option value="Nữ" <?= $old['gioi_tinh']=='Nữ' ? 'selected' : '' ?>>Nữ</option>
        </select>
      </div>

      <div class="mb-3 form-check">
        <input class="form-check-input" type="checkbox" id="agreeCheck" required>
        <label class="form-check-label small" for="agreeCheck">Tôi đồng ý với <a href="terms.php">Điều khoản & Điều kiện</a></label>
      </div>

      <div class="d-grid mb-2">
        <button class="btn btn-primary btn-lg" type="submit"><i class="bi bi-person-plus me-2"></i>Đăng ký & Vào tài khoản</button>
      </div>

      <div class="or-line mb-2"><span>hoặc</span></div>

      <div class="d-grid gap-2 mb-1">
        <a href="#" class="btn btn-light border"><i class="bi bi-google me-2"></i>Đăng ký với Google</a>
        <a href="#" class="btn btn-light border"><i class="bi bi-facebook me-2"></i>Đăng ký với Facebook</a>
      </div>

    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
  // Toggle password visibility
  document.getElementById('togglePw1')?.addEventListener('click', function(){
    const i = this.querySelector('i');
    const inp = document.getElementById('pw1');
    if (inp.type === 'password') { inp.type = 'text'; i.className = 'bi bi-eye-slash'; }
    else { inp.type = 'password'; i.className = 'bi bi-eye'; }
  });
  document.getElementById('togglePw2')?.addEventListener('click', function(){
    const i = this.querySelector('i');
    const inp = document.getElementById('pw2');
    if (inp.type === 'password') { inp.type = 'text'; i.className = 'bi bi-eye-slash'; }
    else { inp.type = 'password'; i.className = 'bi bi-eye'; }
  });

  // Password strength meter (simple)
  const pw1 = document.getElementById('pw1');
  const pwBar = document.getElementById('pwBar');
  const pwLabel = document.getElementById('pwLabel');

  function assessPassword(pw) {
    let score = 0;
    if (!pw) return {score:0, label:'—'};
    if (pw.length >= 6) score++;
    if (pw.length >= 10) score++;
    if (/[0-9]/.test(pw)) score++;
    if (/[A-Z]/.test(pw)) score++;
    if (/[^A-Za-z0-9]/.test(pw)) score++;
    let label = 'Yếu';
    if (score >= 4) label = 'Mạnh';
    else if (score >= 3) label = 'Trung bình';
    return {score, label};
  }

  pw1?.addEventListener('input', function(){
    const val = this.value;
    const result = assessPassword(val);
    const pct = Math.min(100, (result.score / 5) * 100);
    pwBar.style.width = pct + '%';
    // color by CSS background gradient; we can change by inline style as enhancement
    if (result.score <= 2) pwBar.style.background = 'linear-gradient(90deg,#ff4d4f,#ff9a8a)';
    else if (result.score === 3) pwBar.style.background = 'linear-gradient(90deg,#ffa940,#ffd666)';
    else pwBar.style.background = 'linear-gradient(90deg,#6be585,#28c76f)';
    pwLabel.textContent = result.label;
  });

  // simple client-side validation to improve UX
  (function(){
    const form = document.getElementById('regForm');
    if (!form) return;
    form.addEventListener('submit', function(e){
      // check required fields
      const email = form.querySelector('[name=email]');
      const pwA = form.querySelector('[name=mat_khau]');
      const pwB = form.querySelector('[name=mat_khau2]');
      const agree = document.getElementById('agreeCheck');

      if (!email.value.trim()) { alert('Vui lòng nhập email.'); email.focus(); e.preventDefault(); return; }
      if (pwA.value.length < 6) { alert('Mật khẩu cần ít nhất 6 ký tự.'); pwA.focus(); e.preventDefault(); return; }
      if (pwA.value !== pwB.value) { alert('Mật khẩu nhập lại không khớp.'); pwB.focus(); e.preventDefault(); return; }
      if (!agree.checked) { alert('Bạn cần đồng ý Điều khoản & Điều kiện.'); agree.focus(); e.preventDefault(); return; }
    });
  })();
</script>

</body>
</html>
