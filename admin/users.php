<?php
// admin/users.php
// Quản lý người dùng: list, create, edit, delete, reset password, toggle admin/trang_thai
require_once __DIR__ . '/inc/header.php'; // kiểm tra admin, tạo $_SESSION['csrf_admin']
/** @var PDO $conn available from header.php require */

/* Helpers */
function flash($type, $msg) {
    $_SESSION['flash_admin_' . $type] = $msg;
}
function flash_get_once($key) {
    $k = 'flash_admin_' . $key;
    $v = $_SESSION[$k] ?? null;
    if ($v) unset($_SESSION[$k]);
    return $v;
}

/* Handle POST actions */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    // CSRF check
    if (!hash_equals($_SESSION['csrf_admin'] ?? '', $_POST['csrf'] ?? '')) {
        flash('error', 'CSRF token không hợp lệ.');
        header('Location: users.php');
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            // prevent deleting self
            if ($id === ($_SESSION['user']['id_nguoi_dung'] ?? 0)) {
                flash('error', 'Không thể xóa tài khoản đang đăng nhập.');
            } else {
                $stmt = $conn->prepare("DELETE FROM nguoi_dung WHERE id_nguoi_dung = :id");
                $stmt->execute([':id'=>$id]);
                flash('success', 'Đã xóa người dùng #' . $id);
            }
        }
        header('Location: users.php');
        exit;
    }

    if ($action === 'save') {
        // create or update user
        $id = (int)($_POST['id'] ?? 0);
        $ten = trim($_POST['ten'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $dien_thoai = trim($_POST['dien_thoai'] ?? '') ?: null;
        $ngay_sinh = trim($_POST['ngay_sinh'] ?? '') ?: null;
        $gioi_tinh = trim($_POST['gioi_tinh'] ?? '') ?: null;
        $is_admin = !empty($_POST['is_admin']) ? 1 : 0;
        $trang_thai = isset($_POST['trang_thai']) ? (int)($_POST['trang_thai']) : 1;

        $errors = [];
        if ($ten === '') $errors[] = 'Tên không được để trống.';
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email không hợp lệ.';

        // check email unique
        $q = "SELECT id_nguoi_dung FROM nguoi_dung WHERE email = :email";
        $params = [':email'=>$email];
        if ($id > 0) { $q .= " AND id_nguoi_dung != :id"; $params[':id']=$id; }
        $stmt = $conn->prepare($q); $stmt->execute($params);
        if ($stmt->fetchColumn()) $errors[] = 'Email đã được sử dụng bởi tài khoản khác.';

        if ($errors) {
            flash('error', implode('<br>', $errors));
            // preserve input via session (optional)
            $_SESSION['admin_user_form'] = compact('id','ten','email','dien_thoai','ngay_sinh','gioi_tinh','is_admin','trang_thai');
            header('Location: users.php' . ($id>0 ? '?edit=' . $id : '?new=1'));
            exit;
        }

        if ($id > 0) {
            // update
            $upd = $conn->prepare("UPDATE nguoi_dung SET ten=:ten, email=:email, dien_thoai=:dien_thoai, ngay_sinh=:ngay_sinh, gioi_tinh=:gioi_tinh, is_admin=:is_admin, trang_thai=:trang_thai, updated_at=NOW() WHERE id_nguoi_dung = :id");
            $upd->execute([
                ':ten'=>$ten, ':email'=>$email, ':dien_thoai'=>$dien_thoai, ':ngay_sinh'=>$ngay_sinh,
                ':gioi_tinh'=>$gioi_tinh, ':is_admin'=>$is_admin, ':trang_thai'=>$trang_thai, ':id'=>$id
            ]);
            flash('success', 'Cập nhật người dùng thành công.');
            header('Location: users.php?edit=' . $id);
            exit;
        } else {
            // create - require password input
            $password = $_POST['mat_khau'] ?? '';
            if (strlen($password) < 6) {
                flash('error', 'Mật khẩu cần ít nhất 6 ký tự khi tạo tài khoản mới.');
                $_SESSION['admin_user_form'] = compact('id','ten','email','dien_thoai','ngay_sinh','gioi_tinh','is_admin','trang_thai');
                header('Location: users.php?new=1');
                exit;
            }
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $ins = $conn->prepare("INSERT INTO nguoi_dung (ten, email, mat_khau, dien_thoai, ngay_sinh, gioi_tinh, is_admin, trang_thai, created_at) VALUES (:ten,:email,:mat_khau,:dien_thoai,:ngay_sinh,:gioi_tinh,:is_admin,:trang_thai,NOW())");
            $ins->execute([
                ':ten'=>$ten, ':email'=>$email, ':mat_khau'=>$hash, ':dien_thoai'=>$dien_thoai,
                ':ngay_sinh'=>$ngay_sinh, ':gioi_tinh'=>$gioi_tinh, ':is_admin'=>$is_admin, ':trang_thai'=>$trang_thai
            ]);
            $newId = $conn->lastInsertId();
            flash('success', 'Tạo người dùng thành công (ID ' . $newId . ').');
            header('Location: users.php?edit=' . $newId);
            exit;
        }
    }

    if ($action === 'reset_password') {
        $id = (int)($_POST['id'] ?? 0);
        $newpw = trim($_POST['new_password'] ?? '');
        if ($id <= 0 || $newpw === '' || strlen($newpw) < 6) {
            flash('error', 'Yêu cầu mật khẩu mới hợp lệ (>=6 ký tự).');
        } else {
            $hash = password_hash($newpw, PASSWORD_DEFAULT);
            $u = $conn->prepare("UPDATE nguoi_dung SET mat_khau = :h WHERE id_nguoi_dung = :id");
            $u->execute([':h'=>$hash, ':id'=>$id]);
            flash('success', 'Đặt lại mật khẩu thành công cho người dùng #' . $id);
        }
        header('Location: users.php' . ($id ? ('?edit=' . $id) : ''));
        exit;
    }

    if ($action === 'quick_toggle') {
        // toggle is_admin or trang_thai quickly
        $id = (int)($_POST['id'] ?? 0);
        $field = $_POST['field'] ?? '';
        if ($id > 0 && in_array($field, ['is_admin','trang_thai'])) {
            // prevent demoting last admin or self-demote
            if ($field === 'is_admin' && $id === ($_SESSION['user']['id_nguoi_dung'] ?? 0)) {
                flash('error', 'Không thể thay đổi quyền admin cho chính bạn.');
            } else {
                // toggle current value
                $cur = $conn->prepare("SELECT " . $field . " FROM nguoi_dung WHERE id_nguoi_dung = :id LIMIT 1");
                $cur->execute([':id'=>$id]); $val = (int)$cur->fetchColumn();
                $new = $val ? 0 : 1;
                // If removing admin rights, ensure at least one admin remains
                if ($field === 'is_admin' && $new === 0) {
                    $countAdmins = (int)$conn->query("SELECT COUNT(*) FROM nguoi_dung WHERE is_admin = 1")->fetchColumn();
                    if ($countAdmins <= 1) {
                        flash('error', 'Không thể bỏ quyền admin - hệ thống cần ít nhất 1 admin.');
                        header('Location: users.php'); exit;
                    }
                }
                $upd = $conn->prepare("UPDATE nguoi_dung SET " . $field . " = :v WHERE id_nguoi_dung = :id");
                $upd->execute([':v'=>$new, ':id'=>$id]);
                flash('success', 'Đã cập nhật ' . $field . ' cho user #' . $id);
            }
        }
        header('Location: users.php');
        exit;
    }
}

/* GET: list, pagination, search */
$search = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['p'] ?? 1));
$perPage = 40;
$offset = ($page - 1) * $perPage;

