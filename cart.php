<?php
// cart.php - quản lý giỏ hàng (UI đẹp) + header/menu ở trên
// Yêu cầu: db.php (PDO $conn) và inc/helpers.php (esc(), price(), site_name())
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inc/helpers.php';

// migrate helper (giữ tương thích cũ)
function cart_migrate_if_needed() {
    if (empty($_SESSION['cart']) || !is_array($_SESSION['cart'])) return;
    foreach ($_SESSION['cart'] as $key => $item) {
        if ((isset($item['ten']) || isset($item['gia'])) && (!isset($item['name']) || !isset($item['price']))) {
            $name = $item['ten'] ?? ($item['name'] ?? 'Sản phẩm');
            $price = $item['gia'] ?? ($item['price'] ?? 0);
            $img = $item['img'] ?? ($item['hinh'] ?? null);
            $qty = isset($item['qty']) ? (int)$item['qty'] : (isset($item['so_luong']) ? (int)$item['so_luong'] : 1);
            $_SESSION['cart'][$key] = [
                'id' => $item['id'] ?? $key,
                'name' => $name,
                'price' => (float)$price,
                'qty' => max(1, $qty),
                'img' => $img,
                'ten' => $name,
                'gia' => (float)$price
            ];
        }
    }
}
cart_migrate_if_needed();

// helper recalc totals
function recalc_cart_data() {
    $cart = $_SESSION['cart'] ?? [];
    $subtotal = 0.0;
    $total_qty = 0;
    foreach ($cart as $it) {
        $price = isset($it['price']) ? (float)$it['price'] : (isset($it['gia']) ? (float)$it['gia'] : 0.0);
        $qty = isset($it['qty']) ? (int)$it['qty'] : 1;
        $subtotal += $price * $qty;
        $total_qty += $qty;
    }
    $shipping = ($subtotal >= 1000000 || $subtotal == 0) ? 0 : 30000;
    $discount = 0.0;
    $total = max(0, $subtotal - $discount + $shipping);
    return [
        'subtotal' => $subtotal,
        'subtotal_fmt' => price($subtotal),
        'shipping' => $shipping,
        'shipping_fmt' => $shipping == 0 ? 'Miễn phí' : price($shipping),
        'discount' => $discount,
        'discount_fmt' => $discount > 0 ? price($discount) : '-',
        'total' => $total,
        'total_fmt' => price($total),
        'items_count' => $total_qty,
        'items' => $_SESSION['cart'] ?? []
    ];
}

// detect AJAX
function is_ajax() {
    return (!empty($_POST['ajax']) && $_POST['ajax'] == '1')
        || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
}

$action = $_POST['action'] ?? $_GET['action'] ?? null;

// --- Actions: add / remove / update / clear ---
if ($action === 'add') {
    $id = (int)($_POST['id'] ?? 0);
    $qty = max(1, (int)($_POST['qty'] ?? 1));
    $back = $_POST['back'] ?? $_SERVER['HTTP_REFERER'] ?? 'index.php';
    if ($id <= 0) {
        if (is_ajax()) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['success'=>false,'message'=>'ID sản phẩm không hợp lệ']); exit; }
        header('Location: ' . $back); exit;
    }
    $p = null;
    try {
        $stmt = $conn->prepare("SELECT id_san_pham, ten, gia FROM san_pham WHERE id_san_pham = :id LIMIT 1");
        $stmt->execute([':id'=>$id]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $p = null; }
    if ($p) {
        try {
            $imgStmt = $conn->prepare("SELECT duong_dan FROM anh_san_pham WHERE id_san_pham = :id AND la_anh_chinh = 1 LIMIT 1");
            $imgStmt->execute([':id'=>$id]);
            $imgp = $imgStmt->fetchColumn() ?: 'images/placeholder.jpg';
        } catch (Exception $e) { $imgp = 'images/placeholder.jpg'; }
        $name = $p['ten'];
        $price = (float)$p['gia'];
    } else {
        $name = 'Sản phẩm #' . $id;
        $price = 0.0;
        $imgp = 'images/placeholder.jpg';
    }
    if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) $_SESSION['cart'] = [];
    if (isset($_SESSION['cart'][$id])) { $_SESSION['cart'][$id]['qty'] += $qty; }
    else {
        $_SESSION['cart'][$id] = [
            'id' => $id,
            'name' => $name,
            'price' => (float)$price,
            'qty' => $qty,
            'img' => $imgp,
            'ten' => $name,
            'gia' => (float)$price
        ];
    }
    if (is_ajax()) { header('Content-Type: application/json; charset=utf-8'); $data = recalc_cart_data(); echo json_encode(['success'=>true,'message'=>'Đã thêm vào giỏ','cart'=>$data]); exit; }
    header('Location: ' . $back); exit;
}

