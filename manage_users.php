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
    $p = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
    $r = $_POST['new_role'];
    // Validate role
    if (!in_array($r, ['admin', 'manager', 'user'])) $r = 'user';
    try {
        $pdo->prepare("INSERT INTO users (username, email, password, role) VALUES (?,?,?,?)")
            ->execute([$u, $e, $p, $r]);
        $msg = "User '$u' created successfully.";
    } catch (PDOException $ex) {
        $error = "Username or email already exists.";
    }
}

if ($_POST && isset($_POST['update_role'])) {
    $newRole = $_POST['role'];
    if (!in_array($newRole, ['admin', 'manager', 'user'])) $newRole = 'user';
    $pdo->prepare("UPDATE users SET role = ? WHERE user_id = ?")
        ->execute([$newRole, $_POST['uid']]);
    $msg = "Role updated.";
}

if (($_GET['action'] ?? '') === 'delete' && isset($_GET['id'])) {
    $pdo->prepare("DELETE FROM users WHERE user_id = ?")->execute([$_GET['id']]);
    header("Location: manage_users.php?deleted=1"); exit;
}
if (isset($_GET['deleted'])) $msg = "User deleted successfully.";

$users = $pdo->query("SELECT user_id, username, email, role, created_at FROM users ORDER BY created_at DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manage Users – SuccuTrack</title>
<link rel="stylesheet" href="style.css">
<style>
.badge-manager { background: #f0eaff; color: #6b3ec8; border: 1px solid #d4baff; }
</style>
</head>
<body>
<nav class="navbar">
  <div class="nav-brand">🌵 SuccuTrack <span class="admin-badge">Admin</span></div>
  <div class="nav-links">
    <a href="admin_dashboard.php" class="btn btn-sm">← Dashboard</a>
    <a href="logout.php" class="btn btn-sm">Logout</a>
  </div>
</nav>

<div class="container">

  <?php if ($msg):   ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <div class="card">
    <h2>Add New User</h2>
    <p class="subtitle">Create a new account manually</p>
    <form method="POST" class="add-user-form">
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
      <button type="submit" class="btn btn-primary">Create User</button>
    </form>
  </div>

  <div class="card">
    <h2>All Users <span class="user-count"><?= count($users) ?></span></h2>
    <div style="overflow-x:auto;">
    <table class="det-table">
      <thead>
        <tr><th>#</th><th>Username</th><th>Email</th><th>Role</th><th>Joined</th><th>Action</th></tr>
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
          <td><?= date('M d, Y', strtotime($u['created_at'])) ?></td>
          <td>
            <?php if ($u['user_id'] !== $_SESSION['user_id']): ?>
            <a href="manage_users.php?action=delete&id=<?= $u['user_id'] ?>"
               class="btn btn-sm btn-danger"
               onclick="return confirm('Delete <?= htmlspecialchars($u['username']) ?>?')">Delete</a>
            <?php else: ?>
            <span style="color:var(--text-3);font-size:.78rem;">You</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>

</div>
</body>
</html>
