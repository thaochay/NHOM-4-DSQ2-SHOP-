<?php
// addresses.php - for schema exactly as provided (ho_ten, so_dien_thoai, dia_chi_chi_tiet, phuong_xa, quan_huyen, tinh_thanh, mac_dinh)
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inc/helpers.php';

if (!function_exists('esc')) {
    function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!isset($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

// require login
if (empty($_SESSION['user']) || !is_array($_SESSION['user'])) {
    header('Location: login.php?back=addresses.php');
    exit;
}

// robust user id detection
function current_user_id(){
    $u = $_SESSION['user'] ?? [];
    if (isset($u['id_nguoi_dung'])) return (int)$u['id_nguoi_dung'];
    if (isset($u['id'])) return (int)$u['id'];
    if (isset($u['user_id'])) return (int)$u['user_id'];
    return null;
}
$user_id = current_user_id();
if (!$user_id) {
    // should not happen, but guard
    header('Location: login.php?back=addresses.php'); exit;
}

$site_name = function_exists('site_name') ? site_name($conn) : 'AE Shop';
$errors = [];

/* helpers */
function flash($type, $msg){
    $_SESSION['flash_' . $type] = $msg;
}
function get_flash($type){
    $k = 'flash_' . $type;
    $v = $_SESSION[$k] ?? null;
    unset($_SESSION[$k]);
    return $v;
}

/* load addresses for this user */
function load_addresses(PDO $conn, $user_id) {
    try {
        $stmt = $conn->prepare("SELECT * FROM dia_chi WHERE id_nguoi_dung = :uid ORDER BY mac_dinh DESC, id_dia_chi DESC");
        $stmt->execute([':uid' => $user_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

/* find single address (ownership) */
function find_address(PDO $conn, $id, $user_id) {
    try {
        $stmt = $conn->prepare("SELECT * FROM dia_chi WHERE id_dia_chi = :id AND id_nguoi_dung = :uid LIMIT 1");
        $stmt->execute([':id' => $id, ':uid' => $user_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return false;
    }
}

/* POST handling */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted_csrf = $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'], $posted_csrf)) {
        $errors[] = 'Lỗi bảo mật (CSRF). Vui lòng thử lại.';
    } else {
        $action = $_POST['action'] ?? 'add';

        // normalize inputs
        $ho_ten = trim($_POST['ho_ten'] ?? $_POST['ten'] ?? $_POST['name'] ?? '');
        $so_dien_thoai = trim($_POST['so_dien_thoai'] ?? $_POST['dien_thoai'] ?? $_POST['phone'] ?? '');
        $dia_chi_chi_tiet = trim($_POST['dia_chi_chi_tiet'] ?? $_POST['dia_chi'] ?? $_POST['address'] ?? '');
        $phuong_xa = trim($_POST['phuong_xa'] ?? $_POST['phuong'] ?? '');
        $quan_huyen = trim($_POST['quan_huyen'] ?? $_POST['huyen'] ?? $_POST['district'] ?? '');
        $tinh_thanh = trim($_POST['tinh_thanh'] ?? $_POST['tinh'] ?? $_POST['city'] ?? '');
        $mac_dinh = !empty($_POST['mac_dinh']) ? 1 : 0;
        $id_dia_chi = (int)($_POST['id_dia_chi'] ?? 0);

        if (in_array($action, ['add','edit'])) {
            // basic validation
            if ($ho_ten === '') $errors[] = 'Vui lòng nhập họ & tên.';
            if ($so_dien_thoai === '') $errors[] = 'Vui lòng nhập số điện thoại.';
            if ($dia_chi_chi_tiet === '') $errors[] = 'Vui lòng nhập địa chỉ chi tiết.';

            if (empty($errors)) {
                try {
                    $conn->beginTransaction();

                    // if setting default, unset others first
                    if ($mac_dinh) {
                        $u = $conn->prepare("UPDATE dia_chi SET mac_dinh = 0 WHERE id_nguoi_dung = :uid");
                        $u->execute([':uid' => $user_id]);
                    }

                    if ($action === 'add') {
                        $stmt = $conn->prepare("
                            INSERT INTO dia_chi
                            (id_nguoi_dung, ho_ten, so_dien_thoai, dia_chi_chi_tiet, phuong_xa, quan_huyen, tinh_thanh, mac_dinh)
                            VALUES
                            (:uid, :ho_ten, :so_dien_thoai, :dia_chi_chi_tiet, :phuong_xa, :quan_huyen, :tinh_thanh, :mac_dinh)
                        ");
                        $stmt->execute([
                            ':uid' => $user_id,
                            ':ho_ten' => $ho_ten,
                            ':so_dien_thoai' => $so_dien_thoai,
                            ':dia_chi_chi_tiet' => $dia_chi_chi_tiet,
                            ':phuong_xa' => $phuong_xa,
                            ':quan_huyen' => $quan_huyen,
                            ':tinh_thanh' => $tinh_thanh,
                            ':mac_dinh' => $mac_dinh
                        ]);
                        $conn->commit();
                        flash('success', 'Đã thêm địa chỉ mới.');
                        header('Location: addresses.php');
                        exit;
                    } else { // edit
                        if ($id_dia_chi <= 0) {
                            $errors[] = 'ID địa chỉ không hợp lệ.';
                            $conn->rollBack();
                        } else {
                            // ensure ownership
                            $found = find_address($conn, $id_dia_chi, $user_id);
                            if (!$found) {
                                $errors[] = 'Không tìm thấy địa chỉ hoặc không có quyền.';
                                $conn->rollBack();
                            } else {
                                $stmt = $conn->prepare("
                                    UPDATE dia_chi SET
                                    ho_ten = :ho_ten,
                                    so_dien_thoai = :so_dien_thoai,
                                    dia_chi_chi_tiet = :dia_chi_chi_tiet,
                                    phuong_xa = :phuong_xa,
                                    quan_huyen = :quan_huyen,
                                    tinh_thanh = :tinh_thanh,
                                    mac_dinh = :mac_dinh
                                    WHERE id_dia_chi = :id AND id_nguoi_dung = :uid
                                ");
                                $stmt->execute([
                                    ':ho_ten' => $ho_ten,
                                    ':so_dien_thoai' => $so_dien_thoai,
                                    ':dia_chi_chi_tiet' => $dia_chi_chi_tiet,
                                    ':phuong_xa' => $phuong_xa,
                                    ':quan_huyen' => $quan_huyen,
                                    ':tinh_thanh' => $tinh_thanh,
                                    ':mac_dinh' => $mac_dinh,
                                    ':id' => $id_dia_chi,
                                    ':uid' => $user_id
                                ]);
                                $conn->commit();
                                flash('success', 'Cập nhật địa chỉ thành công.');
                                header('Location: addresses.php');
                                exit;
                            }
                        }
                    }
                } catch (Exception $e) {
                    $conn->rollBack();
                    $errors[] = 'Lỗi khi lưu địa chỉ: ' . $e->getMessage();
                }
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id_dia_chi'] ?? 0);
            if ($id <= 0) $errors[] = 'ID không hợp lệ.';
            else {
                try {
                    // ensure ownership
                    $found = find_address($conn, $id, $user_id);
                    if (!$found) $errors[] = 'Không tìm thấy địa chỉ hoặc không có quyền.';
                    else {
                        $stmt = $conn->prepare("DELETE FROM dia_chi WHERE id_dia_chi = :id AND id_nguoi_dung = :uid");
                        $stmt->execute([':id' => $id, ':uid' => $user_id]);
                        flash('success', 'Đã xóa địa chỉ.');
                        header('Location: addresses.php');
                        exit;
                    }
                } catch (Exception $e) {
                    $errors[] = 'Lỗi khi xóa: ' . $e->getMessage();
                }
            }
        } elseif ($action === 'set_default') {
            $id = (int)($_POST['id_dia_chi'] ?? 0);
            if ($id <= 0) $errors[] = 'ID không hợp lệ.';
            else {
                try {
                    // ensure ownership
                    $found = find_address($conn, $id, $user_id);
                    if (!$found) $errors[] = 'Không tìm thấy địa chỉ hoặc không có quyền.';
                    else {
                        $conn->beginTransaction();
                        $u = $conn->prepare("UPDATE dia_chi SET mac_dinh = 0 WHERE id_nguoi_dung = :uid");
                        $u->execute([':uid' => $user_id]);
                        $s = $conn->prepare("UPDATE dia_chi SET mac_dinh = 1 WHERE id_dia_chi = :id AND id_nguoi_dung = :uid");
                        $s->execute([':id' => $id, ':uid' => $user_id]);
                        $conn->commit();
                        flash('success', 'Đã đặt địa chỉ mặc định.');
                        header('Location: addresses.php');
                        exit;
                    }
                } catch (Exception $e) {
                    $conn->rollBack();
                    $errors[] = 'Lỗi khi đặt mặc định: ' . $e->getMessage();
                }
            }
        } else {
            $errors[] = 'Hành động không hợp lệ.';
        }
    }
}

/* load for display */
$addresses = load_addresses($conn, $user_id);
$flash_success = get_flash('success');
$flash_error = get_flash('error');
?>
<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <title>Địa chỉ giao hàng — <?= esc($site_name) ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root{--accent:#0b7bdc;--muted:#6c757d}
    body{background:#f8fbff;font-family:Inter,system-ui,Arial,sans-serif;color:#0b1a2b}
    .container-main{max-width:1000px;margin:28px auto}
    .ae-logo-mark{width:44px;height:44px;border-radius:10px;background:var(--accent);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800}
    .addr-card{background:#fff;padding:18px;border-radius:12px;box-shadow:0 8px 30px rgba(11,38,80,0.04)}
    .addr-item{padding:14px;border-radius:10px;border:1px solid rgba(11,38,80,0.03);background:linear-gradient(180deg,#fff,#fbfdff)}
    .addr-item .meta{color:var(--muted); font-size:.95rem}
  </style>
</head>
<body>
<nav class="p-3 mb-3" style="background:#fff;box-shadow:0 8px 20px rgba(11,38,80,0.03)">
  <div class="container d-flex justify-content-between align-items-center">
    <div class="d-flex align-items-center gap-2">
      <div class="ae-logo-mark">AE</div>
      <div>
        <div style="font-weight:800"><?= esc($site_name) ?></div>
        <div style="font-size:13px;color:var(--muted)">Thời trang nam</div>
      </div>
    </div>
    <div>
      <a href="account.php" class="btn btn-outline-secondary btn-sm">Tài khoản</a>
    </div>
  </div>
</nav>

<div class="container-main">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Địa chỉ giao hàng</h4>
    <div>
      <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addressModal" onclick="openAddModal()">Thêm địa chỉ mới</button>
      <a href="checkout.php" class="btn btn-outline-secondary ms-2">Thanh toán</a>
    </div>
  </div>

  <?php if ($flash_success): ?><div class="alert alert-success"><?= esc($flash_success) ?></div><?php endif; ?>
  <?php if ($flash_error): ?><div class="alert alert-danger"><?= esc($flash_error) ?></div><?php endif; ?>
  <?php if (!empty($errors)): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $er) echo '<li>'.esc($er).'</li>'; ?></ul></div><?php endif; ?>

  <div class="addr-card">
    <?php if (empty($addresses)): ?>
      <div class="text-center py-4">
        <p class="text-muted">Bạn chưa có địa chỉ nào.</p>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addressModal" onclick="openAddModal()">Thêm địa chỉ</button>
      </div>
    <?php else: ?>
      <?php foreach ($addresses as $a): $is_def = !empty($a['mac_dinh']); ?>
        <div class="addr-item mb-3 <?= $is_def ? 'border border-2 border-primary' : '' ?>">
          <div class="d-flex justify-content-between">
            <div>
              <div class="fw-semibold"><?= esc($a['ho_ten'] ?? '') ?> <?= $is_def ? '<span class="badge bg-primary ms-2">Mặc định</span>' : '' ?></div>
              <div class="meta mt-1"><?= esc($a['dia_chi_chi_tiet'] ?? '') ?></div>
              <div class="meta mt-1"><?= esc($a['phuong_xa'] ?? '') ?> <?= $a['quan_huyen'] ? '• ' . esc($a['quan_huyen']) : '' ?> <?= $a['tinh_thanh'] ? '• ' . esc($a['tinh_thanh']) : '' ?></div>
              <div class="meta mt-1">Điện thoại: <?= esc($a['so_dien_thoai'] ?? '') ?></div>
            </div>
            <div class="text-end">
              <div class="d-flex gap-2">
                <?php if (!$is_def): ?>
                  <form method="post" style="display:inline">
                    <input type="hidden" name="csrf" value="<?= esc($_SESSION['csrf']) ?>">
                    <input type="hidden" name="action" value="set_default">
                    <input type="hidden" name="id_dia_chi" value="<?= (int)$a['id_dia_chi'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-primary">Đặt mặc định</button>
                  </form>
                <?php endif; ?>

                <button class="btn btn-sm btn-outline-secondary" onclick='openEditModal(<?= json_encode($a, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>Sửa</button>

                <form method="post" onsubmit="return confirm('Bạn có chắc muốn xóa địa chỉ này?');" style="display:inline">
                  <input type="hidden" name="csrf" value="<?= esc($_SESSION['csrf']) ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id_dia_chi" value="<?= (int)$a['id_dia_chi'] ?>">
                  <button type="submit" class="btn btn-sm btn-danger">Xóa</button>
                </form>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<!-- Modal Add/Edit -->
<div class="modal fade" id="addressModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <form method="post" id="addressForm">
        <input type="hidden" name="csrf" value="<?= esc($_SESSION['csrf']) ?>">
        <input type="hidden" name="action" id="addr_action" value="add">
        <input type="hidden" name="id_dia_chi" id="addr_id" value="0">

        <div class="modal-header">
          <h5 class="modal-title" id="addrModalTitle">Thêm địa chỉ</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button>
        </div>

        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Họ & tên <span class="text-danger">*</span></label>
              <input name="ho_ten" id="addr_ho_ten" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Số điện thoại <span class="text-danger">*</span></label>
              <input name="so_dien_thoai" id="addr_phone" class="form-control" required>
            </div>

            <div class="col-12">
              <label class="form-label">Địa chỉ chi tiết <span class="text-danger">*</span></label>
              <input name="dia_chi_chi_tiet" id="addr_detail" class="form-control" required>
            </div>

            <div class="col-md-4">
              <label class="form-label">Phường / Xã</label>
              <input name="phuong_xa" id="addr_ward" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label">Quận / Huyện</label>
              <input name="quan_huyen" id="addr_district" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label">Tỉnh / Thành</label>
              <input name="tinh_thanh" id="addr_city" class="form-control">
            </div>

            <div class="col-12 d-flex align-items-center">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="addr_default" name="mac_dinh">
                <label class="form-check-label" for="addr_default">Đặt làm địa chỉ mặc định</label>
              </div>
            </div>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Hủy</button>
          <button type="submit" class="btn btn-primary">Lưu địa chỉ</button>
        </div>
      </form>
    </div>
  </div>
</div>

<footer style="text-align:center;margin-top:32px;color:#64748b"><?= esc($site_name) ?> — © <?= date('Y') ?></footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
  function openAddModal(){
    document.getElementById('addr_action').value = 'add';
    document.getElementById('addr_id').value = '0';
    document.getElementById('addr_ho_ten').value = '';
    document.getElementById('addr_phone').value = '';
    document.getElementById('addr_detail').value = '';
    document.getElementById('addr_ward').value = '';
    document.getElementById('addr_district').value = '';
    document.getElementById('addr_city').value = '';
    document.getElementById('addr_default').checked = false;
    var m = new bootstrap.Modal(document.getElementById('addressModal')); m.show();
  }

  function openEditModal(data){
    document.getElementById('addr_action').value = 'edit';
    document.getElementById('addr_id').value = data.id_dia_chi || 0;
    document.getElementById('addr_ho_ten').value = data.ho_ten || '';
    document.getElementById('addr_phone').value = data.so_dien_thoai || '';
    document.getElementById('addr_detail').value = data.dia_chi_chi_tiet || '';
    document.getElementById('addr_ward').value = data.phuong_xa || '';
    document.getElementById('addr_district').value = data.quan_huyen || '';
    document.getElementById('addr_city').value = data.tinh_thanh || '';
    document.getElementById('addr_default').checked = data.mac_dinh && parseInt(data.mac_dinh) === 1 ? true : false;
    var m = new bootstrap.Modal(document.getElementById('addressModal')); m.show();
  }
</script>
</body>
</html>
