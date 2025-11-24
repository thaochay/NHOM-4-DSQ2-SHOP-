<?php
// admin/products.php
// Quản lý sản phẩm + quản lý ảnh sản phẩm (upload / set main / delete)
// Yêu cầu: admin/inc/header.php phải tạo $conn (PDO) và kiểm tra quyền admin

require_once __DIR__ . '/inc/header.php';
/** @var PDO $conn */

// safe helpers (tránh redeclare)
if (!function_exists('esc')) {
    function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('price')) {
    function price($v){ return number_format((float)$v,0,',','.') . ' ₫'; }
}
if (!function_exists('flash')) {
    function flash($type, $msg) { $_SESSION['flash_admin_' . $type] = $msg; }
}
if (!function_exists('flash_get_once')) {
    function flash_get_once($key) {
        $k = 'flash_admin_' . $key;
        $v = $_SESSION[$k] ?? null;
        if ($v) unset($_SESSION[$k]);
        return $v;
    }
}

/**
 * Check whether a column exists in a table (cached per-request)
 */
$__column_check_cache = [];
function columnExists(PDO $conn, string $table, string $column) {
    global $__column_check_cache;
    $key = $table . '::' . $column;
    if (array_key_exists($key, $__column_check_cache)) return $__column_check_cache[$key];
    try {
        $q = $conn->prepare("SHOW COLUMNS FROM `$table` LIKE :col");
        $q->execute([':col'=>$column]);
        $exists = (bool)$q->fetchColumn();
    } catch (Exception $e) {
        $exists = false;
    }
    $__column_check_cache[$key] = $exists;
    return $exists;
}

// CSRF admin token
if (!isset($_SESSION['csrf_admin'])) $_SESSION['csrf_admin'] = bin2hex(random_bytes(16));

// --- image table column mapping (adjust here if your column names differ) ---
// assumed columns in anh_san_pham: id_anh, id_san_pham, duong_dan (path), is_chinh, thu_tu, created_at
// If your DB uses different names, change the variables below accordingly.
$img_table = 'anh_san_pham';
$img_col_id = columnExists($conn, $img_table, 'id_anh') ? 'id_anh' : (columnExists($conn,$img_table,'id') ? 'id' : 'id_anh');
$img_col_product = columnExists($conn, $img_table, 'id_san_pham') ? 'id_san_pham' : 'id_san_pham';
$img_col_path = columnExists($conn, $img_table, 'duong_dan') ? 'duong_dan' : (columnExists($conn,$img_table,'path') ? 'path' : 'duong_dan');
$img_col_main = columnExists($conn, $img_table, 'is_chinh') ? 'is_chinh' : (columnExists($conn,$img_table,'is_main') ? 'is_main' : 'is_chinh');
$img_col_order = columnExists($conn, $img_table, 'thu_tu') ? 'thu_tu' : (columnExists($conn,$img_table,'sort_order') ? 'sort_order' : 'thu_tu');
$img_col_created = columnExists($conn, $img_table, 'created_at') ? 'created_at' : 'created_at';

// upload directory (ensure webserver có quyền ghi)
$uploadDir = __DIR__ . '/../uploads/products/';
$uploadUrlBase = 'uploads/products/'; // đường dẫn tương đối dùng trong HTML <img src="...">

if (!file_exists($uploadDir)) {
    @mkdir($uploadDir, 0755, true);
}

