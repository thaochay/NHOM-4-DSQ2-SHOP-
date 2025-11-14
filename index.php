<?php
session_start();
require_once 'db.php';

define('FALLBACK_IMAGE', 'images/ae.jpg');
define('SITE_TITLE','DSQ2 SHOP - Premium');
if (!isset($heroSlides) || !is_array($heroSlides) || count($heroSlides) === 0) {
  $heroSlides = [
      FALLBACK_IMAGE,
      'images/ds.jpg',
      'images/pho.jpg',
      'images/bia1.jpg',
      'images/biads.jpg'
  ];
}

function first_image($images_field){
    $fallback = FALLBACK_IMAGE;
    if(empty($images_field)) return $fallback;

    $first = null;
    $a = @json_decode($images_field, true);
    if(is_array($a) && count($a)) $first = trim($a[0]);
    else $first = trim($images_field, " \"'");

    if($first === '') return $fallback;
    if(preg_match('#^https?://#i',$first)) return $first;

    $paths = [
        __DIR__ . "/$first",
        __DIR__ . "/uploads/$first",
        __DIR__ . "/images/$first",
    ];
    foreach($paths as $p){
        if(file_exists($p) && is_file($p)){
            $scheme = (!empty($_SERVER['HTTPS'])?'https':'http');
            $host = $_SERVER['HTTP_HOST'];
            $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
            $relative = str_replace(__DIR__, '', realpath($p));
            $url = $scheme . '://' . $host . $base . str_replace('\\','/',$relative);
            return $url;
        }
    }
    return "/uploads/" . $first;
}

$categories = $conn->query("SELECT id, ten FROM danh_muc WHERE trang_thai=1 ORDER BY ten ASC")->fetchAll();
$products   = $conn->query("SELECT id, ten, gia, images FROM san_pham WHERE trang_thai=1 ORDER BY id DESC LIMIT 24")->fetchAll();

