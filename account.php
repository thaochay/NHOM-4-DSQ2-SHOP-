<?php
// account.php — quản lý tài khoản + phương thức thanh toán (dùng phuong_thuc_thanh_toan + user_pttt)
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inc/helpers.php';

// Auth (giữ như bạn)
$userSess = $_SESSION['user'] ?? null;
$userId = $userSess['id_nguoi_dung'] ?? ($userSess['id'] ?? null);
if (empty($userId)) { header('Location: login.php'); exit; }
$userId = (int)$userId;

// CSRF
if (!isset($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

// fetch user
$stmt = $conn->prepare("SELECT id_nguoi_dung, ten, email, dien_thoai FROM nguoi_dung WHERE id_nguoi_dung = :id LIMIT 1");
$stmt->execute(['id'=>$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['ten'=>$userSess['ten'] ?? 'Người dùng', 'email'=>$userSess['email'] ?? ''];

// fetch site cart count (optional)
$cart_count = 0;
if (!empty($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $ci) $cart_count += isset($ci['qty']) ? (int)$ci['qty'] : 1;
}

// --- ensure user_pttt exists (safe create) ---
try {
    $conn->exec("
      CREATE TABLE IF NOT EXISTS user_pttt (
        id_user_pttt INT AUTO_INCREMENT PRIMARY KEY,
        id_nguoi_dung INT NOT NULL,
        id_pttt INT NOT NULL,
        account_info VARCHAR(255) DEFAULT NULL,
        holder_name VARCHAR(255) DEFAULT NULL,
        is_default TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(id_nguoi_dung),
        INDEX(id_pttt)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (\Throwable $e) {
    // ignore
}

// --- Handle POST actions for payment methods ---
$pm_errors = [];
$pm_success = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    // CSRF
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        $pm_errors[] = 'Token không hợp lệ.';
    } else {
        if ($action === 'add_user_pttt') {
            $id_pttt = (int)($_POST['id_pttt'] ?? 0);
            $account_info = trim($_POST['account_info'] ?? '');
            $holder_name = trim($_POST['holder_name'] ?? '');
            if ($id_pttt <= 0) $pm_errors[] = 'Phương thức không hợp lệ.';
            if ($account_info === '') $pm_errors[] = 'Nhập số ví / số tài khoản.';
            if (empty($pm_errors)) {
                // insert
                $ins = $conn->prepare("INSERT INTO user_pttt (id_nguoi_dung, id_pttt, account_info, holder_name, is_default) VALUES (:uid,:pttt,:acc,:holder,0)");
                $ins->execute([':uid'=>$userId, ':pttt'=>$id_pttt, ':acc'=>$account_info, ':holder'=>$holder_name]);
                $pm_success = 'Đã lưu phương thức thanh toán của bạn.';
            }
        } elseif ($action === 'remove_user_pttt') {
            $id = (int)($_POST['id_user_pttt'] ?? 0);
            if ($id <= 0) $pm_errors[] = 'ID không hợp lệ.';
            else {
                $d = $conn->prepare("DELETE FROM user_pttt WHERE id_user_pttt = :id AND id_nguoi_dung = :uid");
                $d->execute([':id'=>$id, ':uid'=>$userId]);
                $pm_success = 'Đã xóa.';
            }
        } elseif ($action === 'set_default_user_pttt') {
            $id = (int)($_POST['id_user_pttt'] ?? 0);
            if ($id <= 0) $pm_errors[] = 'ID không hợp lệ.';
            else {
                $conn->beginTransaction();
                $conn->prepare("UPDATE user_pttt SET is_default = 0 WHERE id_nguoi_dung = :uid")->execute([':uid'=>$userId]);
                $conn->prepare("UPDATE user_pttt SET is_default = 1 WHERE id_user_pttt = :id AND id_nguoi_dung = :uid")->execute([':id'=>$id, ':uid'=>$userId]);
                $conn->commit();
                $pm_success = 'Đã đặt mặc định.';
            }
        }
    }
}

// --- Load available methods from your phuong_thuc_thanh_toan table ---
$available_methods = [];
try {
    $pstmt = $conn->prepare("SELECT id_pttt, ten, mo_ta, trang_thai FROM phuong_thuc_thanh_toan WHERE trang_thai = 1 ORDER BY id_pttt ASC");
    $pstmt->execute();
    $available_methods = $pstmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $available_methods = [];
}

// --- Load user's saved methods ---
$user_methods = [];
try {
    $um = $conn->prepare("SELECT u.id_user_pttt, u.id_pttt, u.account_info, u.holder_name, u.is_default, p.ten AS method_name FROM user_pttt u LEFT JOIN phuong_thuc_thanh_toan p ON u.id_pttt = p.id_pttt WHERE u.id_nguoi_dung = :uid ORDER BY u.is_default DESC, u.created_at DESC");
    $um->execute([':uid'=>$userId]);
    $user_methods = $um->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $user_methods = [];
}

// helper to mask account
function mask_acc_small($s){
    $s = trim((string)$s);
    if ($s === '') return '';
    $last = substr($s, -4);
    return '•••• ' . $last;
}
?>
<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <title>Tài khoản — <?= esc(site_name($conn)) ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body { background:#f6f7f8; color:#222; font-family:system-ui, -apple-system, "Segoe UI", Roboto, Arial; }
    .site-header { border-bottom:1px solid #eee; background:#fff; }
    .account-wrap { padding:36px 0; }
    .account-sidebar { border-right:1px solid #eee; }
    .account-section { padding:18px; background:#fff; border-radius:8px; box-shadow:0 6px 20px rgba(13,38,59,0.03); }
    .pm-card { border:1px solid #eef3fb; border-radius:8px; padding:12px; }
    .badge-default { background:#198754; color:#fff; font-size:.7rem; padding:.25rem .4rem; border-radius:.375rem; }
  </style>
</head>
<body>

<header class="site-header py-2">
  <div class="container d-flex align-items-center justify-content-between">
    <a href="index.php" class="text-decoration-none d-flex align-items-center gap-2">
      <div style="width:46px;height:46px;border-radius:50%;background:#0b1220;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700">AE</div>
      <div style="line-height:1"><strong><?= esc(site_name($conn)) ?></strong><br><small class="text-muted">Tài khoản</small></div>
    </a>

    <div>
      <a class="btn btn-outline-secondary me-2" href="index.php">Về trang chủ</a>
      <a class="btn btn-outline-primary" href="cart.php">Giỏ hàng (<?= (int)$cart_count ?>)</a>
    </div>
  </div>
</header>

<div class="container account-wrap">
  <div class="row">
    <div class="col-lg-3 mb-3">
      <div class="account-sidebar bg-white p-3">
        <div class="list-group">
          <a href="account.php" class="list-group-item list-group-item-action active">Thông tin</a>
          <a href="addresses.php" class="list-group-item list-group-item-action">Địa chỉ</a>
          <a href="orders.php" class="list-group-item list-group-item-action">Đơn hàng</a>
          <a href="logout.php" class="list-group-item list-group-item-action text-danger">Đăng xuất</a>
        </div>
      </div>
    </div>

    <div class="col-lg-9">
      <div class="account-section">
        <h5>Thông tin tài khoản</h5>
        <div class="d-flex justify-content-between align-items-start mb-3">
          <div>
            <div class="fw-semibold"><?= esc($user['ten']) ?></div>
            <div class="text-muted"><?= esc($user['email']) ?></div>
            <?php if(!empty($user['dien_thoai'])): ?><div class="text-muted"><?= esc($user['dien_thoai']) ?></div><?php endif; ?>
          </div>
          <div><a class="btn btn-outline-primary btn-sm" href="account_edit.php">Chỉnh sửa</a></div>
        </div>

        <hr>

        <!-- Payment methods: available list + user's saved -->
        <div class="mb-4">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="mb-0">Phương thức thanh toán</h6>
            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addUserPmtModal">
              <i class="bi bi-plus-circle"></i> Thêm phương thức
            </button>
          </div>

          <?php if ($pm_success): ?><div class="alert alert-success"><?= esc($pm_success) ?></div><?php endif; ?>
          <?php if (!empty($pm_errors)): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach($pm_errors as $e) echo '<li>'.esc($e).'</li>'; ?></ul></div><?php endif; ?>

          <div class="row g-3 mb-3">
            <?php if (empty($available_methods)): ?>
              <div class="col-12"><div class="alert alert-info">Chưa có phương thức thanh toán nào được cấu hình.</div></div>
            <?php else: ?>
              <?php foreach ($available_methods as $m): ?>
                <div class="col-md-4">
                  <div class="p-3 border rounded bg-white">
                    <div class="fw-semibold"><?= esc($m['ten']) ?></div>
                    <?php if (!empty($m['mo_ta'])): ?><div class="text-muted small"><?= esc($m['mo_ta']) ?></div><?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>

          <h6 class="mb-2">Phương thức bạn đã lưu</h6>
          <?php if (empty($user_methods)): ?>
            <div class="alert alert-info">Bạn chưa lưu phương thức thanh toán nào.</div>
          <?php else: ?>
            <div class="row g-3">
              <?php foreach($user_methods as $um): ?>
                <div class="col-md-6">
                  <div class="pm-card d-flex align-items-center gap-3">
                    <div style="width:56px;height:56px;border-radius:8px;background:#f4f7ff;display:flex;align-items:center;justify-content:center">
                      <i class="bi bi-wallet2" style="font-size:20px;color:#1677ff"></i>
                    </div>
                    <div class="flex-grow-1">
                      <div class="d-flex justify-content-between align-items-start">
                        <div>
                          <div class="fw-semibold"><?= esc($um['method_name'] ?: 'PTTT') ?> <?= $um['is_default'] ? '<span class="badge badge-default">Mặc định</span>' : '' ?></div>
                          <div class="small text-muted"><?= esc(mask_acc_small($um['account_info'])) ?><?= $um['holder_name'] ? ' · ' . esc($um['holder_name']) : '' ?></div>
                        </div>
                        <div class="text-end">
                          <?php if (!$um['is_default']): ?>
                            <form method="post" style="display:inline">
                              <input type="hidden" name="csrf" value="<?= esc($csrf) ?>">
                              <input type="hidden" name="action" value="set_default_user_pttt">
                              <input type="hidden" name="id_user_pttt" value="<?= (int)$um['id_user_pttt'] ?>">
                              <button class="btn btn-sm btn-outline-secondary mb-1" type="submit">Đặt mặc định</button>
                            </form>
                          <?php endif; ?>

                          <form method="post" style="display:inline" onsubmit="return confirm('Bạn chắc chắn muốn xóa?');">
                            <input type="hidden" name="csrf" value="<?= esc($csrf) ?>">
                            <input type="hidden" name="action" value="remove_user_pttt">
                            <input type="hidden" name="id_user_pttt" value="<?= (int)$um['id_user_pttt'] ?>">
                            <button class="btn btn-sm btn-outline-danger" type="submit">Xóa</button>
                          </form>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <hr>

        <!-- Addresses + orders (kept minimal) -->
        <div>
          <h6>Địa chỉ</h6>
          <p class="text-muted mb-3">Quản lý địa chỉ giao hàng của bạn. <a href="addresses.php">Xem/Thêm</a></p>

          <h6>Đơn hàng gần đây</h6>
          <p class="text-muted">Xem lịch sử đơn hàng. <a href="orders.php">Tất cả đơn hàng</a></p>
        </div>

      </div>
    </div>
  </div>
</div>

<!-- Modal: add user payment (select from available methods) -->
<div class="modal fade" id="addUserPmtModal" tabindex="-1" aria-labelledby="addUserPmtModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" method="post">
      <input type="hidden" name="csrf" value="<?= esc($csrf) ?>">
      <input type="hidden" name="action" value="add_user_pttt">
      <div class="modal-header">
        <h5 class="modal-title" id="addUserPmtModalLabel">Thêm phương thức thanh toán</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button>
      </div>
      <div class="modal-body">
        <div class="mb-2">
          <label class="form-label">Chọn phương thức</label>
          <select name="id_pttt" class="form-select" required>
            <option value="">-- Chọn --</option>
            <?php foreach($available_methods as $m): ?>
              <option value="<?= (int)$m['id_pttt'] ?>"><?= esc($m['ten']) ?> <?= $m['mo_ta'] ? '- ' . esc($m['mo_ta']) : '' ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="mb-2">
          <label class="form-label">Số tài khoản / Số ví / Số thẻ</label>
          <input name="account_info" class="form-control" required>
          <div class="form-text">Ví dụ: số ví MOMO, số tài khoản ngân hàng. Không lưu CVV.</div>
        </div>

        <div class="mb-2">
          <label class="form-label">Tên chủ (tùy chọn)</label>
          <input name="holder_name" class="form-control">
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Hủy</button>
        <button class="btn btn-primary" type="submit">Lưu</button>
      </div>
    </form>
  </div>
</div>

<footer class="py-3 mt-5" style="background:#111;color:#fff">
  <div class="container d-flex justify-content-between">
    <div>© <?= date('Y') ?> <?= esc(site_name($conn)) ?></div>
    <div>Hotline: 0123 456 789</div>
  </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
