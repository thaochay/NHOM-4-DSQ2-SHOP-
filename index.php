<?php
// index.php - Trang chủ (sửa, gọn & ổn định)
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

/**
 * getProductImage - lấy ảnh sản phẩm (ưu tiên la_anh_chinh, nếu không -> first)
 * trả về path relative (không in debug).
 */
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

    // absolute URL?
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
        if (file_exists(__DIR__ . '/' . $c) && filesize(__DIR__ . '/' . $c) > 0) {
            return $c;
        }
    }
    // fallback
    return $placeholder;
}

/**
 * getBannerImage - trả về banner path (thử nhiều candidate)
 */
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
        if (file_exists(__DIR__ . '/' . $c) && filesize(__DIR__ . '/' . $c) > 0) {
            return $c;
        }
    }
    return $placeholder;
}

/* --- load data --- */
try {
    $cats = $conn->query("SELECT * FROM danh_muc WHERE trang_thai=1 ORDER BY thu_tu ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $cats = []; }

try {
    $promos = $conn->query("SELECT * FROM san_pham WHERE trang_thai=1 AND gia_cu IS NOT NULL AND gia_cu>gia ORDER BY created_at DESC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $promos = []; }

try {
    $latest = $conn->query("SELECT * FROM san_pham WHERE trang_thai=1 ORDER BY created_at DESC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $latest = []; }

/* cart count */
if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) $_SESSION['cart'] = [];
$cart_count = 0;
foreach ($_SESSION['cart'] as $it) {
    $cart_count += isset($it['qty']) ? (int)$it['qty'] : (isset($it['sl']) ? (int)$it['sl'] : 1);
}

$user_name = !empty($_SESSION['user']['ten']) ? $_SESSION['user']['ten'] : null;

/* banners */
$banner1 = getBannerImage('ae1.png');
$banner2 = getBannerImage('anh2.jpg');
$banner3 = getBannerImage('anh3.jpg');
?>
<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <title><?= esc(site_name($conn)) ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <link href="assets/css/style.css" rel="stylesheet">
  <style>
  :root{
    --accent:#0b7bdc;
    --muted:#6c757d;
    --nav-bg:#ffffff;
  }

  body{background:#f8fbff}

  .ae-navbar{
    background:var(--nav-bg);
    box-shadow:0 6px 18px rgba(11,38,80,0.04);
    backdrop-filter:blur(12px);
  }
  .ae-logo-mark{
    width:46px;height:46px;border-radius:10px;
    background:var(--accent);color:#fff;
    display:flex;align-items:center;justify-content:center;
    font-weight:800;
  }
  

  /* MENU CHÍNH ĐẸP HƠN */
  .navbar-nav .nav-item + .nav-item{
    margin-left:.25rem;
  }
  .ae-navbar .nav-link{
    position:relative;
    padding:0.75rem 1rem;
    font-weight:500;
    font-size:.95rem;
    color:#1f2933;
    transition:color .18s ease-out;
  }
  .ae-navbar .nav-link::after{
    content:'';
    position:absolute;
    left:1rem;
    right:1rem;
    bottom:0.35rem;
    height:2px;
    border-radius:99px;
    background:linear-gradient(90deg,#0b7bdc,#38bdf8);
    transform:scaleX(0);
    transform-origin:center;
    transition:transform .18s ease-out,opacity .18s ease-out;
    opacity:0;
  }
  .ae-navbar .nav-link:hover,
  .ae-navbar .nav-link:focus{
    color:#0b7bdc;
  }
  .ae-navbar .nav-link:hover::after,
  .ae-navbar .nav-link:focus::after,
  .ae-navbar .nav-link.active::after{
    transform:scaleX(1);
    opacity:1;
  }

  /* MENU "ĐƠN HÀNG CỦA TÔI" NỔI BẬT */
  .nav-orders{
    padding-inline:0.9rem;
    margin-left:.25rem;
    border-radius:999px;
    background:rgba(11,123,220,.06);
    display:flex;
    align-items:center;
    gap:.35rem;
  }
  .nav-orders i{
    font-size:1rem;
  }
  .nav-orders:hover{
    background:rgba(11,123,220,.12);
    color:#0b7bdc;
  }

  /* CARD SP */
  .product-card{
    border-radius:12px;
    background:linear-gradient(180deg,#fff,#f6fbff);
    border:1px solid rgba(11,38,80,0.04);
    transition:transform .14s, box-shadow .14s;
  }
  .product-card:hover{
    transform:translateY(-8px);
    box-shadow:0 24px 60px rgba(11,38,80,0.06);
  }

  /* Vùng ảnh có hover click QuickView */
  .qv-clickable{
    cursor:pointer;
    border-radius:14px;
    background:#ffffff;
    transition:transform .15s, box-shadow .15s;
  }
  .qv-clickable:hover{
    transform:translateY(-3px);
    box-shadow:0 14px 40px rgba(15,23,42,0.12);
  }

  .sale-badge{padding:6px 8px;border-radius:10px}
  .price-new{font-weight:800;color:var(--accent);font-size:1.05rem}
  .price-old{color:#9aa8c2;text-decoration:line-through}

  .qv-thumb{
    width:70px;height:70px;object-fit:cover;
    border-radius:8px;cursor:pointer;
    border:2px solid transparent;
  }
  .qv-thumb.active{
    border-color:var(--accent);
    box-shadow:0 8px 20px rgba(11,38,80,0.06);
  }
  .swatch{
    width:28px;height:28px;border-radius:50%;
    display:inline-block;border:2px solid #fff;
    box-shadow:0 2px 6px rgba(11,38,80,0.06);
    margin-right:6px;cursor:pointer;
  }
  .size-btn{
    border-radius:8px;padding:6px 10px;
    border:1px solid #e6eefb; background:#fff;
    cursor:pointer;margin-right:6px;
  }
  .size-btn.active{
    border-color:var(--accent);
    box-shadow:0 4px 12px rgba(15,23,42,0.12);
  }
  .add-anim{ transition:transform .18s; }

  .about-block{
    background:linear-gradient(135deg,#ffffff,#f3f8ff);
    padding:48px 0;
  }
  .about-card{
    background:#fff; border-radius:12px;
    padding:28px;
    box-shadow:0 10px 30px rgba(11,38,80,0.04);
  }

  /* QUICKVIEW GIAO DIỆN ĐẸP HƠN */
  #quickViewModal .modal-content{
    border-radius:20px;
    border:none;
    box-shadow:0 22px 80px rgba(15,23,42,0.35);
  }
  #quickViewModal .modal-body{
    background:radial-gradient(circle at top,#f5f9ff,#ffffff);
  }
  #quickViewModal .qv-main{
    border-radius:16px;
    background:#fff;
    box-shadow:0 14px 40px rgba(15,23,42,0.08);
  }
  #quickViewModal h4{
    font-weight:700;
  }
  #quickViewModal .nav-tabs{
    border-bottom:1px solid #e5e7eb;
  }
  #quickViewModal .nav-tabs .nav-link{
    border:none;
    border-bottom:2px solid transparent;
    font-weight:500;
    color:#6b7280;
  }
  #quickViewModal .nav-tabs .nav-link.active{
    color:#111827;
    border-color:#0b7bdc;
  }
  </style>
</head>
<body>

<!-- NAV -->
<nav class="navbar navbar-expand-lg ae-navbar sticky-top">
  <div class="container">
    <a class="navbar-brand d-flex align-items-center gap-2" href="index.php">
      <div class="ae-logo-mark">AE</div>
      <div class="d-none d-md-block">
        <div style="font-weight:800"><?= esc(site_name($conn)) ?></div>
        <div style="font-size:12px;color:var(--muted)">Thời trang nam cao cấp</div>
      </div>
    </a>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain"><span class="navbar-toggler-icon"></span></button>

    <div class="collapse navbar-collapse" id="navMain">
      <ul class="navbar-nav mx-auto mb-2 mb-lg-0">
        <li class="nav-item"><a class="nav-link" href="index.php">Trang chủ</a></li>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">Sản phẩm</a>
          <ul class="dropdown-menu">
            <?php foreach($cats as $c): ?>
              <li><a class="dropdown-item" href="category.php?slug=<?= urlencode($c['slug']) ?>"><?= esc($c['ten']) ?></a></li>
            <?php endforeach; ?>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-muted" href="sanpham.php">Xem tất cả sản phẩm</a></li>
          </ul>
        </li>
        <li class="nav-item"><a class="nav-link" href="about.php">Giới Thiệu</a></li>
        <li class="nav-item"><a class="nav-link" href="contact.php">Liên hệ</a></li>
        <li class="nav-item">
          <a class="nav-link nav-orders" href="orders.php">
            <i class="bi bi-receipt-cutoff"></i>
            <span class="d-none d-lg-inline ms-1">Đơn hàng của tôi</span>
          </a>
        </li>
      </ul>

      <div class="d-flex align-items-center gap-2">
        <form class="d-none d-lg-flex" method="get" action="sanpham.php"><div class="input-group input-group-sm"><input name="q" class="form-control" placeholder="Tìm sản phẩm, mã..." value="<?= esc($_GET['q'] ?? '') ?>"><button class="btn btn-dark" type="submit"><i class="bi bi-search"></i></button></div></form>

        <div class="dropdown">
          <a class="d-flex align-items-center text-decoration-none" href="#" data-bs-toggle="dropdown">
            <div style="width:40px;height:40px;border-radius:8px;background:#f6f8fb;display:flex;align-items:center;justify-content:center;color:var(--accent)"><i class="bi bi-person-fill"></i></div>
            <span class="ms-2 d-none d-md-inline small"><?= $user_name ? esc($user_name) : 'Tài khoản' ?></span>
          </a>
          <ul class="dropdown-menu dropdown-menu-end p-2">
            <?php if(empty($_SESSION['user'])): ?>
              <li><a class="dropdown-item" href="login.php">Đăng nhập</a></li>
              <li><a class="dropdown-item" href="register.php">Tạo tài khoản</a></li>
            <?php else: ?>
              <li><a class="dropdown-item" href="account.php">Tài khoản của tôi</a></li>
              <li><a class="dropdown-item" href="orders.php">Đơn hàng</a></li>
              <li><hr class="dropdown-divider"></li>
              <li><a class="dropdown-item text-danger" href="logout.php">Đăng xuất</a></li>
            <?php endif; ?>
          </ul>
        </div>

        <div class="dropdown">
          <a class="d-flex align-items-center text-decoration-none position-relative" href="#" data-bs-toggle="dropdown">
            <div style="width:40px;height:40px;border-radius:8px;background:#f6f8fb;display:flex;align-items:center;justify-content:center;color:var(--accent)"><i class="bi bi-bag-fill"></i></div>
            <span class="ms-2 d-none d-md-inline small">Giỏ hàng</span>
            <span class="badge bg-danger rounded-pill" style="position:relative;top:-10px;left:-8px"><?= (int)$cart_count ?></span>
          </a>
          <div class="dropdown-menu dropdown-menu-end p-3" style="min-width:320px">
            <div class="d-flex justify-content-between align-items-center mb-2"><strong>Giỏ hàng (<?= (int)$cart_count ?>)</strong><a href="cart.php" class="small">Xem đầy đủ</a></div>
            <?php if (empty($_SESSION['cart'])): ?>
              <div class="text-muted small">Bạn chưa có sản phẩm nào trong giỏ.</div>
              <div class="mt-3"><a href="sanpham.php" class="btn btn-primary btn-sm w-100">Mua ngay</a></div>
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

      </div>
    </div>
  </div>
</nav>

<!-- HERO -->
<section class="py-3">
  <div class="container">
    <div id="heroCarousel" class="carousel slide rounded" data-bs-ride="carousel">
      <div class="carousel-inner">
        <div class="carousel-item active"><img src="<?= esc($banner1) ?>" class="d-block w-100" style="height:420px;object-fit:cover" alt="banner1"></div>
        <div class="carousel-item"><img src="<?= esc($banner2) ?>" class="d-block w-100" style="height:420px;object-fit:cover" alt="banner2"></div>
        <div class="carousel-item"><img src="<?= esc($banner3) ?>" class="d-block w-100" style="height:420px;object-fit:cover" alt="banner3"></div>
      </div>
      <button class="carousel-control-prev" type="button" data-bs-target="#heroCarousel" data-bs-slide="prev"><span class="carousel-control-prev-icon"></span></button>
      <button class="carousel-control-next" type="button" data-bs-target="#heroCarousel" data-bs-slide="next"><span class="carousel-control-next-icon"></span></button>
    </div>
  </div>
</section>

<!-- CATEGORIES -->
<section class="py-4">
  <div class="container">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h5 class="mb-0">Danh mục</h5><a href="sanpham.php" class="text-muted small">Xem tất cả</a>
    </div>
    <div class="row g-3">
      <?php $featuredCats = array_slice($cats,0,6); foreach($featuredCats as $c): ?>
        <div class="col-6 col-sm-4 col-md-2">
          <a href="category.php?slug=<?= urlencode($c['slug']) ?>" class="text-decoration-none">
            <div class="p-3 text-center border rounded bg-white">
              <div class="fw-semibold"><?= esc($c['ten']) ?></div>
            </div>
          </a>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- PROMO -->
<section class="py-4 bg-light">
  <div class="container">
    <div class="d-flex justify-content-between align-items-center mb-3"><h5 class="mb-0">Sản phẩm khuyến mãi</h5><a href="sale.php" class="text-muted small">Xem thêm</a></div>
    <div class="row g-3">
      <?php foreach($promos as $p):
        $imgp = getProductImage($conn, $p['id_san_pham']);
        $discount = ($p['gia_cu'] && $p['gia_cu']>$p['gia']) ? round((($p['gia_cu']-$p['gia'])/$p['gia_cu'])*100) : 0;

        $payloadPromo = [
          'id' => $p['id_san_pham'],
          'name' => $p['ten'],
          'gia_raw' => $p['gia'],
          'price' => $p['gia'],
          'mo_ta' => mb_substr(strip_tags($p['mo_ta']),0,400),
          'img' => $imgp,
          'stock' => (int)$p['so_luong'],
          'thumbs' => [$imgp],
        ];
        $payloadPromoJson = htmlspecialchars(json_encode($payloadPromo, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
      ?>
      <div class="col-6 col-sm-4 col-md-3">
        <div class="product-card h-100 d-flex flex-column">
          <div class="position-relative text-center p-3 qv-clickable"
               data-product="<?= $payloadPromoJson ?>"
               onclick="openQuickView(this)">
            <?php if($discount>0): ?><span class="badge bg-danger sale-badge position-absolute" style="left:12px;top:12px">-<?= $discount ?>%</span><?php endif; ?>
            <img src="<?= esc($imgp) ?>" alt="<?= esc($p['ten']) ?>" style="height:220px;object-fit:contain" class="mx-auto">
          </div>

          <div class="p-3 mt-auto">
            <h6 class="mb-2"><?= esc($p['ten']) ?></h6>
            <div class="d-flex align-items-center mb-3">
              <div>
                <div class="price-new"><?= number_format($p['gia'],0,',','.') ?> ₫</div>
                <?php if($p['gia_cu'] && $p['gia_cu']>$p['gia']): ?><div class="price-old"><?= number_format($p['gia_cu'],0,',','.') ?> ₫</div><?php endif; ?>
              </div>
              <div class="ms-auto small text-muted">Còn <?= (int)$p['so_luong'] ?> sp</div>
            </div>

            <div class="d-flex gap-2">
              <button type="button"
                      class="btn btn-outline-primary w-50 add-anim"
                      data-product="<?= $payloadPromoJson ?>"
                      onclick="openQuickView(this)">
                <i class="bi bi-eye"></i> Xem
              </button>

              <form method="post" action="cart.php" class="w-50">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="id" value="<?= $p['id_san_pham'] ?>">
                <button class="btn btn-success w-100 add-anim"><i class="bi bi-cart-plus"></i> Thêm</button>
              </form>
            </div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- LATEST -->
<section class="py-4">
  <div class="container">
    <div class="d-flex justify-content-between align-items-center mb-3"><h5 class="mb-0">Sản phẩm mới</h5><a href="sanpham.php" class="text-muted small">Xem thêm</a></div>
    <div class="row g-3">
      <?php foreach($latest as $p): $imgp = getProductImage($conn, $p['id_san_pham']);
        $payloadLatest = [
          'id' => $p['id_san_pham'],
          'name' => $p['ten'],
          'gia_raw' => $p['gia'],
          'price' => $p['gia'],
          'mo_ta' => mb_substr(strip_tags($p['mo_ta']),0,400),
          'img' => $imgp,
          'stock' => (int)$p['so_luong'],
          'thumbs' => [$imgp],
        ];
        $payloadLatestJson = htmlspecialchars(json_encode($payloadLatest, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
      ?>
      <div class="col-6 col-sm-4 col-md-3">
        <div class="product-card p-3 text-center h-100">
          <div class="qv-clickable"
               data-product="<?= $payloadLatestJson ?>"
               onclick="openQuickView(this)">
            <img src="<?= esc($imgp) ?>" style="height:200px;object-fit:contain" class="mx-auto" alt="<?= esc($p['ten']) ?>">
          </div>
          <div class="mt-3"><h6><?= esc($p['ten']) ?></h6><div class="d-flex justify-content-between align-items-center mt-2"><div class="fw-semibold"><?= number_format($p['gia'],0,',','.') ?> ₫</div><button type="button" class="btn btn-sm btn-outline-primary add-anim" data-product="<?= $payloadLatestJson ?>" onclick="openQuickView(this)">Xem nhanh</button></div></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ABOUT -->
<section class="about-block">
  <div class="container">
    <div class="about-card">
      <div class="row align-items-center">
        <div class="col-lg-6">
          <h2 class="fw-bold mb-3">Về <?= esc(site_name($conn)) ?></h2>
          <p class="text-muted">
            <?= esc(site_name($conn)) ?> chuyên cung cấp trang phục nam theo phong cách hiện đại — chú trọng chất liệu, kiểu dáng
            và trải nghiệm mua sắm. Chúng tôi chọn lọc sản phẩm kỹ lưỡng, kiểm tra chất lượng và phục vụ khách hàng tận tâm.
          </p>

          <div class="about-feat mt-3">
            <div class="item">
              <div class="fw-semibold">Chất lượng</div>
              <div class="small text-muted">Sản phẩm bền & hợp xu hướng</div>
            </div>
            <div class="item">
              <div class="fw-semibold">Dịch vụ</div>
              <div class="small text-muted">Giao hàng nhanh & đổi trả tiện lợi</div>
            </div>
            <div class="item">
              <div class="fw-semibold">Uy tín</div>
              <div class="small text-muted">Hỗ trợ khách hàng tận tâm</div>
            </div>
          </div>

          <div class="mt-3">
            <a href="about.php" class="btn btn-outline-primary">Tìm hiểu thêm</a>
            <a href="sanpham.php" class="btn btn-primary ms-2">Mua sắm ngay</a>
          </div>
        </div>

        <div class="col-lg-6 text-center">
          <img src="<?= esc(getBannerImage('ae.jpg')) ?>" alt="About banner" class="img-fluid rounded" style="max-height:360px; object-fit:cover;">
        </div>
      </div>
    </div>
  </div>
</section>

<!-- CONTACT -->
<section class="py-5">
  <div class="container">
    <div class="row g-4">
      <div class="col-md-7">
        <h4>Liên hệ</h4>
        <p class="text-muted">Gửi yêu cầu hoặc gọi cho chúng tôi — luôn sẵn sàng hỗ trợ.</p>
        <form action="contact.php" method="get" class="row g-3">
          <div class="col-md-6"><input name="ten" class="form-control" placeholder="Họ & tên" required></div>
          <div class="col-md-6"><input name="email" class="form-control" placeholder="Email" type="email" required></div>
          <div class="col-md-6"><input name="dien_thoai" class="form-control" placeholder="Số điện thoại (tuỳ chọn)"></div>
          <div class="col-md-6"><input name="tieu_de" class="form-control" placeholder="Tiêu đề"></div>
          <div class="col-12"><textarea name="noi_dung" class="form-control" rows="5" placeholder="Nội dung..." required></textarea></div>
          <div class="col-12"><button class="btn btn-primary">Gửi liên hệ</button></div>
        </form>
      </div>
      <div class="col-md-5">
        <div class="p-3 bg-white rounded shadow-sm">
          <h6>Thông tin cửa hàng</h6>
          <p class="mb-1 small"><?= esc(site_name($conn)) ?></p>
          <p class="small text-muted">89 Lê Đức Thọ - Nam Từ Liêm - Hà Nội</p>
          <p class="small">Hotline: <a href="tel:0123456789">0123 456 789</a></p>
          <p class="small">Email: <a href="mailto:info@example.com">info@example.com</a></p>
          <hr>
          <h6 class="small">Giờ mở cửa</h6>
          <p class="small text-muted">T2 - T7: 08:30 — 18:00<br>CN: Nghỉ</p>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- QUICKVIEW modal (giữ giống trước nhưng style đã cải thiện) -->
<div class="modal fade" id="quickViewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-body p-4">
        <div class="row gx-4">
          <div class="col-md-6">
            <div class="qv-main text-center p-3"><img id="qv-main-img" src="images/placeholder.jpg" class="img-fluid" style="max-height:420px;object-fit:contain"></div>
            <div class="d-flex gap-2 mt-3" id="qv-thumbs"></div>
          </div>
          <div class="col-md-6">
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <h4 id="qv-title">Tên sản phẩm</h4>
                <div class="small text-muted" id="qv-sku">Mã: -</div>
                <div class="mt-2" id="qv-rate">★★★★★ <span class="small text-muted">(0 đánh giá)</span></div>
              </div>
              <div class="text-end">
                <div class="h4 text-danger" id="qv-price">0 ₫</div>
                <div class="small text-muted" id="qv-stock">Còn: -</div>
              </div>
            </div>

            <div class="mt-3" id="qv-short-desc">Mô tả ngắn...</div>

            <div class="mt-3">
              <div class="mb-2"><strong>Màu sắc</strong></div>
              <div id="qv-swatches" class="mb-3"></div>

              <div class="mb-2"><strong>Kích thước</strong></div>
              <div id="qv-sizes" class="mb-3"></div>

              <div class="mb-3 d-flex align-items-center gap-3">
                <div>
                  <label class="form-label small mb-1">Số lượng</label>
                  <input id="qv-qty" type="number" class="form-control form-control-sm" value="1" min="1" style="width:100px">
                </div>
                <div class="flex-grow-1 text-muted small">Giao hàng nhanh trong 1-3 ngày, đổi trả 7 ngày.</div>
              </div>

              <div class="d-flex gap-2 mb-2">
                <form id="qv-addform" method="post" action="cart.php" class="d-flex w-100">
                  <input type="hidden" name="action" value="add">
                  <input type="hidden" name="id" id="qv-id" value="">
                  <input type="hidden" name="qty" id="qv-id-qty" value="1">
                  <button type="submit" class="btn btn-success w-100 add-anim"><i class="bi bi-cart-plus"></i> Thêm vào giỏ</button>
                </form>
                <a id="qv-buy" href="#" class="btn btn-outline-primary">Mua ngay</a>
              </div>

              <ul class="nav nav-tabs mt-3" id="qvTab" role="tablist">
                <li class="nav-item" role="presentation"><button class="nav-link active" id="desc-tab" data-bs-toggle="tab" data-bs-target="#desc" type="button">Mô tả</button></li>
                <li class="nav-item" role="presentation"><button class="nav-link" id="spec-tab" data-bs-toggle="tab" data-bs-target="#spec" type="button">Thông số</button></li>
                <li class="nav-item" role="presentation"><button class="nav-link" id="rev-tab" data-bs-toggle="tab" data-bs-target="#rev" type="button">Đánh giá</button></li>
              </ul>
              <div class="tab-content p-3 border rounded-bottom" id="qvTabContent">
                <div class="tab-pane fade show active" id="desc" role="tabpanel"></div>
                <div class="tab-pane fade" id="spec" role="tabpanel"><div class="small text-muted">Chưa có thông số chi tiết.</div></div>
                <div class="tab-pane fade" id="rev" role="tabpanel"><div class="small text-muted">Chưa có đánh giá.</div></div>
              </div>

            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button></div>
    </div>
  </div>
</div>

<footer class="bg-dark text-white py-4 mt-4">
  <div class="container text-center"><small><?= esc(site_name($conn)) ?> — © <?= date('Y') ?> — Hotline: 0123 456 789</small></div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
function openQuickView(btn){
  try {
    const data = JSON.parse(btn.getAttribute('data-product'));
    document.getElementById('qv-title').textContent = data.name || '';
    document.getElementById('qv-short-desc').textContent = data.mo_ta || '';
    document.getElementById('qv-price').textContent = new Intl.NumberFormat('vi-VN').format(data.price || data.gia_raw || 0) + ' ₫';
    document.getElementById('qv-stock').textContent = 'Còn: ' + (data.stock !== undefined ? data.stock : '-');
    document.getElementById('qv-id').value = data.id || '';
    document.getElementById('qv-main-img').src = data.img || 'images/placeholder.jpg';

    const thumbsBox = document.getElementById('qv-thumbs');
    thumbsBox.innerHTML = '';
    let thumbs = Array.isArray(data.thumbs) && data.thumbs.length ? data.thumbs : [data.img || 'images/placeholder.jpg'];
    thumbs.forEach((t, idx) => {
      const im = document.createElement('img');
      im.src = t;
      im.className = 'qv-thumb' + (idx===0 ? ' active' : '');
      im.onclick = function(){ document.getElementById('qv-main-img').src = t; document.querySelectorAll('.qv-thumb').forEach(x=>x.classList.remove('active')); this.classList.add('active'); };
      thumbsBox.appendChild(im);
    });

    const swBox = document.getElementById('qv-swatches'); swBox.innerHTML = '';
    if (Array.isArray(data.colors) && data.colors.length) {
      data.colors.forEach((c) => {
        const el = document.createElement('span'); el.className='swatch'; el.style.background = c; el.onclick = function(){ document.querySelectorAll('.swatch').forEach(s=>s.style.outline=''); this.style.outline='2px solid rgba(0,0,0,0.06)'; }; swBox.appendChild(el);
      });
    } else {
      ['#111','#0b7bdc','#777'].forEach(c => { const el = document.createElement('span'); el.className='swatch'; el.style.background=c; swBox.appendChild(el); });
    }

    const sizeBox = document.getElementById('qv-sizes'); sizeBox.innerHTML = '';
    if (Array.isArray(data.sizes) && data.sizes.length) {
      data.sizes.forEach(sz => { const b=document.createElement('button'); b.className='size-btn'; b.innerText=sz; b.onclick=function(){document.querySelectorAll('.size-btn').forEach(x=>x.classList.remove('active')); this.classList.add('active');}; sizeBox.appendChild(b); });
    } else {
      ['S','M','L','XL'].forEach(sz => { const b=document.createElement('button'); b.className='size-btn'; b.innerText=sz; b.onclick=function(){document.querySelectorAll('.size-btn').forEach(x=>x.classList.remove('active')); this.classList.add('active');}; sizeBox.appendChild(b); });
    }

    const q = document.getElementById('qv-qty'); q.value = 1; document.getElementById('qv-id-qty').value = 1;
    q.oninput = function(){ document.getElementById('qv-id-qty').value = Math.max(1, parseInt(this.value||1)); };

    document.getElementById('desc').innerHTML = data.mo_ta ? data.mo_ta : '<div class="small text-muted">Không có mô tả chi tiết.</div>';
    document.getElementById('spec').innerHTML = data.specs ? data.specs : '<div class="small text-muted">Không có thông số.</div>';
    document.getElementById('rev').innerHTML = '<div class="small text-muted">Chưa có đánh giá.</div>';

    document.getElementById('qv-buy').href = 'product.php?id=' + encodeURIComponent(data.id || '');
    var myModal = new bootstrap.Modal(document.getElementById('quickViewModal'));
    myModal.show();
  } catch(e) {
    console.error('openQuickView error', e);
  }
}

document.addEventListener('click', function(e){
  if (e.target.closest('.add-anim')) {
    const btn = e.target.closest('.add-anim');
    btn.style.transform = 'scale(0.97)';
    setTimeout(()=> btn.style.transform = '', 160);
  }
});
</script>

<script src="assets/js/main.js"></script>
</body>
</html>
