<?php
// db.php - cấu hình DB (fix tên CSDL nếu khác)
$DB_HOST = '127.0.0.1';
$DB_NAME = 'ae_shop';
$DB_USER = 'root';
$DB_PASS = '';
$DB_CHAR = 'utf8mb4';

$dsn = "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=$DB_CHAR";
try {
    $conn = new PDO($dsn, $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    die("Kết nối CSDL thất bại: " . $e->getMessage());
}
