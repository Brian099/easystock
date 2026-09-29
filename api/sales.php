<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/search_helper.php';

$currentUser = require_login();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'overview';

// Helper for DB Date extraction
$is_sqlite = ($db_type === 'sqlite');
$date_expr = $is_sqlite ? "DATE(l.created_at)" : "DATE(l.created_at)";

// Common parameters
$start_date = trim($_GET['start_date'] ?? '');
$end_date = trim($_GET['end_date'] ?? '');
$search = trim($_GET['search'] ?? '');

// Helper to construct common where conditions
function build_sales_where($start_date, $end_date, $search, $pdo, $only_out_and_re = true) {
    $where_clauses = [];
    $params = [];
    
    if ($only_out_and_re) {
        $where_clauses[] = "l.type IN ('out', 're')";
    }
    
    if ($start_date !== '') {
        $where_clauses[] = "DATE(l.created_at) >= ?";
        $params[] = $start_date;
    }
    
    if ($end_date !== '') {
        $where_clauses[] = "DATE(l.created_at) <= ?";
        $params[] = $end_date;
    }
    
    if ($search !== '') {
        $space_ignore_enabled = is_search_space_ignore_enabled($pdo);
        $search_variants = expand_search_variants($search, $pdo);
        if (empty($search_variants)) {
            $search_variants = [$search];
        }

        $log_search_conditions = [];
        foreach ($search_variants as $v) {
            list($sql_frag_name, $param_name) = build_search_like_condition('l.history_name', $v, $space_ignore_enabled);
            $log_search_conditions[] = $sql_frag_name;
            $params[] = $param_name;

            list($sql_frag_model, $param_model) = build_search_like_condition('l.history_model', $v, $space_ignore_enabled);
            $log_search_conditions[] = $sql_frag_model;
            $params[] = $param_model;
        }
        $where_clauses[] = "(" . implode(' OR ', $log_search_conditions) . ")";
    }
    
    $where_sql = '';
    if (!empty($where_clauses)) {
        $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
    }
    
    return [$where_sql, $params];
}