$where = "1=1";
$params = [];
if ($search !== '') {
    $where = "(ten LIKE :kw OR email LIKE :kw OR dien_thoai LIKE :kw)";
    $params[':kw'] = '%' . $search . '%';
}

// total
$totalStmt = $conn->prepare("SELECT COUNT(*) FROM nguoi_dung WHERE $where");
$totalStmt->execute($params);
$total = (int)$totalStmt->fetchColumn();
$pages = max(1, ceil($total / $perPage));

// fetch users
$listStmt = $conn->prepare("SELECT id_nguoi_dung, ten, email, dien_thoai, ngay_sinh, gioi_tinh, is_admin, trang_thai, last_login, created_at FROM nguoi_dung WHERE $where ORDER BY created_at DESC LIMIT :off, :lim");
foreach ($params as $k=>$v) $listStmt->bindValue($k, $v);
$listStmt->bindValue(':off', (int)$offset, PDO::PARAM_INT);
$listStmt->bindValue(':lim', (int)$perPage, PDO::PARAM_INT);
$listStmt->execute();
$users = $listStmt->fetchAll(PDO::FETCH_ASSOC);

// form data if edit/new
$formData = $_SESSION['admin_user_form'] ?? null;
if ($formData) unset($_SESSION['admin_user_form']);

