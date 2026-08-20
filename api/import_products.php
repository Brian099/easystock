<?php
require_once __DIR__ . '/config.php';

// Requires logged in user
$currentUser = require_login();

$action = $_GET['action'] ?? 'import';

/**
 * Calculates EAN-13 check digit for 12-digit string
 */
function import_calculate_ean13_checksum($first12) {
    $sumOdd = 0;
    $sumEven = 0;
    for ($i = 0; $i < 12; $i++) {
        $num = (int)$first12[$i];
        if ($i % 2 === 0) {
            $sumOdd += $num;
        } else {
            $sumEven += $num;
        }
    }
    $total = $sumOdd + $sumEven * 3;
    return (10 - ($total % 10)) % 10;
}

/**
 * Generates an EAN-13 barcode based on random seed
 */
function import_generate_barcode($existingSet) {
    $now = new DateTime();
    $prefix = '690' . $now->format('ymd');
    
    $attempts = 0;
    do {
        $attempts++;
        $rand = str_pad((string)mt_rand(100, 999), 3, '0', STR_PAD_LEFT);
        $first12 = $prefix . $rand;
        $check = import_calculate_ean13_checksum($first12);
        $code = $first12 . $check;
    } while (isset($existingSet[$code]) && $attempts < 100);

    return $code;
}

// 1. Download Template
if ($action === 'template') {
    $filename = "商品批量导入模板.csv";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF"); // UTF-8 BOM

    // Header
    fputcsv($output, [
        '商品名称(*必填)',
        '型号',
        '规格',
        '条形码(留空可自动生成)',
        '单位',
        '品牌/厂商',
        '存放仓位',
        '单价',
        '当前库存',
        '备注信息'
    ]);

    // Sample rows
    fputcsv($output, [
        'LED 旋钮 DALI 面板 Master',
        'TD-K',
        '黑色',
        "\t6902608200018",
        '个',
        '景晴',
        'A区-01货架',
        '86.00',
        '10',
        '标准进口芯片，带旋转调光'
    ]);

    fputcsv($output, [
        '30A WIFI+RF433 涂鸦智能通断器',
        'TY-30A',
        '干接点',
        '',
        '台',
        '五十度',
        'B区-02货架',
        '35.50',
        '5',
        '支持涂鸦 App 远程控制'
    ]);

    fclose($output);
    exit;
}

