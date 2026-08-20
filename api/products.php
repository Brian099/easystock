<?php
require_once __DIR__ . '/config.php';

$currentUser = require_login();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// Create uploads directory if not exists
$uploads_dir = __DIR__ . '/../uploads';
if (!is_dir($uploads_dir)) {
    mkdir($uploads_dir, 0755, true);
}



if ($method === 'GET') {
    // 1. Get single product by ID (for edit & quick transaction populate)
    $single_id = (int)($_GET['id'] ?? 0);
    if ($single_id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
        $stmt->execute([$single_id]);
        $p = $stmt->fetch();
        if ($p) {
            $img_stmt = $pdo->prepare("SELECT image_path FROM product_images WHERE product_id = ? LIMIT 1");
            $img_stmt->execute([$p['id']]);
            $img = $img_stmt->fetchColumn();
            $p['image'] = $img ?: null;
            send_json($p);
        } else {
            send_json(['error' => 'Product not found.'], 404);
        }
    }

    // 2. Get images list for a product
    if ($action === 'images') {
        $product_id = (int)($_GET['product_id'] ?? 0);
        if (!$product_id) {
            send_json(['error' => 'Product ID is required.'], 400);
        }
        $stmt = $pdo->prepare("SELECT * FROM product_images WHERE product_id = ? ORDER BY id DESC");
        $stmt->execute([$product_id]);
        $images = $stmt->fetchAll();
        send_json($images);
    }
    
    // 2. Default GET: List products with search, pagination, filters
    else {
        $search = trim($_GET['search'] ?? '');
        $brand = trim($_GET['brand'] ?? '');
        $unit = trim($_GET['unit'] ?? '');
        $local = trim($_GET['local'] ?? '');
        $low_stock = (int)($_GET['low_stock'] ?? 0);
        
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = max(1, min(100, (int)($_GET['limit'] ?? 30)));
        $offset = ($page - 1) * $limit;
        
        $where_clauses = [];
        $params = [];
        
        if ($search !== '') {
            $where_clauses[] = "(name LIKE ? OR model LIKE ? OR barcode LIKE ? OR spec LIKE ? OR brand LIKE ? OR local LIKE ?)";
            $search_param = "%$search%";
            $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param, $search_param, $search_param]);
        }
        
        if ($brand !== '') {
            $where_clauses[] = "brand = ?";
            $params[] = $brand;
        }
        
        if ($unit !== '') {
            $where_clauses[] = "unit = ?";
            $params[] = $unit;
        }
        
        if ($local !== '') {
            $where_clauses[] = "local = ?";
            $params[] = $local;
        }
        
        if ($low_stock === 1) {
            $where_clauses[] = "stock <= 2"; // Low stock threshold
        }
        
        $where_sql = '';
        if (!empty($where_clauses)) {
            $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
        }
        
        // Count total matching
        $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM products $where_sql");
        $count_stmt->execute($params);
        $total_items = (int)$count_stmt->fetchColumn();
        $total_pages = ceil($total_items / $limit);
        
        // Get paginated data
        $query_sql = "SELECT * FROM products $where_sql ORDER BY id DESC LIMIT $limit OFFSET $offset";
        $data_stmt = $pdo->prepare($query_sql);
        $data_stmt->execute($params);
        $products = $data_stmt->fetchAll();
        
        // Append first image to each product for the UI card view
        foreach ($products as &$p) {
            $img_stmt = $pdo->prepare("SELECT image_path FROM product_images WHERE product_id = ? LIMIT 1");
            $img_stmt->execute([$p['id']]);
            $img = $img_stmt->fetchColumn();
            $p['image'] = $img ?: null;
        }
        
        send_json([
            'products' => $products,
            'pagination' => [
                'total_items' => $total_items,
                'total_pages' => $total_pages,
                'current_page' => $page,
                'limit' => $limit
            ]
        ]);
    }
}

