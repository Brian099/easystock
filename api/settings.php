<?php
require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $currentUser = require_login();
    
    // 1. Get global settings config row (id = 1)
    $stmt = $pdo->query("SELECT allowEditStock FROM setting LIMIT 1");
    $allowEditStock = $stmt->fetchColumn();
    
    $setting = [
        'id' => 1,
        'allowEditStock' => ($allowEditStock === 'true') ? 'true' : 'false'
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
    
    try {
        // Upsert setting row with id = 1
        $stmt = $pdo->prepare("
            INSERT INTO setting (id, allowEditStock) 
            VALUES (1, ?)
            ON DUPLICATE KEY UPDATE 
                allowEditStock = VALUES(allowEditStock)
        ");
        $stmt->execute([$allowEditStock]);
        
        send_json([
            'success' => true,
            'settings' => [
                'id' => 1,
                'allowEditStock' => $allowEditStock
            ]
        ]);
    } catch (Exception $e) {
        send_json(['error' => 'Failed to save settings: ' . $e->getMessage()], 500);
    }
}

else {
    send_json(['error' => 'HTTP Method not allowed.'], 405);
}