if ($action === 'remove') {
    $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    if ($id > 0 && isset($_SESSION['cart'][$id])) unset($_SESSION['cart'][$id]);
    if (is_ajax()) { header('Content-Type: application/json; charset=utf-8'); $data = recalc_cart_data(); echo json_encode(['success'=>true,'cart'=>$data]); exit; }
    header('Location: cart.php'); exit;
}

if ($action === 'update') {
    if (!empty($_POST['qty']) && is_array($_POST['qty'])) {
        foreach ($_POST['qty'] as $k => $v) {
            $id = (int)$k;
            $q = max(0, (int)$v);
            if ($q <= 0) { if (isset($_SESSION['cart'][$id])) unset($_SESSION['cart'][$id]); }
            else { if (isset($_SESSION['cart'][$id])) $_SESSION['cart'][$id]['qty'] = $q; }
        }
    }
    if (is_ajax()) { header('Content-Type: application/json; charset=utf-8'); $data = recalc_cart_data(); echo json_encode(['success'=>true,'cart'=>$data]); exit; }
    header('Location: cart.php'); exit;
}

if ($action === 'clear') {
    unset($_SESSION['cart']);
    if (is_ajax()) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['success'=>true,'cart'=>recalc_cart_data()]); exit; }
    header('Location: cart.php'); exit;
}

// --- Render cart page (enhanced UI) ---
$cart = $_SESSION['cart'] ?? [];
$tot = recalc_cart_data();
$logged = !empty($_SESSION['user']);