elseif ($method === 'POST') {
    // 1. Upload product image
    if ($action === 'upload_image') {
        $product_id = (int)($_POST['product_id'] ?? 0);
        if (!$product_id) {
            send_json(['error' => 'Product ID is required.'], 400);
        }
        
        if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            send_json(['error' => 'No image uploaded or upload error occurred.'], 400);
        }
        
        $file = $_FILES['image'];
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($file['type'], $allowed_types)) {
            send_json(['error' => 'Invalid file type. Only JPG, PNG, GIF, and WEBP are allowed.'], 400);
        }
        
        // Limit to 5MB
        if ($file['size'] > 5 * 1024 * 1024) {
            send_json(['error' => 'File size exceeds maximum limit of 5MB.'], 400);
        }
        
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        if (!$ext) {
            $ext = 'jpg';
        }
        $filename = md5(time() . uniqid()) . '.' . $ext;
        $dest_path = $uploads_dir . '/' . $filename;
        
        if (move_uploaded_file($file['tmp_name'], $dest_path)) {
            $relative_path = 'uploads/' . $filename;
            
            $stmt = $pdo->prepare("INSERT INTO product_images (product_id, image_path) VALUES (?, ?)");
            $stmt->execute([$product_id, $relative_path]);
            
            send_json([
                'success' => true,
                'image' => [
                    'id' => $pdo->lastInsertId(),
                    'product_id' => $product_id,
                    'image_path' => $relative_path
                ]
            ]);
        } else {
            send_json(['error' => 'Failed to save uploaded file.'], 500);
        }
    }
    
    // 2. Create product
    else {
        $input = get_json_input();
        
        $name = trim($input['name'] ?? '');
        $model = trim($input['model'] ?? '');
        $barcode = trim($input['barcode'] ?? '');
        $spec = trim($input['spec'] ?? '');
        $unit = trim($input['unit'] ?? '');
        $brand = trim($input['brand'] ?? '');
        $price_input = trim($input['price'] ?? '0.00');
        $local = trim($input['local'] ?? '');
        $stock = (int)($input['stock'] ?? 0);
        $mark = trim($input['mark'] ?? '');
        
        if (!$name) {
            send_json(['error' => 'Product Name is required.'], 400);
        }
        
        // Format price to decimal
        $price = number_format((float)$price_input, 2, '.', '');
        
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("INSERT INTO products (model, name, barcode, spec, unit, brand, price, local, stock, mark) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$model, $name, $barcode, $spec, $unit, $brand, $price, $local, $stock, $mark]);
            $product_id = (int)$pdo->lastInsertId();
            
            // If initial stock is set, write a stock log entry
            if ($stock > 0) {
                $log_stmt = $pdo->prepare("INSERT INTO stock_log (product_id, history_name, history_model, user_id, type, quantity) VALUES (?, ?, ?, ?, 'in', ?)");
                $log_stmt->execute([$product_id, $name, $model, $currentUser['id'], $stock]);
            }
            
            $pdo->commit();
            
            send_json([
                'success' => true,
                'product' => [
                    'id' => $product_id,
                    'name' => $name,
                    'model' => $model,
                    'stock' => $stock,
                    'price' => $price
                ]
            ]);
        } catch (Exception $e) {
            $pdo->rollBack();
            send_json(['error' => 'Failed to create product: ' . $e->getMessage()], 500);
        }
    }
}