if ($method === 'GET') {
    // 1. Overview metrics
    if ($action === 'overview') {
        list($where_sql, $params) = build_sales_where($start_date, $end_date, $search, $pdo);
        
        $sql = "
            SELECT 
                COALESCE(SUM(CASE WHEN l.type = 'out' THEN ABS(l.quantity) ELSE 0 END), 0) as total_out_qty,
                COALESCE(SUM(CASE WHEN l.type = 're' THEN ABS(l.quantity) ELSE 0 END), 0) as total_return_qty,
                COALESCE(COUNT(DISTINCT CASE WHEN l.type = 'out' THEN l.product_id END), 0) as active_products_count,
                COALESCE(SUM(CASE WHEN l.type = 'out' THEN 1 ELSE 0 END), 0) as out_transactions_count,
                COALESCE(SUM(CASE WHEN l.type = 're' THEN 1 ELSE 0 END), 0) as return_transactions_count
            FROM stock_log l
            $where_sql
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch() ?: [];
        
        $total_out = (int)($row['total_out_qty'] ?? 0);
        $total_re = (int)($row['total_return_qty'] ?? 0);
        $net_sales = $total_out - $total_re;
        
        send_json([
            'total_out_qty' => $total_out,
            'total_return_qty' => $total_re,
            'net_sales_qty' => $net_sales,
            'active_products_count' => (int)($row['active_products_count'] ?? 0),
            'out_transactions_count' => (int)($row['out_transactions_count'] ?? 0),
            'return_transactions_count' => (int)($row['return_transactions_count'] ?? 0),
            'start_date' => $start_date,
            'end_date' => $end_date
        ]);
    }

    // 2. Daily Trends
    elseif ($action === 'trends') {
        list($where_sql, $params) = build_sales_where($start_date, $end_date, $search, $pdo);
        
        $sql = "
            SELECT 
                DATE(l.created_at) as stat_date,
                COALESCE(SUM(CASE WHEN l.type = 'out' THEN ABS(l.quantity) ELSE 0 END), 0) as out_qty,
                COALESCE(SUM(CASE WHEN l.type = 're' THEN ABS(l.quantity) ELSE 0 END), 0) as return_qty
            FROM stock_log l
            $where_sql
            GROUP BY DATE(l.created_at)
            ORDER BY stat_date ASC
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $raw_trends = $stmt->fetchAll();
        
        $trends = array_map(function($t) {
            $out = (int)$t['out_qty'];
            $re = (int)$t['return_qty'];
            return [
                'date' => $t['stat_date'],
                'out_qty' => $out,
                'return_qty' => $re,
                'net_qty' => $out - $re
            ];
        }, $raw_trends);
        
        send_json([
            'trends' => $trends
        ]);
    }

    // 3. Top Selling Products
    elseif ($action === 'top_products') {
        $limit = max(1, min(50, (int)($_GET['limit'] ?? 10)));
        list($where_sql, $params) = build_sales_where($start_date, $end_date, $search, $pdo);
        
        $sql = "
            SELECT 
                l.product_id,
                l.history_name as name,
                l.history_model as model,
                COALESCE(SUM(CASE WHEN l.type = 'out' THEN ABS(l.quantity) ELSE 0 END), 0) as out_qty,
                COALESCE(SUM(CASE WHEN l.type = 're' THEN ABS(l.quantity) ELSE 0 END), 0) as return_qty,
                (COALESCE(SUM(CASE WHEN l.type = 'out' THEN ABS(l.quantity) ELSE 0 END), 0) - COALESCE(SUM(CASE WHEN l.type = 're' THEN ABS(l.quantity) ELSE 0 END), 0)) as net_qty
            FROM stock_log l
            $where_sql
            GROUP BY l.product_id, l.history_name, l.history_model
            HAVING out_qty > 0 OR return_qty > 0
            ORDER BY out_qty DESC, net_qty DESC
            LIMIT $limit
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $products = $stmt->fetchAll();
        
        $formatted = array_map(function($p) {
            return [
                'product_id' => (int)$p['product_id'],
                'name' => $p['name'],
                'model' => $p['model'],
                'out_qty' => (int)$p['out_qty'],
                'return_qty' => (int)$p['return_qty'],
                'net_qty' => (int)$p['net_qty']
            ];
        }, $products);
        
        send_json([
            'top_products' => $formatted
        ]);
    }

    // 4. Sales quantity ranking list with pagination
    elseif ($action === 'ranking_list' || $action === 'logs') {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = max(1, min(100, (int)($_GET['limit'] ?? 15)));
        $offset = ($page - 1) * $limit;
        
        list($where_sql, $params) = build_sales_where($start_date, $end_date, $search, $pdo);
        
        // Count total distinct products in sales
        $count_sql = "
            SELECT COUNT(*) FROM (
                SELECT l.product_id
                FROM stock_log l
                $where_sql
                GROUP BY l.product_id, l.history_name, l.history_model
                HAVING COALESCE(SUM(CASE WHEN l.type = 'out' THEN ABS(l.quantity) ELSE 0 END), 0) > 0 
                    OR COALESCE(SUM(CASE WHEN l.type = 're' THEN ABS(l.quantity) ELSE 0 END), 0) > 0
            ) as sub
        ";
        $count_stmt = $pdo->prepare($count_sql);
        $count_stmt->execute($params);
        $total_items = (int)$count_stmt->fetchColumn();
        $total_pages = ceil($total_items / $limit);
        
        // Query ranking items
        $sql = "
            SELECT 
                l.product_id,
                l.history_name as name,
                l.history_model as model,
                COALESCE(SUM(CASE WHEN l.type = 'out' THEN ABS(l.quantity) ELSE 0 END), 0) as out_qty,
                COALESCE(SUM(CASE WHEN l.type = 're' THEN ABS(l.quantity) ELSE 0 END), 0) as return_qty,
                (COALESCE(SUM(CASE WHEN l.type = 'out' THEN ABS(l.quantity) ELSE 0 END), 0) - COALESCE(SUM(CASE WHEN l.type = 're' THEN ABS(l.quantity) ELSE 0 END), 0)) as net_qty,
                COALESCE(SUM(CASE WHEN l.type = 'out' THEN 1 ELSE 0 END), 0) as out_count
            FROM stock_log l
            $where_sql
            GROUP BY l.product_id, l.history_name, l.history_model
            HAVING out_qty > 0 OR return_qty > 0
            ORDER BY out_qty DESC, net_qty DESC
            LIMIT $limit OFFSET $offset
        ";
        
        $data_stmt = $pdo->prepare($sql);
        $data_stmt->execute($params);
        $ranking_list = $data_stmt->fetchAll();
        
        $formatted = [];
        $start_rank = $offset + 1;
        foreach ($ranking_list as $idx => $r) {
            $formatted[] = [
                'rank' => $start_rank + $idx,
                'product_id' => (int)$r['product_id'],
                'name' => $r['name'],
                'model' => $r['model'],
                'out_qty' => (int)$r['out_qty'],
                'return_qty' => (int)$r['return_qty'],
                'net_qty' => (int)$r['net_qty'],
                'out_count' => (int)$r['out_count']
            ];
        }
        
        send_json([
            'ranking' => $formatted,
            'pagination' => [
                'total_items' => $total_items,
                'total_pages' => $total_pages,
                'current_page' => $page,
                'limit' => $limit
            ]
        ]);
    }

    // 5. Export Sales Ranking CSV
    elseif ($action === 'export') {
        list($where_sql, $params) = build_sales_where($start_date, $end_date, $search, $pdo);
        
        $sql = "
            SELECT 
                l.product_id,
                l.history_name as name,
                l.history_model as model,
                COALESCE(SUM(CASE WHEN l.type = 'out' THEN ABS(l.quantity) ELSE 0 END), 0) as out_qty,
                COALESCE(SUM(CASE WHEN l.type = 're' THEN ABS(l.quantity) ELSE 0 END), 0) as return_qty,
                (COALESCE(SUM(CASE WHEN l.type = 'out' THEN ABS(l.quantity) ELSE 0 END), 0) - COALESCE(SUM(CASE WHEN l.type = 're' THEN ABS(l.quantity) ELSE 0 END), 0)) as net_qty,
                COALESCE(SUM(CASE WHEN l.type = 'out' THEN 1 ELSE 0 END), 0) as out_count
            FROM stock_log l
            $where_sql
            GROUP BY l.product_id, l.history_name, l.history_model
            HAVING out_qty > 0 OR return_qty > 0
            ORDER BY out_qty DESC, net_qty DESC
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        
        $filename = "商品销售数量排行榜_" . date('Ymd_His') . ".csv";
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        
        $output = fopen('php://output', 'w');
        // UTF-8 BOM
        fwrite($output, "\xEF\xBB\xBF");
        
        fputcsv($output, ['排名', '商品名称', '型号/规格/备注', '销售出库总量(件)', '销售退货总量(件)', '实际净销量(件)', '出库频次(笔)']);
        
        $rank = 1;
        foreach ($rows as $r) {
            fputcsv($output, [
                $rank++,
                $r['name'],
                $r['model'] ?: '--',
                (int)$r['out_qty'],
                (int)$r['return_qty'],
                (int)$r['net_qty'],
                (int)$r['out_count']
            ]);
        }
        
        fclose($output);
        exit;
    }
    
    else {
        send_json(['error' => 'Invalid action.'], 400);
    }
} else {
    send_json(['error' => 'HTTP Method not allowed.'], 405);
}
