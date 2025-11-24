<?php
// invoice.php - Xuất hoá đơn đơn hàng (giao diện đẹp, responsive, in-friendly)
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inc/helpers.php';

// helper
function e($v){ return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

/* ensure user logged in */
if (!isset($_SESSION['user'])) {
    header("Location: login.php?back=" . urlencode(basename(__FILE__) . '?' . $_SERVER['QUERY_STRING']));
    exit;
}

$user = $_SESSION['user'];
$user_id = $user['id_nguoi_dung'] ?? $user['id'] ?? $user['user_id'] ?? 0;

/* validate id */
$order_id = (int)($_GET['id'] ?? 0);
if ($order_id <= 0) {
    http_response_code(400);
    die("ID đơn không hợp lệ");
}

/* fetch order and check ownership */
$stmt = $conn->prepare("SELECT * FROM don_hang WHERE id_don_hang = :id AND id_nguoi_dung = :uid LIMIT 1");
$stmt->execute([':id' => $order_id, ':uid' => $user_id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$order) {
    http_response_code(404);
    die("Không tìm thấy đơn hàng hoặc bạn không có quyền xem hoá đơn này.");
}

/* fetch items */
$details = $conn->prepare("
    SELECT ctdh.*, sp.ten AS ten_san_pham
    FROM chi_tiet_don_hang ctdh
    LEFT JOIN san_pham sp ON ctdh.id_san_pham = sp.id_san_pham
    WHERE ctdh.id_don_hang = :id
");
$details->execute([':id' => $order_id]);
$items = $details->fetchAll(PDO::FETCH_ASSOC);

/* image helper (same logic như order_view) */
function normalize_image_path($p) {
    $p = trim((string)$p);
    if ($p === '') return 'images/placeholder.jpg';
    $p = html_entity_decode($p, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (preg_match('#^https?://#i', $p)) return $p;
    if (strpos($p, '/mnt/data/') === 0 || strpos($p, '/var/www') === 0 || stripos($p, 'C:\\') === 0) {
        // path tuyệt đối (dev) - trình duyệt có thể không truy cập trực tiếp. Nếu deploy, hãy chỉnh lại.
        return $p;
    }
    return $p;
}
function get_product_image_path($conn, $product_id) {
    $fallback = 'images/placeholder.jpg';
    if (empty($product_id)) return $fallback;
    try {
        $q = $conn->prepare("SELECT duong_dan FROM anh_san_pham WHERE id_san_pham = :id AND la_anh_chinh = 1 LIMIT 1");
        $q->execute([':id' => $product_id]);
        $p = $q->fetchColumn();
        if ($p) return normalize_image_path($p);
        $q2 = $conn->prepare("SELECT duong_dan FROM anh_san_pham WHERE id_san_pham = :id ORDER BY thu_tu ASC, id_anh ASC LIMIT 1");
        $q2->execute([':id' => $product_id]);
        $p2 = $q2->fetchColumn();
        if ($p2) return normalize_image_path($p2);
    } catch (Exception $e) {}
    return $fallback;
}

/* totals (try fields, fallback compute from items) */
$total = (float)($order['tong_tien'] ?? 0);
$shipping = (float)($order['phi_van_chuyen'] ?? 0);
$discount = (float)($order['giam_gia'] ?? 0);

if ($total <= 0) {
    $calc = 0.0;
    foreach ($items as $it) {
        $calc += (float)($it['thanh_tien'] ?? ($it['gia'] * ($it['so_luong'] ?? 1)));
    }
    $total = $calc + $shipping - $discount;
}

/* company info (tùy bạn chỉnh) */
/* Using uploaded logo path from session history - convert to public URL on your server if needed */
$company = [
    'name' => site_name($conn) ?: 'Shop của tôi',
    'address' => 'Địa chỉ cửa hàng, Thành phố',
    'phone' => '0123 456 789',
    'email' => 'info@example.com',
    // logo từ file upload (local path). Nếu môi trường production không serve được /mnt/data,
    // hãy đổi giá trị này thành đường dẫn public phù hợp (ví dụ: images/logo.png).
    'logo' => '/mnt/data/ea05578d-36a6-42fb-814d-5d84497be9be.png'
];

/* customer info */
$customer_name = $order['ten_khach'] ?? $order['ten'] ?? $user['ten'] ?? '';
$customer_email = $order['email'] ?? $user['email'] ?? '';
$customer_phone = $order['so_dien_thoai'] ?? $order['dien_thoai'] ?? '';
$customer_address = $order['dia_chi'] ?? $order['diachi'] ?? '';

/* format date */
$created_at = $order['ngay_dat'] ?? $order['created_at'] ?? null;
if ($created_at) {
    try {
        $dt = new DateTime($created_at);
        $created_at_fmt = $dt->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        $created_at_fmt = e($created_at);
    }
} else $created_at_fmt = '';

/* invoice number */
$invoice_no = e($order['ma_don'] ?? ('INV' . str_pad($order_id, 6, '0', STR_PAD_LEFT)));
?>
<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <title>Hoá đơn <?= $invoice_no ?> — <?= e($company['name']) ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    :root{
      --muted: #6c757d;
      --accent: #0b7bdc;
      --card-bg: #fff;
      --surface: #f8fafc;
    }
    body{
      font-family: Inter, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial;
      background: var(--surface);
      color: #111827;
      padding: 20px;
    }
    .invoice-wrap{
      max-width: 980px;
      margin: 0 auto;
    }
    .invoice-card{
      background: var(--card-bg);
      border-radius: 12px;
      box-shadow: 0 10px 30px rgba(15,23,42,0.06);
      overflow: hidden;
      border: 1px solid rgba(15,23,42,0.04);
    }
    .invoice-head{
      display:flex;
      gap:20px;
      align-items:center;
      padding: 22px;
      border-bottom:1px solid rgba(15,23,42,0.04);
      background: linear-gradient(180deg,#fff,#fbfdff);
    }
    .company-logo{
      width:140px;
      height:70px;
      object-fit:contain;
      background: #fff;
      padding:8px;
      border-radius:8px;
      border:1px solid rgba(0,0,0,0.03);
    }
    .invoice-meta{
      margin-left:auto;
      text-align:right;
    }
    .invoice-body{
      padding: 22px;
    }
    .customer-box, .company-box{
      background: linear-gradient(90deg,#ffffff,#fbfdff);
      padding:14px;
      border-radius:10px;
      border:1px solid rgba(15,23,42,0.03);
    }
    .table thead th{
      border-bottom: 2px solid rgba(15,23,42,0.06);
      background: transparent;
    }
    .product-img{
      width:56px;height:56px;object-fit:cover;border-radius:8px;border:1px solid rgba(15,23,42,0.04);
    }
    .totals-card{
      background: linear-gradient(180deg,#fff,#fbfdff);
      padding:16px;
      border-radius:10px;
      border:1px solid rgba(15,23,42,0.04);
    }
    .small-muted{ color: var(--muted); font-size: .92rem; }
    .print-hide{ display:inline-block; }
    @media (max-width:767px){
      .invoice-head{ flex-direction:column; align-items:flex-start; gap:12px; }
      .invoice-meta{ margin-left:0; text-align:left; width:100%;}
    }
    /* print */
    @media print {
      body{ background: #fff; padding:0; }
      .print-hide{ display:none !important; }
      .invoice-wrap{ max-width:100%; padding:0; }
      .invoice-card{ box-shadow:none; border: none; }
      a { color: #000; text-decoration:none; }
    }
  </style>
</head>
<body>
  <div class="invoice-wrap">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <div>
        <a href="orders.php" class="btn btn-link">&larr; Quay lại đơn hàng</a>
      </div>
      <div class="print-hide">
        <button class="btn btn-outline-secondary me-2" onclick="window.print()">In / Xuất PDF</button>
        <a class="btn btn-primary" href="order_view.php?id=<?= $order_id ?>">Quay về chi tiết đơn</a>
      </div>
    </div>

    <div class="invoice-card">
      <div class="invoice-head">
        <div style="display:flex;gap:16px;align-items:center;flex:1">
          <?php if (!empty($company['logo'])): ?>
            <img src="<?= e($company['logo']) ?>" alt="logo" class="company-logo" onerror="this.style.display='none'">
          <?php endif; ?>
          <div>
            <h4 class="mb-0"><?= e($company['name']) ?></h4>
            <div class="small-muted"><?= e($company['address']) ?></div>
            <div class="small-muted">Điện thoại: <?= e($company['phone']) ?> — Email: <?= e($company['email']) ?></div>
          </div>
        </div>

        <div class="invoice-meta">
          <div style="font-weight:700; font-size:1.05rem; color:var(--accent)">HÓA ĐƠN</div>
          <div class="small-muted">Số: <strong><?= $invoice_no ?></strong></div>
          <div class="small-muted">Ngày: <?= e($created_at_fmt) ?></div>
          <div class="mt-2 small-muted">Trạng thái: <strong><?= e($order['trang_thai'] ?? '') ?></strong></div>
        </div>
      </div>

      <div class="invoice-body">
        <div class="row g-3 mb-3">
          <div class="col-md-6">
            <div class="customer-box">
              <div class="d-flex justify-content-between align-items-start">
                <div>
                  <div class="small-muted">Người mua</div>
                  <div style="font-weight:700; font-size:1.02rem;"><?= e($customer_name) ?></div>
                  <div class="small-muted"><?= e($customer_email) ?></div>
                  <div class="small-muted"><?= e($customer_phone) ?></div>
                  <div class="small-muted"><?= nl2br(e($customer_address)) ?></div>
                </div>
              </div>
            </div>
          </div>
          <div class="col-md-6">
            <div class="company-box">
              <div class="small-muted">Giao / Thanh toán cho</div>
              <div style="font-weight:700; font-size:1.02rem;"><?= e($company['name']) ?></div>
              <div class="small-muted"><?= e($company['address']) ?></div>
              <div class="small-muted">Điện thoại: <?= e($company['phone']) ?></div>
              <div class="small-muted mt-2">Phương thức: <strong><?= e($order['phuong_thuc_thanh_toan'] ?? $order['phuong_thuc'] ?? 'COD') ?></strong></div>
            </div>
          </div>
        </div>

        <div class="table-responsive mb-3">
          <table class="table align-middle">
            <thead>
              <tr>
                <th style="width:64px"></th>
                <th>Sản phẩm</th>
                <th class="text-end" style="width:140px">Đơn giá</th>
                <th class="text-center" style="width:100px">Số lượng</th>
                <th class="text-end" style="width:160px">Thành tiền</th>
              </tr>
            </thead>
            <tbody>
              <?php
              $i = 0;
              $subtotal = 0.0;
              foreach ($items as $it):
                  $i++;
                  $pname = $it['ten_san_pham'] ?? $it['ten'] ?? 'Sản phẩm';
                  $price = (float)($it['gia'] ?? 0);
                  $qty = (int)($it['so_luong'] ?? $it['so'] ?? 1);
                  $line = (float)($it['thanh_tien'] ?? $price * $qty);
                  $subtotal += $line;
                  $img = get_product_image_path($conn, $it['id_san_pham'] ?? null);
              ?>
              <tr>
                <td>
                  <img src="<?= e($img) ?>" onerror="this.src='images/placeholder.jpg'" alt="" class="product-img">
                </td>
                <td>
                  <div style="font-weight:600"><?= e($pname) ?></div>
                  <?php if (!empty($it['ten_mau']) || !empty($it['size'])): ?>
                    <div class="small-muted"><?= e(implode(' - ', array_filter([$it['ten_mau'] ?? '', $it['size'] ?? '']))) ?></div>
                  <?php endif; ?>
                </td>
                <td class="text-end"><?= number_format($price,0,',','.') ?> ₫</td>
                <td class="text-center"><?= $qty ?></td>
                <td class="text-end"><?= number_format($line,0,',','.') ?> ₫</td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="row align-items-center">
          <div class="col-md-6">
            <?php if (!empty($order['ghi_chu'])): ?>
            <div class="mb-3">
              <strong>Ghi chú:</strong>
              <div class="small-muted"><?= nl2br(e($order['ghi_chu'])) ?></div>
            </div>
            <?php endif; ?>

            <div class="small-muted">
              Mã đơn: <strong><?= $invoice_no ?></strong><br>
              Ngày đặt: <strong><?= e($created_at_fmt) ?></strong>
            </div>
          </div>

          <div class="col-md-6">
            <div class="totals-card ms-auto" style="max-width:320px;">
              <div class="d-flex justify-content-between small-muted"><div>Tạm tính</div><div><?= number_format($subtotal,0,',','.') ?> ₫</div></div>
              <div class="d-flex justify-content-between small-muted mt-2"><div>Phí vận chuyển</div><div><?= $shipping ? number_format($shipping,0,',','.') . ' ₫' : '<span class="text-success">Miễn phí</span>' ?></div></div>
              <div class="d-flex justify-content-between small-muted mt-2"><div>Giảm giá</div><div><?= $discount ? '- ' . number_format($discount,0,',','.') . ' ₫' : '-' ?></div></div>
              <hr>
              <div class="d-flex justify-content-between" style="font-weight:800; font-size:1.15rem;"><div>Tổng thanh toán</div><div><?= number_format($total,0,',','.') ?> ₫</div></div>
              <div class="mt-2 small-muted text-end">Đã bao gồm VAT (nếu có)</div>
            </div>
          </div>
        </div>

        <div class="mt-4 text-center">
          <div style="font-weight:700">Cảm ơn bạn đã mua hàng!</div>
          <div class="small-muted">Mọi thắc mắc vui lòng liên hệ <?= e($company['phone']) ?> — <?= e($company['email']) ?></div>
        </div>

      </div>
    </div>
  </div>
</body>
</html>