// 2. Process Import File
if ($action === 'import') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        send_json(['error' => 'Method Not Allowed. Please POST file.'], 405);
    }

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        send_json(['error' => '请选择要上传的 CSV 导入文件。'], 400);
    }

    $uploadedFile = $_FILES['file']['tmp_name'];
    $mode = $_POST['mode'] ?? 'skip'; // 'skip' or 'update'
    $autoBarcode = isset($_POST['auto_barcode']) && $_POST['auto_barcode'] === '1';

    // Read and detect character encoding
    $rawContent = file_get_contents($uploadedFile);
    if (!$rawContent) {
        send_json(['error' => '上传的文件为空，请检查文件内容。'], 400);
    }

    // Convert encoding to UTF-8 if necessary
    $encoding = mb_detect_encoding($rawContent, ['UTF-8', 'GBK', 'GB2312', 'BIG5', 'CP936'], true);
    if ($encoding && $encoding !== 'UTF-8') {
        $rawContent = mb_convert_encoding($rawContent, 'UTF-8', $encoding);
    }

    // Strip UTF-8 BOM if present
    if (substr($rawContent, 0, 3) === "\xEF\xBB\xBF") {
        $rawContent = substr($rawContent, 3);
    }

    // Parse CSV lines
    $tempStream = fopen('php://memory', 'r+');
    fwrite($tempStream, $rawContent);
    rewind($tempStream);

    $headerRow = fgetcsv($tempStream);
    if (!$headerRow) {
        fclose($tempStream);
        send_json(['error' => '无法读取 CSV 表头，请确认文件格式。'], 400);
    }

    // Map column indexes by header names
    $colMap = [];
    foreach ($headerRow as $idx => $headerText) {
        $cleanHeader = trim(preg_replace('/[\s\(\*（）\)]+/u', '', $headerText));
        if (strpos($cleanHeader, '商品名称') !== false || strpos($cleanHeader, '名称') !== false) {
            $colMap['name'] = $idx;
        } elseif (strpos($cleanHeader, '型号') !== false) {
            $colMap['model'] = $idx;
        } elseif (strpos($cleanHeader, '规格') !== false) {
            $colMap['spec'] = $idx;
        } elseif (strpos($cleanHeader, '条形码') !== false || strpos($cleanHeader, '条码') !== false || strpos($cleanHeader, '编码') !== false) {
            $colMap['barcode'] = $idx;
        } elseif (strpos($cleanHeader, '单位') !== false) {
            $colMap['unit'] = $idx;
        } elseif (strpos($cleanHeader, '品牌') !== false || strpos($cleanHeader, '厂商') !== false || strpos($cleanHeader, '供应商') !== false) {
            $colMap['brand'] = $idx;
        } elseif (strpos($cleanHeader, '仓位') !== false || strpos($cleanHeader, '位置') !== false) {
            $colMap['local'] = $idx;
        } elseif (strpos($cleanHeader, '单价') !== false || strpos($cleanHeader, '价格') !== false || strpos($cleanHeader, '售价') !== false) {
            $colMap['price'] = $idx;
        } elseif (strpos($cleanHeader, '库存') !== false || strpos($cleanHeader, '数量') !== false) {
            $colMap['stock'] = $idx;
        } elseif (strpos($cleanHeader, '备注') !== false) {
            $colMap['mark'] = $idx;
        }
    }

    if (!isset($colMap['name'])) {
        fclose($tempStream);
        send_json(['error' => '表格中未找到「商品名称」列，请下载标准模板比对。'], 400);
    }

    // Load existing database barcodes and names to match duplicates efficiently
    $existBarcodeStmt = $pdo->query("SELECT id, barcode FROM products WHERE barcode IS NOT NULL AND TRIM(barcode) != ''");
    $dbBarcodes = []; // barcode => id
    while ($row = $existBarcodeStmt->fetch()) {
        $dbBarcodes[trim($row['barcode'])] = (int)$row['id'];
    }

    $existNameModelStmt = $pdo->query("SELECT id, name, model FROM products");
    $dbNameModelMap = []; // "name|model" => id
    while ($row = $existNameModelStmt->fetch()) {
        $key = trim($row['name']) . '|' . trim($row['model']);
        $dbNameModelMap[$key] = (int)$row['id'];
    }

    $usedBarcodes = $dbBarcodes; // set for unique generation

    $totalRows = 0;
    $insertedCount = 0;
    $updatedCount = 0;
    $skippedCount = 0;
    $errorDetails = [];

    $pdo->beginTransaction();

    try {
        $insertStmt = $pdo->prepare("INSERT INTO products (name, model, spec, barcode, unit, brand, local, price, stock, mark) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $updateStmt = $pdo->prepare("UPDATE products SET name = ?, model = ?, spec = ?, barcode = ?, unit = ?, brand = ?, local = ?, price = ?, stock = ?, mark = ? WHERE id = ?");
        $logStmt = $pdo->prepare("INSERT INTO stock_log (product_id, history_name, history_model, user_id, type, quantity) VALUES (?, ?, ?, ?, 'in', ?)");

        $rowNum = 1; // 1 is header
        while (($row = fgetcsv($tempStream)) !== false) {
            $rowNum++;
            // Check if entire row is empty
            if (empty(array_filter($row, function($v) { return trim($v) !== ''; }))) {
                continue;
            }

            $totalRows++;

            $name = trim($row[$colMap['name']] ?? '');
            if (!$name) {
                $skippedCount++;
                $errorDetails[] = "第 {$rowNum} 行：缺少商品名称，已跳过。";
                continue;
            }

            $model = isset($colMap['model']) ? trim($row[$colMap['model']] ?? '') : '';
            $spec = isset($colMap['spec']) ? trim($row[$colMap['spec']] ?? '') : '';
            $barcode = isset($colMap['barcode']) ? trim(preg_replace('/\s+/', '', $row[$colMap['barcode']] ?? '')) : '';
            $unit = isset($colMap['unit']) ? trim($row[$colMap['unit']] ?? '') : '';
            $brand = isset($colMap['brand']) ? trim($row[$colMap['brand']] ?? '') : '';
            $local = isset($colMap['local']) ? trim($row[$colMap['local']] ?? '') : '';
            $priceRaw = isset($colMap['price']) ? trim($row[$colMap['price']] ?? '0') : '0';
            $stockRaw = isset($colMap['stock']) ? trim($row[$colMap['stock']] ?? '0') : '0';
            $mark = isset($colMap['mark']) ? trim($row[$colMap['mark']] ?? '') : '';

            $price = number_format((float)$priceRaw, 2, '.', '');
            $stock = (int)$stockRaw;

            // Handle missing barcode
            if (!$barcode && $autoBarcode) {
                $barcode = import_generate_barcode($usedBarcodes);
                $usedBarcodes[$barcode] = true;
            } elseif ($barcode) {
                $usedBarcodes[$barcode] = true;
            }

            // Check if product exists (by barcode or by name+model)
            $existingId = null;
            if ($barcode && isset($dbBarcodes[$barcode])) {
                $existingId = $dbBarcodes[$barcode];
            } else {
                $nameModelKey = $name . '|' . $model;
                if (isset($dbNameModelMap[$nameModelKey])) {
                    $existingId = $dbNameModelMap[$nameModelKey];
                }
            }

            if ($existingId) {
                if ($mode === 'update') {
                    // Update existing
                    $updateStmt->execute([
                        $name, $model, $spec, $barcode, $unit, $brand, $local, $price, $stock, $mark, $existingId
                    ]);
                    $updatedCount++;
                } else {
                    // Skip existing
                    $skippedCount++;
                }
            } else {
                // Insert new product
                $insertStmt->execute([
                    $name, $model, $spec, $barcode, $unit, $brand, $local, $price, $stock, $mark
                ]);
                $newId = (int)$pdo->lastInsertId();
                $insertedCount++;

                // Register into map for subsequent rows in this same CSV
                if ($barcode) $dbBarcodes[$barcode] = $newId;
                $dbNameModelMap[$name . '|' . $model] = $newId;

                // Write initial stock in log if stock > 0
                if ($stock > 0) {
                    $logStmt->execute([$newId, $name, $model, $currentUser['id'], $stock]);
                }
            }
        }

        $pdo->commit();
        fclose($tempStream);

        send_json([
            'success' => true,
            'total_rows' => $totalRows,
            'inserted_count' => $insertedCount,
            'updated_count' => $updatedCount,
            'skipped_count' => $skippedCount,
            'errors' => array_slice($errorDetails, 0, 10),
            'message' => "导入完成！共读取 {$totalRows} 条，新增 {$insertedCount} 条，更新 {$updatedCount} 条，跳过 {$skippedCount} 条。"
        ]);

    } catch (Exception $e) {
        $pdo->rollBack();
        fclose($tempStream);
        send_json(['error' => '导入失败: ' . $e->getMessage()], 500);
    }
}

send_json(['error' => 'Invalid action.'], 400);