$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editUser = null;
if ($editId > 0) {
    $stm = $conn->prepare("SELECT id_nguoi_dung, ten, email, dien_thoai, ngay_sinh, gioi_tinh, is_admin, trang_thai FROM nguoi_dung WHERE id_nguoi_dung = :id LIMIT 1");
    $stm->execute([':id'=>$editId]);
    $editUser = $stm->fetch(PDO::FETCH_ASSOC);
} elseif (isset($_GET['new'])) {
    $editUser = ['id_nguoi_dung'=>0,'ten'=>'','email'=>'','dien_thoai'=>'','ngay_sinh'=>'','gioi_tinh'=>'','is_admin'=>0,'trang_thai'=>1];
}
?>
<div class="card p-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Quản lý Người dùng</h5>
    <div class="d-flex gap-2">
      <form class="d-flex" method="get" action="users.php">
        <input name="q" class="form-control form-control-sm" placeholder="Tìm tên, email, điện thoại" value="<?= esc($search) ?>">
        <button class="btn btn-sm btn-dark ms-2">Tìm</button>
      </form>
      <a href="users.php?new=1" class="btn btn-sm btn-primary">Tạo người dùng</a>
    </div>
  </div>

  <?php if ($msg = flash_get_once('success')): ?><div class="alert alert-success"><?= esc($msg) ?></div><?php endif; ?>
  <?php if ($msg = flash_get_once('error')): ?><div class="alert alert-danger"><?= $msg ?></div><?php endif; ?>

  <div class="table-responsive mb-3">
    <table class="table table-hover table-sm">
      <thead><tr><th>#</th><th>Họ & Tên</th><th>Email</th><th>Điện thoại</th><th>Admin</th><th>Trạng thái</th><th>Last login</th><th>Ngày tạo</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($users as $u): ?>
          <tr>
            <td><?= (int)$u['id_nguoi_dung'] ?></td>
            <td><?= esc($u['ten']) ?></td>
            <td><?= esc($u['email']) ?></td>
            <td><?= esc($u['dien_thoai']) ?></td>
            <td>
              <?php if ((int)$u['is_admin']): ?>
                <span class="badge bg-success">Admin</span>
              <?php else: ?>
                <span class="badge bg-light text-muted">User</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ((int)$u['trang_thai']): ?>
                <span class="small text-success">Hoạt động</span>
              <?php else: ?>
                <span class="small text-muted">Đã khoá</span>
              <?php endif; ?>
            </td>
            <td class="small text-muted"><?= esc($u['last_login']) ?></td>
            <td class="small text-muted"><?= esc($u['created_at']) ?></td>
            <td class="text-end">
              <a class="btn btn-sm btn-outline-primary" href="users.php?edit=<?= (int)$u['id_nguoi_dung'] ?>">Sửa</a>

              <!-- quick toggles -->
              <form method="post" style="display:inline" onsubmit="return confirm('Thay đổi quyền admin?');">
                <input type="hidden" name="csrf" value="<?= esc($_SESSION['csrf_admin']) ?>">
                <input type="hidden" name="action" value="quick_toggle">
                <input type="hidden" name="id" value="<?= (int)$u['id_nguoi_dung'] ?>">
                <input type="hidden" name="field" value="is_admin">
                <button class="btn btn-sm <?= (int)$u['is_admin'] ? 'btn-outline-danger' : 'btn-outline-success' ?>"><?= (int)$u['is_admin'] ? 'Bỏ admin' : 'Gán admin' ?></button>
              </form>

              <form method="post" style="display:inline" onsubmit="return confirm('Thay đổi trạng thái người dùng?');">
                <input type="hidden" name="csrf" value="<?= esc($_SESSION['csrf_admin']) ?>">
                <input type="hidden" name="action" value="quick_toggle">
                <input type="hidden" name="id" value="<?= (int)$u['id_nguoi_dung'] ?>">
                <input type="hidden" name="field" value="trang_thai">
                <button class="btn btn-sm btn-outline-secondary"><?= (int)$u['trang_thai'] ? 'Khoá' : 'Kích hoạt' ?></button>
              </form>

              <form method="post" style="display:inline" onsubmit="return confirm('Xóa người dùng này?');">
                <input type="hidden" name="csrf" value="<?= esc($_SESSION['csrf_admin']) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$u['id_nguoi_dung'] ?>">
                <button class="btn btn-sm btn-danger">Xóa</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($users)): ?><tr><td colspan="9" class="text-center text-muted">Không tìm thấy người dùng</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- pagination -->
  <nav>
    <ul class="pagination">
      <?php for ($i=1;$i<=$pages;$i++): ?>
        <li class="page-item <?= $i === $page ? 'active' : '' ?>"><a class="page-link" href="?p=<?= $i ?>&q=<?= urlencode($search) ?>"><?= $i ?></a></li>
      <?php endfor; ?>
    </ul>
  </nav>