/* ---------- Handle POST actions (including image actions) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // CSRF check
    if (!hash_equals($_SESSION['csrf_admin'] ?? '', $_POST['csrf'] ?? '')) {
        flash('error', 'CSRF token không hợp lệ.');
        header('Location: products.php');
        exit;
    }

    // ---------- image upload ----------
    if ($action === 'upload_images') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            flash('error','Sản phẩm không hợp lệ khi upload ảnh.');
            header('Location: products.php');
            exit;
        }
        if (!isset($_FILES['images'])) {
            flash('error','Không có file nào được gửi lên.');
            header('Location: products.php?edit=' . $id);
            exit;
        }

        $files = $_FILES['images'];
        $n = count($files['name']);
        $saved = 0;
        $errors = [];
        for ($i=0;$i<$n;$i++) {
            if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                $errors[] = "File " . ($files['name'][$i] ?? '') . " upload lỗi code " . $files['error'][$i];
                continue;
            }
            $tmp = $files['tmp_name'][$i];
            $orig = $files['name'][$i];
            // sanitize filename
            $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png','webp','gif'])) {
                $errors[] = "File {$orig} không đúng định dạng (jpg/png/webp/gif).";
                continue;
            }
            $basename = bin2hex(random_bytes(8)) . '.' . $ext;
            $dest = $uploadDir . $basename;
            if (!move_uploaded_file($tmp, $dest)) {
                $errors[] = "Không thể lưu file {$orig}.";
                continue;
            }
            // optional: create thumbnail here if needed

            // determine next sort order
            try {
                $sth = $conn->prepare("SELECT COALESCE(MAX(`{$img_col_order}`),0)+1 FROM `{$img_table}` WHERE `{$img_col_product}` = :pid");
                $sth->execute([':pid'=>$id]);
                $order = (int)$sth->fetchColumn();
            } catch (Exception $e) { $order = 1; }

            // insert DB record
            try {
                $ins = $conn->prepare("INSERT INTO `{$img_table}` (`{$img_col_product}`, `{$img_col_path}`, `{$img_col_main}`, `{$img_col_order}`, `{$img_col_created}`) VALUES (:pid, :path, 0, :ord, NOW())");
                $ins->execute([
                    ':pid' => $id,
                    ':path' => $uploadUrlBase . $basename,
                    ':ord' => $order
                ]);
                $saved++;
            } catch (Exception $e) {
                // rollback file if DB insert fails
                @unlink($dest);
                $errors[] = "Lỗi DB khi lưu thông tin ảnh: " . $e->getMessage();
            }
        }

        if ($saved) flash('success', "Đã lưu {$saved} ảnh.");
        if ($errors) flash('error', implode('<br>', $errors));
        header('Location: products.php?edit=' . $id);
        exit;
    }

    // ---------- set main image ----------
    if ($action === 'set_main_image') {
        $id = (int)($_POST['product_id'] ?? 0);
        $imgId = (int)($_POST['img_id'] ?? 0);
        if ($id <= 0 || $imgId <= 0) {
            flash('error','Yêu cầu không hợp lệ.');
            header('Location: products.php?edit=' . $id);
            exit;
        }
        try {
            // unset current main
            $conn->beginTransaction();
            $u1 = $conn->prepare("UPDATE `{$img_table}` SET `{$img_col_main}` = 0 WHERE `{$img_col_product}` = :pid");
            $u1->execute([':pid'=>$id]);
            $u2 = $conn->prepare("UPDATE `{$img_table}` SET `{$img_col_main}` = 1 WHERE `{$img_col_id}` = :imgid AND `{$img_col_product}` = :pid");
            $u2->execute([':imgid'=>$imgId, ':pid'=>$id]);
            $conn->commit();
            flash('success','Đã đặt ảnh chính.');
        } catch (Exception $e) {
            $conn->rollBack();
            flash('error','Lỗi khi đặt ảnh chính: ' . $e->getMessage());
        }
        header('Location: products.php?edit=' . $id);
        exit;
    }

    // ---------- delete image ----------
    if ($action === 'delete_image') {
        $id = (int)($_POST['product_id'] ?? 0);
        $imgId = (int)($_POST['img_id'] ?? 0);
        if ($imgId <= 0) {
            flash('error','Ảnh không hợp lệ.');
            header('Location: products.php?edit=' . $id);
            exit;
        }
        try {
            // fetch path
            $s = $conn->prepare("SELECT `{$img_col_path}` FROM `{$img_table}` WHERE `{$img_col_id}` = :imgid LIMIT 1");
            $s->execute([':imgid'=>$imgId]);
            $path = $s->fetchColumn();
            // delete DB row
            $d = $conn->prepare("DELETE FROM `{$img_table}` WHERE `{$img_col_id}` = :imgid");
            $d->execute([':imgid'=>$imgId]);
            // delete file (best-effort)
            if ($path) {
                $file = realpath(__DIR__ . '/../' . ltrim($path, '/'));
                if ($file && strpos($file, realpath(__DIR__ . '/../uploads')) === 0) {
                    @unlink($file);
                }
            }
            flash('success','Đã xóa ảnh.');
        } catch (Exception $e) {
            flash('error','Lỗi khi xóa ảnh: ' . $e->getMessage());
        }
        header('Location: products.php?edit=' . $id);
        exit;
    }
    // ---------- end image actions ----------
}

/* ---------- GET: list, search, pagination ---------- */
$search = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['p'] ?? 1));
$perPage = 30;
$offset = ($page - 1) * $perPage;

