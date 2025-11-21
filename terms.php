<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inc/helpers.php';
?>
<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Điều khoản & Điều kiện - <?= esc(site_name($conn)) ?></title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

  <style>
    body { background:#f7f9fc; }
    .page-wrap { max-width:900px; margin:auto; padding:40px 20px; }
    .card-terms { background:#fff; border-radius:14px; padding:32px; 
                  box-shadow:0 12px 40px rgba(10,30,60,0.06); }
    h2 { font-weight:700; }
    h4 { margin-top:28px; font-weight:600; }
    .lead { font-size:1.05rem; color:#555; }
  </style>
</head>
<body>

<div class="page-wrap">
  <div class="card-terms">

    <h2 class="text-center mb-3">Điều khoản & Điều kiện</h2>
    <p class="lead text-center mb-4">
      Vui lòng đọc kỹ Điều khoản & Điều kiện khi sử dụng website <?= esc(site_name($conn)) ?>.
    </p>

    <h4>1. Chấp nhận điều khoản</h4>
    <p>Khi truy cập và sử dụng website, bạn đồng ý tuân thủ tất cả các điều khoản dưới đây. Nếu không đồng ý, vui lòng ngừng sử dụng dịch vụ.</p>

    <h4>2. Tài khoản người dùng</h4>
    <ul>
      <li>Bạn phải cung cấp thông tin đúng và đầy đủ khi đăng ký.</li>
      <li>Bạn chịu trách nhiệm về tài khoản và mật khẩu của mình.</li>
      <li>Không chia sẻ tài khoản cho người khác.</li>
      <li><?= esc(site_name($conn)) ?> có quyền khóa tài khoản vi phạm.</li>
    </ul>

    <h4>3. Bảo mật thông tin</h4>
    <p>Chúng tôi cam kết bảo mật thông tin cá nhân của khách hàng và không chia sẻ cho bên thứ ba ngoại trừ:</p>
    <ul>
      <li>Đối tác vận chuyển</li>
      <li>Đối tác thanh toán</li>
      <li>Cơ quan pháp luật khi được yêu cầu</li>
    </ul>

    <h4>4. Đặt hàng & thanh toán</h4>
    <p>Khi đặt hàng trên website, bạn đồng ý rằng:</p>
    <ul>
      <li>Sản phẩm có thể hết hàng mà không báo trước.</li>
      <li>Giá sản phẩm có thể thay đổi theo thời điểm.</li>
      <li>Đơn hàng chỉ hợp lệ khi được xác nhận.</li>
    </ul>

    <h4>5. Vận chuyển & giao hàng</h4>
    <ul>
      <li>Thời gian giao hàng tùy khu vực.</li>
      <li>Chúng tôi không chịu trách nhiệm với trường hợp khách hàng cung cấp sai địa chỉ.</li>
      <li>Phí vận chuyển tùy thuộc khu vực và đơn vị vận chuyển.</li>
    </ul>

    <h4>6. Đổi trả & hoàn tiền</h4>
    <ul>
      <li>Sản phẩm lỗi được đổi miễn phí trong 3–7 ngày.</li>
      <li>Không áp dụng đối với sản phẩm đã sử dụng.</li>
      <li>Quy trình hoàn tiền mất 3–5 ngày làm việc.</li>
    </ul>

    <h4>7. Quyền sở hữu trí tuệ</h4>
    <p>Mọi nội dung trên website (hình ảnh, logo, thiết kế…) thuộc quyền sở hữu của <?= esc(site_name($conn)) ?>. Vui lòng không sao chép khi chưa được cho phép.</p>

    <h4>8. Thay đổi điều khoản</h4>
    <p>Chúng tôi có quyền thay đổi điều khoản bất cứ lúc nào và sẽ thông báo trên website. Việc bạn tiếp tục sử dụng nghĩa là bạn đồng ý với điều khoản mới.</p>

    <div class="text-center mt-4">
      <a href="index.php" class="btn btn-primary px-4">Quay lại trang chủ</a>
    </div>

  </div>
</div>

</body>
</html>
