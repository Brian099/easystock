<?php
// Helper to send JSON response and terminate script
function send_json($data, $status_code = 200) {
    http_response_code($status_code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// 1. Security Check: If config already exists, prevent re-installation
if (file_exists(__DIR__ . '/db_config.php')) {
    send_json([
        'error' => '系统已经完成安装引导。如需重新运行安装，请手动删除 api/db_config.php 文件。',
        'installed' => true
    ], 403);
}

$method = $_SERVER['REQUEST_METHOD'];

// Handle GET to retrieve environment variable pre-fills
if ($method === 'GET') {
    send_json([
        'prefill' => [
            'db_host' => getenv('DB_HOST') ?: '127.0.0.1',
            'db_user' => getenv('DB_USER') ?: 'root',
            'db_pass' => getenv('DB_PASS') ?: '',
            'db_name' => getenv('DB_NAME') ?: 'stock',
            'admin_user' => 'admin'
        ]
    ]);
}

if ($method !== 'POST') {
    send_json(['error' => 'HTTP Method not allowed.'], 405);
}

// Read JSON input
$raw = file_get_contents('php://input');
$input = json_decode($raw, true) ?: [];

$db_host = trim($input['db_host'] ?? '127.0.0.1');
$db_user = trim($input['db_user'] ?? 'root');
$db_pass = $input['db_pass'] ?? '';
$db_name = trim($input['db_name'] ?? 'stock');
$admin_user = trim($input['admin_user'] ?? 'admin');
$admin_pass = $input['admin_pass'] ?? '';

if (!$admin_user || !$admin_pass) {
    send_json(['error' => '管理员用户名和密码不能为空。'], 400);
}

if (strlen($admin_pass) < 6) {
    send_json(['error' => '管理员密码长度不能少于 6 位。'], 400);
}

// 2. Try to connect to MySQL/MariaDB server
try {
    // Connect to mysql server first (without database to allow creating it if it doesn't exist)
    $temp_pdo = new PDO(
        "mysql:host=$db_host;charset=utf8mb4",
        $db_user,
        $db_pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5 // 5 seconds timeout
        ]
    );
    
    // Create database if not exists
    $temp_pdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    
    // Connect to the specific database
    $pdo = new PDO(
        "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",
        $db_user,
        $db_pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    send_json(['error' => '数据库连接失败，请检查配置信息。详情: ' . $e->getMessage()], 400);
}

// 3. Initialize complete clean database schema (100% self-contained)
$schema_sql = "
CREATE TABLE IF NOT EXISTS `products` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '商品主键',
  `name` varchar(150) NOT NULL COMMENT '商品名称',
  `model` varchar(100) DEFAULT '' COMMENT '型号',
  `spec` varchar(100) DEFAULT '' COMMENT '规格',
  `barcode` varchar(100) DEFAULT '' COMMENT '条形码/编码',
  `unit` varchar(50) DEFAULT '个' COMMENT '单位',
  `brand` varchar(100) DEFAULT '' COMMENT '厂商/品牌',
  `local` varchar(100) DEFAULT '' COMMENT '存放仓位',
  `price` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '单价',
  `stock` int(11) NOT NULL DEFAULT '0' COMMENT '当前库存',
  `mark` varchar(255) DEFAULT '' COMMENT '备注信息',
  PRIMARY KEY (`id`),
  KEY `idx_brand` (`brand`),
  KEY `idx_unit` (`unit`),
  KEY `idx_local` (`local`),
  KEY `idx_barcode` (`barcode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `setting` (
  `id` int(11) NOT NULL DEFAULT '1' COMMENT '主键',
  `allowEditStock` varchar(10) NOT NULL DEFAULT 'false' COMMENT '是否允许直接修改库存',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stock_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '流水ID',
  `product_id` int(11) NOT NULL COMMENT '关联商品ID',
  `history_name` varchar(150) NOT NULL COMMENT '发生时商品名称',
  `history_model` varchar(100) DEFAULT '' COMMENT '发生时商品型号',
  `user_id` int(11) NOT NULL DEFAULT '1' COMMENT '操作人ID',
  `type` varchar(10) NOT NULL COMMENT '类型: in, out, re, del',
  `quantity` int(11) NOT NULL COMMENT '变动数量',
  `mark` varchar(255) DEFAULT '' COMMENT '出入库备注',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '发生时间',
  PRIMARY KEY (`id`),
  KEY `idx_log_product` (`product_id`),
  KEY `idx_log_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `product_images` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '图片ID',
  `product_id` int(11) NOT NULL COMMENT '关联商品ID',
  `image_path` varchar(255) NOT NULL COMMENT '相对路径',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '上传时间',
  PRIMARY KEY (`id`),
  KEY `idx_img_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '用户ID',
  `username` varchar(50) NOT NULL COMMENT '登录用户名',
  `password` varchar(255) NOT NULL COMMENT '加密密码',
  `role` varchar(20) NOT NULL DEFAULT 'user' COMMENT '角色: admin 或 user',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_user_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `setting` (`id`, `allowEditStock`) VALUES (1, 'false')
ON DUPLICATE KEY UPDATE `allowEditStock` = VALUES(`allowEditStock`);
";

try {
    $pdo->exec($schema_sql);
} catch (PDOException $e) {
    send_json(['error' => '数据库表结构初始化失败: ' . $e->getMessage()], 500);
}

// 4. Optional: Import demo/historical data if requested and stock.sql exists
$import_demo = !empty($input['import_demo']);
if ($import_demo) {
    $sql_path = __DIR__ . '/../stock.sql';
    if (is_file($sql_path)) {
        try {
            $demo_sql = file_get_contents($sql_path);
            if ($demo_sql) {
                $pdo->exec($demo_sql);
            }
        } catch (Exception $e) {
            // Silently ignore demo import errors or continue
        }
    }
}

// 5. Create or Update Administrator account in database
try {
    $admin_hash = password_hash($admin_pass, PASSWORD_BCRYPT);
    
    // Check user counts
    $stmt = $pdo->query("SELECT COUNT(*) FROM users");
    $user_count = (int)$stmt->fetchColumn();
    
    if ($user_count === 0) {
        $ins = $pdo->prepare("INSERT INTO users (id, username, password, role) VALUES (1, ?, ?, 'admin')");
        $ins->execute([$admin_user, $admin_hash]);
    } else {
        // Check if admin_user already exists
        $stmt_check = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt_check->execute([$admin_user]);
        $exists_id = $stmt_check->fetchColumn();
        
        if ($exists_id) {
            $upd = $pdo->prepare("UPDATE users SET password = ?, role = 'admin' WHERE id = ?");
            $upd->execute([$admin_hash, $exists_id]);
        } else {
            $ins = $pdo->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, 'admin')");
            $ins->execute([$admin_user, $admin_hash]);
        }
    }
} catch (PDOException $e) {
    send_json(['error' => '管理员账户创建失败: ' . $e->getMessage()], 500);
}

// 6. Save config to db_config.php
$config_content = "<?php\n" .
                  "// 数据库配置信息 - 自动生成\n" .
                  "define('DB_HOST', " . var_export($db_host, true) . ");\n" .
                  "define('DB_USER', " . var_export($db_user, true) . ");\n" .
                  "define('DB_PASS', " . var_export($db_pass, true) . ");\n" .
                  "define('DB_NAME', " . var_export($db_name, true) . ");\n";

if (@file_put_contents(__DIR__ . '/db_config.php', $config_content) === false) {
    send_json(['error' => '无法写入配置文件 api/db_config.php，请检查 api/ 目录写入权限。'], 500);
}

send_json(['success' => true]);
