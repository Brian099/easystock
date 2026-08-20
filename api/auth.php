<?php
require_once __DIR__ . '/config.php';

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $input = get_json_input();
    
    if ($action === 'login') {
        $username = trim($input['username'] ?? '');
        $password = $input['password'] ?? '';
        
        if (!$username || !$password) {
            send_json(['error' => 'Username and password are required.'], 400);
        }
        
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user'] = [
                'id' => (int)$user['id'],
                'username' => $user['username'],
                'role' => $user['role']
            ];
            send_json(['success' => true, 'user' => $_SESSION['user']]);
        } else {
            send_json(['error' => 'Invalid username or password.'], 401);
        }
    }
    
    elseif ($action === 'logout') {
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();
        send_json(['success' => true]);
    }
    
    elseif ($action === 'change_password') {
        $currentUser = require_login();
        $old_password = $input['old_password'] ?? '';
        $new_password = $input['new_password'] ?? '';
        
        if (!$old_password || !$new_password) {
            send_json(['error' => 'Old and new passwords are required.'], 400);
        }
        
        if (strlen($new_password) < 6) {
            send_json(['error' => 'New password must be at least 6 characters long.'], 400);
        }
        
        // Fetch user from DB to get the password hash
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$currentUser['id']]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($old_password, $user['password'])) {
            $new_hash = password_hash($new_password, PASSWORD_BCRYPT);
            $update_stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $update_stmt->execute([$new_hash, $currentUser['id']]);
            send_json(['success' => true, 'message' => 'Password updated successfully.']);
        } else {
            send_json(['error' => 'Incorrect old password.'], 401);
        }
    }
    
    else {
        send_json(['error' => 'Invalid POST action.'], 400);
    }
} 

elseif ($method === 'GET') {
    if ($action === 'status') {
        if (isset($_SESSION['user'])) {
            send_json(['logged_in' => true, 'user' => $_SESSION['user']]);
        } else {
            send_json(['logged_in' => false]);
        }
    } else {
        send_json(['error' => 'Invalid GET action.'], 400);
    }
} 

else {
    send_json(['error' => 'HTTP Method not allowed.'], 405);
}
