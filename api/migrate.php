<?php
/**
 * Database Schema Migration Utility
 * 专用数据库表结构维护与字段迁移脚本
 * 
 * 可以在命令行直接运行: php api/migrate.php
 * 也可以在管理员登录状态下通过浏览器访问: api/migrate.php
 */

require_once __DIR__ . '/config.php';

// Check if running from CLI or HTTP
$is_cli = (php_sapi_name() === 'cli' || empty($_SERVER['REMOTE_ADDR']));

if (!$is_cli) {
    // Web requests require admin privilege
    $currentUser = require_admin();
}

$results = [
    'success' => true,
    'db_type' => $db_type,
    'migrations' => []
];

/**
 * Helper to record step result
 */
function record_step(&$results, $name, $status, $message = '')
{
    $results['migrations'][] = [
        'step' => $name,
        'status' => $status,
        'message' => $message
    ];
}

// ----------------------------------------------------
// Migration 1: Add defaultSearchFields to setting table
// ----------------------------------------------------
try {
    $has_column = false;
    try {
        $chk = $pdo->query("SELECT defaultSearchFields FROM setting LIMIT 1");
        if ($chk !== false) {
            $has_column = true;
        }
    } catch (Throwable $e) {
        $has_column = false;
    }

    if ($has_column) {
        record_step($results, 'setting.defaultSearchFields', 'already_exists', '字段 defaultSearchFields 已存在，无需重复添加');
    } else {
        $default_val = 'name,model,spec,barcode,brand,local,mark';

        if ($db_type === 'mysql') {
            $sql = "ALTER TABLE `setting` ADD COLUMN `defaultSearchFields` VARCHAR(255) NOT NULL DEFAULT '$default_val' COMMENT '默认搜索来源字段'";
        } else {
            $sql = "ALTER TABLE `setting` ADD COLUMN `defaultSearchFields` TEXT NOT NULL DEFAULT '$default_val'";
        }

        $pdo->exec($sql);
        // Ensure existing rows have the default value
        $pdo->exec("UPDATE `setting` SET `defaultSearchFields` = '$default_val' WHERE `defaultSearchFields` IS NULL OR `defaultSearchFields` = ''");

        record_step($results, 'setting.defaultSearchFields', 'success', '成功为 setting 表新增 defaultSearchFields 字段');
    }
} catch (Throwable $e) {
    $results['success'] = false;
    record_step($results, 'setting.defaultSearchFields', 'error', '迁移失败: ' . $e->getMessage());
}

// Output response
if ($is_cli) {
    echo "========================================\n";
    echo "  Database Migration Report (" . strtoupper($db_type) . ")\n";
    echo "========================================\n";
    foreach ($results['migrations'] as $m) {
        $tag = ($m['status'] === 'success' || $m['status'] === 'already_exists') ? '[OK]' : '[FAIL]';
        echo "$tag {$m['step']}: {$m['message']}\n";
    }
    echo "----------------------------------------\n";
    echo "Overall Status: " . ($results['success'] ? "SUCCESS" : "FAILED") . "\n";
    exit($results['success'] ? 0 : 1);
} else {
    send_json($results, $results['success'] ? 200 : 500);
}