$sale_products = [];
try {
    $stmt = $conn->query("SELECT id, ten, gia, images, IFNULL(gia_sale, NULL) AS gia_sale FROM san_pham WHERE trang_thai=1 AND is_sale=1 ORDER BY id DESC LIMIT 8");
    $sale_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(Throwable $e){
}

if(empty($sale_products)){
    try {
        $stmt2 = $conn->query("SELECT id, ten, gia, images, gia_sale FROM san_pham WHERE trang_thai=1 AND gia_sale IS NOT NULL AND gia_sale < gia ORDER BY id DESC LIMIT 8");
        $sale_products = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    } catch(Throwable $e){
    }
}

if(empty($sale_products)){
    $take = array_slice($products, 0, 8);
    $sale_products = [];
    foreach($take as $p){
        $p['sim_sale'] = true; 
        $sale_products[] = $p;
    }
}

function render_product_card($p){
    $img = first_image($p['images'] ?? '');
    $title = htmlspecialchars($p['ten'] ?? 'Sản phẩm');
    $price = isset($p['gia']) && is_numeric($p['gia']) ? (float)$p['gia'] : null;
    $id = (int)($p['id'] ?? 0);


    $isSale = false;
    $salePrice = null;
    if(isset($p['gia_sale']) && is_numeric($p['gia_sale']) && $p['gia_sale'] < $price){
        $isSale = true;
        $salePrice = (float)$p['gia_sale'];
    } elseif(!empty($p['sim_sale']) && is_numeric($price)){
        $isSale = true;
        $salePrice = round($price * 0.90); 
    }

    $html = '<div class="col"><div class="card-product">';
    if($isSale){
        $html .= '<div style="position:absolute;top:12px;left:12px;z-index:5"><span class="badge-sale">SALE</span></div>';
    }
    $html .= '<div class="product-glow"></div>';
    $html .= '<a href="product.php?id='.$id.'"><img src="'.htmlspecialchars($img,ENT_QUOTES,'UTF-8').'" alt="'. $title .'"></a>';
    $html .= '<div class="p-3"><div class="product-title">'. $title .'</div>';

    if($isSale && $salePrice !== null){
        $html .= '<div class="product-price"><span style="text-decoration:line-through;color:#9aa1a6;margin-right:8px">'. (is_numeric($price) ? number_format($price,0,',','.') . ' ₫' : '') .'</span>';
        $html .= '<span style="color:#e53935;font-weight:800">'.number_format($salePrice,0,',','.') . ' ₫</span></div>';
    } else {
        $html .= '<div class="product-price">'. (is_numeric($price) ? number_format($price,0,',','.') . ' ₫' : 'Liên hệ') .'</div>';
    }

    $html .= '<div class="mt-2 d-flex gap-2">';
    $html .= '<button class="btn btn-sm btn-outline-primary" onclick="addToCart('.$id.')">Thêm</button>';
    $html .= '<a class="btn btn-sm btn-secondary" href="product.php?id='.$id.'">Xem</a>';
    $html .= '</div></div></div></div>';

    return $html;
}
?>
<!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport"content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars(SITE_TITLE) ?></title>

<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
:root{
  --bg-start: #fbf8f3;
  --bg-end:   #f1f6f9;
  --panel: #ffffff;
  --muted: #6b6b6b;
  --accent: #ff8a65;
  --card-shadow: 0 12px 30px rgba(16,24,40,0.06);
  --radius:12px;
}
body{
  font-family:'Montserrat',sans-serif;
  margin:0;
  background: linear-gradient(180deg,var(--bg-start),var(--bg-end));
  color:#1a1a1a;
}
.container-lg{max-width:1250px}

.topbar{background:transparent;padding:12px 0;border-bottom:1px solid rgba(16,24,40,0.03)}
.brand{font-weight:800;color:#0b1220;letter-spacing:1px}


.main-nav{background:transparent;padding:12px 0}
.main-nav .nav-link{color:#0b1220;margin-right:8px}
.main-nav .nav-link:hover{color:var(--accent)}


.category-bar{background:var(--panel);border-radius:8px;padding:8px 12px;margin-top:12px;box-shadow:var(--card-shadow);display:flex;gap:10px;align-items:center;overflow:auto}
.category-bar a{white-space:nowrap;padding:8px 12px;border-radius:8px;color:#334;text-decoration:none;border:1px solid transparent}
.category-bar a:hover{background:#fff;border-color:rgba(16,24,40,0.06);color:var(--accent)}


.hero-split{margin:18px 0;border-radius:var(--radius);overflow:hidden;box-shadow:var(--card-shadow);background:var(--panel);display:flex;gap:0;align-items:stretch}
@media(max-width:991px){ .hero-split{flex-direction:column} }
.hero-left{flex:0 0 60%;min-width:380px}
.hero-left .carousel-inner img{width:100%;height:520px;object-fit:cover;display:block}
@media(max-width:991px){ .hero-left .carousel-inner img{height:320px} }
.hero-right{flex:1;padding:36px;display:flex;flex-direction:column;justify-content:center}
.hero-right h1{font-size:34px;margin:0 0 12px 0;color:#0b1220}
.hero-right p{color:var(--muted);margin-bottom:18px;line-height:1.5}
.hero-cta{display:flex;gap:12px;align-items:center}


.card-product{border-radius:10px;overflow:hidden;background:linear-gradient(180deg,#fff,#fbfbfb);border:1px solid rgba(0,0,0,0.04);position:relative;transition:transform .22s,box-shadow .22s;min-height:420px}
.card-product:hover{transform:translateY(-6px);box-shadow:var(--card-shadow)}
.card-product img{width:100%;height:300px;object-fit:cover;display:block;transition:transform .35s}
.card-product:hover img{transform:scale(1.03)}
.product-glow{position:absolute;inset:0;pointer-events:none;background:radial-gradient(circle at 20% 10%, rgba(255,202,163,0.12), transparent 40%);opacity:0;transition:opacity .2s}
.card-product:hover .product-glow{opacity:1}
.product-title{font-weight:600;color:#0b1220;margin-bottom:8px}
.product-price{color:var(--accent);font-weight:700}
.badge-sale{background:linear-gradient(90deg,var(--accent),#ffb199);color:#fff;padding:6px 8px;border-radius:8px;font-size:12px;}


.section-title{font-size:20px;font-weight:700;color:#0b1220;margin-bottom:8px}
.section-sub{color:var(--muted);font-size:14px;margin-bottom:16px}


footer{margin-top:48px;padding:36px 0;color:var(--muted)}
.footer-legal{font-size:13px;color:#95a0a6}

@media(max-width:768px){
  .hero-right{padding:18px}
  .hero-left{min-width:unset}
  .card-product{min-height:360px}
  .card-product img{height:220px}
}
</style>
</head>
<body>

<div class="topbar">
  <div class="container-lg d-flex align-items-center justify-content-between">
    <div class="d-flex align-items-center gap-3">
      <a class="brand" href="index.php">DSQ2 SHOP</a>
      <div class="text-muted small d-none d-md-block">Thời trang trẻ trung — phong cách — hiện đại</div>
    </div>
    <div class="d-flex align-items-center gap-3">
      <a href="login.php" class="text-muted">Đăng nhập</a>
      <a href="cart.php" style="font-size:20px">🛒</a>
    </div>
  </div>
</div>

<nav class="main-nav">
  <div class="container-lg d-flex align-items-center justify-content-between">
    <ul class="nav align-items-center">
      <li class="nav-item"><a class="nav-link" href="index.php">Trang chủ</a></li>

      <li class="nav-item dropdown">
        <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">Bộ Siêu Tập</a>
        <ul class="dropdown-menu">
          <?php if(!empty($categories)): foreach($categories as $c): ?>
            <li><a class="dropdown-item" href="products.php?cat=<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['ten']) ?></a></li>
          <?php endforeach; else: ?>
            <li><span class="dropdown-item text-muted">Chưa có danh mục</span></li>
          <?php endif; ?>
        </ul>
      </li>

      <li class="nav-item"><a class="nav-link" href="products.php">Sản phẩm</a></li>
      <li class="nav-item"><a class="nav-link" href="news.php">Tin tức</a></li>
      <li class="nav-item"><a class="nav-link" href="about.php">Về chúng tôi</a></li>
      <li class="nav-item"><a class="nav-link" href="contact.php">Liên hệ</a></li>
    </ul>

    <form class="d-flex" action="products.php">
      <input class="form-control form-control-sm" name="q" placeholder="Tìm kiếm...">
    </form>
  </div>


  <div class="container-lg">
    <div class="category-bar mt-3" role="navigation" aria-label="Danh mục">
      <?php if(!empty($categories)): foreach($categories as $c): ?>
        <a href="products.php?cat=<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['ten']) ?></a>
      <?php endforeach; else: ?>
        <span class="text-muted">Chưa có danh mục</span>
      <?php endif; ?>
    </div>
  </div>
</nav>

<div class="container-lg">
  <div class="hero-split">

  <div class="hero-left">
  <div id="splitHeroCarousel" class="carousel slide" data-bs-ride="carousel" data-bs-interval="3000">
  <div class="carousel-inner">
    <?php if (!empty($heroSlides) && is_array($heroSlides)): ?>
      <?php foreach ($heroSlides as $k => $img): ?>
        <div class="carousel-item <?= $k==0 ? 'active' : '' ?>">
          <img src="<?= htmlspecialchars($img, ENT_QUOTES, 'UTF-8') ?>" class="d-block w-100" style="height:520px;object-fit:cover" alt="Banner <?= $k+1 ?>">
        </div>
      <?php endforeach; ?>
    <?php else: ?>
      <div class="carousel-item active">
        <img src="<?= FALLBACK_IMAGE ?>" class="d-block w-100" style="height:520px;object-fit:cover" alt="Default banner">
      </div>
    <?php endif; ?>
  </div>

  <button class="carousel-control-prev" type="button" data-bs-target="#splitHeroCarousel" data-bs-slide="prev">
    <span class="carousel-control-prev-icon"></span>
  </button>
  <button class="carousel-control-next" type="button" data-bs-target="#splitHeroCarousel" data-bs-slide="next">
    <span class="carousel-control-next-icon"></span>
  </button>

  <div class="carousel-indicators">
    <?php if (!empty($heroSlides) && is_array($heroSlides)): ?>
      <?php foreach ($heroSlides as $k => $s): ?>
        <button type="button" data-bs-target="#splitHeroCarousel" data-bs-slide-to="<?= $k ?>" class="<?= $k==0 ? 'active' : '' ?>"></button>
      <?php endforeach; ?>
    <?php else: ?>
      <button type="button" data-bs-target="#splitHeroCarousel" data-bs-slide-to="0" class="active"></button>
    <?php endif; ?>
  </div>
</div>

</div>


    <div class="hero-right">
      <h1>Mùa mới — Phong cách hiện đại</h1>
      <p>Khám phá bộ sưu tập mùa này: thiết kế tinh tế, chất liệu thoải mái và màu sắc dễ phối. Mua ngay để nhận ưu đãi đặc biệt.</p>

      <div class="hero-cta">
        <a class="btn btn-primary btn-lg" href="products.php">Mua sắm ngay</a>
        <a class="btn btn-outline-secondary btn-lg" href="about.php">Tìm hiểu thêm</a>
      </div>

      <div style="margin-top:18px;color:var(--muted)">
        <small>Giao hàng toàn quốc · Đổi trả trong 7 ngày · Hỗ trợ 24/7</small>
      </div>
    </div>

  </div>
</div>

<script>
  const splitHero = document.querySelector('#splitHeroCarousel');
  if(splitHero) new bootstrap.Carousel(splitHero,{interval:3000,ride:'carousel'});
</script>


<main class="container-lg mt-4">

  <section class="mb-5">
    <div class="d-flex justify-content-between align-items-end mb-3">
      <div>
        <div class="section-title">Sale - Ưu đãi</div>
        <div class="section-sub">Sản phẩm đang giảm giá</div>
      </div>
      <a href="products.php?filter=sale">Xem tất cả →</a>
    </div>

    <div class="row row-cols-2 row-cols-md-4 g-4">
      <?php foreach($sale_products as $p) echo render_product_card($p); ?>
    </div>
  </section>


  <section class="mb-5">
    <div class="d-flex justify-content-between align-items-end mb-3">
      <div>
        <div class="section-title">Featured</div>
        <div class="section-sub">Sản phẩm nổi bật</div>
      </div>
      <a href="products.php">Xem tất cả →</a>
    </div>

    <div class="row row-cols-2 row-cols-md-4 g-4">
      <?php foreach(array_slice($products,0,8) as $p) echo render_product_card($p); ?>
    </div>
  </section>

  
  <section class="mb-5">
    <div class="d-flex justify-content-between align-items-end mb-3">
      <div>
        <div class="section-title">New Arrivals</div>
        <div class="section-sub">Mẫu mới cập nhật</div>
      </div>
      <a href="products.php?sort=new">Xem mới →</a>
    </div>

    <div class="row row-cols-2 row-cols-md-4 g-4">
      <?php foreach(array_slice($products,0,8) as $p) echo render_product_card($p); ?>
    </div>
  </section>

</main>


<footer>
  <div class="container-lg">
    <div class="row">
      <div class="col-md-4">
        <h6>Về chúng tôi</h6>
        <p class="text-muted">DSQ2 SHOP - Thời trang trẻ trung, cao cấp.</p>
      </div>
      <div class="col-md-4">
        <h6>Hỗ trợ</h6>
        <p class="text-muted">Vận chuyển · Đổi trả · Thanh toán</p>
      </div>
      <div class="col-md-4 text-md-end">
        <h6>Liên hệ</h6>
        <p class="text-muted">support@dsq2shop.com · 0978 229 594</p>
      </div>
    </div>

    <div class="row mt-4">
      <div class="col-12 text-center footer-legal">© <?= date('Y') ?> DSQ2 SHOP — All rights reserved.</div>
    </div>
  </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
  function addToCart(id){
    try {
      const fd = new FormData();
      fd.append('action','add');
      fd.append('product_id', id);
      fetch('inc/cart_actions.php',{method:'POST',body:fd})
        .then(r=>r.json()).then(d=>{
          if(d && d.ok) alert('Đã thêm vào giỏ hàng');
          else alert(d && d.msg ? d.msg : 'Lỗi thêm giỏ hàng');
        }).catch(e=>{ console.error(e); alert('Lỗi kết nối'); });
    } catch(e){ console.error(e); }
  }
</script>
</body>
</html>

