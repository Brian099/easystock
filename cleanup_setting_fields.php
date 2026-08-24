<?php
/**
 * 数据库 setting 表无用字段清理脚本 (unit, brand)
 * 支持通过浏览器访问运行，也支持通过命令行执行: php cleanup_setting_fields.php
 */

$is_cli = (php_sapi_name() === 'cli');

// 1. 读取数据库配置
$config_file = __DIR__ . '/api/db_config.php';
if (!file_exists($config_file)) {
    $msg = "错误: 未检测到数据库配置文件 (api/db_config.php)，请先安装或配置系统数据库连接。";
    if ($is_cli) {
        fwrite(STDERR, $msg . PHP_EOL);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo "<div style='font-family: sans-serif; color: #dc2626; padding: 20px;'><h3>" . htmlspecialchars($msg) . "</h3></div>";
    }
    exit(1);
}

require_once $config_file;

// 2. 建立数据库连接
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    $msg = "数据库连接失败: " . $e->getMessage();
    if ($is_cli) {
        fwrite(STDERR, $msg . PHP_EOL);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo "<div style='font-family: sans-serif; color: #dc2626; padding: 20px;'><h3>" . htmlspecialchars($msg) . "</h3></div>";
    }
    exit(1);
}

// 3. 检查表是否存在
$table_exists = false;
try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'setting'");
    $table_exists = (bool)$stmt->fetch();
} catch (Exception $e) {}

if (!$table_exists) {
    $msg = "错误: 数据库中未找到 `setting` 表！";
    if ($is_cli) {
        fwrite(STDERR, $msg . PHP_EOL);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo "<div style='font-family: sans-serif; color: #dc2626; padding: 20px;'><h3>" . htmlspecialchars($msg) . "</h3></div>";
    }
    exit(1);
}

// 4. 获取清理前的字段列表
$stmt = $pdo->query("SHOW COLUMNS FROM `setting`");
$columns_before = $stmt->fetchAll(PDO::FETCH_COLUMN);

$actions_taken = [];

// 5. 执行清理与优化
// A. 删除 unit 字段
if (in_array('unit', $columns_before)) {
    try {
        $pdo->exec("ALTER TABLE `setting` DROP COLUMN `unit`");
        $actions_taken[] = "已成功删除 `unit` 字段";
    } catch (Exception $e) {
        $actions_taken[] = "删除 `unit` 字段失败: " . $e->getMessage();
    }
} else {
    $actions_taken[] = "`unit` 字段已不存在，无需删除";
}

// B. 删除 brand 字段
if (in_array('brand', $columns_before)) {
    try {
        $pdo->exec("ALTER TABLE `setting` DROP COLUMN `brand`");
        $actions_taken[] = "已成功删除 `brand` 字段";
    } catch (Exception $e) {
        $actions_taken[] = "删除 `brand` 字段失败: " . $e->getMessage();
    }
} else {
    $actions_taken[] = "`brand` 字段已不存在，无需删除";
}

// C. 确保 companyName 字段存在
if (!in_array('companyName', $columns_before) && !in_array('company_name', $columns_before)) {
    try {
        $pdo->exec("ALTER TABLE `setting` ADD COLUMN `companyName` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '公司名称/标识'");
        $actions_taken[] = "已自动补充 `companyName`（公司标识）字段";
    } catch (Exception $e) {
        $actions_taken[] = "添加 `companyName` 字段失败: " . $e->getMessage();
    }
} else {
    $actions_taken[] = "`companyName`（公司标识）字段已就绪";
}

// 6. 获取清理后的字段结构
$stmt = $pdo->query("SHOW COLUMNS FROM `setting`");
$columns_after = $stmt->fetchAll();

// 7. 输出结果
if ($is_cli) {
    echo "========================================\n";
    echo "       数据库 setting 表字段清理完成      \n";
    echo "========================================\n";
    foreach ($actions_taken as $act) {
        echo " - " . $act . "\n";
    }
    echo "----------------------------------------\n";
    echo "当前 `setting` 表剩余字段列表:\n";
    foreach ($columns_after as $col) {
        echo "  * {$col['Field']} ({$col['Type']}) " . ($col['Null'] === 'NO' ? 'NOT NULL' : '') . "\n";
    }
    echo "========================================\n";
} else {
    header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>清理 setting 表冗余字段</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: #0f172a;
            color: #f8fafc;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
            box-sizing: border-box;
        }
        .card {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 12px;
            padding: 28px 32px;
            max-width: 560px;
            width: 100%;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.5);
        }
        h2 {
            margin-top: 0;
            color: #38bdf8;
            font-size: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .actions-list {
            list-style: none;
            padding: 0;
            margin: 16px 0 24px 0;
        }
        .actions-list li {
            padding: 8px 12px;
            background: #0f172a;
            border-radius: 6px;
            margin-bottom: 8px;
            font-size: 14px;
            border-left: 3px solid #10b981;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            margin-top: 10px;
            background: #0f172a;
            border-radius: 6px;
            overflow: hidden;
        }
        th, td {
            padding: 10px 12px;
            text-align: left;
            border-bottom: 1px solid #334155;
        }
        th {
            background: #273549;
            color: #94a3b8;
            font-weight: 600;
        }
        .btn-back {
            display: inline-block;
            margin-top: 24px;
            padding: 10px 20px;
            background: #2563eb;
            color: #ffffff;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 500;
            font-size: 14px;
        }
        .btn-back:hover {
            background: #1d4ed8;
        }
    </style>
</head>
<body>
    <div class="card">
        <h2>✅ 数据库 `setting` 表清理完成</h2>
        <p style="color: #94a3b8; font-size: 14px;">已安全移除已废弃的 <code>unit</code> 和 <code>brand</code> 字段：</p>
        <ul class="actions-list">
            <?php foreach ($actions_taken as $action): ?>
                <li><?= htmlspecialchars($action) ?></li>
            <?php endforeach; ?>
        </ul>

        <h3 style="font-size: 15px; color: #cbd5e1; margin-bottom: 8px;">当前 <code>setting</code> 表最新结构：</h3>
        <table>
            <thead>
                <tr>
                    <th>字段名</th>
                    <th>类型</th>
                    <th>允许NULL</th>
                    <th>默认值</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($columns_after as $col): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($col['Field']) ?></strong></td>
                        <td><code><?= htmlspecialchars($col['Type']) ?></code></td>
                        <td><?= htmlspecialchars($col['Null']) ?></td>
                        <td><?= htmlspecialchars($col['Default'] ?? 'NULL') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <a href="index.html" class="btn-back">返回库存管理系统首页 →</a>
    </div>
</body>
</html>
<?php
}
