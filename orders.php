<?php
// orders.php - danh sách & quản lý đơn hàng của user
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inc/helpers.php';

// --- Auth: require login ---
$userSess = $_SESSION['user'] ?? null;
$userId = $userSess['id_nguoi_dung'] ?? ($userSess['id'] ?? null);
if (empty($userId)) {
    header('Location: login.php'); exit;
}
$userId = (int)$userId;

// CSRF token
if (!isset($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

// POST actions (cancel)
$messages = [];
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        $errors[] = 'Token không hợp lệ. Hãy tải lại trang và thử lại.';
    } else {
        if ($act === 'cancel') {
            $id = (int)($_POST['order_id'] ?? 0);
            if ($id <= 0) $errors[] = 'ID đơn không hợp lệ.';
            else {
                try {
                    // check ownership & status
                    $s = $conn->prepare("SELECT trang_thai FROM don_hang WHERE id_don_hang = :id AND id_nguoi_dung = :uid LIMIT 1");
                    $s->execute([':id'=>$id, ':uid'=>$userId]);
                    $row = $s->fetch(PDO::FETCH_ASSOC);
                    if (!$row) {
                        $errors[] = 'Đơn hàng không tồn tại.';
                    } else {
                        $status = (string)($row['trang_thai'] ?? '');
                        // allow cancel only for statuses like 'pending','new','đang xử lý' etc.
                        $allow = preg_match('/^(pending|new|moi|đang xử lý|chuẩn bị|chưa xử lý|processing|pending_payment)$/i', trim($status));
                        if (!$allow) {
                            $errors[] = 'Đơn hàng không thể hủy ở trạng thái hiện tại (' . esc($status) . ').';
                        } else {
                            $u = $conn->prepare("UPDATE don_hang SET trang_thai = :st WHERE id_don_hang = :id AND id_nguoi_dung = :uid");
                            $u->execute([':st'=>'Đã hủy', ':id'=>$id, ':uid'=>$userId]);
                            $messages[] = 'Đã huỷ đơn #' . $id . '.';
                        }
                    }
                } catch (Throwable $e) {
                    $errors[] = 'Lỗi khi huỷ đơn: ' . $e->getMessage();
                }
            }
        }
    }
    // After POST, avoid re-post: redirect back (preserve query)
    $qs = $_SERVER['QUERY_STRING'] ? ('?' . $_SERVER['QUERY_STRING']) : '';
    header('Location: ' . $_SERVER['PHP_SELF'] . $qs);
    exit;
}

// --- Paging & filters ---
$q = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 12;
$offset = ($page - 1) * $per_page;

// build where
$where = "WHERE id_nguoi_dung = :uid";
$params = [':uid' => $userId];
if ($q !== '') {
    // search ma_don or id
    $where .= " AND (ma_don LIKE :q OR id_don_hang = :idq)";
    $params[':q'] = "%{$q}%";
    $params[':idq'] = (int)$q;
}

// count total
$total_items = 0;
try {
    $countSql = "SELECT COUNT(*) FROM don_hang $where";
    $cstmt = $conn->prepare($countSql);
    $cstmt->execute($params);
    $total_items = (int)$cstmt->fetchColumn();
} catch (Throwable $e) {
    // if table doesn't exist or other error, set total 0
    $total_items = 0;
}

// fetch orders
$orders = [];
try {
    $sql = "SELECT * FROM don_hang $where ORDER BY ngay_dat DESC LIMIT :limit OFFSET :offset";
    $stmt = $conn->prepare($sql);
    foreach ($params as $k=>$v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':limit', (int)$per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $orders = [];
    $errors[] = 'Không tải được đơn hàng: ' . $e->getMessage();
}

// For each order try to fetch items from possible detail tables
function fetch_order_items($conn, $orderId) {
    $candidates = ['chi_tiet_don_hang', 'don_hang_chi_tiet', 'order_items', 'order_detail'];
    foreach ($candidates as $tbl) {
        try {
            // Try to select typical columns
            $sql = "SELECT * FROM `$tbl` WHERE id_don_hang = :id LIMIT 100";
            $s = $conn->prepare($sql);
            $s->execute([':id'=>$orderId]);
            $rows = $s->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($rows)) return [$tbl, $rows];
        } catch (Throwable $e) {
            // ignore and try next
        }
    }
    return [null, []];
}

