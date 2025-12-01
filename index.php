<?php
// index.php (full file - updated QuickView + SKU & stock, NAV updated to match category.php style)
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inc/helpers.php';

/* fallback helpers nếu inc/helpers.php thiếu */
if (!function_exists('esc')) {
    function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('price')) {
    function price($v){ return number_format((float)$v,0,',','.') . ' ₫'; }
}

/* get product image helper */
function getProductImage($conn, $product_id) {
    $placeholder = 'images/placeholder.jpg';
    try {
        $stmt = $conn->prepare("SELECT duong_dan FROM anh_san_pham WHERE id_san_pham = :id AND la_anh_chinh = 1 LIMIT 1");
        $stmt->execute([':id' => $product_id]);
        $path = $stmt->fetchColumn();
        if (!$path) {
            $stmt2 = $conn->prepare("SELECT duong_dan FROM anh_san_pham WHERE id_san_pham = :id ORDER BY thu_tu ASC, id_anh ASC LIMIT 1");
            $stmt2->execute([':id' => $product_id]);
            $path = $stmt2->fetchColumn();
        }
    } catch (Exception $e) {
        $path = null;
    }
    if (!$path) return $placeholder;
    $path = trim($path);
    if (preg_match('#^https?://#i', $path)) return $path;
    $candidates = [
        ltrim($path, '/'),
        'images/' . ltrim($path, '/'),
        'images/products/' . ltrim($path, '/'),
        'uploads/' . ltrim($path, '/'),
        'public/' . ltrim($path, '/'),
        'images/' . basename($path),
        'images/products/' . basename($path),
        'uploads/' . basename($path),
    ];
    $candidates = array_values(array_unique($candidates));
    foreach ($candidates as $c) {
        if (file_exists(__DIR__ . '/' . $c) && @filesize(__DIR__ . '/' . $c) > 0) {
            return $c;
        }
    }
    return $placeholder;
}

/* banner helper */
function getBannerImage($filename) {
    $placeholder = 'images/placeholder-banner.jpg';
    $candidates = [
        ltrim($filename, '/'),
        'images/' . ltrim($filename, '/'),
        'assets/images/' . ltrim($filename, '/'),
        'uploads/' . ltrim($filename, '/'),
        '../images/' . ltrim($filename, '/'),
    ];
    foreach (array_values(array_unique($candidates)) as $c) {
        if (file_exists(__DIR__ . '/' . $c) && @filesize(__DIR__ . '/' . $c) > 0) {
            return $c;
        }
    }
    return $placeholder;
}