elseif ($method === 'PUT') {
    $product_id = (int)($_GET['id'] ?? 0);
    if (!$product_id) {
        send_json(['error' => 'Product ID is required.'], 400);
    }
    
    // Check if product exists
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch();
    if (!$product) {
        send_json(['error' => 'Product not found.'], 404);
    }
    
    $input = get_json_input();
    
    $name = trim($input['name'] ?? $product['name']);
    $model = trim($input['model'] ?? $product['model']);
    $barcode = trim($input['barcode'] ?? $product['barcode']);
    $spec = trim($input['spec'] ?? $product['spec']);
    $unit = trim($input['unit'] ?? $product['unit']);
    $brand = trim($input['brand'] ?? $product['brand']);
    $price_input = trim($input['price'] ?? $product['price']);
    $local = trim($input['local'] ?? $product['local']);
    $new_stock = isset($input['stock']) ? (int)$input['stock'] : (int)$product['stock'];
    $mark = trim($input['mark'] ?? $product['mark']);
    
    $price = number_format((float)$price_input, 2, '.', '');
    
    try {
        $pdo->beginTransaction();
        
        // Check if editing stock directly is allowed or if stock changed
        $stock_changed = ($new_stock !== (int)$product['stock']);
        if ($stock_changed) {
            // Check if global settings allow editing stock directly
            $sett_stmt = $pdo->query("SELECT allowEditStock FROM setting LIMIT 1");
            $allow_edit = $sett_stmt->fetchColumn() === 'true';
            
            if (!$allow_edit) {
                // Not allowed to modify stock directly! Must use stock_log
                send_json(['error' => 'Direct stock modification is disabled. Please record in/out logs.'], 400);
            }
            
            // Log the adjustment
            $diff = $new_stock - (int)$product['stock'];
            $log_type = $diff > 0 ? 'in' : 'out';
            $log_qty = abs($diff);
            
            $log_stmt = $pdo->prepare("INSERT INTO stock_log (product_id, history_name, history_model, user_id, type, quantity) VALUES (?, ?, ?, ?, ?, ?)");
            // If out, log quantity as negative (standard in database audit)
            $signed_qty = $diff; // keep sign or store absolute? The stock_log quantity in original dump had signed quantity (like -3, 5, etc.)
            $log_stmt->execute([$product_id, $name, $model, $currentUser['id'], $log_type, $signed_qty]);
        }
        
        $update_stmt = $pdo->prepare("UPDATE products SET model = ?, name = ?, barcode = ?, spec = ?, unit = ?, brand = ?, price = ?, local = ?, stock = ?, mark = ? WHERE id = ?");
        $update_stmt->execute([$model, $name, $barcode, $spec, $unit, $brand, $price, $local, $new_stock, $mark, $product_id]);
        
        $pdo->commit();
        
        send_json([
            'success' => true,
            'product' => [
                'id' => $product_id,
                'name' => $name,
                'stock' => $new_stock,
                'price' => $price
            ]
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        send_json(['error' => 'Failed to update product: ' . $e->getMessage()], 500);
    }
}

elseif ($method === 'DELETE') {
    if ($currentUser['role'] !== 'admin') {
        send_json(['error' => 'Permission denied. Only administrators can perform delete operations.'], 403);
    }
    
    // 1. Delete single image
    if ($action === 'delete_image') {
        $image_id = (int)($_GET['image_id'] ?? 0);
        if (!$image_id) {
            send_json(['error' => 'Image ID is required.'], 400);
        }
        
        $stmt = $pdo->prepare("SELECT * FROM product_images WHERE id = ?");
        $stmt->execute([$image_id]);
        $img = $stmt->fetch();
        
        if ($img) {
            // Delete file from disk
            $file_path = __DIR__ . '/../' . $img['image_path'];
            if (is_file($file_path)) {
                unlink($file_path);
            }
            
            // Delete record
            $del_stmt = $pdo->prepare("DELETE FROM product_images WHERE id = ?");
            $del_stmt->execute([$image_id]);
            
            send_json(['success' => true]);
        } else {
            send_json(['error' => 'Image not found.'], 404);
        }
    }
    
    // 2. Delete product
    else {
        $product_id = (int)($_GET['id'] ?? 0);
        if (!$product_id) {
            send_json(['error' => 'Product ID is required.'], 400);
        }
        
        // Get details before delete to log
        $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
        $stmt->execute([$product_id]);
        $product = $stmt->fetch();
        
        if (!$product) {
            send_json(['error' => 'Product not found.'], 404);
        }
        
        try {
            $pdo->beginTransaction();
            
            // Get all associated images to delete files
            $img_stmt = $pdo->prepare("SELECT * FROM product_images WHERE product_id = ?");
            $img_stmt->execute([$product_id]);
            $images = $img_stmt->fetchAll();
            foreach ($images as $img) {
                $file_path = __DIR__ . '/../' . $img['image_path'];
                if (is_file($file_path)) {
                    unlink($file_path);
                }
            }
            
            // Delete image records
            $del_img_stmt = $pdo->prepare("DELETE FROM product_images WHERE product_id = ?");
            $del_img_stmt->execute([$product_id]);
            
            // Log deletion in stock_log
            // We set product_id to NULL to prevent integrity errors since we are physically deleting it.
            // But we keep history_name and history_model!
            $log_stmt = $pdo->prepare("INSERT INTO stock_log (product_id, history_name, history_model, user_id, type, quantity) VALUES (NULL, ?, ?, ?, 'del', ?)");
            // Log quantity deleted as negative of current stock
            $log_stmt->execute([$product['name'], $product['model'], $currentUser['id'], -((int)$product['stock'])]);
            
            // Delete product
            $del_prod_stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
            $del_prod_stmt->execute([$product_id]);
            
            $pdo->commit();
            send_json(['success' => true]);
        } catch (Exception $e) {
            $pdo->rollBack();
            send_json(['error' => 'Failed to delete product: ' . $e->getMessage()], 500);
        }
    }
}

else {
    send_json(['error' => 'HTTP Method not allowed.'], 405);
}
