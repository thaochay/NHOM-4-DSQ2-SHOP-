<?php
// sanpham.php - danh sách sản phẩm (AE Shop) - menu 1 dòng, nút thêm đỏ, UI gọn hơn
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inc/helpers.php';

/* fallback helpers nếu inc/helpers.php thiếu */
if (!function_exists('esc')) {
    function esc($v){ return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('price')) {
    function price($v){ return number_format((float)$v,0,',','.') . ' ₫'; }
}

/* getProductImage - ưu tiên ảnh chính hoặc first */
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
    ];
    foreach ($candidates as $c) {
        if (file_exists(__DIR__ . '/' . $c) && @filesize(__DIR__ . '/' . $c) > 0) return $c;
    }
    return ltrim($path, '/') ?: $placeholder;
}

/* --- load categories --- */
try {
    $cats = $conn->query("SELECT * FROM danh_muc WHERE trang_thai=1 ORDER BY thu_tu ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $cats = [];
}

/* --- params: q, cat, page --- */
$q = trim((string)($_GET['q'] ?? ''));
$cat = (int)($_GET['cat'] ?? 0);
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 12;
$offset = ($page - 1) * $per_page;

$where = "WHERE sp.trang_thai = 1";
$params = [];
if ($q !== '') {
    $where .= " AND (sp.ten LIKE :q OR sp.mo_ta LIKE :q OR sp.ma_san_pham LIKE :q)";
    $params[':q'] = '%' . $q . '%';
}
if ($cat > 0) {
    $where .= " AND sp.id_danh_muc = :cat";
    $params[':cat'] = $cat;
}

/* total count */
$countSql = "SELECT COUNT(*) FROM san_pham sp $where";
$countStmt = $conn->prepare($countSql);
$countStmt->execute($params);
$total_items = (int)$countStmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_items / $per_page));

/* fetch products */
$sql = "
  SELECT sp.id_san_pham, sp.ten, sp.gia, sp.gia_cu, sp.so_luong, sp.id_danh_muc, dm.ten AS danh_muc_ten, sp.mo_ta
  FROM san_pham sp
  LEFT JOIN danh_muc dm ON sp.id_danh_muc = dm.id_danh_muc
  $where
  ORDER BY sp.id_san_pham DESC
  LIMIT :limit OFFSET :offset
";
$stmt = $conn->prepare($sql);
foreach ($params as $k=>$v) $stmt->bindValue($k,$v);
$stmt->bindValue(':limit', (int)$per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
$stmt->execute();
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* cart count */
if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) $_SESSION['cart'] = [];
$cart_count = 0;
foreach ($_SESSION['cart'] as $it) {
    $cart_count += isset($it['qty']) ? (int)$it['qty'] : (isset($it['sl']) ? (int)$it['sl'] : 1);
}

/* user */
$user_name = !empty($_SESSION['user']['ten']) ? $_SESSION['user']['ten'] : null;

/* helper */
function buildUrl($overrides = []) {
    $qs = array_merge($_GET, $overrides);
    return basename($_SERVER['PHP_SELF']) . '?' . http_build_query($qs);
}

