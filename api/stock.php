<?php
require_once __DIR__ . '/config.php';

$currentUser = require_login();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($method === 'GET') {
    // 1. Stock logs analytics/stats
    if ($action === 'stats') {
        // Daily transaction trends for the past 14 days
        $trend_stmt = $pdo->query("
            SELECT DATE(created_at) as date, type, SUM(ABS(quantity)) as total_qty, COUNT(*) as count 
            FROM stock_log 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
            GROUP BY DATE(created_at), type
            ORDER BY DATE(created_at) ASC
        ");
        $trends = $trend_stmt->fetchAll();
        
        // Distribution of types
        $dist_stmt = $pdo->query("
            SELECT type, COUNT(*) as count, SUM(ABS(quantity)) as total_qty 
            FROM stock_log 
            GROUP BY type
        ");
        $distribution = $dist_stmt->fetchAll();
        
        // Low stock products count
        $low_stock_count = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE stock <= 2")->fetchColumn();
        
        // Total products and total stock sum
        $tot_stmt = $pdo->query("SELECT COUNT(*) as total_items, SUM(stock) as total_stock_qty FROM products");
        $totals = $tot_stmt->fetch();
        
        send_json([
            'trends' => $trends,
            'distribution' => $distribution,
            'low_stock_count' => $low_stock_count,
            'total_items' => (int)($totals['total_items'] ?? 0),
            'total_stock_qty' => (int)($totals['total_stock_qty'] ?? 0)
        ]);
    }
    
    // 2. Default GET: List stock logs with pagination and filters
    else {
        $type = trim($_GET['type'] ?? '');
        $product_id = (int)($_GET['product_id'] ?? 0);
        $search = trim($_GET['search'] ?? '');
        
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = max(1, min(100, (int)($_GET['limit'] ?? 30)));
        $offset = ($page - 1) * $limit;
        
        $where_clauses = [];
        $params = [];
        
        if ($type !== '') {
            $where_clauses[] = "l.type = ?";
            $params[] = $type;
        }
        
        if ($product_id > 0) {
            $where_clauses[] = "l.product_id = ?";
            $params[] = $product_id;
        }
        
        if ($search !== '') {
            $where_clauses[] = "(l.history_name LIKE ? OR l.history_model LIKE ?)";
            $search_param = "%$search%";
            $params[] = $search_param;
            $params[] = $search_param;
        }
        
        $where_sql = '';
        if (!empty($where_clauses)) {
            $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
        }
        
        // Count total matching
        $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM stock_log l $where_sql");
        $count_stmt->execute($params);
        $total_items = (int)$count_stmt->fetchColumn();
        $total_pages = ceil($total_items / $limit);
        
        // Get logs list (joined with users to get username)
        $query_sql = "
            SELECT l.*, u.username as operator_name 
            FROM stock_log l 
            LEFT JOIN users u ON l.user_id = u.id 
            $where_sql 
            ORDER BY l.created_at DESC, l.id DESC 
            LIMIT $limit OFFSET $offset
        ";
        $data_stmt = $pdo->prepare($query_sql);
        $data_stmt->execute($params);
        $logs = $data_stmt->fetchAll();
        
        send_json([
            'logs' => $logs,
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
    // Record transactional stock log (in, out, re)
    if ($action === 'log') {
        $input = get_json_input();
        
        $product_id = (int)($input['product_id'] ?? 0);
        $type = trim($input['type'] ?? '');
        $quantity = (int)($input['quantity'] ?? 0);
        $mark = trim($input['mark'] ?? ''); // Optional additional notes inside log context
        
        if (!$product_id) {
            send_json(['error' => 'Product ID is required.'], 400);
        }
        
        if (!in_array($type, ['in', 'out', 're'])) {
            send_json(['error' => 'Invalid operation type. Must be: in, out, or re.'], 400);
        }
        
        if ($quantity <= 0) {
            send_json(['error' => 'Quantity must be a positive integer greater than 0.'], 400);
        }
        
        try {
            $pdo->beginTransaction();
            
            // Pessimistic lock (FOR UPDATE) to prevent race condition updates
            $stmt = $pdo->prepare("SELECT name, model, stock FROM products WHERE id = ? FOR UPDATE");
            $stmt->execute([$product_id]);
            $product = $stmt->fetch();
            
            if (!$product) {
                $pdo->rollBack();
                send_json(['error' => 'Product not found.'], 404);
            }
            
            $current_stock = (int)$product['stock'];
            $new_stock = $current_stock;
            $signed_qty = $quantity;
            
            if ($type === 'in') {
                $new_stock = $current_stock + $quantity;
                $signed_qty = $quantity;
            } 
            elseif ($type === 'out') {
                if ($current_stock < $quantity) {
                    $pdo->rollBack();
                    send_json(['error' => 'Insufficient stock. Current stock is ' . $current_stock], 400);
                }
                $new_stock = $current_stock - $quantity;
                $signed_qty = -$quantity;
            } 
            elseif ($type === 're') {
                $new_stock = $current_stock + $quantity;
                $signed_qty = $quantity;
            }
            
            // Update product stock count
            $update_stmt = $pdo->prepare("UPDATE products SET stock = ? WHERE id = ?");
            $update_stmt->execute([$new_stock, $product_id]);
            
            // Append optional mark to history model/name or keep stock_log mark
            $history_name = $product['name'];
            $history_model = $product['model'];
            if ($mark !== '') {
                $history_model .= " (" . $mark . ")";
            }
            
            // Log entry
            $log_stmt = $pdo->prepare("
                INSERT INTO stock_log (product_id, history_name, history_model, user_id, type, quantity) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $log_stmt->execute([$product_id, $history_name, $history_model, $currentUser['id'], $type, $signed_qty]);
            
            $pdo->commit();
            
            send_json([
                'success' => true,
                'product_id' => $product_id,
                'name' => $product['name'],
                'previous_stock' => $current_stock,
                'new_stock' => $new_stock,
                'operation' => $type
            ]);
        } catch (Exception $e) {
            $pdo->rollBack();
            send_json(['error' => 'Transaction failed: ' . $e->getMessage()], 500);
        }
    } else {
        send_json(['error' => 'Invalid POST action.'], 400);
    }
}

else {
    send_json(['error' => 'HTTP Method not allowed.'], 405);
}