$where = "1=1";
$params = [];
if ($search !== '') {
    $where = "(ten LIKE :kw OR ma_san_pham LIKE :kw)";
    $params[':kw'] = '%' . $search . '%';
}

// total
$totalStmt = $conn->prepare("SELECT COUNT(*) FROM san_pham WHERE $where");
$totalStmt->execute($params);
$total = (int)$totalStmt->fetchColumn();
$pages = max(1, ceil($total / $perPage));

// fetch products
$listStmt = $conn->prepare("SELECT id_san_pham, ma_san_pham, ten, gia, gia_cu, so_luong, trang_thai, created_at FROM san_pham WHERE $where ORDER BY created_at DESC LIMIT :off, :lim");
foreach ($params as $k=>$v) $listStmt->bindValue($k, $v);
$listStmt->bindValue(':off', (int)$offset, PDO::PARAM_INT);
$listStmt->bindValue(':lim', (int)$perPage, PDO::PARAM_INT);
$listStmt->execute();
$products = $listStmt->fetchAll(PDO::FETCH_ASSOC);

// edit/new form data (preserve on error)
$formData = $_SESSION['admin_product_form'] ?? null;
if ($formData) unset($_SESSION['admin_product_form']);

$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editProduct = null;
$productImages = [];
if ($editId > 0) {
    $stm = $conn->prepare("SELECT * FROM san_pham WHERE id_san_pham = :id LIMIT 1");
    $stm->execute([':id'=>$editId]);
    $editProduct = $stm->fetch(PDO::FETCH_ASSOC);

    // fetch images for this product
    try {
        $imgQ = "SELECT * FROM `{$img_table}` WHERE `{$img_col_product}` = :pid ORDER BY `{$img_col_order}` ASC, `{$img_col_id}` ASC";
        $imgSt = $conn->prepare($imgQ);
        $imgSt->execute([':pid'=>$editId]);
        $productImages = $imgSt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $productImages = [];
    }
} elseif (isset($_GET['new'])) {
    $editProduct = ['id_san_pham'=>0,'ma_san_pham'=>'','ten'=>'','gia'=>0,'gia_cu'=>null,'so_luong'=>0,'mo_ta'=>'','trang_thai'=>1];
}

