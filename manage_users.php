<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php"); exit;
}
require 'config.php';

$msg = $error = "";

if ($_POST && isset($_POST['new_username'])) {
    $u = trim($_POST['new_username']);
    $e = trim($_POST['new_email']);
    $p = $_POST['new_password'];
    $r = $_POST['new_role'];
    if (!in_array($r, ['admin','manager','user'], true)) $r = 'user';
    if ($u === '' || $e === '' || $p === '') {
        $error = "All fields are required.";
    } else {
        try {
            $pdo->prepare("INSERT INTO users (username, email, password, role) VALUES (?,?,?,?)")
                ->execute([$u, $e, password_hash($p, PASSWORD_DEFAULT), $r]);
            $msg = "User '$u' created successfully.";
        } catch (PDOException $ex) {
            $error = "Username or email already exists.";
        }
    }
}

if ($_POST && isset($_POST['update_role'])) {
    $newRole = $_POST['role'];
    if (!in_array($newRole, ['admin','manager','user'], true)) $newRole = 'user';
    $pdo->prepare("UPDATE users SET role=? WHERE user_id=?")->execute([$newRole, intval($_POST['uid'])]);
    $msg = "Role updated.";
}

if (($_GET['action'] ?? '') === 'delete' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    if ($id && $id !== (int)$_SESSION['user_id']) {
        $pdo->prepare("DELETE FROM users WHERE user_id=?")->execute([$id]);
    }
    header("Location: manage_users.php?deleted=1"); exit;
}
if (isset($_GET['deleted'])) $msg = "User deleted successfully.";

$users = $pdo->query("SELECT user_id, username, email, role, COALESCE(status,'active') AS status, created_at FROM users ORDER BY created_at DESC")->fetchAll();

$activePage = 'manage_users';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manage Users – SuccuTrack</title>
<link rel="stylesheet" href="style.css">
</head>
<body class="role-admin">
<div class="app-layout">
  <?php include 'sidebar.php'; ?>

  <div class="main-content">
    <header class="topbar">
      <div class="topbar-left">
        <button class="sb-toggle" onclick="openSidebar()">☰</button>
        <div class="topbar-title">Manage <span>Users</span></div>
      </div>
    </header>

    <div class="page-body">

      <?php if ($msg):   ?><div class="alert alert-success">✅ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
      <?php if ($error): ?><div class="alert alert-error">⚠️ <?= htmlspecialchars($error) ?></div><?php endif; ?>

      <div class="pg-header">
        <h1>User Management</h1>
        <p>Create, edit roles, or delete user accounts</p>
      </div>

      <!-- Add user form -->
      <div class="card">
        <h2>Add New User</h2>
        <p class="subtitle">Create an account manually and assign a role</p>
        <form method="POST">
          <div class="form-row form-row-4" style="margin-bottom:14px;">
            <div class="form-group">
              <label>Username</label>
              <input type="text" name="new_username" required placeholder="Username">
            </div>
            <div class="form-group">
              <label>Email</label>
              <input type="email" name="new_email" required placeholder="email@example.com">
            </div>
            <div class="form-group">
              <label>Password</label>
              <input type="password" name="new_password" required placeholder="Password">
            </div>
            <div class="form-group">
              <label>Role</label>
              <select name="new_role">
                <option value="user">User</option>
                <option value="manager">Manager</option>
                <option value="admin">Admin</option>
              </select>
            </div>
          </div>
          <button type="submit" class="btn btn-primary">👤 Create User</button>
        </form>
      </div>

      <!-- Users table -->
      <div class="card">
        <div class="card-header">
          <div>
            <h2>All Users <span class="user-count"><?= count($users) ?></span></h2>
            <p class="subtitle" style="margin:0;">Timestamps in Asia/Manila (PHT, UTC+8)</p>
          </div>
        </div>
        <div class="table-wrap">
          <table class="det-table">
            <thead>
              <tr><th>#</th><th>Username</th><th>Email</th><th>Role</th><th>Status</th><th>Joined (PHT)</th><th>Action</th></tr>
            </thead>
            <tbody>
              <?php foreach ($users as $u): ?>
              <tr>
                <td><?= $u['user_id'] ?></td>
                <td><strong><?= htmlspecialchars($u['username']) ?></strong></td>
                <td><?= htmlspecialchars($u['email']) ?></td>
                <td>
                  <form method="POST" class="role-form">
                    <input type="hidden" name="uid" value="<?= $u['user_id'] ?>">
                    <input type="hidden" name="update_role" value="1">
                    <select name="role" onchange="this.form.submit()" class="role-select">
                      <option value="user"    <?= $u['role']==='user'    ? 'selected':'' ?>>user</option>
                      <option value="manager" <?= $u['role']==='manager' ? 'selected':'' ?>>manager</option>
                      <option value="admin"   <?= $u['role']==='admin'   ? 'selected':'' ?>>admin</option>
                    </select>
                  </form>
                </td>
                <td><?php
                  $ust = $u['status'] ?? 'active';
                  $pm = ['pending'=>'pill-pending','recommended'=>'pill-recommended','active'=>'pill-active'];
                  $lm = ['pending'=>'⏳ Pending','recommended'=>'📋 Recommended','active'=>'✅ Active'];
                  echo '<span class="status-pill '.($pm[$ust]??'pill-active').'">'.($lm[$ust]??ucfirst($ust)).'</span>';
                ?></td>
                <td><?= date('M d, Y', strtotime($u['created_at'])) ?></td>
                <td>
                  <?php if ($u['user_id'] !== $_SESSION['user_id']): ?>
                  <a href="manage_users.php?action=delete&id=<?= $u['user_id'] ?>"
                     class="btn btn-danger"
                     onclick="return confirm('Delete <?= htmlspecialchars($u['username'],ENT_QUOTES) ?>?')">Delete</a>
                  <?php else: ?>
                  <span class="you-label">You</span>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

    </div><!-- /page-content -->
  </div><!-- /main-content -->
</div><!-- /app-layout -->
</body>
</html>
