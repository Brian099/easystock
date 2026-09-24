<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/search_helper.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $currentUser = require_login();
    
    // 1. Get global settings config row (id = 1)
    $stmt = null;
    $row = false;
    try {
        $stmt = $pdo->query("SELECT allowEditStock, companyName, defaultSearchFields, requiredProductFields, searchNumberConvert, searchSymbolConvert, searchSpaceIgnore FROM setting WHERE id = 1 LIMIT 1");
        $row = $stmt ? $stmt->fetch() : false;
    } catch (Throwable $e) {
        // Fallback
        $row = false;
    }
    
    $defaultFields = (!empty($row['defaultSearchFields'])) ? (string)$row['defaultSearchFields'] : 'name,model,spec,barcode,brand,local,mark';
    $requiredProductFields = (!empty($row['requiredProductFields'])) ? (string)$row['requiredProductFields'] : 'name';
    $searchNumberConvert = (($row['searchNumberConvert'] ?? 'true') === 'false') ? 'false' : 'true';
    $searchSymbolConvert = (($row['searchSymbolConvert'] ?? 'true') === 'false') ? 'false' : 'true';
    $searchSpaceIgnore = (($row['searchSpaceIgnore'] ?? 'true') === 'false') ? 'false' : 'true';
    
    $setting = [
        'id' => 1,
        'allowEditStock' => (($row['allowEditStock'] ?? 'false') === 'true') ? 'true' : 'false',
        'companyName' => (string)($row['companyName'] ?? ''),
        'defaultSearchFields' => $defaultFields,
        'requiredProductFields' => $requiredProductFields,
        'searchNumberConvert' => $searchNumberConvert,
        'searchSymbolConvert' => $searchSymbolConvert,
        'searchSpaceIgnore' => $searchSpaceIgnore
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
    
    // Validate only allowed search fields
    $valid_search_fields = ['name', 'model', 'spec', 'barcode', 'brand', 'local', 'mark'];
    $submitted_search_fields = array_filter(array_map('trim', explode(',', $defaultSearchFields)));
    $filtered_search_fields = array_values(array_intersect($submitted_search_fields, $valid_search_fields));
    $cleanDefaultSearchFields = implode(',', $filtered_search_fields);

    // Validate required product fields
    $rawRequiredProductFields = isset($input['requiredProductFields']) ? trim((string)$input['requiredProductFields']) : 'name';
    $valid_product_fields = ['name', 'model', 'spec', 'barcode', 'unit', 'brand', 'local', 'price', 'mark'];
    $submitted_req_fields = array_filter(array_map('trim', explode(',', $rawRequiredProductFields)));
    $filtered_req_fields = array_values(array_intersect($submitted_req_fields, $valid_product_fields));
    // Always ensure valid string (if none selected, defaults to empty or name)
    $cleanRequiredProductFields = implode(',', $filtered_req_fields);

    // Validate search number convert switch
    $searchNumberConvert = trim($input['searchNumberConvert'] ?? 'true');
    if (!in_array($searchNumberConvert, ['true', 'false'])) {
        $searchNumberConvert = 'true';
    }

    // Validate search symbol convert switch
    $searchSymbolConvert = trim($input['searchSymbolConvert'] ?? 'true');
    if (!in_array($searchSymbolConvert, ['true', 'false'])) {
        $searchSymbolConvert = 'true';
    }

    // Validate search space ignore switch
    $searchSpaceIgnore = trim($input['searchSpaceIgnore'] ?? 'true');
    if (!in_array($searchSpaceIgnore, ['true', 'false'])) {
        $searchSpaceIgnore = 'true';
    }

    try {
        // Standard SQL update/insert compatible with both SQLite and MySQL
        $stmt = $pdo->prepare("UPDATE setting SET allowEditStock = ?, companyName = ?, defaultSearchFields = ?, requiredProductFields = ?, searchNumberConvert = ?, searchSymbolConvert = ?, searchSpaceIgnore = ? WHERE id = 1");
        $stmt->execute([$allowEditStock, $companyName, $cleanDefaultSearchFields, $cleanRequiredProductFields, $searchNumberConvert, $searchSymbolConvert, $searchSpaceIgnore]);
        
        $chk = $pdo->query("SELECT id FROM setting WHERE id = 1");
        if (!$chk->fetch()) {
            $ins = $pdo->prepare("INSERT INTO setting (id, allowEditStock, companyName, defaultSearchFields, requiredProductFields, searchNumberConvert, searchSymbolConvert, searchSpaceIgnore) VALUES (1, ?, ?, ?, ?, ?, ?, ?)");
            $ins->execute([$allowEditStock, $companyName, $cleanDefaultSearchFields, $cleanRequiredProductFields, $searchNumberConvert, $searchSymbolConvert, $searchSpaceIgnore]);
        }
        
        send_json([
            'success' => true,
            'settings' => [
                'id' => 1,
                'allowEditStock' => $allowEditStock,
                'companyName' => $companyName,
                'defaultSearchFields' => $cleanDefaultSearchFields,
                'requiredProductFields' => $cleanRequiredProductFields,
                'searchNumberConvert' => $searchNumberConvert,
                'searchSymbolConvert' => $searchSymbolConvert,
                'searchSpaceIgnore' => $searchSpaceIgnore
            ]
        ]);
    } catch (Exception $e) {
        // If column missing, give friendly guide
        if (strpos($e->getMessage(), 'Unknown column') !== false || strpos($e->getMessage(), 'no such column') !== false) {
            send_json(['error' => '数据库缺少设置字段，请访问或执行 api/migrate.php 进行结构更新。详细: ' . $e->getMessage()], 500);
        }
        send_json(['error' => 'Failed to save settings: ' . $e->getMessage()], 500);
    }
}

else {
    send_json(['error' => 'HTTP Method not allowed.'], 405);
}