// create small helper for active link
function is_active($file) {
    return basename($_SERVER['PHP_SELF']) === $file ? 'active' : '';
}
?>
<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <title>Giỏ hàng — <?= esc(site_name($conn)) ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root { --primary:#0d6efd; --muted:#6c757d; --brand-bg:#0b1220; --border:#eef3f8; }
    body { background:#f5f7fb; color:#222; font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial; }

    /* Compact header */
    .header { border-bottom:1px solid var(--border); background:#fff; }
    .hdr-inner { max-width:1200px; margin:0 auto; padding:10px 16px; display:flex; gap:12px; align-items:center; justify-content:space-between; }
    .brand { display:flex; gap:10px; align-items:center; text-decoration:none; color:inherit; }
    .brand-circle { width:52px; height:52px; border-radius:50%; background:var(--brand-bg); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:18px; }
    .brand-title { font-weight:700; margin:0; font-size:16px; }
    .brand-sub { margin:0; font-size:12px; color:var(--muted); }

    .nav-short { display:flex; gap:8px; align-items:center; }
    .nav-short a { color:#444; padding:6px 8px; border-radius:6px; text-decoration:none; font-size:15px;}
    .nav-short a.active, .nav-short a:hover { color:var(--primary); font-weight:600; }

    .right-controls { display:flex; gap:10px; align-items:center; }

    /* cart UI */
    .cart-wrap { max-width:1100px; margin:36px auto; }
    .card-cart { border-radius:14px; box-shadow:0 10px 30px rgba(14,30,60,0.06); overflow:hidden; display:flex; gap:0; }
    .cart-left { padding:20px; background:#fff; min-height:260px; flex:1; }
    .cart-right { background:linear-gradient(180deg,#fff,#f8fbff); padding:20px; min-width:320px; width:320px; }
    .product-thumb { width:84px; height:84px; object-fit:cover; border-radius:8px; }
    .muted-small { color:#6c757d; font-size:.95rem; }
    .qty-input { width:110px; }
    .empty-hero { padding:28px; border-radius:12px; border:1px dashed #e6eef9; background:linear-gradient(90deg,#fbfcff,#ffffff); text-align:center; }
    .btn-login { background: linear-gradient(90deg,#0d6efd,#0667e6); color:#fff; border:0; }
    .mini-cta { border-radius:10px; border:1px solid #eaf0ff; padding:12px; background:#fff; }

    @media (max-width:991px){ .cart-right{ position:static; width:100%; } .cart-left{ padding:12px; } .cart-wrap{ margin:18px 12px; } .nav-short{ display:none; } .brand-sub{ display:none; } }
  </style>
</head>
<body>

<!-- ===== Compact Header ===== -->
<header class="header">
  <div class="hdr-inner">
    <!-- left: brand -->
    <a href="index.php" class="brand" aria-label="Trang chủ">
      <div class="brand-circle" aria-hidden="true">AE</div>
      <div class="d-none d-md-block">
        <p class="brand-title mb-0"><?= esc(site_name($conn)) ?></p>
        <p class="brand-sub mb-0">Thời trang nam cao cấp</p>
      </div>
    </a>

    <!-- center: nav (desktop) -->
    <nav class="nav-short" role="navigation" aria-label="Menu chính">
      <a class="<?= is_active('index.php') ?>" href="index.php">Trang chủ</a>
      <a class="<?= is_active('sanpham.php') ?>" href="sanpham.php">Sản phẩm</a>
      <a class="sale-link <?= is_active('sale.php') ?>" href="sale.php" style="font-weight:700;font-size:16px;color:var(--primary);">Danh mục sale</a>
      <a href="about.php">Giới Thiệu</a>
    </nav>

    <!-- right -->
    <div class="right-controls">
      <form class="search-xs d-none d-lg-flex" method="get" action="sanpham.php" role="search" aria-label="Tìm sản phẩm">
        <input type="search" name="q" placeholder="Tìm sản phẩm, mã..." aria-label="Tìm sản phẩm" class="form-control form-control-sm" style="width:260px;max-width:36vw;">
        <button type="submit" class="btn btn-dark btn-sm ms-2"><i class="bi bi-search"></i></button>
      </form>

      <a href="account.php" class="btn btn-link text-decoration-none">
        <i class="bi bi-person" style="color:var(--primary); font-size:18px;"></i>
      </a>

      <div class="dropdown d-none d-md-block">
        <a class="btn btn-link text-decoration-none" href="#" data-bs-toggle="dropdown">việt</a>
        <ul class="dropdown-menu dropdown-menu-end">
          <li><a class="dropdown-item" href="?lang=vi">Tiếng Việt</a></li>
          <li><a class="dropdown-item" href="?lang=en">English</a></li>
        </ul>
      </div>

      <a href="cart.php" class="btn btn-outline-primary position-relative" aria-label="Giỏ hàng">
        <i class="bi bi-bag" style="font-size:18px"></i>
        <span class="d-none d-md-inline ms-2">Giỏ hàng</span>
        <span id="cart-count-badge" class="badge bg-danger rounded-pill cart-badge"><?= (int)$tot['items_count'] ?></span>
      </a>

      <button class="btn btn-light d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileMenu" aria-controls="mobileMenu">
        <i class="bi bi-list" style="font-size:20px"></i>
      </button>
    </div>
  </div>
</header>

<!-- Mobile offcanvas -->
<div class="offcanvas offcanvas-start" tabindex="-1" id="mobileMenu" aria-labelledby="mobileMenuLabel">
  <div class="offcanvas-header">
    <h5 id="mobileMenuLabel"><?= esc(site_name($conn)) ?></h5>
    <button type="button" class="btn-close text-reset" data-bs-dismiss="offcanvas" aria-label="Đóng"></button>
  </div>
  <div class="offcanvas-body">
    <form class="d-flex mb-3" role="search" method="get" action="sanpham.php">
      <input class="form-control form-control-sm me-2" type="search" name="q" placeholder="Tìm sản phẩm...">
      <button class="btn btn-sm btn-dark" type="submit"><i class="bi bi-search"></i></button>
    </form>
    <ul class="list-unstyled">
      <li class="mb-2"><a href="index.php" class="text-decoration-none">Trang chủ</a></li>
      <li class="mb-2"><a href="sanpham.php" class="text-decoration-none">Sản phẩm</a></li>
      <li class="mb-2"><a href="sale.php" class="text-decoration-none">Danh mục sale</a></li>
      <li class="mb-2"><a href="about.php" class="text-decoration-none">Giới thiệu</a></li>
      <li class="mb-2"><a href="contact.php" class="text-decoration-none">Liên hệ</a></li>
    </ul>
  </div>
</div>

<!-- ===== Page content (cart) ===== -->
<div class="cart-wrap">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <a href="sanpham.php" class="btn btn-link">&larr; Tiếp tục mua sắm</a>
    <h3 class="mb-0">Giỏ hàng của bạn (<?= (int)$tot['items_count'] ?>)</h3>
    <div></div>
  </div>

  <div class="card card-cart">
    <div class="cart-left">
      <?php if (empty($cart)): ?>
        <div class="empty-hero">
          <h5>Giỏ hàng trống</h5>
          <p class="muted-small">Bạn chưa thêm sản phẩm nào. Bắt đầu khám phá và thêm vào giỏ nhé!</p>
          <div class="mt-3 d-flex gap-2 justify-content-center">
            <a href="sanpham.php" class="btn btn-primary">Mua ngay</a>
            <a href="index.php" class="btn btn-outline-secondary">Về trang chủ</a>
          </div>
        </div>
      <?php else: ?>
        <form id="cartForm" method="post" action="cart.php?action=update">
          <?php foreach ($cart as $id => $it):
            $price = isset($it['price']) ? (float)$it['price'] : (isset($it['gia']) ? (float)$it['gia'] : 0);
            $qty = isset($it['qty']) ? (int)$it['qty'] : 1;
            $img = $it['img'] ?? 'images/placeholder.jpg';
            $name = $it['name'] ?? $it['ten'] ?? 'Sản phẩm';
          ?>
          <div class="d-flex align-items-center gap-3 mb-3">
            <img src="<?= esc($img) ?>" alt="" class="product-thumb">
            <div class="flex-grow-1">
              <div class="d-flex justify-content-between align-items-start">
                <div>
                  <div class="fw-semibold"><?= esc($name) ?></div>
                  <div class="muted-small mt-1">Mã: <?= esc($it['id'] ?? $id) ?></div>
                </div>
                <div class="text-end">
                  <div class="fw-bold"><?= price($price) ?></div>
                  <div class="muted-small mt-1"><?= price($price * $qty) ?></div>
                </div>
              </div>

              <div class="d-flex align-items-center justify-content-between mt-3">
                <div class="input-group input-group-sm qty-input" style="width:140px;">
                  <button type="button" class="btn btn-outline-secondary btn-decr" data-id="<?= (int)$id ?>">-</button>
                  <input type="number" min="0" name="qty[<?= (int)$id ?>]" value="<?= (int)$qty ?>" class="form-control text-center qty-field" data-id="<?= (int)$id ?>">
                  <button type="button" class="btn btn-outline-secondary btn-incr" data-id="<?= (int)$id ?>">+</button>
                </div>

                <div>
                  <button type="button" class="btn btn-sm btn-outline-danger btn-remove" data-id="<?= (int)$id ?>">Xóa</button>
                </div>
              </div>
            </div>
          </div>
          <hr>
          <?php endforeach; ?>

          <div class="d-flex gap-2 mt-3">
            <button type="submit" class="btn btn-primary">Cập nhật giỏ hàng</button>
            <button type="button" id="clearCartBtn" class="btn btn-outline-danger">Xóa toàn bộ</button>
          </div>
        </form>
      <?php endif; ?>
    </div>

    <div class="cart-right">
      <div class="position-sticky" style="top:22px;">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div class="muted-small">Tạm tính</div>
          <div class="fw-semibold"><?= $tot['subtotal_fmt'] ?></div>
        </div>

        <div class="d-flex justify-content-between align-items-center mb-3">
          <div class="muted-small">Phí vận chuyển</div>
          <div class="fw-semibold"><?= $tot['shipping_fmt'] ?></div>
        </div>

        <hr>

        <div class="d-flex justify-content-between align-items-center mb-3">
          <div class="h5 mb-0">Tổng thanh toán</div>
          <div class="h5 mb-0 text-primary"><?= $tot['total_fmt'] ?></div>
        </div>

        <?php if (!$logged): ?>
          <div class="mb-3 mini-cta">
            <div class="fw-semibold mb-1">Bạn chưa đăng nhập</div>
            <div class="muted-small mb-2">Đăng nhập để lưu giỏ hàng, theo dõi đơn và thanh toán nhanh hơn.</div>
            <div class="d-grid gap-2">
              <a href="login.php?back=cart.php" class="btn btn-login">Đăng nhập</a>
              <a href="register.php" class="btn btn-outline-primary">Tạo tài khoản</a>
              <a href="checkout.php?guest=1" class="btn btn-secondary">Thanh toán như khách</a>
            </div>
          </div>
          <div class="text-muted small">Lưu ý: tạo tài khoản giúp bạn theo dõi đơn hàng và nhận ưu đãi.</div>
        <?php else: ?>
          <div class="d-grid gap-2">
            <a href="checkout.php" class="btn btn-success btn-lg">Tiến hành thanh toán</a>
            <a href="sanpham.php" class="btn btn-outline-secondary">Tiếp tục mua sắm</a>
          </div>
        <?php endif; ?>

        <div class="mt-3">
          <form id="couponForm" onsubmit="event.preventDefault(); alert('Mã giảm giá demo');">
            <label class="form-label small mb-1">Mã khuyến mãi</label>
            <div class="input-group input-group-sm">
              <input class="form-control form-control-sm" placeholder="Nhập mã (nếu có)">
              <button class="btn btn-outline-secondary" type="submit">Áp dụng</button>
            </div>
          </form>
        </div>

        <div class="mt-3 muted-small">Hỗ trợ: <a href="contact.php">Liên hệ</a></div>
      </div>
    </div>
  </div>
</div>

<!-- scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
  // qty controls
  document.querySelectorAll('.btn-decr').forEach(b => b.addEventListener('click', e => {
    const id = b.dataset.id; const f = document.querySelector('input.qty-field[data-id="'+id+'"]'); if(!f) return;
    f.value = Math.max(0, parseInt(f.value||0) - 1);
  }));
  document.querySelectorAll('.btn-incr').forEach(b => b.addEventListener('click', e => {
    const id = b.dataset.id; const f = document.querySelector('input.qty-field[data-id="'+id+'"]'); if(!f) return;
    f.value = Math.max(1, parseInt(f.value||0) + 1);
  }));

  // remove item with AJAX (stay on same page)
  document.querySelectorAll('.btn-remove').forEach(b => b.addEventListener('click', e => {
    if (!confirm('Bạn có chắc muốn xóa sản phẩm này khỏi giỏ?')) return;
    const id = b.dataset.id;
    fetch('cart.php?action=remove', {
      method: 'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded'},
      body: 'id=' + encodeURIComponent(id) + '&ajax=1'
    }).then(r=>r.json()).then(res=>{
      if (res.success) location.reload();
      else alert(res.message || 'Lỗi');
    }).catch(()=>alert('Lỗi kết nối'));
  }));

  // clear cart
  document.getElementById('clearCartBtn')?.addEventListener('click', function(){
    if (!confirm('Xóa toàn bộ giỏ hàng?')) return;
    fetch('cart.php?action=clear', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: 'ajax=1' })
    .then(r=>r.json()).then(res=>{ if(res.success) location.reload(); else alert('Lỗi'); })
    .catch(()=>alert('Lỗi kết nối'));
  });

  // normal submit keeps behavior (full POST)
  document.getElementById('cartForm')?.addEventListener('submit', function(e){
    return true;
  });
</script>
</body>
</html>
