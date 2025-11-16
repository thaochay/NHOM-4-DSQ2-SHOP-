<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inc/helpers.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  header('Location: products.php'); exit;
}


$stmt = $conn->prepare("SELECT * FROM san_pham WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $id]);
$p = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$p) {
  header('Location: products.php'); exit;
}

$imgs = parse_images($p['images'] ?? '');
$main_img = first_image($p['images'] ?? '');
$price = isset($p['gia']) ? (float)$p['gia'] : null;
$sale = null;
if (isset($p['gia_sale']) && is_numeric($p['gia_sale']) && $p['gia_sale'] > 0 && $p['gia_sale'] < $price) {
  $sale = (float)$p['gia_sale'];
}

include __DIR__ . '/header.php';
?>
<div class="container-lg mt-4">
  <div class="row g-4">
    <div class="col-md-6">
      <div class="card p-2">
        <img id="mainProductImg" src="<?= esc($main_img) ?>" 
             onerror="this.onerror=null; this.src='<?= esc(defined('FALLBACK_IMAGE')?FALLBACK_IMAGE:'images/ae.jpg') ?>';"
             class="w-100" style="height:520px;object-fit:cover" alt="<?= esc($p['ten']) ?>" loading="lazy">
        <?php if (count($imgs) > 1): ?>
          <div class="mt-2 d-flex gap-2 flex-wrap">
            <?php foreach($imgs as $im): 
              $thumb = first_image($im); 
            ?>
              <img src="<?= esc($thumb) ?>" 
                   onerror="this.onerror=null; this.src='<?= esc(defined('FALLBACK_IMAGE')?FALLBACK_IMAGE:'images/ae.jpg') ?>';"
                   style="height:64px; width:64px; object-fit:cover; border-radius:6px; cursor:pointer; border:1px solid #eee"
                   onclick="document.getElementById('mainProductImg').src='<?= esc($thumb) ?>'">
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="col-md-6">
      <h2 class="mb-2"><?= esc($p['ten']) ?></h2>
      <div class="mb-3">
        <?php if ($sale !== null): ?>
          <div class="text-muted text-decoration-line-through mb-1"><?= price_format($price) ?></div>
          <div class="fs-3 text-danger fw-bold"><?= price_format($sale) ?></div>
        <?php else: ?>
          <div class="fs-3 fw-bold"><?= price_format($price) ?></div>
        <?php endif; ?>
      </div>

      <p class="text-muted mb-3"><?= esc($p['short_desc'] ?? '') ?></p>

      <form id="addToCartForm" class="d-flex gap-2 align-items-center" onsubmit="return addToCart(event, <?= (int)$p['id'] ?>)">
        <div style="width:120px">
          <label class="form-label small">Số lượng</label>
          <input type="number" name="qty" value="1" min="1" class="form-control form-control-sm">
        </div>
        <div class="d-flex flex-column">
          <button type="submit" class="btn btn-primary mt-3">Thêm vào giỏ</button>
          <a href="cart.php" class="btn btn-outline-secondary mt-2">Xem giỏ</a>
        </div>
      </form>

      <hr class="my-4">

      <h6>Chi tiết sản phẩm</h6>
      <div class="small text-muted"><?= nl2br(esc($p['mo_ta'] ?? $p['description'] ?? '')) ?></div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>

<script>

function addToCart(e, productId){
  e.preventDefault();
  const form = document.getElementById('addToCartForm');
  const qty = parseInt(form.querySelector('input[name="qty"]').value || 1, 10);
  if (qty <= 0) { alert('Số lượng không hợp lệ'); return false; }

  const temp = document.createElement('form');
  temp.method = 'POST';
  temp.action = 'cart_add.php';
  const idInput = document.createElement('input');
  idInput.type = 'hidden'; idInput.name = 'product_id'; idInput.value = productId;
  const qtyInput = document.createElement('input');
  qtyInput.type = 'hidden'; qtyInput.name = 'qty'; qtyInput.value = qty;
  temp.appendChild(idInput); temp.appendChild(qtyInput);
  document.body.appendChild(temp);
  temp.submit();
  return false;
}
</script>

