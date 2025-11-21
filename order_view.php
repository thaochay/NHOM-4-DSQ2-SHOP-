<?php
// order_view.php - Chi tiết đơn hàng (giao diện đẹp & responsive)
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inc/helpers.php';

// ensure CSRF token
if (!isset($_SESSION['csrf'])) {
    try {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    } catch (Exception $e) {
        // fallback
        $_SESSION['csrf'] = bin2hex(openssl_random_pseudo_bytes(16));
    }
}

if (!isset($_SESSION['user'])) {
    header("Location: login.php?back=" . urlencode(basename(__FILE__) . '?' . $_SERVER['QUERY_STRING']));
    exit;
}

$user = $_SESSION['user'];
$user_id = $user['id_nguoi_dung'] ?? $user['id'] ?? $user['user_id'] ?? 0;

$order_id = (int)($_GET['id'] ?? 0);
if ($order_id <= 0) {
    http_response_code(400);
    die("ID đơn không hợp lệ");
}

/* Lấy đơn hàng và kiểm tra quyền */
$stmt = $conn->prepare("SELECT * FROM don_hang WHERE id_don_hang = :id AND id_nguoi_dung = :uid LIMIT 1");
$stmt->execute([':id' => $order_id, ':uid' => $user_id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    http_response_code(404);
    die("Không tìm thấy đơn hàng hoặc bạn không có quyền xem đơn này.");
}

/* Lấy chi tiết đơn */
$details = $conn->prepare("
    SELECT ctdh.*, sp.ten as ten_san_pham
    FROM chi_tiet_don_hang ctdh
    LEFT JOIN san_pham sp ON ctdh.id_san_pham = sp.id_san_pham
    WHERE ctdh.id_don_hang = :id
");
$details->execute([':id' => $order_id]);
$items = $details->fetchAll(PDO::FETCH_ASSOC);

/* Helper safe output */
function e($v){ return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

/* map trạng thái -> badge */
function status_badge($s){
    $s = strtolower((string)$s);
    $map = [
        'moi'=>'Chờ xử lý', 'new'=>'Chờ xử lý', 'processing'=>'Đang xử lý', 'dang_xu_ly'=>'Đang xử lý',
        'shipped'=>'Đã giao', 'delivered'=>'Đã giao', 'completed'=>'Hoàn tất',
        'cancel'=>'Đã huỷ', 'huy'=>'Đã huỷ'
    ];
    $label = $map[$s] ?? ucfirst($s);
    $cls = 'secondary';
    if (in_array($s, ['moi','new','processing','dang_xu_ly'])) $cls = 'warning';
    if (in_array($s, ['shipped','delivered','completed'])) $cls = 'success';
    if (in_array($s, ['cancel','huy'])) $cls = 'danger';
    return "<span class=\"badge bg-{$cls}\">" . e($label) . "</span>";
}

/* simple totals if DB columns differ */
$total = $order['tong_tien'] ?? 0;
$shipping = $order['phi_van_chuyen'] ?? 0;
$discount = $order['giam_gia'] ?? 0;
$created_at = $order['ngay_dat'] ?? $order['created_at'] ?? null;

/* banner image (developer provided local upload) */
/* Path from conversation assets: /mnt/data/5f049ff1-f99e-4f23-bfd2-539082acbdf8.png */
$hero_image = '/mnt/data/5f049ff1-f99e-4f23-bfd2-539082acbdf8.png';
?>
<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <title>Chi tiết đơn hàng #<?= e($order['ma_don'] ?? $order_id) ?> — <?= e(site_name($conn)) ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    :root{
      --accent: #0d6efd;
      --muted: #6c757d;
      --card-radius: 14px;
    }
    body{ background:#f5f8fb; color:#222; font-family:system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial; padding:24px 12px; }
    .page-wrap{ max-width:1100px; margin:0 auto; }
    .hero {
      border-radius: var(--card-radius);
      overflow:hidden;
      display:flex;
      gap:16px;
      align-items:center;
      padding:18px;
      background: linear-gradient(90deg, rgba(13,110,253,0.03), rgba(13,110,253,0.02));
      border:1px solid rgba(13,110,253,0.04);
    }
    .hero img{ width:120px; height:120px; object-fit:cover; border-radius:10px; background:#fff; }
    .order-meta { display:flex; gap:12px; align-items:flex-start; flex-wrap:wrap; }
    .meta-item { background:#fff; padding:10px 12px; border-radius:10px; border:1px solid #eef4ff; box-shadow: 0 6px 18px rgba(11,38,80,0.02); }
    .card-panel { border-radius:14px; background:#fff; padding:18px; box-shadow: 0 16px 40px rgba(11,38,80,0.04); }
    .table-products img{ width:64px; height:64px; object-fit:cover; border-radius:8px; }
    .status-timeline { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-top:12px; }
    .timeline-step { padding:8px 12px; border-radius:999px; background:#f8fbff; border:1px solid #eaf2ff; font-size:14px; color:var(--muted); }
    .print-hide { display:inline-block; }
    @media print {
      body{ background:#fff; padding:0; }
      .print-hide{ display:none !important; }
    }
  </style>
</head>
<body>

<div class="page-wrap">

  <div class="d-flex justify-content-between align-items-start mb-3">
    <div class="d-flex gap-2 align-items-center">
      <a href="orders.php" class="btn btn-link">&larr; Quay lại</a>
      <h4 class="mb-0">Chi tiết đơn hàng</h4>
    </div>
    <div class="print-hide">
      <button onclick="window.print()" class="btn btn-outline-secondary me-2"><i class="bi bi-printer"></i> In đơn</button>
      <a href="orders.php" class="btn btn-outline-primary">Danh sách đơn hàng</a>
    </div>
  </div>

  <div class="hero mb-4">
    <img src="<?= e($hero_image) ?>" alt="Order hero" onerror="this.style.display='none'">
    <div style="flex:1">
      <div class="d-flex justify-content-between align-items-start">
        <div>
          <h5 class="mb-1">Mã đơn: <strong>#<?= e($order['ma_don'] ?? $order_id) ?></strong></h5>
          <div class="text-muted small">Ngày đặt: <?= e($created_at) ?></div>
        </div>
        <div class="text-end">
          <div style="font-size:18px"><?= status_badge($order['trang_thai'] ?? '') ?></div>
          <div class="text-muted small mt-2">Tổng: <strong style="font-size:18px"><?= number_format($total,0,',','.') ?> ₫</strong></div>
        </div>
      </div>

      <div class="status-timeline mt-3">
        <div class="timeline-step">Đã đặt</div>
        <div class="timeline-step">Đang xử lý</div>
        <div class="timeline-step">Đang giao</div>
        <div class="timeline-step">Hoàn tất</div>
      </div>
    </div>
  </div>

  <div class="row g-4">
    <div class="col-lg-8">
      <div class="card-panel mb-3">
        <h5 class="mb-3">Sản phẩm (<?= count($items) ?>)</h5>

        <div class="table-responsive">
          <table class="table table-borderless align-middle table-products">
            <thead>
              <tr class="text-muted small">
                <th style="width:72px"></th>
                <th>Sản phẩm</th>
                <th class="text-end">Giá</th>
                <th class="text-center">Số lượng</th>
                <th class="text-end">Thành tiền</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($items as $it): 
                $pname = $it['ten_san_pham'] ?? $it['ten'] ?? 'Sản phẩm';
                $price = (float)($it['gia'] ?? 0);
                $qty   = (int)($it['so_luong'] ?? $it['so'] ?? 1);
                $subtotal = (float)($it['thanh_tien'] ?? $price * $qty);
                // try to fetch image for product if available via anh_san_pham table (non-blocking)
                $img = 'images/placeholder.jpg';
                if (!empty($it['id_san_pham'])) {
                  try {
                    $s2 = $conn->prepare("SELECT duong_dan FROM anh_san_pham WHERE id_san_pham = :id AND la_anh_chinh = 1 LIMIT 1");
                    $s2->execute([':id'=>$it['id_san_pham']]);
                    $pd = $s2->fetchColumn();
                    if ($pd) $img = $pd;
                  } catch(Exception $e){}
                }
              ?>
              <tr>
                <td><img src="<?= e($img) ?>" alt="<?= e($pname) ?>" onerror="this.src='images/placeholder.jpg'"></td>
                <td>
                  <div class="fw-semibold"><?= e($pname) ?></div>
                  <?php if (!empty($it['id_chi_tiet'])): ?><div class="text-muted small">Chi tiết: <?= e($it['id_chi_tiet']) ?></div><?php endif; ?>
                </td>
                <td class="text-end"><?= number_format($price,0,',','.') ?> ₫</td>
                <td class="text-center"><?= $qty ?></td>
                <td class="text-end"><?= number_format($subtotal,0,',','.') ?> ₫</td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="mt-3 d-flex justify-content-between">
          <div class="text-muted small">Ghi chú:</div>
          <div class="text-end small"><?= e($order['ghi_chu'] ?? '-') ?></div>
        </div>
      </div>

      <div class="card-panel">
        <h5 class="mb-3">Thông tin thanh toán & giao hàng</h5>
        <div class="row">
          <div class="col-md-6 mb-3">
            <div class="small text-muted">Người đặt</div>
            <div class="fw-semibold"><?= e($order['ten_khach'] ?? $order['ten'] ?? $_SESSION['user']['ten'] ?? '') ?></div>
            <div class="small text-muted"><?= e($order['email'] ?? $_SESSION['user']['email'] ?? '') ?></div>
            <div class="small text-muted"><?= e($order['so_dien_thoai'] ?? $order['dien_thoai'] ?? '') ?></div>
          </div>

          <div class="col-md-6 mb-3">
            <div class="small text-muted">Địa chỉ giao hàng</div>
            <div class="fw-semibold"><?= nl2br(e($order['dia_chi'] ?? $order['diachi'] ?? $order['ghi_chu'] ?? '-')) ?></div>
          </div>

          <div class="col-md-6 mb-3">
            <div class="small text-muted">Phương thức thanh toán</div>
            <div class="fw-semibold"><?= e($order['phuong_thuc_thanh_toan'] ?? $order['phuong_thuc'] ?? 'COD') ?></div>
          </div>

          <div class="col-md-6 mb-3">
            <div class="small text-muted">Trạng thái đơn</div>
            <div class="fw-semibold"><?= status_badge($order['trang_thai'] ?? '') ?></div>
          </div>
        </div>
      </div>

    </div>

    <div class="col-lg-4">
      <div class="card-panel">
        <h5 class="mb-3">Tóm tắt thanh toán</h5>
        <div class="d-flex justify-content-between mb-2"><div class="text-muted">Tạm tính</div><div><?= number_format(array_reduce($items, function($s,$it){ return $s + (float)($it['thanh_tien'] ?? ($it['gia'] * ($it['so_luong'] ?? 1))); }, 0),0,',','.') ?> ₫</div></div>
        <div class="d-flex justify-content-between mb-2"><div class="text-muted">Phí vận chuyển</div><div><?= $shipping ? number_format($shipping,0,',','.') . ' ₫' : '<strong>Miễn phí</strong>' ?></div></div>
        <div class="d-flex justify-content-between mb-2"><div class="text-muted">Giảm giá</div><div><?= $discount ? '-' . number_format($discount,0,',','.') . ' ₫' : '-' ?></div></div>
        <hr>
        <div class="d-flex justify-content-between align-items-center"><div class="fw-bold">Tổng thanh toán</div><div class="h5 text-primary fw-bold"><?= number_format($total,0,',','.') ?> ₫</div></div>

        <div class="mt-3">
          <!-- FORM: Mua lại (reorder) -->
          <form method="post" action="checkout.php" class="d-grid mb-2 print-hide">
            <input type="hidden" name="action" value="reorder">
            <input type="hidden" name="order_id" value="<?= $order_id ?>">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf']) ?>">
            <button class="btn btn-outline-primary" type="submit"><i class="bi bi-arrow-clockwise"></i> Mua lại</button>
          </form>

          <!-- FORM: Thanh toán đơn hiện tại -->
          <form method="post" action="checkout.php" class="d-grid print-hide">
            <input type="hidden" name="action" value="pay_order">
            <input type="hidden" name="order_id" value="<?= $order_id ?>">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf']) ?>">
            <button class="btn btn-primary" type="submit"><i class="bi bi-credit-card"></i> Thanh toán đơn này</button>
          </form>

          <!-- Fallback links -->
          <a href="invoice.php?id=<?= $order_id ?>" class="btn btn-outline-secondary w-100 mt-2 print-hide">Xem hoá đơn</a>
          <a href="orders.php" class="btn btn-link w-100 mt-1 print-hide">Quay về danh sách đơn</a>
        </div>
      </div>

      <div class="card-panel mt-3 text-muted small">
        <div><strong>Hỗ trợ</strong></div>
        <div style="margin-top:8px">Nếu cần hỗ trợ về đơn hàng, liên hệ: <br><a href="tel:0123456789">0123 456 789</a> — <a href="mailto:info@example.com">info@example.com</a></div>
      </div>
    </div>
  </div>

</div>

<!-- Bootstrap icons & script -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