/* ---------- Render UI ---------- */
?>
<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <title>Quản lý Sản phẩm — Admin</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body{background:#f6f8fb;font-family:Inter,system-ui,Roboto,Arial}
    .card{border-radius:12px}
    .small-muted{color:#6c757d}
    .table thead th{border-bottom:2px solid #eef3f8}
    .badge-status{padding:6px 8px;border-radius:999px}
    .top-actions .form-control{min-width:300px}
    .thumb { width:120px; height:120px; object-fit:cover; border-radius:8px; border:1px solid #e9eef8 }
    .img-grid { display:flex; gap:12px; flex-wrap:wrap; }
    .img-box { width:120px; text-align:center; }
    .img-actions { margin-top:6px; display:flex; gap:6px; justify-content:center; }
  </style>
</head>
<body>
<?php if (file_exists(__DIR__ . '/inc/topbar.php')) require_once __DIR__ . '/inc/topbar.php'; ?>

<div class="container-fluid my-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4>Quản lý Sản phẩm</h4>
    <div class="d-flex gap-2">
      <a href="index.php" class="btn btn-outline-secondary btn-sm">← Dashboard</a>
      <a href="products.php?new=1" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i> Thêm sản phẩm</a>
    </div>
  </div>

  <?php if ($msg = flash_get_once('success')): ?><div class="alert alert-success"><?= esc($msg) ?></div><?php endif; ?>
  <?php if ($msg = flash_get_once('error')): ?><div class="alert alert-danger"><?= $msg ?></div><?php endif; ?>

  <div class="card p-3 mb-3">
    <form method="get" class="d-flex gap-2 align-items-center top-actions">
      <input name="q" class="form-control form-control-sm" placeholder="Tìm tên hoặc mã sản phẩm" value="<?= esc($search) ?>">
      <button class="btn btn-sm btn-dark">Tìm</button>
      <a href="products.php" class="btn btn-sm btn-outline-secondary">Làm mới</a>
      <div class="ms-auto small-muted">Tổng: <?= $total ?> sản phẩm</div>
    </form>
  </div>

  <div class="card p-3 mb-3">
    <div class="table-responsive">
      <table class="table table-hover align-middle">
        <thead><tr><th>#</th><th>Mã</th><th>Tên</th><th>Giá</th><th>Giá cũ</th><th>Tồn</th><th>Trạng thái</th><th>Ngày tạo</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($products as $p): ?>
            <tr>
              <td><?= (int)$p['id_san_pham'] ?></td>
              <td><?= esc($p['ma_san_pham']) ?></td>
              <td><?= esc($p['ten']) ?></td>
              <td><?= number_format((float)$p['gia'],0,',','.') ?> ₫</td>
              <td><?= $p['gia_cu'] ? number_format((float)$p['gia_cu'],0,',','.') . ' ₫' : '-' ?></td>
              <td><?= (int)$p['so_luong'] ?></td>
              <td><?= (int)$p['trang_thai'] ? '<span class="small text-success">Hiển thị</span>' : '<span class="small text-muted">Ẩn</span>' ?></td>
              <td class="small text-muted"><?= esc($p['created_at']) ?></td>
              <td class="text-end">
                <a class="btn btn-sm btn-outline-primary" href="products.php?edit=<?= (int)$p['id_san_pham'] ?>">Sửa</a>
                <form method="post" style="display:inline" onsubmit="return confirm('Xóa sản phẩm này?');">
                  <input type="hidden" name="csrf" value="<?= esc($_SESSION['csrf_admin']) ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$p['id_san_pham'] ?>">
                  <button class="btn btn-sm btn-danger">Xóa</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($products)): ?><tr><td colspan="9" class="text-center text-muted">Không tìm thấy sản phẩm</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="d-flex justify-content-between align-items-center mt-3">
      <div class="small-muted">Trang <?= $page ?> / <?= $pages ?></div>
      <nav><ul class="pagination mb-0">
        <?php for ($i=1;$i<=$pages;$i++): ?>
          <li class="page-item <?= $i === $page ? 'active' : '' ?>"><a class="page-link" href="?p=<?= $i ?>&q=<?= urlencode($search) ?>"><?= $i ?></a></li>
        <?php endfor; ?>
      </ul></nav>
    </div>
  </div>

  <?php if ($editProduct !== null): ?>
    <div class="card p-3 mb-5">
      <h5 class="mb-3"><?= !empty($editProduct['id_san_pham']) ? 'Sửa sản phẩm #' . (int)$editProduct['id_san_pham'] : 'Tạo sản phẩm mới' ?></h5>

      <?php if ($msg = flash_get_once('error')): ?><div class="alert alert-danger"><?= $msg ?></div><?php endif; ?>

      <?php
        $d = $formData ?? $editProduct;
        $d['id'] = $d['id_san_pham'] ?? ($d['id'] ?? 0);
      ?>
      <form method="post" class="row g-2">
        <input type="hidden" name="csrf" value="<?= esc($_SESSION['csrf_admin']) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">

        <div class="col-md-3">
          <label class="form-label small">Mã sản phẩm</label>
          <input name="ma_san_pham" class="form-control" required value="<?= esc($d['ma_san_pham'] ?? $d['ma'] ?? '') ?>">
        </div>

        <div class="col-md-6">
          <label class="form-label small">Tên sản phẩm</label>
          <input name="ten" class="form-control" required value="<?= esc($d['ten'] ?? '') ?>">
        </div>

        <div class="col-md-3">
          <label class="form-label small">Trạng thái</label>
          <select name="trang_thai" class="form-select">
            <option value="1" <?= (!isset($d['trang_thai']) || $d['trang_thai']==1) ? 'selected' : '' ?>>Hiển thị</option>
            <option value="0" <?= (isset($d['trang_thai']) && $d['trang_thai']==0) ? 'selected' : '' ?>>Ẩn</option>
          </select>
        </div>

        <div class="col-md-3">
          <label class="form-label small">Giá (VNĐ)</label>
          <input name="gia" type="number" step="1" min="0" class="form-control" required value="<?= (isset($d['gia']) ? (float)$d['gia'] : 0) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label small">Giá cũ (tùy chọn)</label>
          <input name="gia_cu" type="number" step="1" min="0" class="form-control" value="<?= (isset($d['gia_cu']) && $d['gia_cu']!=='') ? (float)$d['gia_cu'] : '' ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label small">Số lượng tồn</label>
          <input name="so_luong" type="number" step="1" min="0" class="form-control" value="<?= (int)($d['so_luong'] ?? 0) ?>">
        </div>

        <div class="col-12">
          <label class="form-label small">Mô tả</label>
          <textarea name="mo_ta" class="form-control" rows="6"><?= esc($d['mo_ta'] ?? '') ?></textarea>
        </div>

        <div class="col-12 d-flex gap-2 mt-2">
          <button class="btn btn-primary"><?= $d['id'] ? 'Lưu thay đổi' : 'Tạo sản phẩm' ?></button>
          <a href="products.php" class="btn btn-outline-secondary">Hủy</a>
        </div>
      </form>

      <?php if (!empty($d['id'])): ?>
        <hr>
        <h6>Ảnh sản phẩm</h6>

        <div class="mb-3">
          <div class="img-grid">
            <?php foreach ($productImages as $img): 
                $imgIdVal = $img[$img_col_id] ?? ($img['id_anh'] ?? $img['id'] ?? '');
                $imgPathVal = $img[$img_col_path] ?? ($img['duong_dan'] ?? $img['path'] ?? '');
                $isMain = !empty($img[$img_col_main]) || !empty($img['is_chinh']) || !empty($img['is_main']);
            ?>
              <div class="img-box">
                <img src="<?= esc($imgPathVal) ?>" class="thumb" alt="ảnh <?= esc($imgIdVal) ?>">
                <div class="img-actions">
                  <form method="post" style="display:inline">
                    <input type="hidden" name="csrf" value="<?= esc($_SESSION['csrf_admin']) ?>">
                    <input type="hidden" name="action" value="set_main_image">
                    <input type="hidden" name="product_id" value="<?= (int)$d['id'] ?>">
                    <input type="hidden" name="img_id" value="<?= esc($imgIdVal) ?>">
                    <button class="btn btn-sm <?= $isMain ? 'btn-success' : 'btn-outline-secondary' ?>" title="Đặt làm ảnh chính"><?= $isMain ? '<i class="bi bi-star-fill"></i>' : '<i class="bi bi-star"></i>' ?></button>
                  </form>

                  <form method="post" onsubmit="return confirm('Xác nhận xóa ảnh?');" style="display:inline">
                    <input type="hidden" name="csrf" value="<?= esc($_SESSION['csrf_admin']) ?>">
                    <input type="hidden" name="action" value="delete_image">
                    <input type="hidden" name="product_id" value="<?= (int)$d['id'] ?>">
                    <input type="hidden" name="img_id" value="<?= esc($imgIdVal) ?>">
                    <button class="btn btn-sm btn-outline-danger" title="Xóa ảnh"><i class="bi bi-trash"></i></button>
                  </form>
                </div>
              </div>
            <?php endforeach; ?>
            <?php if (empty($productImages)): ?>
              <div class="text-muted">Chưa có ảnh nào cho sản phẩm này.</div>
            <?php endif; ?>
          </div>
        </div>

        <div class="mb-3">
          <form method="post" enctype="multipart/form-data" class="row g-2">
            <input type="hidden" name="csrf" value="<?= esc($_SESSION['csrf_admin']) ?>">
            <input type="hidden" name="action" value="upload_images">
            <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
            <div class="col-md-8">
              <input type="file" name="images[]" accept="image/*" multiple class="form-control">
              <div class="small text-muted mt-1">Hỗ trợ: jpg, png, webp, gif. Tải lên nhiều ảnh cùng lúc.</div>
            </div>
            <div class="col-md-4 d-flex align-items-end">
              <button class="btn btn-primary w-100">Tải ảnh lên</button>
            </div>
          </form>
        </div>

        <hr>
        <h6>Điều chỉnh tồn kho (sẽ ghi log nếu bảng inventory_log tồn tại)</h6>
        <form method="post" class="row g-2" onsubmit="return confirm('Xác nhận điều chỉnh tồn kho?');">
          <input type="hidden" name="csrf" value="<?= esc($_SESSION['csrf_admin']) ?>">
          <input type="hidden" name="action" value="stock_adjust">
          <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">

          <div class="col-md-3">
            <label class="form-label small">Δ Số lượng (ví dụ +5 hoặc -3)</label>
            <input name="delta" type="number" step="1" class="form-control" required>
          </div>
          <div class="col-md-6">
            <label class="form-label small">Ghi chú</label>
            <input name="note" class="form-control" placeholder="Lý do điều chỉnh, người thực hiện...">
          </div>
          <div class="col-md-3 d-flex align-items-end">
            <button class="btn btn-warning w-100">Cập nhật kho</button>
          </div>
        </form>
      <?php endif; ?>
    </div>
  <?php endif; ?>

</div>

<?php if (file_exists(__DIR__ . '/inc/footer.php')) require_once __DIR__ . '/inc/footer.php'; ?>
</body>
</html>