/* rating helpers */
function getProductRating($conn, $product_id) {
    $result = ['avg'=>0, 'count'=>0];
    try {
        $stmt = $conn->prepare("SELECT rating, rating_count, so_sao FROM san_pham WHERE id_san_pham = :id LIMIT 1");
        $stmt->execute([':id'=>$product_id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($r) {
            if (!empty($r['rating']) || !empty($r['rating_count']) || !empty($r['so_sao'])) {
                $avg = !empty($r['rating']) ? (float)$r['rating'] : (!empty($r['so_sao']) ? (float)$r['so_sao'] : 0);
                $cnt = !empty($r['rating_count']) ? (int)$r['rating_count'] : 0;
                return ['avg'=>$avg, 'count'=>$cnt];
            }
        }
    } catch (Exception $e) {}
    $tables = ['danh_gia','danhgia','reviews','product_reviews','rating','review'];
    $prodCols = ['id_san_pham','san_pham_id','product_id','product','id_product'];
    $ratingCols = ['rating','so_sao','diem','stars','score','point'];
    foreach ($tables as $t) {
        foreach ($prodCols as $pc) {
            foreach ($ratingCols as $rc) {
                try {
                    $sql = "SELECT AVG(`$rc`) AS avg_rating, COUNT(*) AS countv FROM `$t` WHERE `$pc` = :id";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([':id'=>$product_id]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($row && ($row['countv'] ?? 0) > 0) {
                        $avg = round((float)$row['avg_rating'], 1);
                        $cnt = (int)$row['countv'];
                        return ['avg'=>$avg, 'count'=>$cnt];
                    }
                } catch (Exception $e) {}
            }
        }
    }
    return $result;
}

function render_rating_number($avg, $count) {
    $avg = max(0, min(5, (float)$avg));
    $cnt = (int)$count;
    if ($cnt <= 0) {
        return '<div class="small text-muted">(Chưa có đánh giá)</div>';
    }
    $display = number_format($avg, 1, '.', '');
    return '<div class="d-flex align-items-center" style="gap:8px;"><div style="font-weight:700;color:#0b7bdc;">' . esc($display) . '*</div><div class="small text-muted">(' . $cnt . ')</div></div>';
}

/* load data */
try { $cats = $conn->query("SELECT * FROM danh_muc WHERE trang_thai=1 ORDER BY thu_tu ASC")->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) { $cats = []; }
try { $promos = $conn->query("SELECT * FROM san_pham WHERE trang_thai=1 AND gia_cu IS NOT NULL AND gia_cu>gia ORDER BY created_at DESC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) { $promos = []; }
try { $latest = $conn->query("SELECT * FROM san_pham WHERE trang_thai=1 ORDER BY created_at DESC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) { $latest = []; }

/* category images */
$cat_image_by_name = [
    'Sơ mi tay dài'  => 'images/viet1.jpg',
    'Sơ mi tay ngắn' => 'images/cats/somi-tay-ngan.jpg',
    'Áo thun'        => 'images/cats/ao-thun.jpg',
    'Quần jean'      => 'images/cats/quan-jean.jpg',
    'Phụ kiện'       => 'images/cats/phu-kien.jpg',
];
$priority_names = ['Sơ mi tay dài'];

if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) $_SESSION['cart'] = [];
$cart_count = 0;
foreach ($_SESSION['cart'] as $it) { $cart_count += isset($it['qty']) ? (int)$it['qty'] : (isset($it['sl']) ? (int)$it['sl'] : 1); }
$user_name = !empty($_SESSION['user']['ten']) ? $_SESSION['user']['ten'] : null;
$banner1 = getBannerImage('aeshop2.png');
$banner2 = getBannerImage('viet1.png');
$banner3 = getBannerImage('ae2.png');

/* For header nav (use $cats as menu source) */
$catsMenu = $cats;
?>
<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <title><?= esc(function_exists('site_name') ? site_name($conn) : 'AE Shop') ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <link href="assets/css/style.css" rel="stylesheet">
  <style>
  :root{ --accent:#0b7bdc; --muted:#6c757d; --nav-bg:#ffffff; --overlay-bg: rgba(12,17,20,0.08); --overlay-text:#061023; --circle-bg:#ffffff; --circle-icon:#0b7bdc; }
  body{ background:#f8fbff; font-family: Inter, system-ui, -apple-system, "Segoe UI", Roboto, Arial; color:#0f1724; }

  .ae-navbar{ background:var(--nav-bg); box-shadow:0 6px 18px rgba(11,38,80,0.04); }
  .ae-logo-mark{ width:46px;height:46px;border-radius:10px;background:var(--accent);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800; }

  .hero-image { width:100%; height:220px; object-fit:cover; display:block; }
  @media (min-width:768px){ .hero-image { height:320px } }

  .cats-section { margin-bottom:28px; }
  .cats-head .title { display:none; }

  .cats-grid {
    display:grid;
    gap:28px;
    grid-template-columns: repeat(1, 1fr);
    align-items:stretch;
  }
  @media (min-width:576px) and (max-width:767px){
    .cats-grid{ grid-template-columns: repeat(2, 1fr); }
  }
  @media (min-width:768px) and (max-width:991px){
    .cats-grid{ grid-template-columns: repeat(3, 1fr); }
  }
  @media (min-width:992px){
    .cats-grid{ grid-template-columns: repeat(5, 1fr); }
  }

  .cat-card-hero { position:relative; overflow:hidden; border-radius:12px; min-height:360px; display:flex; flex-direction:column; justify-content:flex-end; background:#fff; box-shadow:0 10px 30px rgba(2,6,23,0.05); transition: transform .25s ease, box-shadow .25s ease; cursor:pointer; }
  .cat-card-hero .cat-image { position:absolute; left:0; right:0; top:0; bottom:0; z-index:1; background-size:cover; background-position:center; transition: transform .45s cubic-bezier(.2,.8,.2,1); }
  .cat-ov { position:relative; z-index:3; width:100%; background: linear-gradient(180deg, rgba(255,255,255,0.00), rgba(8,12,18,0.10)); backdrop-filter: blur(4px); padding:18px; display:flex; align-items:center; justify-content:space-between; gap:12px; }
  .cat-ov .cat-name { font-weight:700; color:#071427; font-size:1.05rem; text-shadow: 0 1px 0 rgba(255,255,255,0.6); }
  .cat-ov .cat-action { width:48px; height:48px; border-radius:50%; background:var(--circle-bg); display:flex; align-items:center; justify-content:center; box-shadow:0 8px 18px rgba(2,6,23,0.08); transition: transform .18s ease, box-shadow .18s ease, background .18s; flex:0 0 48px; }
  .cat-link { display:block; text-decoration:none; color:inherit; height:100%; }
  .card-pro { border-radius:12px; overflow:hidden; background:#fff; box-shadow:0 8px 30px rgba(2,6,23,0.06); display:flex; flex-direction:column; height:100%; transition:transform .14s; }
  .card-media { background:linear-gradient(180deg,#fff,#f7fbff); display:flex; align-items:center; justify-content:center; padding:18px; min-height:220px; cursor:pointer; }
  .card-media img{ max-width:100%; max-height:200px; object-fit:contain; }
  .card-body-pro{ padding:14px; display:flex; flex-direction:column; gap:8px; flex:1; }
  .pro-title{ font-weight:700; font-size:1rem; line-height:1.2; min-height:48px; color:#0b1a2b; }
  .pro-desc{ color:#6b7280; font-size:0.9rem; min-height:36px; }
  .pro-price{ font-weight:800; color:var(--accent); font-size:1.05rem; }
  .pro-old{ text-decoration:line-through; color:#93a3b3; font-size:0.9rem; }
  .badge-discount{ position:absolute; left:12px; top:12px; background:#ef4444; color:#fff; padding:6px 10px; border-radius:999px; font-weight:700; z-index:9; }
  .badge-new{ position:absolute; left:12px; top:12px; background:var(--accent); color:#fff; padding:6px 10px; border-radius:999px; font-weight:700; z-index:9; }
  .card-actions { display:flex; gap:8px; margin-top:10px; }
  .btn-primary-filled{ background:var(--accent); color:#fff; border:none; padding:10px 12px; border-radius:10px; font-weight:700; }
  .btn-outline-ghost{ background:transparent; border:1px solid rgba(11,123,220,0.12); color:var(--accent); padding:8px 12px; border-radius:10px; }
  .products-grid { display:grid; grid-template-columns: repeat(4,1fr); gap:18px; grid-auto-rows:1fr; }
  @media(max-width:1199px){ .products-grid{ grid-template-columns: repeat(3,1fr); } }
  @media(max-width:767px){ .products-grid{ grid-template-columns: repeat(2,1fr); } }
  @media(max-width:479px){ .products-grid{ grid-template-columns: repeat(1,1fr); } }

  /* ===== New styles: add button, size pills, buy now ===== */
  .btn-add-primary {
    background: linear-gradient(90deg,#0b6ff0,#0b7bdc);
    border: none;
    color: #fff;
    padding: 10px 16px;
    border-radius: 10px;
    font-weight: 700;
    box-shadow: 0 12px 30px rgba(11,123,220,0.14);
    display: inline-flex;
    align-items: center;
    gap: .6rem;
  }
  .btn-add-primary i { font-size: 16px; line-height: 1; color: #fff; }

  .size-pill {
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width:38px;
    height:38px;
    padding:0 10px;
    border-radius:8px;
    border:1px solid rgba(11,123,220,0.12);
    background:#fff;
    color:#0b1a2b;
    font-weight:700;
    cursor:pointer;
    transition: all .15s ease;
    box-shadow: 0 6px 18px rgba(2,6,23,0.03);
  }
  .size-pill.active,
  .size-pill:active {
    background: linear-gradient(90deg,#0b6ff0,#0b7bdc);
    color: #fff;
    border-color: transparent;
    transform: translateY(-2px);
    box-shadow: 0 14px 30px rgba(11,123,220,0.14);
  }
  .size-pill:hover { transform: translateY(-3px); }

  .qty-compact {
    width:110px;
    border-radius:8px;
    border:1px solid rgba(11,123,220,0.12);
    padding:8px 12px;
    font-weight:700;
    text-align:center;
  }

  .btn-buy-now {
    border:2px solid #16a34a;
    color:#16a34a;
    background:#fff;
    padding:10px 14px;
    border-radius:10px;
    font-weight:700;
  }

  #qv-sizes .size-pill { margin-right:8px; margin-bottom:8px; }

  @media (max-width:767px){
    .btn-add-primary { padding:10px; font-size:14px; }
    .size-pill{ min-width:34px; height:34px; }
  }

  /* Quickview specific */
  .qv-left { padding:1.2rem; }
  .qv-right { padding:1.2rem 1.6rem; border-left:1px solid #eef3f7; }

  .qv-title { font-weight:800; font-size:1.2rem; margin-bottom:6px; }
  .qv-meta { font-size:0.92rem; color:#556070; margin-bottom:10px; display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
  .qv-meta .sku-label{ color:#6b7280; }
  .qv-meta .sku-value{ font-weight:700; color:#0b1a2b; }
  .qv-meta .status { font-weight:700; }
  .qv-meta .brand { color:#374151; font-weight:700; }

  .qv-price-box { background:#fff; border:1px solid #f1f5f9; padding:14px; border-radius:8px; margin:12px 0; }
  .qv-price { font-size:1.25rem; font-weight:800; color:#ef4444; }

  .size-pill {
    display:inline-flex; align-items:center; justify-content:center;
    min-width:56px; height:42px; padding:0 12px; border-radius:8px;
    border:1px solid #e6eef9; background:#fff; color:#0b1a2b; font-weight:700;
    cursor:pointer; position:relative; margin-right:8px; margin-bottom:8px;
    box-shadow: 0 6px 18px rgba(2,6,23,0.03);
  }
  .size-pill.selected{
    background: #fff;
    color: #0b1a2b;
    outline: 3px solid #ef4444;
    transform: translateY(-3px);
    box-shadow:0 14px 30px rgba(239,68,68,0.12);
  }
  .size-pill .tick {
    position:absolute; top:-8px; right:-8px; width:18px; height:18px;
    background:#ef4444; color:#fff; border-radius:3px; font-size:11px;
    display:flex; align-items:center; justify-content:center; font-weight:800;
    box-shadow:0 6px 16px rgba(239,68,68,0.16);
    display:none;
  }
  .size-pill.selected .tick{ display:flex; }

  .size-pill.disabled {
    opacity:0.35; pointer-events:none;
  }
  .size-pill.disabled::after {
    content: "✕"; position:absolute; font-size:20px; color:#d1d5db; right:6px; bottom:6px;
  }

  .qty-group { display:flex; align-items:center; gap:8px; }
  .qty-btn { width:38px; height:38px; border-radius:8px; border:1px solid #e6eef9; background:#fff; display:flex;align-items:center;justify-content:center; cursor:pointer; font-weight:700; }
  .qty-input { width:70px; text-align:center; border-radius:8px; border:1px solid #e6eef9; padding:8px; }

  .btn-add-danger {
    background: linear-gradient(180deg,#ef4444,#dc2626);
    color:#fff; border:none; padding:14px 20px; border-radius:8px; font-weight:800;
    font-size:1rem; text-align:center; box-shadow: 0 14px 30px rgba(220,38,38,0.2);
  }

  .btn-buy-subtle {
    background:#fff; border:1px solid #e6eef9; color:#0b1a2b; padding:12px 14px; border-radius:8px; font-weight:700;
  }

  .qv-thumbs img{ width:64px; height:64px; object-fit:cover; border-radius:6px; border:1px solid #f1f5f9; cursor:pointer; margin-right:8px; }
  .qv-thumbs img.active { outline:3px solid rgba(239,68,68,0.12); transform:translateY(-4px); }

  /* Header/nav styles borrowed from category.php look */
  .ae-header { background:#fff; border-bottom:1px solid #eef3f8; position:sticky; top:0; z-index:1050; }
  .brand { display:flex; align-items:center; gap:12px; text-decoration:none; color:inherit; }
  .nav-center { display:flex; gap:8px; align-items:center; justify-content:center; flex:1; }
  .nav-center .nav-link { color:#333; padding:8px 12px; border-radius:8px; font-weight:600; text-decoration:none; transition:all .15s; }
  .nav-center .nav-link.active, .nav-center .nav-link:hover { color:var(--accent); background:rgba(11,123,220,0.06); }
  .nav-orders{ padding-inline:0.9rem; margin-left:.25rem; border-radius:999px; background:rgba(11,123,220,.06); display:flex; align-items:center; gap:.35rem; text-decoration:none; color:inherit; }
  .nav-orders:hover{ background:rgba(11,123,220,.12); color:var(--accent); }
  @media (max-width:991px){ .nav-center{ display:none; } .search-input{ display:none; } }
  </style>
</head>
<body>

<!-- NAV / HEADER (updated to category.php style) -->
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
      <a class="nav-link" href="about.php">Giới thiệu</a>
      <a class="nav-link" href="contact.php">Liên hệ</a>
    </nav>

    <div class="ms-auto d-flex align-items-center gap-2">
      <form class="d-none d-lg-flex" action="sanpham.php" method="get" role="search">
        <div class="input-group input-group-sm shadow-sm" style="border-radius:10px; overflow:hidden;">
          <input name="q" class="form-control form-control-sm search-input" placeholder="Tìm sản phẩm, mã..." value="<?= esc($_GET['q'] ?? '') ?>">
          <button class="btn btn-dark btn-sm" type="submit"><i class="bi bi-search"></i></button>
        </div>
      </form>

      <div class="dropdown">
        <a class="text-decoration-none d-flex align-items-center" href="#" data-bs-toggle="dropdown" aria-expanded="false">
          <div style="width:40px;height:40px;border-radius:8px;background:#f6f8fb;display:flex;align-items:center;justify-content:center;color:#0b1220"><i class="bi bi-person-fill"></i></div>
        </a>
        <ul class="dropdown-menu dropdown-menu-end p-2">
          <?php if(empty($_SESSION['user'])): ?>
            <li><a class="dropdown-item" href="login.php">Đăng nhập</a></li>
            <li><a class="dropdown-item" href="register.php">Tạo tài khoản</a></li>
          <?php else: ?>
            <li><a class="dropdown-item" href="account.php">Tài khoản</a></li>
            <li><a class="dropdown-item" href="orders.php">Đơn hàng</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-danger" href="logout.php">Đăng xuất</a></li>
          <?php endif; ?>
        </ul>
      </div>

      <div class="dropdown">
        <a class="text-decoration-none position-relative d-flex align-items-center" href="#" id="miniCartBtn" data-bs-toggle="dropdown" aria-expanded="false">
          <div style="width:40px;height:40px;border-radius:8px;background:#f6f8fb;display:flex;align-items:center;justify-content:center;color:#0b1220"><i class="bi bi-bag-fill"></i></div>
          <span class="ms-2 d-none d-md-inline small">Giỏ hàng</span>
          <span class="badge bg-danger rounded-pill" style="position:relative;top:-10px;left:-8px"><?= (int)$cart_count ?></span>
        </a>
        <div class="dropdown-menu dropdown-menu-end p-3" aria-labelledby="miniCartBtn" style="min-width:320px;">
          <?php if (empty($_SESSION['cart'])): ?>
            <div class="small text-muted">Bạn chưa có sản phẩm nào trong giỏ.</div>
            <div class="mt-3 d-grid gap-2">
              <a href="sanpham.php" class="btn btn-primary btn-sm">Mua ngay</a>
            </div>
          <?php else: ?>
            <div style="max-height:240px;overflow:auto">
              <?php $total=0; foreach($_SESSION['cart'] as $id=>$item):
                $qty = isset($item['qty']) ? (int)$item['qty'] : (isset($item['sl']) ? (int)$item['sl'] : 1);
                $price = isset($item['price']) ? (float)$item['price'] : (isset($item['gia']) ? (float)$item['gia'] : 0);
                $name = $item['name'] ?? $item['ten'] ?? '';
                $img = $item['img'] ?? $item['hinh'] ?? 'images/placeholder.jpg';
                $img = preg_match('#^https?://#i', $img) ? $img : ltrim($img, '/');
                $subtotal = $qty * $price; $total += $subtotal;
              ?>
                <div class="d-flex gap-2 align-items-center py-2">
                  <img src="<?= esc($img) ?>" style="width:56px;height:56px;object-fit:cover;border-radius:8px" alt="<?= esc($name) ?>">
                  <div class="flex-grow-1"><div class="small fw-semibold mb-1"><?= esc($name) ?></div><div class="small text-muted"><?= $qty ?> x <?= number_format($price,0,',','.') ?> ₫</div></div>
                  <div class="small"><?= number_format($subtotal,0,',','.') ?> ₫</div>
                </div>
              <?php endforeach; ?>
            </div>
            <div class="d-flex justify-content-between align-items-center mt-3"><div class="text-muted small">Tạm tính</div><div class="fw-semibold"><?= number_format($total,0,',','.') ?> ₫</div></div>
            <div class="mt-3 d-grid gap-2"><a href="cart.php" class="btn btn-outline-secondary btn-sm">Giỏ hàng</a><a href="checkout.php" class="btn btn-primary btn-sm">Thanh toán</a></div>
          <?php endif; ?>
        </div>
      </div>

      <button class="btn btn-light d-lg-none ms-2" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileMenu" aria-controls="mobileMenu">
        <i class="bi bi-list"></i>
      </button>
    </div>
  </div>
</header>

<!-- Mobile offcanvas -->
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

<!-- HERO -->
<section class="py-0">
  <div class="container-fluid px-0">
    <div id="heroCarousel" class="carousel slide" data-bs-ride="carousel">
      <div class="carousel-inner">
        <div class="carousel-item active"><img src="<?= esc($banner1) ?>" class="d-block w-100 hero-image" alt="banner1"></div>
        <div class="carousel-item"><img src="<?= esc($banner2) ?>" class="d-block w-100 hero-image" alt="banner2"></div>
        <div class="carousel-item"><img src="<?= esc($banner3) ?>" class="d-block w-100 hero-image" alt="banner3"></div>
      </div>
      <button class="carousel-control-prev" type="button" data-bs-target="#heroCarousel" data-bs-slide="prev"><span class="carousel-control-prev-icon"></span></button>
      <button class="carousel-control-next" type="button" data-bs-target="#heroCarousel" data-bs-slide="next"><span class="carousel-control-next-icon"></span></button>
    </div>
  </div>
</section>

<div class="container my-4">

  <!-- DANH MỤC -->
  <section class="cats-section">
    <div class="cats-head">
      <div class="title"></div>
      <div class="text-muted"><a href="sanpham.php" class="text-muted">Xem tất cả</a></div>
    </div>

    <div class="cats-grid">
      <?php
        if (!empty($priority_names) && is_array($priority_names) && !empty($cats)) {
            $byName = [];
            foreach ($cats as $cat) $byName[trim($cat['ten'] ?? '')] = $cat;
            $ordered = [];
            foreach ($priority_names as $pname) {
                if (isset($byName[$pname])) { $ordered[] = $byName[$pname]; unset($byName[$pname]); }
            }
            foreach ($cats as $cat) {
                $t = trim($cat['ten'] ?? '');
                if (isset($byName[$t])) { $ordered[] = $cat; unset($byName[$t]); }
            }
            $cats = $ordered;
        }

        foreach ($cats as $c):
          $name = trim($c['ten'] ?? '');
          $img = !empty($cat_image_by_name[$name]) ? $cat_image_by_name[$name] : 'images/ae.jpg';
          $link = 'category.php?slug=' . urlencode($c['slug'] ?? '');
          $sub = !empty($c['mo_ta']) ? mb_substr(strip_tags($c['mo_ta']), 0, 80) : '';
      ?>
      <a class="cat-link" href="<?= esc($link) ?>">
        <div class="cat-card-hero" role="button" aria-label="<?= esc($name) ?>">
          <div class="cat-image" style="background-image:url('<?= esc($img) ?>');"></div>

          <div class="cat-ov">
            <div class="d-flex flex-column" style="min-width:0;">
              <div class="cat-name"><?= esc($name) ?></div>
              <?php if ($sub): ?><span class="cat-sub"><?= esc($sub) ?></span><?php endif; ?>
            </div>
            <div class="cat-action" aria-hidden="true"><i class="bi bi-arrow-right"></i></div>
          </div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- SẢN PHẨM KHUYẾN MÃI -->
  <section class="mb-5">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h5 class="mb-0">Sản phẩm khuyến mãi</h5>
      <a href="sale.php" class="text-muted small">Xem thêm</a>
    </div>

    <?php if (!empty($promos)): ?>
      <div class="products-grid">
        <?php foreach ($promos as $p):
          $imgp = getProductImage($conn, $p['id_san_pham']);
          $discount = ($p['gia_cu'] && $p['gia_cu']>$p['gia']) ? round((($p['gia_cu']-$p['gia'])/$p['gia_cu'])*100) : 0;
          $rating = getProductRating($conn, $p['id_san_pham']);
          // product payload for quickview - include sizes (default)
          // add sku, in_stock, brand if available in DB
          $payload = [
            'id'=>$p['id_san_pham'],
            'name'=>$p['ten'],
            'price'=>$p['gia'],
            'gia_raw'=>$p['gia'],
            'mo_ta'=>strip_tags($p['mo_ta'] ?? ''),
            'img'=>$imgp,
            'thumbs'=>[$imgp],
            'rating'=>$rating['avg'],
            'rating_count'=>$rating['count'],
            'sizes'=> ['S','M','L','XL'],
            // best-effort additional fields if exist
            'sku' => $p['ma_san_pham'] ?? ($p['sku'] ?? null),
            'in_stock' => (isset($p['so_luong']) ? ($p['so_luong'] > 0) : true),
            'brand' => $p['thuong_hieu'] ?? ($p['brand'] ?? null),
            'colors' => [] // optionally populate
          ];
        ?>
        <div>
          <div class="card-pro" style="position:relative;">
            <?php if ($discount): ?><div class="badge-discount">-<?= $discount ?>%</div><?php endif; ?>

            <div class="card-media" data-product='<?= htmlspecialchars(json_encode($payload, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>' onclick="window.location.href='sanpham_chitiet.php?id=<?= (int)$p['id_san_pham'] ?>'">
              <img src="<?= esc($imgp) ?>" alt="<?= esc($p['ten']) ?>">
            </div>

            <div class="card-body-pro">
              <div class="pro-title"><?= esc($p['ten']) ?></div>
              <div class="pro-desc"><?= mb_substr(strip_tags($p['mo_ta'] ?? ''), 0, 60) ?><?= (mb_strlen(strip_tags($p['mo_ta'] ?? ''))>60?'...':'') ?></div>

              <div style="display:flex; justify-content:space-between; align-items:center; margin-top:8px;">
                <div>
                  <div class="pro-price"><?= number_format($p['gia'],0,',','.') ?> ₫</div>
                  <?php if ($p['gia_cu'] && $p['gia_cu']>$p['gia']): ?><div class="pro-old"><?= number_format($p['gia_cu'],0,',','.') ?> ₫</div><?php endif; ?>
                </div>
                <div><?= render_rating_number($rating['avg'], $rating['count']) ?></div>
              </div>

              <div class="card-actions">
                <!-- Open quickview instead of direct add -->
                <button type="button" class="btn-add-primary w-100 qv-open" data-product='<?= htmlspecialchars(json_encode($payload, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>'><i class="bi bi-cart-plus"></i> Thêm</button>

                <a href="sanpham_chitiet.php?id=<?= (int)$p['id_san_pham'] ?>" class="btn-outline-ghost w-100 d-inline-flex align-items-center justify-content-center text-decoration-none">
                  <span>Xem</span>
                </a>
              </div>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="alert alert-info">Hiện không có sản phẩm khuyến mãi.</div>
    <?php endif; ?>
  </section>

  <!-- SẢN PHẨM MỚI -->
  <section class="mb-5">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h5 class="mb-0">Sản phẩm mới</h5>
      <a href="sanpham.php" class="text-muted small">Xem thêm</a>
    </div>

    <?php if (!empty($latest)): ?>
      <div class="products-grid">
        <?php foreach ($latest as $p):
          $imgp = getProductImage($conn, $p['id_san_pham']);
          $rating = getProductRating($conn, $p['id_san_pham']);
          $is_new = false;
          if (!empty($p['created_at'])) {
            $ts = strtotime($p['created_at']);
            if ($ts && (time() - $ts) < 60*60*24*30) $is_new = true;
          }
          $payload = [
            'id'=>$p['id_san_pham'],
            'name'=>$p['ten'],
            'price'=>$p['gia'],
            'gia_raw'=>$p['gia'],
            'mo_ta'=>strip_tags($p['mo_ta'] ?? ''),
            'img'=>$imgp,
            'thumbs'=>[$imgp],
            'rating'=>$rating['avg'],
            'rating_count'=>$rating['count'],
            'sizes'=> ['S','M','L','XL'],
            'sku' => $p['ma_san_pham'] ?? ($p['sku'] ?? null),
            'in_stock' => (isset($p['so_luong']) ? ($p['so_luong'] > 0) : true),
            'brand' => $p['thuong_hieu'] ?? ($p['brand'] ?? null),
            'colors' => []
          ];
        ?>
        <div>
          <div class="card-pro" style="position:relative;">
            <?php if ($is_new): ?><div class="badge-new">NEW</div><?php endif; ?>

            <div class="card-media" data-product='<?= htmlspecialchars(json_encode($payload, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>' onclick="window.location.href='sanpham_chitiet.php?id=<?= (int)$p['id_san_pham'] ?>'">
              <img src="<?= esc($imgp) ?>" alt="<?= esc($p['ten']) ?>">
            </div>

            <div class="card-body-pro">
              <div class="pro-title"><?= esc($p['ten']) ?></div>
              <div class="pro-desc"><?= mb_substr(strip_tags($p['mo_ta'] ?? ''), 0, 60) ?><?= (mb_strlen(strip_tags($p['mo_ta'] ?? ''))>60?'...':'') ?></div>

              <div style="display:flex; justify-content:space-between; align-items:center; margin-top:8px;">
                <div class="pro-price"><?= number_format($p['gia'],0,',','.') ?> ₫</div>
                <div><?= render_rating_number($rating['avg'], $rating['count']) ?></div>
              </div>

              <div class="card-actions">
                <button type="button" class="btn-add-primary w-100 qv-open" data-product='<?= htmlspecialchars(json_encode($payload, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>'><i class="bi bi-cart-plus"></i> Thêm</button>

                <a href="sanpham_chitiet.php?id=<?= (int)$p['id_san_pham'] ?>" class="btn-outline-ghost w-100 d-inline-flex align-items-center justify-content-center text-decoration-none">
                  <span>Xem</span>
                </a>
              </div>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="alert alert-info">Chưa có sản phẩm mới.</div>
    <?php endif; ?>
  </section>

  <!-- CONTACT -->
  <section class="py-5">
    <div class="row g-4">
      <div class="col-lg-7">
        <h4>Liên hệ</h4>
        <p class="text-muted">Gửi yêu cầu hoặc đặt câu hỏi — chúng tôi luôn sẵn sàng hỗ trợ bạn.</p>
        <form action="contact.php" method="post" class="row g-3">
          <input type="hidden" name="source" value="index_contact">
          <div class="col-md-6"><input name="ten" class="form-control" placeholder="Họ & tên" required></div>
          <div class="col-md-6"><input name="email" class="form-control" placeholder="Email" type="email" required></div>
          <div class="col-md-6"><input name="dien_thoai" class="form-control" placeholder="Số điện thoại (tuỳ chọn)"></div>
          <div class="col-md-6"><input name="tieu_de" class="form-control" placeholder="Tiêu đề"></div>
          <div class="col-12"><textarea name="noi_dung" class="form-control" rows="6" placeholder="Nội dung..." required></textarea></div>
          <div class="col-12 d-flex gap-2"><button type="submit" class="btn btn-primary">Gửi liên hệ</button><button type="reset" class="btn btn-outline-secondary">Làm lại</button></div>
        </form>
      </div>
      <div class="col-lg-5">
        <div class="p-3 bg-white rounded shadow-sm">
          <h6>Thông tin cửa hàng</h6>
          <p class="mb-1 small fw-semibold"><?= esc(function_exists('site_name') ? site_name($conn) : 'AE Shop') ?></p>
          <p class="small text-muted mb-1">Địa chỉ: 89 Lê Đức Thọ - Nam Từ Liêm - Hà Nội</p>
          <p class="small mb-1">Hotline: <a href="tel:0123456789">0123 456 789</a></p>
          <p class="small mb-2">Email: <a href="mailto:info@example.com">info@example.com</a></p>
          <hr>
          <h6 class="small">Giờ mở cửa</h6>
          <p class="small text-muted mb-2">T2 - T7: 08:30 — 18:00<br>CN: Nghỉ</p>
          <h6 class="small">Các kênh hỗ trợ</h6>
          <p class="small mb-1"><i class="bi bi-telephone"></i> Zalo: <a href="tel:0123456789">0123 456 789</a></p>
          <p class="small mb-1"><i class="bi bi-instagram"></i> <a href="#" target="_blank">@aeshop</a></p>
          <p class="small"><i class="bi bi-facebook"></i> <a href="#" target="_blank">facebook.com/aeshop</a></p>
        </div>
      </div>
    </div>
  </section>

</div>

<!-- QUICKVIEW modal (updated: SKU + STATUS + size pills + add ajax) -->
<div class="modal fade" id="quickViewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-body p-0">
        <div class="row g-0">
          <div class="col-md-6 qv-left">
            <div class="text-center">
              <img id="qv-main-img" src="images/placeholder.jpg" class="img-fluid" style="max-height:520px; object-fit:contain;">
            </div>
            <div class="d-flex qv-thumbs mt-3 px-2" id="qv-thumbs"></div>
          </div>

          <div class="col-md-6 qv-right">
            <div class="d-flex justify-content-between align-items-start">
              <div style="flex:1; min-width:0;">
                <div class="qv-title" id="qv-title">Tên sản phẩm</div>

                <div class="qv-meta">
                  <div class="sku-label">Mã sản phẩm: <span id="qv-sku-text" class="sku-value">-</span></div>
                  <div class="status" id="qv-status" style="color:#16a34a">Còn hàng</div>
                  <div class="brand" id="qv-brand" style="margin-left:6px;">Thương hiệu: <strong> - </strong></div>
                </div>

                <div id="qv-rate" class="mb-2 small text-muted"></div>
              </div>

              <div class="text-end" style="min-width:180px;">
                <div class="qv-price-box">
                  <div class="small text-muted">Giá:</div>
                  <div class="qv-price" id="qv-price">0 ₫</div>
                </div>
              </div>
            </div>

            <div id="qv-short-desc" class="small text-muted mt-2">Mô tả ngắn...</div>

            <hr>

            <div class="mt-2">
              <div class="small mb-2"><strong>Màu sắc:</strong> <span id="qv-color-name" class="small text-primary"> - </span></div>
              <div id="qv-colors" class="mb-3"></div>

              <div class="small mb-2"><strong>Kích thước:</strong></div>
              <div id="qv-sizes" class="mb-3"></div>

              <div class="d-flex align-items-center justify-content-start gap-3 mb-3">
                <div class="qty-group">
                  <button type="button" class="qty-btn" id="qv-dec">−</button>
                  <input type="number" id="qv-qty" class="qty-input" value="1" min="1">
                  <button type="button" class="qty-btn" id="qv-inc">+</button>
                </div>
                <div class="small text-muted">Giao hàng 1-3 ngày • Đổi trả 7 ngày</div>
              </div>

              <div class="d-grid gap-2 mb-3">
                <form id="qv-addform" method="post" action="cart.php" class="d-flex gap-2">
                  <input type="hidden" name="action" value="add">
                  <input type="hidden" name="id" id="qv-id" value="">
                  <input type="hidden" name="qty" id="qv-id-qty" value="1">
                  <input type="hidden" name="size" id="qv-size" value="">
                  <input type="hidden" name="color" id="qv-color" value="">
                  <button id="qv-addbtn" type="submit" class="btn-add-danger w-100">
                    <i class="bi bi-cart-plus me-2"></i> THÊM VÀO GIỎ
                  </button>
                </form>

                <button id="qv-buy" type="button" class="btn-buy-subtle">Mua ngay</button>
              </div>

              <div class="small text-muted">Chia sẻ:</div>
              <div class="mt-2">
                <a class="me-2" href="#"><i class="bi bi-facebook"></i></a>
                <a class="me-2" href="#"><i class="bi bi-messenger"></i></a>
                <a class="me-2" href="#"><i class="bi bi-twitter"></i></a>
                <a class="me-2" href="#"><i class="bi bi-pinterest"></i></a>
              </div>

            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer"><button class="btn btn-light" data-bs-dismiss="modal">Đóng</button></div>
    </div>
  </div>
</div>

<footer class="bg-dark text-white py-4 mt-4">
  <div class="container text-center">
    <small><?= esc(function_exists('site_name') ? site_name($conn) : 'AE Shop') ?> — © <?= date('Y') ?> — Hotline: 0123 456 789</small>
  </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* QuickView: populate modal with SKU & status + size pill behavior + add-to-cart */
function openQuickView(el){
  try {
    const target = el && el.getAttribute && el.getAttribute('data-product') ? el : (el && el.closest ? el.closest('[data-product]') : null);
    const dataAttr = target ? target.getAttribute('data-product') : null;
    const data = dataAttr ? JSON.parse(dataAttr) : null;
    if (!data) { console.warn('No data-product'); return; }

    // Basic populate
    document.getElementById('qv-title').textContent = data.name || '';
    document.getElementById('qv-sku-text').textContent = data.sku || (data.sku_text || ('#' + (data.id||'')));
    // status
    const statusEl = document.getElementById('qv-status');
    const inStock = (data.in_stock === undefined) ? true : !!data.in_stock;
    statusEl.textContent = inStock ? 'Còn hàng' : 'Hết hàng';
    statusEl.style.color = inStock ? '#16a34a' : '#ef4444';

    // brand (optional)
    if (data.brand) {
      document.getElementById('qv-brand').innerHTML = 'Thương hiệu: <strong>' + (data.brand) + '</strong>';
    } else {
      document.getElementById('qv-brand').innerHTML = '';
    }

    // rating
    const qvRateEl = document.getElementById('qv-rate');
    let avg = parseFloat(data.rating || 0), cnt = parseInt(data.rating_count || 0);
    if (cnt>0) qvRateEl.innerHTML = '<strong style="color:#0b7bdc;">' + avg.toFixed(1) + '</strong> ★ <span class="small text-muted">(' + cnt + ')</span>';
    else qvRateEl.innerHTML = '<span class="small text-muted">(Chưa có đánh giá)</span>';

    // desc
    const desc = data.mo_ta || '';
    document.getElementById('qv-short-desc').innerHTML = desc ? desc.replace(/\n/g,'<br>') : '<div class="small text-muted">Không có mô tả chi tiết.</div>';

    // price
    document.getElementById('qv-price').textContent = new Intl.NumberFormat('vi-VN').format(data.price || data.gia_raw || 0) + ' ₫';

    // image + thumbs
    document.getElementById('qv-main-img').src = data.img || 'images/placeholder.jpg';
    const thumbsBox = document.getElementById('qv-thumbs'); thumbsBox.innerHTML = '';
    const thumbs = Array.isArray(data.thumbs) && data.thumbs.length ? data.thumbs : [data.img || 'images/placeholder.jpg'];
    thumbs.forEach((t,i)=>{
      const im = document.createElement('img');
      im.src = t; im.alt = data.name || '';
      if (i===0) im.classList.add('active');
      im.onclick = function(){ document.getElementById('qv-main-img').src = t; document.querySelectorAll('#qv-thumbs img').forEach(x=>x.classList.remove('active')); this.classList.add('active'); };
      thumbsBox.appendChild(im);
    });

    // colors (optional)
    const colorsBox = document.getElementById('qv-colors'); colorsBox.innerHTML = '';
    if (data.colors && data.colors.length){
      document.getElementById('qv-color-name').textContent = data.colors[0].name || '';
      data.colors.forEach(c => {
        const b = document.createElement('button'); b.type='button'; b.className='size-pill';
        b.style.minWidth='44px'; b.textContent = c.name || ''; b.dataset.color = c.value || c.name || '';
        b.onclick = function(){ document.getElementById('qv-color').value = this.dataset.color || ''; document.querySelectorAll('#qv-colors .size-pill').forEach(x=>x.classList.remove('selected')); this.classList.add('selected'); };
        colorsBox.appendChild(b);
      });
    } else {
      document.getElementById('qv-color-name').textContent = '';
    }

    // sizes: create pills; support available boolean per size
    const sizeBox = document.getElementById('qv-sizes'); sizeBox.innerHTML = '';
    const sizes = Array.isArray(data.sizes) ? data.sizes : (data.size_options || ['S','M','L','XL']);
    sizes.forEach((s, idx) => {
      let label = s, available = true;
      if (typeof s === 'object'){ label = s.label || s.size || s.name; available = (s.available === undefined) ? true : !!s.available; }
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'size-pill' + (available ? '' : ' disabled');
      btn.innerHTML = '<span class="size-label">'+label+'</span><span class="tick">✓</span>';
      btn.dataset.size = label;
      if (!available) { btn.setAttribute('aria-disabled','true'); }
      btn.onclick = function(){
        if (!available) return;
        document.querySelectorAll('#qv-sizes .size-pill').forEach(x=>x.classList.remove('selected'));
        this.classList.add('selected');
        document.getElementById('qv-size').value = this.dataset.size || '';
      };
      sizeBox.appendChild(btn);
    });

    // ensure qty default
    document.getElementById('qv-qty').value = 1;
    document.getElementById('qv-id').value = data.id || '';
    document.getElementById('qv-id-qty').value = 1;
    document.getElementById('qv-size').value = '';

    // toggle add button disabled if out of stock
    const addBtn = document.getElementById('qv-addbtn');
    if (!inStock){
      addBtn.disabled = true;
      addBtn.style.opacity = 0.6;
    } else {
      addBtn.disabled = false;
      addBtn.style.opacity = 1;
    }

    // show modal
    const myModal = new bootstrap.Modal(document.getElementById('quickViewModal'));
    myModal.show();
  } catch (err) {
    console.error('openQuickView error', err);
  }
}

/* attach triggers and form handling */
document.addEventListener('DOMContentLoaded', function(){
  // attach click handlers on qv-open buttons
  document.querySelectorAll('.qv-open').forEach(btn=>{
    btn.addEventListener('click', function(e){
      e.preventDefault();
      openQuickView(this);
    });
  });

  // qty buttons
  const dec = document.getElementById('qv-dec'), inc = document.getElementById('qv-inc'), qty = document.getElementById('qv-qty'), qtyHidden = document.getElementById('qv-id-qty');
  if (dec && inc && qty) {
    dec.addEventListener('click', ()=> {
      const v = Math.max(1, parseInt(qty.value||1) - 1);
      qty.value = v; if (qtyHidden) qtyHidden.value = v;
    });
    inc.addEventListener('click', ()=> {
      const v = Math.max(1, parseInt(qty.value||1) + 1);
      qty.value = v; if (qtyHidden) qtyHidden.value = v;
    });
    qty.addEventListener('input', ()=> { const v = Math.max(1, parseInt(qty.value||1) || 1); qty.value = v; if (qtyHidden) qtyHidden.value = v; });
  }

  // ajax add-to-cart
  const qvForm = document.getElementById('qv-addform');
  if (qvForm){
    qvForm.addEventListener('submit', function(ev){
      ev.preventDefault();
      const id = document.getElementById('qv-id').value || '';
      const qtyVal = Math.max(1, parseInt(document.getElementById('qv-qty').value||1));
      const size = document.getElementById('qv-size').value || '';
      if (!id){ alert('Sản phẩm không hợp lệ'); return; }
      // nếu muốn bắt buộc chọn size, bật dòng dưới:
      // if (!size){ alert('Vui lòng chọn kích thước'); return; }

      const fd = new FormData();
      fd.append('action','add');
      fd.append('id', id);
      fd.append('qty', qtyVal);
      if (size) fd.append('size', size);
      fd.append('ajax','1');

      const btn = document.getElementById('qv-addbtn');
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Đang thêm...';

      fetch('cart.php?action=add', { method:'POST', body: fd, credentials:'same-origin' })
        .then(r => r.json ? r.json() : r.text())
        .then(res => {
          btn.disabled = false;
          btn.innerHTML = '<i class="bi bi-cart-plus me-2"></i> THÊM VÀO GIỎ';
          let json = res;
          if (typeof res === 'string'){ try{ json = JSON.parse(res);}catch(e){ json = null; } }
          if (json && json.success){
            // update badge
            if (json.cart && typeof json.cart.items_count !== 'undefined'){
              const badge = document.getElementById('cartBadge');
              if (badge) badge.textContent = json.cart.items_count;
            }
            // toast
            const tmp = document.createElement('div'); tmp.className='position-fixed top-0 end-0 p-3'; tmp.style.zIndex=12000;
            tmp.innerHTML = '<div class="toast align-items-center text-bg-success border-0 show" role="status" aria-live="polite" aria-atomic="true"><div class="d-flex"><div class="toast-body">Đã thêm vào giỏ hàng</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button></div></div>';
            document.body.appendChild(tmp); setTimeout(()=> tmp.remove(), 2300);
            // close modal
            const modalEl = document.getElementById('quickViewModal'); const modalInstance = bootstrap.Modal.getInstance(modalEl);
            if (modalInstance) modalInstance.hide();
          } else {
            alert((json && json.message) ? json.message : 'Lỗi khi thêm vào giỏ hàng');
          }
        }).catch(err=>{
          btn.disabled = false;
          btn.innerHTML = '<i class="bi bi-cart-plus me-2"></i> THÊM VÀO GIỎ';
          alert('Lỗi kết nối. Vui lòng thử lại.');
        });
    });
  }

  // buy now: add and go to checkout
  const buyBtn = document.getElementById('qv-buy');
  if (buyBtn){
    buyBtn.addEventListener('click', function(){
      const id = document.getElementById('qv-id').value || '';
      const qtyVal = Math.max(1, parseInt(document.getElementById('qv-qty').value||1));
      const size = document.getElementById('qv-size').value || '';
      if (!id){ alert('Sản phẩm không hợp lệ'); return; }
      const fd = new FormData();
      fd.append('action','add'); fd.append('id', id); fd.append('qty', qtyVal);
      if (size) fd.append('size', size);
      fetch('cart.php', { method:'POST', body: fd, credentials:'same-origin' })
        .then(()=> { window.location.href = 'checkout.php'; })
        .catch(()=> { window.location.href = 'cart.php'; });
    });
  }
});
</script>

</body>
</html>