// helper status label
function status_label($s) {
    $s = trim((string)$s);
    $lower = mb_strtolower($s);
    if (preg_match('/hủy|huy/i', $s)) return '<span class="badge bg-danger">Đã hủy</span>';
    if (preg_match('/(đã giao|completed|delivered)/i', $s)) return '<span class="badge bg-success">Đã giao</span>';
    if (preg_match('/(đang|processing|xử lý)/i', $s)) return '<span class="badge bg-warning text-dark">Đang xử lý</span>';
    if (preg_match('/(pending|mới)/i', $s)) return '<span class="badge bg-secondary text-dark">Mới</span>';
    return '<span class="badge bg-info text-dark">' . esc($s) . '</span>';
}

$total_pages = max(1, (int)ceil($total_items / $per_page));
?>
<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <title>Đơn hàng của tôi — <?= esc(site_name($conn)) ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    .order-card { border:1px solid #eef4ff; border-radius:8px; padding:14px; background:#fff; }
    .small-muted { color:#6c757d; font-size: .95rem; }
    .items-list { max-height:240px; overflow:auto; }
  </style>
</head>
<body style="background:#f6f7f8;">
<div class="container py-4">

  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Đơn hàng của tôi</h4>
    <a href="index.php" class="btn btn-outline-secondary btn-sm">Về trang chủ</a>
  </div>

  <?php if (!empty($messages)): foreach($messages as $m): ?>
    <div class="alert alert-success"><?= esc($m) ?></div>
  <?php endforeach; endif; ?>

  <?php if (!empty($errors)): foreach($errors as $e): ?>
    <div class="alert alert-danger"><?= esc($e) ?></div>
  <?php endforeach; endif; ?>

  <div class="card p-3 mb-3">
    <form method="get" class="d-flex gap-2 align-items-center">
      <input name="q" class="form-control form-control-sm" placeholder="Tìm theo mã đơn hoặc ID" value="<?= esc($q) ?>">
      <button class="btn btn-sm btn-primary">Tìm</button>
      <a href="orders.php" class="btn btn-sm btn-outline-secondary">Reset</a>
      <div class="ms-auto small-muted">Hiển thị <?= count($orders) ?> / <?= $total_items ?> đơn</div>
    </form>
  </div>

  <?php if (empty($orders)): ?>
    <div class="alert alert-info">Không có đơn hàng nào.</div>
  <?php else: ?>
    <div class="row g-3">
      <?php foreach ($orders as $ord): 
        $oid = $ord['id_don_hang'] ?? ($ord['id'] ?? null);
        $code = $ord['ma_don'] ?? ('#' . ($oid ?? ''));
        $date = $ord['ngay_dat'] ?? ($ord['created_at'] ?? '');
        $status = $ord['trang_thai'] ?? ($ord['status'] ?? '');
        $total = $ord['tong_tien'] ?? ($ord['total'] ?? 0);
        // fetch items
        list($tblName, $items) = fetch_order_items($conn, $oid);
      ?>
      <div class="col-12">
        <div class="order-card">
          <div class="d-flex align-items-start gap-3">
            <div style="min-width:140px">
              <div class="fw-semibold">Mã: <?= esc($code) ?></div>
              <div class="small-muted"><?= esc($date) ?></div>
            </div>

            <div class="flex-grow-1">
              <div class="d-flex justify-content-between align-items-start mb-2">
                <div>
                  <div class="small-muted">Trạng thái: <?= \closure(function() use ($status) { return status_label($status); })() ?></div>
                </div>
                <div class="text-end">
                  <div class="fw-semibold"><?= function_exists('price') ? price($total) : number_format($total,0,',','.') . ' ₫' ?></div>
                </div>
              </div>

              <?php if (!empty($items)): ?>
                <div class="items-list mb-2">
                  <?php foreach($items as $it):
                    // try to find common fields
                    $iname = $it['ten'] ?? $it['name'] ?? $it['product_name'] ?? ($it['title'] ?? 'Sản phẩm');
                    $iquan = $it['so_luong'] ?? $it['qty'] ?? $it['quantity'] ?? 1;
                    $iprice = $it['gia'] ?? $it['price'] ?? $it['unit_price'] ?? 0;
                    $iimg = $it['img'] ?? $it['image'] ?? null;
                  ?>
                  <div class="d-flex align-items-center gap-3 mb-2">
                    <?php if ($iimg): ?>
                      <img src="<?= esc($iimg) ?>" alt="" style="width:56px;height:56px;object-fit:cover;border-radius:6px">
                    <?php else: ?>
                      <div style="width:56px;height:56px;background:#f3f4f6;border-radius:6px"></div>
                    <?php endif; ?>
                    <div class="flex-grow-1">
                      <div class="fw-semibold small mb-1"><?= esc($iname) ?></div>
                      <div class="small-muted">Số lượng: <?= (int)$iquan ?> &nbsp; · &nbsp; <?= function_exists('price') ? price($iprice) : number_format($iprice,0,',','.') . ' ₫' ?></div>
                    </div>
                    <div class="text-end small"><?= function_exists('price') ? price($iprice * $iquan) : number_format($iprice * $iquan,0,',','.') . ' ₫' ?></div>
                  </div>
                  <?php endforeach; ?>
                </div>
                <div class="small text-muted">Nguồn chi tiết: <?= esc($tblName) ?></div>
              <?php else: ?>
                <div class="small-muted mb-2">Không có chi tiết sản phẩm (bảng chi tiết đơn hàng chưa có hoặc tên bảng khác).</div>
              <?php endif; ?>

              <div class="d-flex gap-2 mt-3">
                <a href="order_view.php?id=<?= urlencode($oid) ?>" class="btn btn-sm btn-outline-secondary">Xem chi tiết</a>

                <?php
                  // show cancel button if allowed
                  $can_cancel = preg_match('/^(pending|new|moi|đang xử lý|chuẩn bị|chưa xử lý|processing|pending_payment)$/i', trim($status));
                ?>
                <?php if ($can_cancel): ?>
                  <form method="post" class="d-inline" onsubmit="return confirm('Bạn có chắc muốn huỷ đơn này?');">
                    <input type="hidden" name="csrf" value="<?= esc($csrf) ?>">
                    <input type="hidden" name="action" value="cancel">
                    <input type="hidden" name="order_id" value="<?= esc($oid) ?>">
                    <button class="btn btn-sm btn-outline-danger">Huỷ đơn</button>
                  </form>
                <?php else: ?>
                  <button class="btn btn-sm btn-outline-secondary" disabled>Không thể huỷ</button>
                <?php endif; ?>

              </div>
            </div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- pagination -->
    <nav class="mt-4">
      <ul class="pagination">
        <?php
          $start = max(1, $page - 3);
          $end = min($total_pages, $page + 3);
          $base = $_SERVER['PHP_SELF'] . '?';
          if ($q !== '') $base .= 'q=' . urlencode($q) . '&';
          // prev
          if ($page > 1) echo '<li class="page-item"><a class="page-link" href="'. $base .'page='.($page-1).'">&laquo;</a></li>';
          else echo '<li class="page-item disabled"><span class="page-link">&laquo;</span></li>';
          for ($i=$start;$i<=$end;$i++){
            if ($i==$page) echo '<li class="page-item active"><span class="page-link">'.$i.'</span></li>';
            else echo '<li class="page-item"><a class="page-link" href="'. $base .'page='.$i.'">'.$i.'</a></li>';
          }
          if ($page < $total_pages) echo '<li class="page-item"><a class="page-link" href="'. $base .'page='.($page+1).'">&raquo;</a></li>';
          else echo '<li class="page-item disabled"><span class="page-link">&raquo;</span></li>';
        ?>
      </ul>
    </nav>
  <?php endif; ?>

</div>

</body>
</html>
