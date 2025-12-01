<?php
// cart.php
// Full cart handling + UI (supports sizes, AJAX add/update/remove/clear)
// Autoupdate version (no "Lưu/Cập nhật" button)

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inc/helpers.php';

if (!function_exists('esc')) {
    function esc($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('price')) {
    function price($v) { return number_format((float)$v, 0, ',', '.') . ' ₫'; }
}

function getProductImage($conn, $product_id) {
    $placeholder = 'images/placeholder.jpg';
    try {
        $stmt = $conn->prepare('SELECT duong_dan FROM anh_san_pham WHERE id_san_pham = :id ORDER BY la_anh_chinh DESC, thu_tu ASC, id_anh ASC LIMIT 1');
        $stmt->execute([':id' => (int)$product_id]);
        $p = $stmt->fetchColumn();
        if ($p && is_string($p) && trim($p) !== '') {
            $p = trim($p);
            if (preg_match('#^https?://#i', $p)) return $p;
            $candidates = [
                ltrim($p, '/'),
                'images/' . ltrim($p, '/'),
                'uploads/' . ltrim($p, '/'),
                'public/' . ltrim($p, '/'),
                'images/' . basename($p),
            ];
            foreach ($candidates as $c) {
                if (file_exists(__DIR__ . '/' . $c) && @filesize(__DIR__ . '/' . $c) > 0) return $c;
            }
            return ltrim($p, '/');
        }
    } catch (Exception $e) {}
    return $placeholder;
}

if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

function cart_summary() {
    $sum = 0.0;
    $items_count = 0;
    foreach ($_SESSION['cart'] as $it) {
        $qty = isset($it['qty']) ? (int)$it['qty'] : 1;
        $price = isset($it['price']) ? (float)$it['price'] : 0.0;
        $sum += $price * $qty;
        $items_count += $qty;
    }
    return ['total' => $sum, 'items_count' => $items_count];
}

function fetch_product_basic($conn, $id) {
    $id = (int)$id;
    if ($id <= 0) return null;
    try {
        $stmt = $conn->prepare('SELECT id_san_pham, ten, gia FROM san_pham WHERE id_san_pham = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    } catch (Exception $e) { return null; }
}

function normalize_size_key($s) {
    $s = trim((string)$s);
    $s = preg_replace('/\s+/', '_', $s);
    $s = preg_replace('/[^A-Za-z0-9_\-]/', '', $s);
    return $s;
}

function item_key($id, $size) {
    $id = (int)$id;
    $k = (string)$id;
    if ($size !== '') {
        $s = normalize_size_key($size);
        if ($s !== '') $k .= '_size_' . $s;
    }
    return $k;
}

function is_ajax_request() {
    if (!empty($_POST['ajax']) || !empty($_GET['ajax'])) return true;
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') return true;
    return false;
}

/* ---------- Actions ---------- */
$action = $_REQUEST['action'] ?? '';

if ($action === 'add') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : (isset($_REQUEST['id']) ? (int)$_REQUEST['id'] : 0);
    $qty = isset($_POST['qty']) ? (int)$_POST['qty'] : (isset($_REQUEST['qty']) ? (int)$_REQUEST['qty'] : 1);
    $qty = max(1, $qty);
    $size = isset($_POST['size']) ? trim($_POST['size']) : (isset($_REQUEST['size']) ? trim($_REQUEST['size']) : '');
    $ajax = is_ajax_request();

    if ($id <= 0) {
        if ($ajax) { header('Content-Type: application/json'); echo json_encode(['success'=>false,'message'=>'Sản phẩm không hợp lệ']); exit; }
        header('Location: sanpham.php'); exit;
    }

    $p = fetch_product_basic($conn, $id);
    $price = $p ? (float)$p['gia'] : 0.0;
    $name = $p ? $p['ten'] : ('Sản phẩm #' . $id);
    $key = item_key($id, $size);

    if (isset($_SESSION['cart'][$key])) {
        $_SESSION['cart'][$key]['qty'] = (int)$_SESSION['cart'][$key]['qty'] + $qty;
    } else {
        $_SESSION['cart'][$key] = [
            'key' => $key,
            'product_id' => $id,
            'name' => $name,
            'price' => $price,
            'qty' => $qty,
            'size' => $size,
            'added_at' => time(),
        ];
    }

    if ($ajax) {
        header('Content-Type: application/json');
        $summary = cart_summary();
        echo json_encode(['success' => true, 'cart' => ['items_count' => $summary['items_count'], 'total' => $summary['total']]]);
        exit;
    } else {
        header('Location: cart.php'); exit;
    }
}

if ($action === 'remove') {
    $key = $_REQUEST['key'] ?? '';
    if ($key && isset($_SESSION['cart'][$key])) unset($_SESSION['cart'][$key]);
    if (is_ajax_request()) {
        header('Content-Type: application/json');
        $summary = cart_summary();
        echo json_encode(['success' => true, 'cart' => ['items_count' => $summary['items_count'], 'total' => $summary['total']]]);
        exit;
    } else { header('Location: cart.php'); exit; }
}

if ($action === 'update') {
    $ajax = is_ajax_request();
    $items = [];

    if (!empty($_POST['items']) && is_array($_POST['items'])) {
        $items = $_POST['items'];
    } elseif (!empty($_REQUEST['items']) && is_array($_REQUEST['items'])) {
        $items = $_REQUEST['items'];
    } else {
        $raw = file_get_contents('php://input');
        if ($raw) {
            $json = @json_decode($raw, true);
            if (!empty($json['items']) && is_array($json['items'])) $items = $json['items'];
        }
    }

    foreach ($items as $k => $v) {
        $q = (int)$v;
        if ($q <= 0) { if (isset($_SESSION['cart'][$k])) unset($_SESSION['cart'][$k]); continue; }
        if (isset($_SESSION['cart'][$k])) $_SESSION['cart'][$k]['qty'] = $q;
    }

    if ($ajax) {
        header('Content-Type: application/json');
        $summary = cart_summary();
        echo json_encode(['success' => true, 'cart' => ['items_count' => $summary['items_count'], 'total' => $summary['total']]]);
        exit;
    } else { header('Location: cart.php'); exit; }
}

if ($action === 'clear') {
    $_SESSION['cart'] = [];
    header('Location: cart.php'); exit;
}

/* ---------- Render ---------- */
$summary = cart_summary();

/* load categories for menu */
try { $cats = $conn->query("SELECT * FROM danh_muc WHERE trang_thai=1 ORDER BY thu_tu ASC")->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) { $cats = []; }
$catsMenu = $cats;
$user_name = !empty($_SESSION['user']['ten']) ? $_SESSION['user']['ten'] : null;
?>
<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <title>Giỏ hàng — <?= esc(function_exists('site_name') ? site_name($conn) : 'AE Shop') ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root{ --accent:#0b7bdc; --muted:#6c757d; --nav-bg:#ffffff; }
    body { background:#f7fbff; color:#0b2336; font-family: Inter, system-ui, -apple-system, "Segoe UI", Roboto, Arial; }
    .product-thumb { width:92px; height:92px; object-fit:cover; border-radius:8px; background:#fff; padding:6px; box-shadow:0 6px 18px rgba(2,6,23,0.04); }
    .size-badge { display:inline-block; padding:6px 10px; border-radius:8px; background:#fff; border:1px solid rgba(11,123,220,0.12); font-weight:700; }
    .qty-input { width:108px; }
    .action-btn { min-width:44px; }
    .empty-cart { padding:36px; text-align:center; background:#fff; border-radius:12px; box-shadow:0 8px 30px rgba(2,6,23,0.04); }
    .ae-header { background:#fff; border-bottom:1px solid #eef3f8; position:sticky; top:0; z-index:1050; }
    .ae-logo-mark{ width:46px;height:46px;border-radius:10px;background:var(--accent);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800; }
    .brand { display:flex; align-items:center; gap:12px; text-decoration:none; color:inherit; }
    .nav-center { display:flex; gap:8px; align-items:center; justify-content:center; flex:1; }
    .nav-center .nav-link { color:#333; padding:8px 12px; border-radius:8px; font-weight:600; text-decoration:none; transition:all .15s; }
    .nav-center .nav-link.active, .nav-center .nav-link:hover { color:var(--accent); background:rgba(11,123,220,0.06); }
    @media (max-width:991px){ .nav-center{ display:none; } .search-input{ display:none; } }
  </style>
</head>
<body>

<header class="ae-header">
  <div class="container d-flex align-items-center gap-3 py-2">
    <a class="brand" href="index.php" aria-label="<?= esc(function_exists('site_name') ? site_name($conn) : 'AE Shop') ?>">
      <div class="ae-logo-mark">AE</div>
      <div class="d-none d-md-block">
        <div style="font-weight:800"><?= esc(function_exists('site_name') ? site_name($conn) : 'AE Shop') ?></div>
        <div style="font-size:12px;color:var(--muted)">Thời trang nam cao cấp</div>
      </div>
    </a>

    <nav class="nav-center d-none d-lg-flex" role="navigation" aria-label="Main menu">
      <a class="nav-link" href="index.php">Trang chủ</a>
      <div class="nav-item dropdown">
        <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown" aria-expanded="false">Sản Phẩm</a>
        <ul class="dropdown-menu p-2">
          <?php if (!empty($catsMenu)): foreach($catsMenu as $c): ?>
            <li><a class="dropdown-item" href="category.php?slug=<?= urlencode($c['slug']) ?>"><?= esc($c['ten']) ?></a></li>
          <?php endforeach; else: ?>
            <li><span class="dropdown-item text-muted">Chưa có danh mục</span></li>
          <?php endif; ?>
          <li><hr class="dropdown-divider"></li>
          <li><a class="dropdown-item text-muted" href="sanpham.php">Xem tất cả sản phẩm</a></li>
        </ul>
      </div>

      <!-- CHANGED: show "Liên hệ" menu instead of "Giỏ hàng" -->
      <a class="nav-link" href="about.php">Giới thiệu</a>
      <a class="nav-link active" href="contact.php">Liên hệ</a>
    </nav>

    <div class="ms-auto d-flex align-items-center gap-2">
      <form class="d-none d-lg-flex" action="sanpham.php" method="get" role="search">
        <div class="input-group input-group-sm shadow-sm" style="border-radius:10px; overflow:hidden;">
          <input name="q" class="form-control form-control-sm search-input" placeholder="Tìm sản phẩm, mã..." value="<?= esc($_GET['q'] ?? '') ?>">
          <button class="btn btn-dark btn-sm" type="submit"><i class="bi bi-search"></i></button>
        </div>
      </form>

      <div class="dropdown">
        <a class="text-decoration-none position-relative d-flex align-items-center" href="cart.php" id="miniCartBtn">
          <div style="width:40px;height:40px;border-radius:8px;background:#f6f8fb;display:flex;align-items:center;justify-content:center;color:#0b1220"><i class="bi bi-bag-fill"></i></div>
          <span class="ms-2 d-none d-md-inline small">Giỏ hàng</span>
          <span id="cartBadge" class="badge bg-danger rounded-pill" style="position:relative;top:-10px;left:-8px"><?= (int)$summary['items_count'] ?></span>
        </a>
      </div>

      <button class="btn btn-light d-lg-none ms-2" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileMenu" aria-controls="mobileMenu">
        <i class="bi bi-list"></i>
      </button>
    </div>
  </div>
</header>

<!-- mobile menu -->
<div class="offcanvas offcanvas-start" tabindex="-1" id="mobileMenu" aria-labelledby="mobileMenuLabel">
  <div class="offcanvas-header">
    <h5 id="mobileMenuLabel"><?= esc(function_exists('site_name') ? site_name($conn) : 'AE Shop') ?></h5>
    <button type="button" class="btn-close text-reset" data-bs-dismiss="offcanvas" aria-label="Đóng"></button>
  </div>
  <div class="offcanvas-body">
    <form action="sanpham.php" method="get" class="mb-3 d-flex">
      <input class="form-control me-2" name="q" placeholder="Tìm sản phẩm..." value="<?= esc($_GET['q'] ?? '') ?>">
      <button class="btn btn-dark">Tìm</button>
    </form>
    <ul class="list-unstyled">
      <li class="mb-2"><a href="index.php" class="text-decoration-none">Trang chủ</a></li>
      <li class="mb-2"><a href="sanpham.php" class="text-decoration-none">Sản phẩm</a></li>
      <?php foreach($catsMenu as $c): ?>
        <li class="mb-2 ps-2"><a href="category.php?slug=<?= urlencode($c['slug']) ?>" class="text-decoration-none"><?= esc($c['ten']) ?></a></li>
      <?php endforeach; ?>
      <li class="mb-2"><a href="about.php" class="text-decoration-none">Giới thiệu</a></li>
      <li class="mb-2"><a href="contact.php" class="text-decoration-none">Liên hệ</a></li>
    </ul>
  </div>
</div>

<main class="container mb-5" style="padding-top:18px;">
  <div class="row g-4">
    <div class="col-lg-8">
      <h4>Giỏ hàng của bạn</h4>
      <?php if (empty($_SESSION['cart'])): ?>
        <div class="empty-cart mt-3">
          <p class="mb-3">Giỏ hàng trống.</p>
          <a href="sanpham.php" class="btn btn-primary">Xem sản phẩm</a>
        </div>
      <?php else: ?>
        <!-- note: form kept for graceful degradation but no submit button -->
        <form id="cartForm" method="post" action="cart.php?action=update" novalidate>
          <input type="hidden" name="action" value="update">
          <div class="card p-3 cart-table">
            <div class="table-responsive">
              <table class="table align-middle mb-0">
                <thead>
                  <tr>
                    <th style="min-width:300px">Sản phẩm</th>
                    <th>Size</th>
                    <th>Số lượng</th>
                    <th>Đơn giá</th>
                    <th style="min-width:140px">Thành tiền</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($_SESSION['cart'] as $key => $item):
                    $img = 'images/placeholder.jpg';
                    if (!empty($item['product_id'])) $img = getProductImage($conn, $item['product_id']);
                    $qty = (int)($item['qty'] ?? 1);
                    $price = (float)($item['price'] ?? 0.0);
                    $line_total = $price * $qty;
                  ?>
                    <tr data-key="<?= esc($key) ?>" data-price="<?= esc($price) ?>">
                      <td>
                        <div class="d-flex align-items-center gap-3">
                          <img src="<?= esc($img) ?>" alt="<?= esc($item['name'] ?? '') ?>" class="product-thumb">
                          <div>
                            <div class="fw-bold"><?= esc($item['name'] ?? '') ?></div>
                            <div class="small text-muted">Mã: <?= esc($item['product_id'] ?? '') ?></div>
                          </div>
                        </div>
                      </td>

                      <td>
                        <?php if (!empty($item['size'])): ?>
                          <div class="size-badge"><?= esc($item['size']) ?></div>
                        <?php else: ?>
                          <div class="small text-muted">—</div>
                        <?php endif; ?>
                      </td>

                      <td>
                        <div class="d-flex align-items-center gap-2">
                          <button type="button" class="btn btn-outline-secondary btn-sm action-btn qty-decrease" data-key="<?= esc($key) ?>">−</button>
                          <input name="items[<?= esc($key) ?>]" type="number" min="1" value="<?= $qty ?>" class="form-control qty-input" data-key="<?= esc($key) ?>">
                          <button type="button" class="btn btn-outline-secondary btn-sm action-btn qty-increase" data-key="<?= esc($key) ?>">+</button>
                        </div>
                      </td>

                      <td class="unit-price"><?= price($price) ?></td>
                      <td>
                        <div class="d-flex align-items-center justify-content-between">
                          <div class="fw-semibold line-total"><?= price($line_total) ?></div>
                          <div>
                            <a href="cart.php?action=remove&key=<?= urlencode($key) ?>" class="btn btn-link text-danger btn-sm remove-link" data-key="<?= esc($key) ?>"><i class="bi bi-trash3"></i></a>
                          </div>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>

            <div class="d-flex justify-content-between align-items-center mt-3">
              <div>
                <!-- removed update button; keep clear all -->
                <a href="cart.php?action=clear" class="btn btn-outline-danger btn-sm ms-2">Xóa tất cả</a>
              </div>
              <div class="small text-muted">Tổng: <strong id="cartTotal"><?= price($summary['total']) ?></strong></div>
            </div>
          </div>
        </form>
      <?php endif; ?>
    </div>

    <div class="col-lg-4">
      <div class="card p-3">
        <h5 class="mb-3">Thông tin đơn hàng</h5>

        <!-- NEW: tổng số sản phẩm -->
        <div class="d-flex justify-content-between mb-2"><div>Tổng số sản phẩm</div><div id="summaryItemsCount" class="fw-semibold"><?= (int)$summary['items_count'] ?></div></div>

        <div class="d-flex justify-content-between mb-2"><div>Tạm tính</div><div id="summarySubtotal"><?= price($summary['total']) ?></div></div>
        <div class="d-flex justify-content-between mb-3"><div>Phí vận chuyển</div><div class="text-muted">Tính khi thanh toán</div></div>
        <hr>
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div><strong>Tổng thanh toán</strong></div>
          <div class="h5 text-danger" id="summaryTotal"><?= price($summary['total']) ?></div>
        </div>
        <div class="d-grid">
          <a href="checkout.php" class="btn btn-success">Thanh toán</a>
          <a href="sanpham.php" class="btn btn-outline-secondary mt-2">Tiếp tục mua</a>
        </div>
      </div>

      <div class="card p-3 mt-3">
        <h6>Chính sách</h6>
        <div class="small text-muted">Đổi trả 7 ngày | Giao hàng nhanh 1-3 ngày</div>
      </div>
    </div>
  </div>
</main>

<script>
function formatVND(n){
  try { return new Intl.NumberFormat('vi-VN').format(Number(n)) + ' ₫'; }
  catch(e) { return n + ' ₫'; }
}

document.addEventListener('DOMContentLoaded', function(){

  function applySummary(json){
    if (!json || !json.cart) return;
    var itemsCount = json.cart.items_count;
    var total = json.cart.total;
    var badge = document.getElementById('cartBadge');
    if (badge) badge.textContent = itemsCount;
    var cartTotal = document.getElementById('cartTotal');
    if (cartTotal) cartTotal.textContent = formatVND(total);
    var summarySubtotal = document.getElementById('summarySubtotal');
    if (summarySubtotal) summarySubtotal.textContent = formatVND(total);
    var summaryTotal = document.getElementById('summaryTotal');
    if (summaryTotal) summaryTotal.textContent = formatVND(total);
    var summaryItems = document.getElementById('summaryItemsCount');
    if (summaryItems) summaryItems.textContent = itemsCount;
  }

  function updateLineTotal(row, qty){
    var price = parseFloat(row.getAttribute('data-price') || '0');
    var line = price * Number(qty || 0);
    var el = row.querySelector('.line-total');
    if (el) el.textContent = formatVND(line);
    return line;
  }

  function collectItemsPayload(){
    var inputs = document.querySelectorAll('input.qty-input[data-key]');
    var items = {};
    inputs.forEach(function(inp){
      var k = inp.dataset.key;
      var q = parseInt(inp.value || '1', 10);
      q = isNaN(q) ? 1 : Math.max(1, q);
      items[k] = q;
    });
    return items;
  }

  function debounce(fn, wait){
    var t;
    return function(){
      clearTimeout(t);
      var args = arguments;
      t = setTimeout(function(){ fn.apply(null, args); }, wait);
    };
  }

  var sendUpdate = debounce(function(items){
    var url = 'cart.php?action=update';
    var fd = new FormData();
    fd.append('ajax', '1');
    fd.append('action', 'update');
    Object.keys(items).forEach(function(k){ fd.append('items['+k+']', items[k]); });

    fetch(url, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: {'X-Requested-With': 'XMLHttpRequest'}
    }).then(function(r){ return r.json(); })
    .then(function(json){
      if (json && json.success) {
        var rows = document.querySelectorAll('tbody tr[data-key]');
        var totalCalc = 0;
        var totalItems = 0;
        rows.forEach(function(row){
          var k = row.getAttribute('data-key');
          var inp = document.querySelector('input.qty-input[data-key="'+k+'"]');
          var q = inp ? parseInt(inp.value || '1', 10) : 1;
          q = isNaN(q) ? 1 : Math.max(1, q);
          var line = updateLineTotal(row, q);
          totalCalc += line;
          totalItems += q;
        });
        applySummary(json);
      } else {
        console.warn('Update failed', json);
      }
    }).catch(function(err){
      console.error('Update error', err);
    });
  }, 180);

  // +/- handlers
  document.querySelectorAll('.qty-decrease').forEach(function(btn){
    btn.addEventListener('click', function(){
      var key = this.dataset.key;
      var input = document.querySelector('input.qty-input[data-key="'+key+'"]');
      if (!input) return;
      var v = parseInt(input.value || '1', 10);
      v = Math.max(1, v - 1);
      input.value = v;
      var row = document.querySelector('tr[data-key="'+key+'"]');
      if (row) updateLineTotal(row, v);
      sendUpdate(collectItemsPayload());
    });
  });
  document.querySelectorAll('.qty-increase').forEach(function(btn){
    btn.addEventListener('click', function(){
      var key = this.dataset.key;
      var input = document.querySelector('input.qty-input[data-key="'+key+'"]');
      if (!input) return;
      var v = parseInt(input.value || '1', 10);
      v = Math.max(1, v + 1);
      input.value = v;
      var row = document.querySelector('tr[data-key="'+key+'"]');
      if (row) updateLineTotal(row, v);
      sendUpdate(collectItemsPayload());
    });
  });

  // input changes (debounced)
  document.querySelectorAll('input.qty-input').forEach(function(inp){
    inp.addEventListener('input', function(){
      var key = this.dataset.key;
      var v = parseInt(this.value || '1', 10);
      v = isNaN(v) ? 1 : Math.max(1, v);
      this.value = v;
      var row = document.querySelector('tr[data-key="'+key+'"]');
      if (row) updateLineTotal(row, v);
      sendUpdate(collectItemsPayload());
    });
  });

  // AJAX remove
  document.querySelectorAll('.remove-link').forEach(function(a){
    a.addEventListener('click', function(e){
      e.preventDefault();
      if (!confirm('Bạn có muốn xóa sản phẩm này khỏi giỏ?')) return;
      var key = this.dataset.key;
      fetch('cart.php?action=remove&ajax=1&key=' + encodeURIComponent(key), {
        method: 'GET',
        credentials: 'same-origin',
        headers: {'X-Requested-With': 'XMLHttpRequest'}
      }).then(function(res){ return res.json(); })
      .then(function(json){
        if (json && json.success) {
          var row = document.querySelector('tr[data-key="'+key+'"]');
          if (row) row.remove();
          applySummary(json);
          var rows = document.querySelectorAll('tbody tr[data-key]');
          var totalCalc = 0;
          var totalItems = 0;
          rows.forEach(function(row){
            var k = row.getAttribute('data-key');
            var inp = document.querySelector('input.qty-input[data-key="'+k+'"]');
            var q = inp ? parseInt(inp.value || '1', 10) : 1;
            var price = parseFloat(row.getAttribute('data-price') || '0');
            totalCalc += price * q;
            totalItems += q;
          });
          var cartTotal = document.getElementById('cartTotal');
          if (cartTotal) cartTotal.textContent = formatVND(totalCalc);
          var summarySubtotal = document.getElementById('summarySubtotal');
          if (summarySubtotal) summarySubtotal.textContent = formatVND(totalCalc);
          var summaryTotal = document.getElementById('summaryTotal');
          if (summaryTotal) summaryTotal.textContent = formatVND(totalCalc);
          var summaryItems = document.getElementById('summaryItemsCount');
          if (summaryItems) summaryItems.textContent = totalItems;
          var badge = document.getElementById('cartBadge');
          if (badge && json.cart) badge.textContent = json.cart.items_count;
        } else {
          alert('Không thể xóa mục.');
        }
      }).catch(function(err){
        console.error(err);
        alert('Lỗi kết nối.');
      });
    });
  });

});
</script>

</body>
</html>
