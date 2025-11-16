<?php
// checkout.php - thanh toán đơn giản, chuẩn, dùng chung với cart.php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inc/helpers.php';

if (!isset($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

$cart = $_SESSION['cart'] ?? [];
if (!is_array($cart)) $cart = [];

// TÍNH TỔNG
$subtotal = 0;
foreach ($cart as $it) {
    $price = isset($it['price']) ? (float)$it['price'] : (float)($it['gia'] ?? 0);
    $qty   = isset($it['qty']) ? (int)$it['qty'] : 1;
    $subtotal += $price * $qty;
}
$shipping = ($subtotal >= 1000000 || $subtotal == 0) ? 0 : 30000;
$discount = 0;
$total = $subtotal + $shipping - $discount;

$errors = [];
$success = false;
$order_id = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $errors[] = "Lỗi bảo mật! (CSRF)";
    }

    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $payment = $_POST['payment_method'] ?? 'cod';

    if ($name === '') $errors[] = "Vui lòng nhập họ tên";
    if ($phone === '') $errors[] = "Vui lòng nhập số điện thoại";
    if ($address === '') $errors[] = "Vui lòng nhập địa chỉ";
    if (empty($cart)) $errors[] = "Giỏ hàng trống";

    if (empty($errors)) {
        try {
            $conn->beginTransaction();

            // TẠO ĐƠN HÀNG
            $stmt = $conn->prepare("
                INSERT INTO don_hang (ten_khach, email, so_dien_thoai, dia_chi, tong_tien, phi_van_chuyen, giam_gia, phuong_thuc_thanh_toan, trang_thai, created_at)
                VALUES (:name, :email, :phone, :address, :tong, :ship, :discount, :pay, 'new', NOW())
            ");
            $stmt->execute([
                ':name' => $name,
                ':email' => $email,
                ':phone' => $phone,
                ':address' => $address,
                ':tong' => $total,
                ':ship' => $shipping,
                ':discount' => $discount,
                ':pay' => $payment
            ]);

            $order_id = $conn->lastInsertId();

            // CHI TIẾT ĐƠN
            $stmtDetail = $conn->prepare("
                INSERT INTO don_hang_chi_tiet (id_don_hang, id_san_pham, ten, gia, so_luong, thanh_tien)
                VALUES (:dh, :sp, :ten, :gia, :qty, :tien)
            ");

            foreach ($cart as $id => $it) {
                $pid = $it['id'] ?? $id;
                $nameP = $it['name'] ?? $it['ten'];
                $price = $it['price'] ?? $it['gia'];
                $qty = $it['qty'];

                $stmtDetail->execute([
                    ':dh' => $order_id,
                    ':sp' => $pid,
                    ':ten' => $nameP,
                    ':gia' => $price,
                    ':qty' => $qty,
                    ':tien' => $price * $qty
                ]);
            }

            $conn->commit();
            unset($_SESSION['cart']);
            $success = true;

        } catch (Exception $e) {
            $conn->rollBack();
            $errors[] = "Lỗi hệ thống: " . $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <title>Thanh toán | <?= esc(site_name($conn)) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="container my-5">

  <a href="cart.php" class="btn btn-link">&larr; Quay lại giỏ hàng</a>

  <?php if ($success): ?>
    <div class="alert alert-success">
      <h4>Đặt hàng thành công!</h4>
      <p>Mã đơn hàng: <strong>#<?= $order_id ?></strong></p>
      <a href="index.php" class="btn btn-primary">Tiếp tục mua sắm</a>
    </div>
  <?php else: ?>

    <?php if (!empty($errors)): ?>
      <div class="alert alert-danger">
        <ul class="mb-0">
          <?php foreach ($errors as $er): ?>
            <li><?= esc($er) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <div class="row g-4">
      <div class="col-md-7">
        <div class="card p-4">
          <h4>Thông tin thanh toán</h4>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= esc($_SESSION['csrf']) ?>">

            <div class="mb-3">
              <label>Họ và tên</label>
              <input name="name" class="form-control" required>
            </div>

            <div class="mb-3">
              <label>Số điện thoại</label>
              <input name="phone" class="form-control" required>
            </div>

            <div class="mb-3">
              <label>Email (tuỳ chọn)</label>
              <input name="email" class="form-control">
            </div>

            <div class="mb-3">
              <label>Địa chỉ giao hàng</label>
              <textarea name="address" class="form-control" rows="3" required></textarea>
            </div>

            <div class="mb-3">
              <label>Phương thức thanh toán</label>
              <select name="payment_method" class="form-select">
                <option value="cod">Thanh toán khi nhận hàng (COD)</option>
                <option value="bank">Chuyển khoản</option>
                <option value="momo">Ví MoMo</option>
              </select>
            </div>

            <button class="btn btn-primary w-100">Đặt hàng</button>
          </form>
        </div>
      </div>

      <div class="col-md-5">
        <div class="card p-4">
          <h4>Đơn hàng</h4>
          <ul class="list-group mb-3">
            <?php foreach ($cart as $item): ?>
              <li class="list-group-item d-flex justify-content-between">
                <div><?= esc($item['name'] ?? $item['ten']) ?></div>
                <div><?= price($item['price'] * $item['qty']) ?></div>
              </li>
            <?php endforeach; ?>
          </ul>

          <div class="d-flex justify-content-between">
            <span>Tạm tính:</span>
            <span><?= price($subtotal) ?></span>
          </div>

          <div class="d-flex justify-content-between">
            <span>Vận chuyển:</span>
            <span><?= $shipping == 0 ? 'Miễn phí' : price($shipping) ?></span>
          </div>

          <hr>
          <div class="d-flex justify-content-between fw-bold fs-5">
            <span>Tổng:</span>
            <span><?= price($total) ?></span>
          </div>

        </div>
      </div>
    </div>

  <?php endif; ?>

</div>
</body>
</html>
