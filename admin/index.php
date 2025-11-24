<?php
// admin/index.php - Admin dashboard (improved + orders & coupons)
session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../inc/helpers.php';

if (!function_exists('esc')) {
    function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('price')) {
    function price($v){ return number_format((float)$v,0,',','.') . ' ₫'; }
}

// guard: must be admin
if (empty($_SESSION['is_admin']) || empty($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

// --- basic stats with safe fallbacks ---
try {
    $countUsers = (int)$conn->query("SELECT COUNT(*) FROM nguoi_dung")->fetchColumn();
} catch (Exception $e) { $countUsers = 0; }

try {
    $countProducts = (int)$conn->query("SELECT COUNT(*) FROM san_pham")->fetchColumn();
} catch (Exception $e) { $countProducts = 0; }

try {
    $lowStock = (int)$conn->query("SELECT COUNT(*) FROM san_pham WHERE so_luong <= 5")->fetchColumn();
} catch (Exception $e) { $lowStock = 0; }

// orders stats (table name assumed: don_hang)
try {
    $countOrders = (int)$conn->query("SELECT COUNT(*) FROM don_hang")->fetchColumn();
    $pendingOrders = (int)$conn->query("SELECT COUNT(*) FROM don_hang WHERE trang_thai IN ('moi','chuaxuly',0)")->fetchColumn();
} catch (Exception $e) {
    $countOrders = 0;
    $pendingOrders = 0;
}

// coupons stats (table name assumed: ma_giam_gia)
try {
    $countCoupons = (int)$conn->query("SELECT COUNT(*) FROM ma_giam_gia")->fetchColumn();
    $activeCoupons = (int)$conn->query("SELECT COUNT(*) FROM ma_giam_gia WHERE trang_thai = 1")->fetchColumn();
} catch (Exception $e) {
    $countCoupons = 0;
    $activeCoupons = 0;
}

// latest users (5)
try {
    $latestUsers = $conn->query("SELECT id_nguoi_dung, ten, email, created_at FROM nguoi_dung ORDER BY created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $latestUsers = []; }

// latest products (5)
try {
    $latestProducts = $conn->query("SELECT id_san_pham, ma_san_pham, ten, so_luong, gia FROM san_pham ORDER BY created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $latestProducts = []; }

// latest orders (5) - adapt columns if your table differs
try {
    $latestOrders = $conn->query("SELECT id_don_hang, ten_khach, email, tong_tien, trang_thai, created_at FROM don_hang ORDER BY created_at DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $latestOrders = []; }

// latest coupons (6) - adapt columns if your table differs
try {
    $latestCoupons = $conn->query("SELECT id_ma, code, gia_tri, so_luong, trang_thai, created_at FROM ma_giam_gia ORDER BY created_at DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $latestCoupons = []; }

?>
<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Admin Dashboard — <?= esc(function_exists('site_name') ? site_name($conn) : 'AE Shop') ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root{
      --accent: #0b7bdc;
      --muted: #6c757d;
      --card-radius: 12px;
      --bg: #f4f7fb;
    }
    html,body{height:100%}
    body{background:var(--bg);font-family:Inter,system-ui,Roboto,Arial;margin:0;color:#222}
    .topbar{background:#fff;padding:14px 20px;border-bottom:1px solid #e9eef8;box-shadow:0 8px 30px rgba(11,38,80,0.03)}
    .sidebar{background:#fff;padding:18px;border-right:1px solid #eef3f8;min-height:calc(100vh - 72px)}
    .main{padding:28px}
    .nav-link{color:#374151;padding:10px 12px;border-radius:8px;margin-bottom:6px}
    .nav-link.active{background:linear-gradient(90deg,var(--bg),#fff);box-shadow:inset 0 0 0 1px rgba(11,123,220,0.04)}
    .card-stat{border-radius:var(--card-radius);border:1px solid rgba(11,38,80,0.04);padding:18px;box-shadow:0 12px 40px rgba(11,38,80,0.04)}
    .stat-number{font-size:1.75rem;font-weight:700;color:var(--accent)}
    .small-muted{color:var(--muted)}
    .list-small .list-group-item{display:flex;justify-content:space-between;align-items:center}
    .badge-status { font-size:12px;padding:6px 8px;border-radius:999px }
    @media (max-width:991px){ .sidebar{display:none} .main{padding:12px} }
  </style>
</head>
<body>
  <div class="topbar d-flex justify-content-between align-items-center">
    <div class="d-flex align-items-center gap-3">
      <div style="width:44px;height:44px;background:var(--accent);color:#fff;border-radius:10px;display:flex;align-items:center;justify-content:center;font-weight:800">AE</div>
      <div>
        <div style="font-weight:800"><?= esc(function_exists('site_name') ? site_name($conn) : 'AE Shop') ?> — Admin</div>
        <div class="small-muted">Bảng điều khiển quản trị</div>
      </div>
    </div>

    <div class="d-flex align-items-center gap-2">
      <div class="text-end me-2 small-muted" style="font-size:13px">
        <div>Xin chào, <strong><?= esc($_SESSION['user']['ten'] ?? 'Admin') ?></strong></div>
        <div class="small">Phiên: <?= esc($_SESSION['user']['email'] ?? '') ?></div>
      </div>
      <a href="../index.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-house-door"></i> Xem site</a>
      <a href="logout.php" class="btn btn-outline-danger btn-sm"><i class="bi bi-box-arrow-right"></i> Đăng xuất</a>
    </div>
  </div>

  <div class="container-fluid">
    <div class="row g-0">
      <aside class="col-md-2 sidebar">
        <nav class="nav flex-column">
          <a class="nav-link <?= (basename($_SERVER['PHP_SELF'])==='index.php' ? 'active' : '') ?>" href="index.php"><i class="bi bi-speedometer2 me-2"></i> Dashboard</a>
          <a class="nav-link" href="users.php"><i class="bi bi-people me-2"></i> Người dùng</a>
          <a class="nav-link" href="products.php"><i class="bi bi-box-seam me-2"></i> Sản phẩm</a>
          <a class="nav-link" href="inventory_log.php"><i class="bi bi-clock-history me-2"></i> Lịch sử tồn kho</a>
          <a class="nav-link" href="orders.php"><i class="bi bi-receipt me-2"></i> Đơn hàng</a>
          <a class="nav-link" href="coupons.php"><i class="bi bi-percent me-2"></i> Mã giảm giá</a>
          <hr>
          <div class="small-muted mt-2">Phiên quản trị</div>
        </nav>
      </aside>

      <main class="col-md-10 main">
        <div class="row g-3 mb-4">
          <div class="col-md-3">
            <div class="card-stat">
              <div class="small-muted">Người dùng</div>
              <div class="stat-number"><?= $countUsers ?></div>
              <div class="mt-2"><a href="users.php" class="small">Quản lý người dùng →</a></div>
            </div>
          </div>

          <div class="col-md-3">
            <div class="card-stat">
              <div class="small-muted">Sản phẩm</div>
              <div class="stat-number"><?= $countProducts ?></div>
              <div class="mt-2"><a href="products.php" class="small">Quản lý sản phẩm →</a></div>
            </div>
          </div>

          <div class="col-md-3">
            <div class="card-stat">
              <div class="small-muted">Đơn hàng</div>
              <div class="stat-number"><?= $countOrders ?></div>
              <div class="mt-2"><span class="badge bg-danger badge-status"><?= $pendingOrders ?> đang chờ</span> <a href="orders.php" class="small ms-2">Xem đơn hàng →</a></div>
            </div>
          </div>

          <div class="col-md-3">
            <div class="card-stat">
              <div class="small-muted">Mã giảm giá</div>
              <div class="stat-number"><?= $countCoupons ?></div>
              <div class="mt-2"><span class="badge bg-success badge-status"><?= $activeCoupons ?> kích hoạt</span> <a href="coupons.php" class="small ms-2">Quản lý mã →</a></div>
            </div>
          </div>
        </div>

        <div class="row g-3">
          <div class="col-lg-6">
            <div class="card p-3">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <h5 class="mb-0">Đơn hàng gần đây</h5>
                <a class="small" href="orders.php">Xem tất cả →</a>
              </div>
              <?php if ($latestOrders): ?>
                <div class="list-group list-small">
                  <?php foreach($latestOrders as $o): ?>
                    <div class="list-group-item">
                      <div>
                        <div class="fw-semibold"><?= esc($o['ten_khach'] ?? ('#'.(int)$o['id_don_hang'])) ?></div>
                        <div class="small text-muted"><?= esc($o['email'] ?? '') ?></div>
                      </div>
                      <div class="text-end">
                        <div class="fw-semibold"><?= price($o['tong_tien'] ?? 0) ?></div>
                        <div class="small text-muted"><?= esc($o['created_at']) ?></div>
                        <div class="mt-1">
                          <?php
                            $st = strtolower((string)($o['trang_thai'] ?? ''));
                            if (in_array($st, ['moi','chuaxuly','0','pending','new'])) echo '<span class="badge bg-warning text-dark">Chờ xử lý</span>';
                            elseif (in_array($st, ['hoanthanh','paid','done','1'])) echo '<span class="badge bg-success">Hoàn thành</span>';
                            elseif (in_array($st, ['huy','cancel','canceled'])) echo '<span class="badge bg-danger">Đã huỷ</span>';
                            else echo '<span class="badge bg-secondary"> '.$st.' </span>';
                          ?>
                        </div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <div class="text-muted small">Chưa có đơn hàng gần đây.</div>
              <?php endif; ?>
            </div>
          </div>

          <div class="col-lg-6">
            <div class="card p-3">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <h5 class="mb-0">Mã giảm giá mới</h5>
                <a class="small" href="coupons.php">Quản lý mã →</a>
              </div>

              <?php if ($latestCoupons): ?>
                <div class="list-group list-small">
                  <?php foreach($latestCoupons as $c): ?>
                    <div class="list-group-item">
                      <div>
                        <div class="fw-semibold"><?= esc($c['code'] ?? ('#'.(int)$c['id_ma'])) ?></div>
                        <div class="small text-muted">Giá trị: <?= esc($c['gia_tri']) ?> — Số lượng: <?= (int)($c['so_luong'] ?? 0) ?></div>
                      </div>
                      <div class="text-end">
                        <div class="small text-muted"><?= esc($c['created_at']) ?></div>
                        <div class="mt-1"><?= (int)$c['trang_thai'] ? '<span class="badge bg-success">Hoạt động</span>' : '<span class="badge bg-secondary">Đã khoá</span>' ?></div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <div class="text-muted small">Không có mã giảm giá mới.</div>
              <?php endif; ?>

            </div>
          </div>
        </div>

        <div class="row g-3 mt-4">
          <div class="col-md-6">
            <div class="card p-3">
              <h5>Người dùng mới</h5>
              <div class="list-group mt-2">
                <?php if (count($latestUsers)): foreach($latestUsers as $u): ?>
                  <div class="list-group-item d-flex justify-content-between align-items-start">
                    <div>
                      <div class="fw-semibold"><?= esc($u['ten']) ?></div>
                      <div class="small text-muted"><?= esc($u['email']) ?></div>
                    </div>
                    <div class="small text-muted"><?= esc($u['created_at']) ?></div>
                  </div>
                <?php endforeach; else: ?>
                  <div class="text-muted small">Không có người dùng mới.</div>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <div class="col-md-6">
            <div class="card p-3">
              <h5>Sản phẩm mới</h5>
              <div class="list-group mt-2">
                <?php if (count($latestProducts)): foreach($latestProducts as $p): ?>
                  <div class="list-group-item d-flex justify-content-between align-items-start">
                    <div>
                      <div class="fw-semibold"><?= esc($p['ten']) ?> <small class="text-muted">#<?= (int)$p['id_san_pham'] ?></small></div>
                      <div class="small text-muted"><?= number_format((float)$p['gia'],0,',','.') ?> ₫ — Tồn: <?= (int)$p['so_luong'] ?></div>
                    </div>
                    <div><a class="btn btn-sm btn-outline-primary" href="products.php?edit=<?= (int)$p['id_san_pham'] ?>">Sửa</a></div>
                  </div>
                <?php endforeach; else: ?>
                  <div class="text-muted small">Không có sản phẩm mới.</div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>

        <div class="mt-4">
          <div class="d-flex gap-2">
            <a class="btn btn-primary" href="products.php?new=1"><i class="bi bi-plus-lg me-1"></i> Thêm sản phẩm</a>
            <a class="btn btn-outline-secondary" href="orders.php"><i class="bi bi-receipt me-1"></i> Quản lý đơn hàng</a>
            <a class="btn btn-outline-secondary" href="coupons.php"><i class="bi bi-percent me-1"></i> Tạo mã giảm giá</a>
            <a class="btn btn-outline-secondary" href="inventory_log.php"><i class="bi bi-clock-history me-1"></i> Nhập kho</a>
          </div>
        </div>

      </main>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
