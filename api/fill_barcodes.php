<?php
require_once __DIR__ . '/config.php';

// Requires admin privileges to batch update barcodes
$currentUser = require_admin();

$action = $_GET['action'] ?? 'check';

/**
 * Calculates EAN-13 check digit for 12-digit string
 */
function calculate_ean13_checksum($first12) {
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
 * Generates an EAN-13 barcode based on product ID and random seed
 */
function generate_barcode_for_id($productId, $existingSet) {
    // 690 + 6 digits ID-based/random + 3 random digits + 1 checksum
    $paddedId = str_pad(substr((string)$productId, -6), 6, '0', STR_PAD_LEFT);
    
    $attempts = 0;
    do {
        $attempts++;
        $rand = str_pad((string)mt_rand(100, 999), 3, '0', STR_PAD_LEFT);
        $first12 = '690' . $paddedId . $rand;
        $check = calculate_ean13_checksum($first12);
        $code = $first12 . $check;
    } while (isset($existingSet[$code]) && $attempts < 100);

    return $code;
}

if ($action === 'check') {
    $total = (int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
    $missing = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE barcode IS NULL OR TRIM(barcode) = ''")->fetchColumn();
    $filled = $total - $missing;

    send_json([
        'total_products' => $total,
        'missing_barcodes' => $missing,
        'filled_barcodes' => $filled
    ]);
}

elseif ($action === 'execute') {
    // 1. Fetch all existing barcodes to avoid any collision
    $exist_stmt = $pdo->query("SELECT barcode FROM products WHERE barcode IS NOT NULL AND TRIM(barcode) != ''");
    $existingList = $exist_stmt->fetchAll(PDO::FETCH_COLUMN);
    $existingSet = array_flip($existingList);

    // 2. Fetch all products without barcodes
    $missing_stmt = $pdo->query("SELECT id, name FROM products WHERE barcode IS NULL OR TRIM(barcode) = '' ORDER BY id ASC");
    $missingProducts = $missing_stmt->fetchAll();

    $updatedCount = 0;
    $pdo->beginTransaction();

    try {
        $update_stmt = $pdo->prepare("UPDATE products SET barcode = ? WHERE id = ?");

        foreach ($missingProducts as $prod) {
            $newBarcode = generate_barcode_for_id($prod['id'], $existingSet);
            $existingSet[$newBarcode] = true; // register as used

            $update_stmt->execute([$newBarcode, $prod['id']]);
            $updatedCount++;
        }

        $pdo->commit();

        send_json([
            'success' => true,
            'updated_count' => $updatedCount,
            'message' => "成功为 {$updatedCount} 个商品生成并补全了唯一条形码！"
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        send_json(['error' => '补全条码失败: ' . $e->getMessage()], 500);
    }
}

else {
    send_json(['error' => 'Invalid action. Supported: check, execute'], 400);
}
