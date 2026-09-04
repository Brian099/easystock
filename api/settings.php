<?php
require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $currentUser = require_login();
    
    // 1. Get global settings config row (id = 1)
    $stmt = $pdo->query("SELECT allowEditStock, companyName, defaultSearchFields FROM setting WHERE id = 1 LIMIT 1");
    $row = $stmt ? $stmt->fetch() : false;
    
    $defaultFields = (!empty($row['defaultSearchFields'])) ? (string)$row['defaultSearchFields'] : 'name,model,spec,barcode,brand,local,mark';
    
    $setting = [
        'id' => 1,
        'allowEditStock' => (($row['allowEditStock'] ?? 'false') === 'true') ? 'true' : 'false',
        'companyName' => (string)($row['companyName'] ?? ''),
        'defaultSearchFields' => $defaultFields
    ];
    
    // 2. Aggregate unique brands, units, and locations directly from products table
    $brands_stmt = $pdo->query("SELECT DISTINCT brand FROM products WHERE brand IS NOT NULL AND TRIM(brand) != '' ORDER BY brand ASC");
    $brands = $brands_stmt->fetchAll(PDO::FETCH_COLUMN);

    $units_stmt = $pdo->query("SELECT DISTINCT unit FROM products WHERE unit IS NOT NULL AND TRIM(unit) != '' ORDER BY unit ASC");
    $db_units = $units_stmt->fetchAll(PDO::FETCH_COLUMN);

    $default_fallback_units = ['个', '套', '件', '台', '支', '米', '包', '箱', '瓶', '盒', '条', '卷'];
    $units = !empty($db_units) ? $db_units : $default_fallback_units;
    
    $locals_stmt = $pdo->query("SELECT DISTINCT local FROM products WHERE local IS NOT NULL AND TRIM(local) != '' ORDER BY local ASC");
    $locals = $locals_stmt->fetchAll(PDO::FETCH_COLUMN);
    
    send_json([
        'settings' => $setting,
        'suggestions' => [
            'brands' => $brands,
            'units' => $units,
            'locals' => $locals
        ]
    ]);
}

elseif ($method === 'PUT') {
    // Only admins can modify settings
    $currentUser = require_admin();
    
    // Update global settings
    $input = get_json_input();
    $allowEditStock = trim($input['allowEditStock'] ?? 'false');
    if (!in_array($allowEditStock, ['true', 'false'])) {
        $allowEditStock = 'false';
    }
    
    $companyName = isset($input['companyName']) ? trim(mb_substr((string)$input['companyName'], 0, 100, 'UTF-8')) : '';
    $defaultSearchFields = isset($input['defaultSearchFields']) ? trim((string)$input['defaultSearchFields']) : 'name,model,spec,barcode,brand,local,mark';
    
    // Validate only allowed fields
    $valid_fields = ['name', 'model', 'spec', 'barcode', 'brand', 'local', 'mark'];
    $submitted_fields = array_filter(array_map('trim', explode(',', $defaultSearchFields)));
    $filtered_fields = array_values(array_intersect($submitted_fields, $valid_fields));
    $cleanDefaultSearchFields = implode(',', $filtered_fields);

    try {
        // Standard SQL update/insert compatible with both SQLite and MySQL
        $stmt = $pdo->prepare("UPDATE setting SET allowEditStock = ?, companyName = ?, defaultSearchFields = ? WHERE id = 1");
        $stmt->execute([$allowEditStock, $companyName, $cleanDefaultSearchFields]);
        
        $chk = $pdo->query("SELECT id FROM setting WHERE id = 1");
        if (!$chk->fetch()) {
            $ins = $pdo->prepare("INSERT INTO setting (id, allowEditStock, companyName, defaultSearchFields) VALUES (1, ?, ?, ?)");
            $ins->execute([$allowEditStock, $companyName, $cleanDefaultSearchFields]);
        }
        
        send_json([
            'success' => true,
            'settings' => [
                'id' => 1,
                'allowEditStock' => $allowEditStock,
                'companyName' => $companyName,
                'defaultSearchFields' => $cleanDefaultSearchFields
            ]
        ]);
    } catch (Exception $e) {
        // If column missing, give friendly guide
        if (strpos($e->getMessage(), 'Unknown column') !== false || strpos($e->getMessage(), 'no such column') !== false) {
            send_json(['error' => '数据库缺少 defaultSearchFields 字段，请访问或执行 api/migrate.php 进行结构更新。详细: ' . $e->getMessage()], 500);
        }
        send_json(['error' => 'Failed to save settings: ' . $e->getMessage()], 500);
    }
}

else {
    send_json(['error' => 'HTTP Method not allowed.'], 405);
}