</div>

<?php if ($editUser !== null): ?>
  <div class="card p-3 mt-3">
    <h5 class="mb-3"><?= $editUser['id_nguoi_dung'] ? 'Sửa người dùng #' . (int)$editUser['id_nguoi_dung'] : 'Tạo người dùng mới' ?></h5>

    <?php if ($msg = flash_get_once('error')): ?><div class="alert alert-danger"><?= $msg ?></div><?php endif; ?>

    <?php
      $d = $formData ?? $editUser;
      $d['id'] = $d['id_nguoi_dung'] ?? ($d['id'] ?? 0);
    ?>
    <form method="post" class="row g-2">
      <input type="hidden" name="csrf" value="<?= esc($_SESSION['csrf_admin']) ?>">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">

      <div class="col-md-6">
        <label class="form-label small">Họ & Tên</label>
        <input name="ten" class="form-control" required value="<?= esc($d['ten'] ?? '') ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label small">Email</label>
        <input name="email" type="email" class="form-control" required value="<?= esc($d['email'] ?? '') ?>">
      </div>

      <div class="col-md-4">
        <label class="form-label small">Số điện thoại</label>
        <input name="dien_thoai" class="form-control" value="<?= esc($d['dien_thoai'] ?? '') ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label small">Ngày sinh</label>
        <input name="ngay_sinh" type="date" class="form-control" value="<?= esc($d['ngay_sinh'] ?? '') ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label small">Giới tính</label>
        <select name="gioi_tinh" class="form-select">
          <option value="">—</option>
          <option value="Nam" <?= (isset($d['gioi_tinh']) && $d['gioi_tinh']=='Nam') ? 'selected' : '' ?>>Nam</option>
          <option value="Nữ" <?= (isset($d['gioi_tinh']) && $d['gioi_tinh']=='Nữ') ? 'selected' : '' ?>>Nữ</option>
        </select>
      </div>

      <?php if (empty($d['id'])): // create requires password ?>
        <div class="col-md-6">
          <label class="form-label small">Mật khẩu (tối thiểu 6 ký tự)</label>
          <input name="mat_khau" type="password" class="form-control" required>
        </div>
      <?php endif; ?>

      <div class="col-md-3">
        <label class="form-label small">Quyền admin</label>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="isAdmin" name="is_admin" value="1" <?= !empty($d['is_admin']) ? 'checked' : '' ?>>
          <label class="form-check-label small" for="isAdmin">is_admin</label>
        </div>
      </div>
      <div class="col-md-3">
        <label class="form-label small">Trạng thái</label>
        <select name="trang_thai" class="form-select">
          <option value="1" <?= (!isset($d['trang_thai']) || $d['trang_thai']==1) ? 'selected' : '' ?>>Hoạt động</option>
          <option value="0" <?= (isset($d['trang_thai']) && $d['trang_thai']==0) ? 'selected' : '' ?>>Đã khoá</option>
        </select>
      </div>

      <div class="col-12 d-flex gap-2 mt-2">
        <button class="btn btn-primary"><?= $d['id'] ? 'Lưu thay đổi' : 'Tạo người dùng' ?></button>
        <a href="users.php" class="btn btn-outline-secondary">Hủy</a>
      </div>
    </form>

    <?php if (!empty($d['id'])): // reset password form ?>
      <hr>
      <h6>Đặt lại mật khẩu cho user #<?= (int)$d['id'] ?></h6>
      <form method="post" class="row g-2" onsubmit="return confirm('Bạn có chắc muốn đặt lại mật khẩu?');">
        <input type="hidden" name="csrf" value="<?= esc($_SESSION['csrf_admin']) ?>">
        <input type="hidden" name="action" value="reset_password">
        <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
        <div class="col-md-6">
          <input name="new_password" type="password" class="form-control" placeholder="Mật khẩu mới (>=6 ký tự)" required>
        </div>
        <div class="col-md-6">
          <button class="btn btn-warning">Đặt lại mật khẩu</button>
        </div>
      </form>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php require_once __DIR__ . '/inc/footer.php'; ?>
