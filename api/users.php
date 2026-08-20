<?php
require_once __DIR__ . '/config.php';

// Only admins can access user management endpoints
$currentUser = require_admin();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // List all users (hide password hashes for security)
    $stmt = $pdo->query("SELECT id, username, role FROM users ORDER BY id ASC");
    $users = $stmt->fetchAll();
    send_json($users);
}

elseif ($method === 'POST') {
    // Create new user account
    $input = get_json_input();
    
    $username = trim($input['username'] ?? '');
    $password = $input['password'] ?? '';
    $role = trim($input['role'] ?? 'user');
    
    if (!$username || !$password) {
        send_json(['error' => '用户名和密码为必填项。'], 400);
    }
    
    if (strlen($password) < 6) {
        send_json(['error' => '密码长度不能少于 6 位。'], 400);
    }
    
    if (!in_array($role, ['admin', 'user'])) {
        $role = 'user';
    }
    
    try {
        // Check if username already exists
        $chk_stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
        $chk_stmt->execute([$username]);
        if ((int)$chk_stmt->fetchColumn() > 0) {
            send_json(['error' => '用户名已存在，请使用其他用户名。'], 400);
        }
        
        $hash = password_hash($password, PASSWORD_BCRYPT);
        
        $ins_stmt = $pdo->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
        $ins_stmt->execute([$username, $hash, $role]);
        
        send_json([
            'success' => true,
            'user' => [
                'id' => $pdo->lastInsertId(),
                'username' => $username,
                'role' => $role
            ]
        ]);
    } catch (Exception $e) {
        send_json(['error' => '创建用户账户失败: ' . $e->getMessage()], 500);
    }
}

elseif ($method === 'PUT') {
    // Edit user role or reset password
    $user_id = (int)($_GET['id'] ?? 0);
    if (!$user_id) {
        send_json(['error' => 'User ID is required.'], 400);
    }
    
    // Check if target user exists
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $target_user = $stmt->fetch();
    if (!$target_user) {
        send_json(['error' => '用户账户未找到。'], 404);
    }
    
    $input = get_json_input();
    $role = trim($input['role'] ?? $target_user['role']);
    $password = $input['password'] ?? ''; // Only update if provided
    
    if (!in_array($role, ['admin', 'user'])) {
        $role = 'user';
    }
    
    try {
        $pdo->beginTransaction();
        
        if ($password !== '') {
            if (strlen($password) < 6) {
                $pdo->rollBack();
                send_json(['error' => '新密码长度不能少于 6 位。'], 400);
            }
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $upd_stmt = $pdo->prepare("UPDATE users SET password = ?, role = ? WHERE id = ?");
            $upd_stmt->execute([$hash, $role, $user_id]);
        } else {
            $upd_stmt = $pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
            $upd_stmt->execute([$role, $user_id]);
        }
        
        $pdo->commit();
        send_json([
            'success' => true,
            'user' => [
                'id' => $user_id,
                'username' => $target_user['username'],
                'role' => $role
            ]
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        send_json(['error' => '更新用户账户失败: ' . $e->getMessage()], 500);
    }
}

elseif ($method === 'DELETE') {
    // Delete user account
    $user_id = (int)($_GET['id'] ?? 0);
    if (!$user_id) {
        send_json(['error' => 'User ID is required.'], 400);
    }
    
    // Prevent self-deletion
    if ($user_id === $currentUser['id']) {
        send_json(['error' => '您不能删除您当前登录的账户。'], 400);
    }
    
    // Check if user exists
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $target_user = $stmt->fetch();
    if (!$target_user) {
        send_json(['error' => '用户账户未找到。'], 404);
    }
    
    try {
        $del_stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $del_stmt->execute([$user_id]);
        send_json(['success' => true]);
    } catch (Exception $e) {
        send_json(['error' => '删除用户失败: ' . $e->getMessage()], 500);
    }
}

else {
    send_json(['error' => 'HTTP Method not allowed.'], 405);
}
