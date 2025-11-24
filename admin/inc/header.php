<?php
// admin/inc/header.php
// Include this at top of admin pages. It ensures $conn (PDO) is available,
// checks admin session, creates CSRF token, and prints topbar + sidebar + opens main content area.

if (session_status() === PHP_SESSION_NONE) session_start();

// require DB and helpers from project root
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../inc/helpers.php';

// basic fallback helpers
if (!function_exists('esc')) {
    function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('site_name')) {
    function site_name($conn = null){ return 'AE Shop'; }
}

// Guard: must be logged in and is_admin
$loggedUser = $_SESSION['user'] ?? null;
$isAdmin = !empty($_SESSION['is_admin']) && !empty($loggedUser);

if (!$isAdmin) {
    // try redirect to admin login (if exists) or root login
    $login = file_exists(__DIR__ . '/../login.php') ? '../admin/login.php' : '../login.php';
    header('Location: ../login.php');
    exit;
}

// create CSRF token for admin actions
if (empty($_SESSION['csrf_admin'])) {
    $_SESSION['csrf_admin'] = bin2hex(random_bytes(16));
}

?>
<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <title>Admin — <?= esc(site_name($conn)) ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body { background:#f4f6fb; color:#222; }
    .topbar{background:#fff;padding:12px 18px;border-bottom:1px solid #eef3f8;box-shadow:0 6px 18px rgba(11,38,80,0.03)}
    .sidebar{background:#fff;padding:16px;border-right:1px solid #eef3f8;min-height:calc(100vh - 64px)}
    .main{padding:24px}
    .nav-link.active{background:linear-gradient(90deg,#e8f4ff,#fff);border-radius:8px}
    .brand { font-weight:800; }
    .small-muted{ color:#6c757d; font-size:.9rem; }
    .content-wrap { min-height: calc(100vh - 72px); }
  </style>
</head>
<body>
  <div class="topbar d-flex justify-content-between align-items-center">
    <div><strong><?= esc(site_name($conn)) ?> — Admin</strong></div>
    <div class="d-flex align-items-center gap-3">
      <div class="small-muted">Xin chào, <?= esc($loggedUser['ten'] ?? 'Admin') ?></div>
      <a href="../index.php" class="btn btn-sm btn-outline-secondary">Xem site</a>
      <a href="../logout.php" class="btn btn-sm btn-outline-danger">Đăng xuất</a>
    </div>
  </div>

  <div class="container-fluid content-wrap">
    <div class="row g-0">
      <aside class="col-md-2 sidebar">
        <nav class="nav flex-column">
          <a class="nav-link <?= (basename($_SERVER['PHP_SELF'])==='index.php' ? 'active' : '') ?>" href="index.php"><i class="bi bi-speedometer2 me-1"></i> Dashboard</a>
          <a class="nav-link <?= (basename($_SERVER['PHP_SELF'])==='users.php' ? 'active' : '') ?>" href="users.php"><i class="bi bi-people me-1"></i> Người dùng</a>
          <a class="nav-link <?= (basename($_SERVER['PHP_SELF'])==='products.php' ? 'active' : '') ?>" href="products.php"><i class="bi bi-box-seam me-1"></i> Sản phẩm</a>
          <a class="nav-link <?= (basename($_SERVER['PHP_SELF'])==='inventory_log.php' ? 'active' : '') ?>" href="inventory_log.php"><i class="bi bi-clock-history me-1"></i> Lịch sử tồn kho</a>
          <hr>
          <div class="small-muted mt-2">Phiên: <?= esc($loggedUser['email'] ?? '') ?></div>
        </nav>
      </aside>

      <main class="col-md-10 main">
        <!-- admin page content starts here -->
<?php
// header ends — admin pages continue rendering content (and must include footer.php at end)