$site_name = function_exists('site_name') ? site_name($conn) : 'AE Shop';
$accent = '#0b7bdc';
$red = '#dc2626';
?>
<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <title>Sản phẩm — <?= esc($site_name) ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root{ --accent: <?= $accent ?>; --muted:#6c757d; --card-radius:10px; --card-shadow: 0 10px 30px rgba(11,37,74,0.06); --danger: <?= $red ?>; }
    body{ font-family:Inter,system-ui,-apple-system,"Segoe UI",Roboto,Helvetica,Arial; background:#f6fbff; color:#071428; margin:0; font-size:14px; }
    .container-main{ max-width:1200px; margin:0 auto; padding:0 12px; }
    /* header (1-line menu) */
    .ae-header{ background:#fff; border-bottom:1px solid #eef3f8; position:sticky; top:0; z-index:1100; padding:10px 0; }
    .brand-mark{ width:44px;height:44px;border-radius:10px;background:var(--accent);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:16px; }
    .nav-center{ display:flex; gap:6px; align-items:center; justify-content:center; flex:1; white-space:nowrap; }
    .nav-link{ color:#1f2937; padding:6px 10px; border-radius:8px; text-decoration:none; font-weight:600; font-size:14px; }
    .nav-link:hover, .nav-link.active{ color:var(--accent); background:rgba(11,123,220,0.06); }

    /* compact layout */
    .page-wrap{ padding:18px 0; }
    .page-head{ display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:12px; }
    .page-head h3{ margin:0; font-size:1.05rem; font-weight:800; color:#07203a; }

    aside .filter-card{ background:#fff; padding:12px; border-radius:var(--card-radius); box-shadow:var(--card-shadow); border:1px solid rgba(11,37,74,0.03); font-size:13px; }
    @media(min-width:992px){ aside .filter-card{ position:sticky; top:80px; } }

    /* tighter grid */
    .products-grid{ display:grid; grid-template-columns: repeat(2,1fr); gap:12px; }
    @media(min-width:576px){ .products-grid{ grid-template-columns: repeat(3,1fr); } }
    @media(min-width:992px){ .products-grid{ grid-template-columns: repeat(4,1fr); } }

    .card-product{ background:#fff; border-radius:12px; overflow:hidden; display:flex; flex-direction:column; height:100%; transition:transform .12s ease, box-shadow .12s ease; border:1px solid rgba(11,37,74,0.03); }
    .card-product:hover{ transform:translateY(-6px); box-shadow:var(--card-shadow); }
    .card-media{ padding:12px; display:flex; align-items:center; justify-content:center; min-height:170px; background:linear-gradient(180deg,#fff,#fbfdff); }
    .card-media img{ width:100%; max-height:170px; object-fit:contain; display:block; }

    .card-body{ padding:10px 12px 12px; display:flex; flex-direction:column; gap:6px; flex-grow:1; font-size:13px; }
    .product-title{ font-size:0.95rem; font-weight:700; color:#071428; line-height:1.2; min-height:2.2em; overflow:hidden; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; text-decoration:none; }
    .product-meta{ display:flex; align-items:center; gap:8px; justify-content:space-between; margin-top:2px; }
    .price-current{ font-weight:800; color:var(--accent); font-size:0.97rem; }
    .price-old{ color:var(--muted); text-decoration:line-through; font-size:0.85rem; }

    .product-ctas{ display:flex; gap:8px; margin-top:auto; align-items:center; }
    .btn-view{ border:1px solid rgba(11,37,74,0.06); background:#fff; color:var(--accent); padding:8px 10px; border-radius:8px; width:48%; display:flex; align-items:center; justify-content:center; gap:6px; text-decoration:none; font-size:13px; }
    .btn-buy{ background:var(--danger); border:0; color:#fff; padding:8px 10px; border-radius:8px; width:52%; display:flex; align-items:center; justify-content:center; gap:6px; font-size:13px; box-shadow: 0 6px 18px rgba(220,38,38,0.12); }

    .badge-discount{ position:absolute; left:10px; top:10px; background:#ef4444;color:#fff;padding:5px 7px;border-radius:8px;font-weight:700; font-size:12px; box-shadow:0 8px 18px rgba(14,20,30,0.12); }

    .small-muted{ color:var(--muted); font-size:12.5px; }

    .pagination .page-link{ color:#0b1724; font-size:13px; padding:6px 9px; }
    .pagination .page-item.active .page-link{ background:var(--accent); border-color:var(--accent); color:#fff; }

    @media(max-width:991px){
      .nav-center{ display:none; }
      .search-inline{ display:none; }
      .brand-mark{ width:40px;height:40px; }
    }
  </style>
</head>
<body>

<!-- HEADER -->
<header class="ae-header">
  <div class="container container-main d-flex align-items-center gap-3">
    <a class="d-flex align-items-center gap-2 text-decoration-none" href="index.php" aria-label="<?= esc($site_name) ?>">
      <div class="brand-mark"><?= strtoupper(substr(preg_replace('/\s+/', '', strip_tags($site_name)),0,3)) ?></div>
      <div class="d-none d-md-block">
        <div style="font-weight:700; font-size:15px;"><?= esc($site_name) ?></div>
      </div>
    </a>

    <nav class="nav-center" role="navigation" aria-label="Main menu">
      <a class="nav-link" href="index.php">Trang chủ</a>

      <div class="dropdown">
        <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown" aria-expanded="false">Danh mục</a>
        <ul class="dropdown-menu p-1">
          <?php if (!empty($cats)): foreach($cats as $c): ?>
            <li><a class="dropdown-item small" href="category.php?slug=<?= urlencode($c['slug']) ?>"><?= esc($c['ten']) ?></a></li>
          <?php endforeach; else: ?>
            <li><span class="dropdown-item text-muted small">Chưa có danh mục</span></li>
          <?php endif; ?>
          <li><hr class="dropdown-divider my-1"></li>
          <li><a class="dropdown-item text-muted small" href="sanpham.php">Xem tất cả</a></li>
        </ul>
      </div>

      <a class="nav-link active" href="sanpham.php">Sản phẩm</a>
      <a class="nav-link" href="about.php">Giới thiệu</a>
      <a class="nav-link" href="contact.php">Liên hệ</a>
    </nav>

    <div class="ms-auto d-flex align-items-center gap-2">
      <form class="d-none d-lg-flex search-inline" action="sanpham.php" method="get" role="search">
        <div class="input-group shadow-sm" style="border-radius:8px; overflow:hidden;">
          <input name="q" class="form-control form-control-sm" placeholder="Tìm sản phẩm, mã..." value="<?= esc($_GET['q'] ?? '') ?>">
          <button class="btn btn-light btn-sm" type="submit"><i class="bi bi-search"></i></button>
        </div>
      </form>

      <div class="dropdown">
        <a class="text-decoration-none d-flex align-items-center" href="#" data-bs-toggle="dropdown" aria-expanded="false" style="gap:8px;">
          <div style="width:36px;height:36px;border-radius:8px;background:#f6f8fb;display:flex;align-items:center;justify-content:center;color:#0b1220"><i class="bi bi-person-fill"></i></div>
          <div class="d-none d-md-block small-muted"><?= $user_name ? esc($user_name) : 'Tài khoản' ?></div>
        </a>
        <ul class="dropdown-menu dropdown-menu-end p-1">
          <?php if(empty($_SESSION['user'])): ?>
            <li><a class="dropdown-item small" href="login.php">Đăng nhập</a></li>
            <li><a class="dropdown-item small" href="register.php">Tạo tài khoản</a></li>
          <?php else: ?>
            <li><a class="dropdown-item small" href="account.php">Tài khoản</a></li>
            <li><a class="dropdown-item small" href="orders.php">Đơn hàng</a></li>
            <li><hr class="dropdown-divider my-1"></li>
            <li><a class="dropdown-item small text-danger" href="logout.php">Đăng xuất</a></li>
          <?php endif; ?>
        </ul>
      </div>

      <div class="dropdown">
        <a class="text-decoration-none position-relative d-flex align-items-center" href="#" id="miniCartBtn" data-bs-toggle="dropdown" aria-expanded="false" style="gap:8px;">
          <div style="width:36px;height:36px;border-radius:8px;background:#f6f8fb;display:flex;align-items:center;justify-content:center;color:#0b1220"><i class="bi bi-bag-fill"></i></div>
          <span class="d-none d-md-inline small-muted">Giỏ hàng</span>
          <span class="badge bg-danger rounded-pill" style="position:relative;top:-6px;left:-6px;font-size:12px;padding:4px 7px;"><?= (int)$cart_count ?></span>
        </a>
        <div class="dropdown-menu dropdown-menu-end p-2" aria-labelledby="miniCartBtn" style="min-width:320px;">
          <?php if (empty($_SESSION['cart'])): ?>
            <div class="small text-muted">Bạn chưa có sản phẩm nào trong giỏ.</div>
            <div class="mt-2 d-grid gap-2">
              <a href="sanpham.php" class="btn btn-primary btn-sm">Mua ngay</a>
            </div>
          <?php else: ?>
            <div style="max-height:220px;overflow:auto" id="cartDropdownItems">
              <?php $total=0; foreach($_SESSION['cart'] as $id=>$item):
                $qty = isset($item['qty']) ? (int)$item['qty'] : (isset($item['sl']) ? (int)$item['sl'] : 1);
                $price = isset($item['price']) ? (float)$item['price'] : (isset($item['gia']) ? (float)$item['gia'] : 0);
                $name = $item['name'] ?? $item['ten'] ?? '';
                $img = $item['img'] ?? $item['hinh'] ?? 'images/placeholder.jpg';
                $img = preg_match('#^https?://#i', $img) ? $img : ltrim($img, '/');
                $subtotal = $qty * $price; $total += $subtotal;
              ?>
                <div class="d-flex gap-2 align-items-center py-2">
                  <img src="<?= esc($img) ?>" style="width:48px;height:48px;object-fit:cover;border-radius:6px" alt="<?= esc($name) ?>">
                  <div class="flex-grow-1"><div class="small fw-semibold mb-1"><?= esc($name) ?></div><div class="small text-muted"><?= $qty ?> x <?= number_format($price,0,',','.') ?> ₫</div></div>
                  <div class="small"><?= number_format($subtotal,0,',','.') ?> ₫</div>
                </div>
              <?php endforeach; ?>
            </div>
            <div class="d-flex justify-content-between align-items-center mt-2"><div class="text-muted small">Tạm tính</div><div class="fw-semibold" id="cartDropdownSubtotal"><?= number_format($total,0,',','.') ?> ₫</div></div>
            <div class="mt-2 d-grid gap-2"><a href="cart.php" class="btn btn-outline-secondary btn-sm">Giỏ hàng</a><a href="checkout.php" class="btn btn-primary btn-sm">Thanh toán</a></div>
          <?php endif; ?>
        </div>
      </div>

      <button class="btn btn-light d-lg-none ms-2" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileMenu" aria-controls="mobileMenu">
        <i class="bi bi-list"></i>
      </button>
    </div>
  </div>
</header>

<!-- Mobile offcanvas menu -->
<div class="offcanvas offcanvas-start" tabindex="-1" id="mobileMenu" aria-labelledby="mobileMenuLabel">
  <div class="offcanvas-header">
    <h5 id="mobileMenuLabel"><?= esc($site_name) ?></h5>
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
      <?php foreach($cats as $c): ?>
        <li class="mb-2 ps-2"><a href="category.php?slug=<?= urlencode($c['slug']) ?>" class="text-decoration-none"><?= esc($c['ten']) ?></a></li>
      <?php endforeach; ?>
      <li class="mb-2"><a href="about.php" class="text-decoration-none">Giới thiệu</a></li>
      <li class="mb-2"><a href="contact.php" class="text-decoration-none">Liên hệ</a></li>
    </ul>
  </div>
</div>

<!-- PAGE -->
<main class="page-wrap">
  <div class="container container-main">
    <div class="page-head">
      <div>
        <h3>Sản phẩm</h3>
        <?php if ($q): ?><div class="small-muted">Kết quả tìm kiếm: "<?= esc($q) ?>"</div><?php endif; ?>
      </div>
      <div class="d-flex gap-2 align-items-center">
        <a href="index.php" class="btn btn-link p-0">&larr; Trang chủ</a>
        <button class="btn btn-outline-secondary d-lg-none btn-sm" data-bs-toggle="offcanvas" data-bs-target="#mobileFilters">Bộ lọc</button>
      </div>
    </div>

    <div class="row g-2">
      <!-- SIDEBAR -->
      <aside class="col-lg-3 d-none d-lg-block">
        <div class="filter-card">
          <form method="get" action="sanpham.php" class="mb-2">
            <label class="form-label small">Tìm kiếm</label>
            <input name="q" value="<?= esc($q) ?>" class="form-control form-control-sm mb-2" placeholder="Tên, mã...">

            <label class="form-label small">Danh mục</label>
            <select name="cat" class="form-select form-select-sm mb-2">
              <option value="0">Tất cả</option>
              <?php foreach ($cats as $c): ?>
                <option value="<?= (int)$c['id_danh_muc'] ?>" <?= $cat === (int)$c['id_danh_muc'] ? 'selected' : '' ?>><?= esc($c['ten']) ?></option>
              <?php endforeach; ?>
            </select>

            <div class="d-grid">
              <button class="btn btn-primary btn-sm">Lọc</button>
            </div>
          </form>

          <hr>
          <div class="small-muted">Hiển thị <strong><?= count($products) ?></strong> / <?= $total_items ?> sản phẩm</div>
        </div>
      </aside>

      <!-- mobile filters offcanvas -->
      <div class="offcanvas offcanvas-start" tabindex="-1" id="mobileFilters" aria-labelledby="mobileFiltersLabel">
        <div class="offcanvas-header">
          <h5 id="mobileFiltersLabel">Bộ lọc</h5>
          <button type="button" class="btn-close text-reset" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body">
          <form method="get" action="sanpham.php">
            <div class="mb-2">
              <label class="form-label small">Tìm kiếm</label>
              <input name="q" value="<?= esc($q) ?>" class="form-control form-control-sm mb-2" placeholder="Tên, mã...">
            </div>
            <div class="mb-2">
              <label class="form-label small">Danh mục</label>
              <select name="cat" class="form-select form-select-sm mb-2">
                <option value="0">Tất cả</option>
                <?php foreach ($cats as $c): ?>
                  <option value="<?= (int)$c['id_danh_muc'] ?>" <?= $cat === (int)$c['id_danh_muc'] ? 'selected' : '' ?>><?= esc($c['ten']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="d-grid">
              <button class="btn btn-primary btn-sm">Lọc</button>
            </div>
          </form>
        </div>
      </div>

      <!-- PRODUCTS -->
      <section class="col-12 col-lg-9">
        <div class="products-grid">
          <?php if (empty($products)): ?>
            <div class="col-12"><div class="alert alert-info py-2">Không tìm thấy sản phẩm.</div></div>
          <?php endif; ?>

          <?php foreach ($products as $p):
            $pid = (int)$p['id_san_pham'];
            $img = getProductImage($conn, $pid);
            $name = $p['ten'];
            $price = (float)$p['gia'];
            $old = !empty($p['gia_cu']) ? (float)$p['gia_cu'] : 0;
            $detailUrl = 'sanpham_chitiet.php?id=' . $pid;
            $stock = (int)($p['so_luong'] ?? 0);
            $discount = ($old && $old > $price) ? (int)round((($old - $price)/$old)*100) : 0;
            $short = mb_substr(strip_tags($p['mo_ta'] ?? ''),0,100);
          ?>
          <article class="card-product position-relative" aria-label="<?= esc($name) ?>">
            <?php if ($discount>0): ?><div class="badge-discount">-<?= $discount ?>%</div><?php endif; ?>
            <div class="card-media">
              <a href="<?= esc($detailUrl) ?>" class="d-block" style="width:100%;height:100%;">
                <img src="<?= esc($img) ?>" alt="<?= esc($name) ?>">
              </a>
            </div>

            <div class="card-body">
              <?php if (!empty($p['danh_muc_ten'])): ?><div class="small-muted"><?= esc($p['danh_muc_ten']) ?></div><?php endif; ?>
              <a href="<?= esc($detailUrl) ?>" class="text-decoration-none"><div class="product-title"><?= esc($name) ?></div></a>

              <div class="product-meta">
                <div>
                  <div class="price-current"><?= price($price) ?></div>
                  <?php if ($old && $old > $price): ?><div class="price-old"><?= number_format($old,0,',','.') ?> ₫</div><?php endif; ?>
                </div>
                <div class="small-muted">Còn <?= $stock ?> sp</div>
              </div>

              <div class="small-muted"><?= esc($short) ?></div>

              <div class="product-ctas">
                <a href="<?= esc($detailUrl) ?>" class="btn-view" aria-label="Xem <?= esc($name) ?>"><i class="bi bi-eye"></i> Xem</a>

                <form method="post" action="cart.php?action=add" class="m-0 w-100" onsubmit="return ajaxAddToCart(event, this)" aria-label="Thêm <?= esc($name) ?> vào giỏ">
                  <input type="hidden" name="id" value="<?= $pid ?>">
                  <input type="hidden" name="qty" value="1">
                  <button type="submit" class="btn-buy"><i class="bi bi-cart-plus"></i> Thêm</button>
                </form>
              </div>
            </div>
          </article>
          <?php endforeach; ?>
        </div>

        <!-- pagination -->
        <nav class="mt-3" aria-label="Trang sản phẩm">
          <ul class="pagination justify-content-center">
            <?php
              $start = max(1, $page - 3);
              $end = min($total_pages, $page + 3);
              if ($page > 1) echo '<li class="page-item"><a class="page-link" href="'. buildUrl(['page'=>$page-1]) .'">&laquo;</a></li>';
              else echo '<li class="page-item disabled"><span class="page-link">&laquo;</span></li>';
              for ($i=$start;$i<=$end;$i++){
                if ($i==$page) echo '<li class="page-item active"><span class="page-link">'.$i.'</span></li>';
                else echo '<li class="page-item"><a class="page-link" href="'. buildUrl(['page'=>$i]) .'">'.$i.'</a></li>';
              }
              if ($page < $total_pages) echo '<li class="page-item"><a class="page-link" href="'. buildUrl(['page'=>$page+1]) .'">&raquo;</a></li>';
              else echo '<li class="page-item disabled"><span class="page-link">&raquo;</span></li>';
            ?>
          </ul>
        </nav>
      </section>
    </div>
  </div>
</main>

<footer class="bg-white py-3 mt-4 border-top">
  <div class="container container-main text-center small text-muted"><?= esc($site_name) ?> — © <?= date('Y') ?></div>
</footer>

<!-- scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// AJAX add-to-cart helper (expects cart.php to return JSON { success:true, cart:{ items_count, subtotal, items: [...] } } )
async function ajaxAddToCart(evt, form){
  try {
    evt.preventDefault();
    const fd = new FormData(form);
    fd.append('ajax','1');
    const actionUrl = form.getAttribute('action') || 'cart.php';
    const res = await fetch(actionUrl, { method:'POST', body:fd, credentials:'same-origin', headers: {'X-Requested-With':'XMLHttpRequest'} });
    if (!res.ok) { form.submit(); return false; }
    const data = await res.json();
    if (data && data.success) {
      const btn = form.querySelector('button[type="submit"]');
      if (btn) {
        const old = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check-lg"></i> Đã thêm';
        btn.disabled = true;
        setTimeout(()=>{ btn.innerHTML = old; btn.disabled = false; }, 900);
      }
      // update cart badges/subtotal
      if (data.cart && typeof data.cart.items_count !== 'undefined') {
        document.querySelectorAll('.badge.bg-danger').forEach(b => b.textContent = data.cart.items_count);
      }
      if (data.cart && typeof data.cart.subtotal !== 'undefined') {
        const elem = document.getElementById('cartDropdownSubtotal');
        if (elem) elem.textContent = Number(data.cart.subtotal).toLocaleString('vi-VN') + ' ₫';
      }
      // update dropdown items if returned
      if (data.cart && Array.isArray(data.cart.items) && document.getElementById('cartDropdownItems')) {
        const wrap = document.getElementById('cartDropdownItems');
        let html = '';
        data.cart.items.forEach(it => {
          const img = it.img ? it.img : 'images/placeholder.jpg';
          const name = it.name ? it.name : ('Sản phẩm #' + (it.id ?? ''));
          const qty = it.qty ?? 1;
          const price = Number(it.price ?? 0);
          html += `<div class="d-flex gap-2 align-items-center py-2">
              <img src="${img}" style="width:48px;height:48px;object-fit:cover;border-radius:6px" alt="">
              <div class="flex-grow-1"><div class="small fw-semibold mb-1">${name}</div><div class="small text-muted">${qty} x ${price.toLocaleString('vi-VN')} ₫</div></div>
              <div class="small">${(qty * price).toLocaleString('vi-VN')} ₫</div>
            </div>`;
        });
        wrap.innerHTML = html;
      }
      return false;
    } else {
      form.submit();
      return true;
    }
  } catch (err) {
    console.error(err);
    form.submit();
    return true;
  }
}
</script>
</body>
</html>
