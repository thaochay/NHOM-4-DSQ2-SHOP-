<?php
// sale.php - trang danh sách sản phẩm giảm giá + header menu
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inc/helpers.php';

// cart count
$cart = $_SESSION['cart'] ?? [];
$cart_count = 0;
foreach ($cart as $it) {
    $cart_count += isset($it['qty']) ? (int)$it['qty'] : (isset($it['so_luong']) ? (int)$it['so_luong'] : 1);
}

// helpers
function is_active($file) { return basename($_SERVER['PHP_SELF']) === $file ? 'active' : ''; }

// params
$q = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 12;
$offset = ($page - 1) * $per_page;

// Attempt to query sale products using common sale columns (try-catch to be safe)
$products = [];
$total_items = 0;
$total_pages = 1;

try {
    // Try an SQL that works if columns exist (safe: will throw if column missing)
    $sql = "
      SELECT
        sp.id_san_pham,
        sp.ten,
        sp.gia,
        sp.gia_khuyen_mai,
        sp.giam_gia,
        sp.is_sale,
        dm.ten AS danh_muc_ten,
        (SELECT duong_dan FROM anh_san_pham WHERE id_san_pham = sp.id_san_pham AND la_anh_chinh = 1 LIMIT 1) AS img
      FROM san_pham sp
      LEFT JOIN danh_muc dm ON sp.id_danh_muc = dm.id_danh_muc
      WHERE ( (sp.gia_khuyen_mai IS NOT NULL AND sp.gia_khuyen_mai > 0)
              OR (sp.giam_gia IS NOT NULL AND sp.giam_gia > 0)
              OR (sp.is_sale = 1) )
    ";
    $params = [];
    if ($q !== '') {
        $sql .= " AND (sp.ten LIKE :q OR sp.mo_ta LIKE :q)";
        $params[':q'] = "%$q%";
    }
    $countSql = "SELECT COUNT(*) FROM ( $sql ) AS tcount";
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute($params);
    $total_items = (int)$countStmt->fetchColumn();
    $total_pages = max(1, (int)ceil($total_items / $per_page));

    $sql .= " ORDER BY sp.id_san_pham DESC LIMIT :limit OFFSET :offset";
    $stmt = $conn->prepare($sql);
    foreach ($params as $k=>$v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':limit', (int)$per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    // Fallback: select products and filter in PHP
    try {
        $sql = "SELECT sp.id_san_pham, sp.ten, sp.gia, sp.mo_ta,
                       (SELECT duong_dan FROM anh_san_pham WHERE id_san_pham = sp.id_san_pham AND la_anh_chinh = 1 LIMIT 1) AS img,
                       dm.ten AS danh_muc_ten,
                       sp.* 
                FROM san_pham sp
                LEFT JOIN danh_muc dm ON sp.id_danh_muc = dm.id_danh_muc
               ";
        $params = [];
        if ($q !== '') {
            $sql .= " WHERE (sp.ten LIKE :q OR sp.mo_ta LIKE :q)";
            $params[':q'] = "%$q%";
        }
        $sql .= " ORDER BY sp.id_san_pham DESC";
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $all = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Filter: detect sale price available in common fields or computed discount fields
        $filtered = [];
        foreach ($all as $p) {
            $salePrice = null;
            // common variants
            if (isset($p['gia_khuyen_mai']) && $p['gia_khuyen_mai'] > 0) $salePrice = (float)$p['gia_khuyen_mai'];
            elseif (isset($p['gia_sale']) && $p['gia_sale'] > 0) $salePrice = (float)$p['gia_sale'];
            elseif (isset($p['giam_gia']) && $p['giam_gia'] > 0) {
                // giam_gia might be percent or absolute: guess percent if <=100
                $g = (float)$p['giam_gia'];
                if ($g > 0 && $g <= 100) $salePrice = (float)$p['gia'] * (1 - $g/100);
                else $salePrice = max(0, (float)$p['gia'] - $g);
            } elseif (isset($p['phan_tram_giam']) && $p['phan_tram_giam'] > 0) {
                $g = (float)$p['phan_tram_giam'];
                $salePrice = (float)$p['gia'] * (1 - $g/100);
            } elseif (isset($p['is_sale']) && ($p['is_sale'] == 1 || $p['is_sale'] === '1')) {
                // no explicit sale price, treat as sale but use existing price (no discount)
                $salePrice = (float)$p['gia'];
            }

            if ($salePrice !== null) {
                $p['sale_price'] = (float)$salePrice;
                $filtered[] = $p;
            }
        }

        // paginate filtered array
        $total_items = count($filtered);
        $total_pages = max(1, (int)ceil($total_items / $per_page));
        $products = array_slice($filtered, $offset, $per_page);
    } catch (\Throwable $e2) {
        // final fallback - empty
        $products = [];
        $total_items = 0;
        $total_pages = 1;
    }
}

// normalize each product: ensure sale_price available and image fallback
foreach ($products as &$p) {
    // sale_price from SQL fields if present
    if (!isset($p['sale_price'])) {
        if (isset($p['gia_khuyen_mai']) && $p['gia_khuyen_mai'] > 0) $p['sale_price'] = (float)$p['gia_khuyen_mai'];
        elseif (isset($p['giam_gia']) && $p['giam_gia'] > 0) {
            $g = (float)$p['giam_gia'];
            $p['sale_price'] = ($g <= 100) ? (float)$p['gia'] * (1 - $g/100) : max(0, (float)$p['gia'] - $g);
        } else {
            // if is_sale flag present but no explicit sale price, use original price
            if (isset($p['is_sale']) && ($p['is_sale'] == 1 || $p['is_sale'] === '1')) $p['sale_price'] = (float)$p['gia'];
        }
    }
    if (empty($p['img'])) $p['img'] = 'images/placeholder.jpg';
    $p['gia'] = isset($p['gia']) ? (float)$p['gia'] : 0.0;
    $p['sale_price'] = isset($p['sale_price']) ? (float)$p['sale_price'] : null;
}
unset($p);

// helper build url
function buildUrl($overrides = []) {
    $qs = array_merge($_GET, $overrides);
    return basename($_SERVER['PHP_SELF']) . '?' . http_build_query($qs);
}
?>
<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <title>Khuyến mãi — <?= esc(site_name($conn)) ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root{ --primary:#0d6efd; --muted:#6c757d; }
    body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial; background:#fff; color:#222; }
    /* compact header (same style as other pages) */
    .header { border-bottom:1px solid #eef3fb; background:#fff; }
    .hdr-inner { max-width:1200px; margin:0 auto; padding:12px 16px; display:flex; align-items:center; justify-content:space-between; gap:12px; }
    .brand { display:flex; gap:10px; align-items:center; text-decoration:none; color:inherit; }
    .brand-circle { width:52px;height:52px;border-radius:50%;background:#0b1220;color:#fff; display:flex;align-items:center;justify-content:center;font-weight:700; }
    .nav-short { display:flex; gap:10px; align-items:center; }
    .nav-short a { color:#444; text-decoration:none; padding:6px 8px; border-radius:6px; }
    .nav-short a.active, .nav-short a:hover { color:var(--primary); font-weight:600; }
    .search-xs input { width:260px; max-width:36vw; height:36px; border-radius:8px; border:1px solid #e6ecf8; padding:6px 10px; }
    .card-prod { border:1px solid #eef6ff; border-radius:12px; overflow:hidden; }
    .prod-img { width:100%; height:220px; object-fit:cover; display:block; }
    .price-old { text-decoration:line-through; color:#6c757d; margin-right:8px; }
    .badge-sale { background:#ff4d4f; color:#fff; font-weight:700; padding:4px 8px; border-radius:6px; font-size:.8rem; }
    @media (max-width:992px) { .nav-short{ display:none; } .search-xs{ display:none; } }
  </style>
</head>
<body>

<!-- Header -->
<header class="header">
  <div class="hdr-inner">
    <a href="index.php" class="brand" aria-label="Trang chủ">
      <div class="brand-circle">AE</div>
      <div class="d-none d-md-block">
        <div style="font-weight:700"><?= esc(site_name($conn)) ?></div>
        <div style="font-size:12px;color:var(--muted)">Thời trang nam cao cấp</div>
      </div>
    </a>

    <nav class="nav-short" aria-label="Main menu">
      <a class="<?= is_active('index.php') ?>" href="index.php">Trang chủ</a>
      <a class="<?= is_active('sanpham.php') ?>" href="sanpham.php">Sản phẩm</a>
      <a class="<?= is_active('sale.php') ?>" href="sale.php">Danh Mục Sale</a>
      <a href="about.php">Giới thiệu</a>
    </nav>

    <div style="display:flex;align-items:center;gap:10px">
      <form method="get" action="sale.php" class="search-xs d-none d-lg-flex" role="search">
        <input type="search" name="q" value="<?= esc($q) ?>" placeholder="Tìm sản phẩm giảm giá..." aria-label="Tìm">
        <button class="btn btn-dark btn-sm" type="submit"><i class="bi bi-search"></i></button>
      </form>

      <a href="account.php" class="btn btn-link"><i class="bi bi-person" style="color:var(--primary)"></i></a>
      <!-- mini cart simplified -->
      <a href="cart.php" class="btn btn-outline-primary position-relative">
        <i class="bi bi-bag"></i>
        <span class="badge bg-danger rounded-pill" style="position:relative; top:-8px; left:-6px; font-size:.72rem;"><?= (int)$cart_count ?></span>
      </a>
    </div>
  </div>
</header>

<!-- Page hero -->
<section style="background:linear-gradient(90deg,#f6fbff,#ffffff); padding:34px 0;">
  <div class="container">
    <div class="d-flex justify-content-between align-items-center">
      <div>
        <h2 class="mb-0">Khuyến mãi & Giảm giá</h2>
        <div style="color:var(--muted)">Các sản phẩm đang giảm giá — cập nhật thường xuyên.</div>
      </div>
      <div class="text-end">
        <div class="small text-muted">Hiển thị <?= count($products) ?> / <?= $total_items ?> sản phẩm</div>
      </div>
    </div>
  </div>
</section>

<!-- Products -->
<div class="container my-4">
  <?php if (empty($products)): ?>
    <div class="alert alert-info">Không tìm thấy sản phẩm khuyến mãi. Thử thay đổi bộ lọc hoặc kiểm tra lại sau.</div>
  <?php else: ?>
    <div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 g-3">
      <?php foreach ($products as $p):
        $pid = $p['id_san_pham'];
        $img = $p['img'] ?? 'images/placeholder.jpg';
        $name = $p['ten'] ?? 'Sản phẩm';
        $price = isset($p['gia']) ? (float)$p['gia'] : 0.0;
        $sale = isset($p['sale_price']) ? (float)$p['sale_price'] : null;
        // if sale not set but gia_khuyen_mai exists
        if ($sale === null && isset($p['gia_khuyen_mai'])) $sale = (float)$p['gia_khuyen_mai'];
        $has_discount = $sale !== null && $sale < $price;
      ?>
      <div class="col">
        <div class="card card-prod h-100">
          <a href="sanpham_chitiet.php?id=<?= (int)$pid ?>" class="text-decoration-none text-dark">
            <img src="<?= esc($img) ?>" class="prod-img" alt="<?= esc($name) ?>">
          </a>
          <div class="card-body d-flex flex-column">
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <?php if (!empty($p['danh_muc_ten'])): ?>
                  <div class="small text-muted"><?= esc($p['danh_muc_ten']) ?></div>
                <?php endif; ?>
                <a href="sanpham_chitiet.php?id=<?= (int)$pid ?>" class="text-decoration-none text-dark"><h6 class="mt-1"><?= esc($name) ?></h6></a>
              </div>
              <?php if ($has_discount): ?>
                <div class="badge-sale">-<?= round(100 - ($sale / max(0.01,$price) * 100)) ?>%</div>
              <?php endif; ?>
            </div>

            <div class="mt-3">
              <?php if ($has_discount): ?>
                <div class="mb-1">
                  <span class="price-old"><?= price($price) ?></span>
                  <span class="fw-bold" style="color:var(--primary)"><?= price($sale) ?></span>
                </div>
              <?php else: ?>
                <div class="fw-bold" style="color:var(--primary)"><?= price($price) ?></div>
              <?php endif; ?>
            </div>

            <form method="post" action="cart.php?action=add" class="mt-auto">
              <input type="hidden" name="id" value="<?= (int)$pid ?>">
              <input type="hidden" name="qty" value="1">
              <input type="hidden" name="back" value="<?= esc($_SERVER['REQUEST_URI']) ?>">
              <button class="btn btn-outline-primary w-100 btn-sm" type="submit"><i class="bi bi-cart-plus"></i> Thêm vào giỏ</button>
            </form>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- pagination -->
    <nav class="mt-4">
      <ul class="pagination justify-content-center">
        <?php
          $start = max(1, $page - 3);
          $end = min($total_pages, $page + 3);
          $base = 'sale.php?';
          if ($q !== '') $base .= 'q=' . urlencode($q) . '&';
          if ($page > 1) echo '<li class="page-item"><a class="page-link" href="'. $base .'page='.($page-1).'">&laquo;</a></li>'; else echo '<li class="page-item disabled"><span class="page-link">&laquo;</span></li>';
          for ($i=$start;$i<=$end;$i++){
            if ($i==$page) echo '<li class="page-item active"><span class="page-link">'.$i.'</span></li>';
            else echo '<li class="page-item"><a class="page-link" href="'. $base .'page='.$i.'">'.$i.'</a></li>';
          }
          if ($page < $total_pages) echo '<li class="page-item"><a class="page-link" href="'. $base .'page='.($page+1).'">&raquo;</a></li>'; else echo '<li class="page-item disabled"><span class="page-link">&raquo;</span></li>';
        ?>
      </ul>
    </nav>
  <?php endif; ?>
</div>

<footer style="background:#0b1220;color:#dfefff;padding:28px 0;margin-top:32px;">
  <div class="container text-center">
    <small>© <?= date('Y') ?> <?= esc(site_name($conn)) ?></small>
  </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
