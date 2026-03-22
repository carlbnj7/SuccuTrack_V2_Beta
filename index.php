<?php
session_start();
if (isset($_SESSION['user_id'])) {
    header("Location: " . match($_SESSION['role']) {
        'admin'   => 'admin_dashboard.php',
        'manager' => 'manager_dashboard.php',
        default   => 'dashboard.php',
    });
    exit;
}

$error  = '';
$notice = '';

// Show notice if redirected here after successful registration
if (isset($_GET['registered'])) {
    $notice = "Account created! Sign in below.";
}
// Show notice if redirected here after OTP expiry during registration attempt
if (isset($_GET['expired'])) {
    $notice = "Your verification code expired. Please register again.";
}

if ($_POST) {
    require 'config.php';
    $u = trim($_POST['username'] ?? '');
    $p = $_POST['password']     ?? '';

    if ($u === '' || $p === '') {
        $error = "Please enter your username and password.";
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$u]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($p, $user['password'])) {
            $error = "Invalid username or password.";
        } else {
            // Block unverified accounts (is_verified = 0)
            // Admin-created accounts have is_verified = 1 by default (trusted)
            if (isset($user['is_verified']) && (int)$user['is_verified'] === 0) {
                // Check if they have a pending verification
                require_once 'config.php'; // already included but harmless
                $pend = $pdo->prepare("SELECT email FROM email_verifications WHERE email=? AND expires_at > NOW() LIMIT 1");
                $pend->execute([$user['email']]);
                if ($pend->fetch()) {
                    $_SESSION['pending_email']    = $user['email'];
                    $_SESSION['pending_username'] = $user['username'];
                    header("Location: verify_email.php");
                    exit;
                }
                $error = "Your email is not verified. Please register again to receive a new code.";
            } else {
                if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
                    $pdo->prepare("UPDATE users SET password=? WHERE user_id=?")
                        ->execute([password_hash($p, PASSWORD_DEFAULT), $user['user_id']]);
                }
                session_regenerate_id(true);
                $_SESSION['user_id']  = $user['user_id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role']     = $user['role'];
                header("Location: " . match($user['role']) {
                    'admin'   => 'admin_dashboard.php',
                    'manager' => 'manager_dashboard.php',
                    default   => 'dashboard.php',
                });
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SuccuTrack – Sign In</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="auth-wrap">

  <!-- Left decorative panel -->
  <div class="auth-panel">
    <div class="auth-panel-dots"></div>
    <div class="auth-panel-top">
      <div class="auth-panel-icon">🌵</div>
      <span class="auth-panel-name">SuccuTrack</span>
    </div>
    <div class="auth-panel-body">
      <div class="auth-panel-hl">Monitor. Automate.<br><em>Grow</em> Smarter.</div>
      <p class="auth-panel-desc">
        Real-time humidity monitoring for succulents. Automated readings every 10 minutes,
        smart care tips, and multi-plant tracking — all in one dashboard.
      </p>
      <div class="auth-features">
        <div class="auth-feature"><div class="auth-feature-ic">💧</div>Live humidity via OpenWeatherMap</div>
        <div class="auth-feature"><div class="auth-feature-ic">🤖</div>Auto-fetch every 10 minutes</div>
        <div class="auth-feature"><div class="auth-feature-ic">📊</div>Per-plant humidity charts</div>
        <div class="auth-feature"><div class="auth-feature-ic">🗺️</div>Coverage map · Manolo Fortich</div>
      </div>
    </div>
    <div class="auth-panel-foot">© <?= date('Y') ?> SuccuTrack · Manolo Fortich, Bukidnon</div>
  </div>

  <!-- Right form panel -->
  <div class="auth-form-side">
    <div class="auth-form-box">
      <div class="auth-form-title">Welcome back</div>
      <div class="auth-form-sub">Sign in to your SuccuTrack account</div>

      <?php if ($notice): ?>
        <div class="alert alert-success" style="margin-bottom:14px;">✅ <?= htmlspecialchars($notice) ?></div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="alert alert-error" style="margin-bottom:14px;">⚠️ <?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="POST" id="loginForm">
        <div class="auth-fg">
          <label for="username">Username</label>
          <input type="text" id="username" name="username" required
                 placeholder="Enter your username"
                 value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                 autocomplete="username">
        </div>
        <div class="auth-fg" style="margin-bottom:16px;">
          <label for="password">Password</label>
          <input type="password" id="password" name="password" required
                 placeholder="Enter your password" autocomplete="current-password">
        </div>
        <button type="submit" class="auth-submit">Sign In →</button>
      </form>

      <div class="auth-link">No account? <a href="create_user.php">Register here</a></div>

      <!-- Demo credentials -->
      <div class="demo-divider">Demo accounts</div>
      <div class="demo-grid">
        <button class="demo-btn" onclick="fillDemo('admin','password')" type="button">
          <span class="demo-btn-icon">🛡️</span>
          <span class="demo-btn-label">Admin</span>
        </button>
        <button class="demo-btn" onclick="fillDemo('manager1','password')" type="button">
          <span class="demo-btn-icon">⚙️</span>
          <span class="demo-btn-label">Manager</span>
        </button>
        <button class="demo-btn" onclick="fillDemo('juan','password')" type="button">
          <span class="demo-btn-icon">🌿</span>
          <span class="demo-btn-label">User</span>
        </button>
      </div>
      <div class="demo-hint">Password for all demo accounts: <strong>password</strong></div>
    </div>
  </div>

</div>

<script>
function fillDemo(u, p) {
  document.getElementById('username').value = u;
  document.getElementById('password').value = p;
  document.getElementById('loginForm').submit();
}
</script>
</body>
</html>
