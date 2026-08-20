<?php
require_once __DIR__ . '/config.php';

// Requires logged in user
$currentUser = require_login();

// Build query with filters
$search = trim($_GET['search'] ?? '');
$brand = trim($_GET['brand'] ?? '');
$local = trim($_GET['local'] ?? '');
$low_stock = isset($_GET['low_stock']) && $_GET['low_stock'] === '1';

$conditions = [];
$params = [];

if ($search) {
    $conditions[] = "(name LIKE ? OR model LIKE ? OR barcode LIKE ? OR spec LIKE ? OR mark LIKE ?)";
    $term = "%{$search}%";
    $params = array_merge($params, [$term, $term, $term, $term, $term]);
}

if ($brand) {
    $conditions[] = "brand = ?";
    $params[] = $brand;
}

if ($local) {
    $conditions[] = "local = ?";
    $params[] = $local;
}

if ($low_stock) {
    $conditions[] = "stock <= 2";
}

$whereClause = "";
if (!empty($conditions)) {
    $whereClause = "WHERE " . implode(' AND ', $conditions);
}

$stmt = $pdo->prepare("SELECT name, model, spec, barcode, unit, brand, local, price, stock, mark FROM products {$whereClause} ORDER BY id DESC");
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Output CSV with UTF-8 BOM
$filename = "商品列表_" . date('Ymd_His') . ".csv";

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$output = fopen('php://output', 'w');

// Write UTF-8 BOM so Excel opens it without character encoding issues
fwrite($output, "\xEF\xBB\xBF");

// Header row
fputcsv($output, [
    '商品名称',
    '型号',
    '规格',
    '条形码',
    '单位',
    '品牌/厂商',
    '存放仓位',
    '单价',
    '当前库存',
    '备注信息'
]);

// Write data rows
foreach ($products as $p) {
    $barcodeText = $p['barcode'] ?? '';
    // Prepend tab or format barcode to prevent Excel scientific notation on 13+ digit numbers
    if ($barcodeText && is_numeric($barcodeText) && strlen($barcodeText) >= 10) {
        $barcodeText = "\t" . $barcodeText;
    }

    fputcsv($output, [
        $p['name'] ?? '',
        $p['model'] ?? '',
        $p['spec'] ?? '',
        $barcodeText,
        $p['unit'] ?? '',
        $p['brand'] ?? '',
        $p['local'] ?? '',
        number_format((float)($p['price'] ?? 0), 2, '.', ''),
        (int)($p['stock'] ?? 0),
        $p['mark'] ?? ''
    ]);
}

fclose($output);
exit;
