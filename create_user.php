<?php
/**
 * create_user.php — Registration with email OTP verification
 * Flow:
 *   Step 1 (GET / POST with step=register): Fill registration form
 *   Step 2 (POST success → redirect to verify_email.php): OTP sent to email
 *
 * The user row is NOT created until email is verified in verify_email.php.
 * Pending data lives in email_verifications table.
 */

session_start();

if (isset($_SESSION['user_id'])) {
    header("Location: " . match($_SESSION['role']) {
        'admin'   => 'admin_dashboard.php',
        'manager' => 'manager_dashboard.php',
        default   => 'dashboard.php',
    });
    exit;
}

require 'config.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u  = trim($_POST['username']         ?? '');
    $e  = strtolower(trim($_POST['email'] ?? ''));
    $p  = $_POST['password']              ?? '';
    $p2 = $_POST['password_confirm']      ?? '';

    // ── Validation ──────────────────────────────────────────────────────────
    if ($u === '' || $e === '' || $p === '' || $p2 === '') {
        $error = "All fields are required.";
    } elseif (strlen($u) < 3 || strlen($u) > 30) {
        $error = "Username must be 3–30 characters.";
    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $u)) {
        $error = "Username may only contain letters, numbers, and underscores.";
    } elseif (!filter_var($e, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif (strlen($p) < 6) {
        $error = "Password must be at least 6 characters.";
    } elseif ($p !== $p2) {
        $error = "Passwords do not match.";
    } else {
        // ── Uniqueness check ────────────────────────────────────────────────
        $dup = $pdo->prepare("SELECT user_id FROM users WHERE username=? OR email=?");
        $dup->execute([$u, $e]);
        if ($dup->fetch()) {
            $error = "Username or email is already taken. Please choose another.";
        } else {
            // Check pending verifications for same email/username
            $dupPend = $pdo->prepare("SELECT id FROM email_verifications WHERE email=? AND expires_at > NOW()");
            $dupPend->execute([$e]);
            if ($dupPend->fetch()) {
                // Already has an active pending verification — redirect to it
                $_SESSION['pending_email'] = $e;
                header("Location: verify_email.php?resent=1");
                exit;
            }

            // ── Send OTP ─────────────────────────────────────────────────────
            $hashed = password_hash($p, PASSWORD_DEFAULT);
            $result = send_otp_email($pdo, $e, $u, $hashed);

            if ($result['success']) {
                $_SESSION['pending_email']    = $e;
                $_SESSION['pending_username'] = $u;
                header("Location: verify_email.php");
                exit;
            } else {
                $error = "Could not send verification email. Please try again.";
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
<title>SuccuTrack – Create Account</title>
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
      <div class="auth-panel-hl">Start Monitoring<br>Your Plants <em>Today</em>.</div>
      <p class="auth-panel-desc">
        Create a free account and get instant access to real-time humidity tracking,
        automated readings, smart care tips, and a personalised plant dashboard.
      </p>
      <div class="auth-features">
        <div class="auth-feature"><div class="auth-feature-ic">💧</div>Live humidity readings from your location</div>
        <div class="auth-feature"><div class="auth-feature-ic">🤖</div>Auto-fetch every 10 minutes</div>
        <div class="auth-feature"><div class="auth-feature-ic">📊</div>Per-plant charts and history</div>
        <div class="auth-feature"><div class="auth-feature-ic">✉️</div>Email-verified secure accounts</div>
      </div>
    </div>
    <div class="auth-panel-foot">© <?= date('Y') ?> SuccuTrack · Manolo Fortich, Bukidnon</div>
  </div>

  <!-- Right form panel -->
  <div class="auth-form-side">
    <div class="auth-form-box">

      <!-- Step indicator -->
      <div class="reg-steps">
        <div class="reg-step active">
          <div class="reg-step-dot">1</div>
          <div class="reg-step-lbl">Details</div>
        </div>
        <div class="reg-step-line"></div>
        <div class="reg-step">
          <div class="reg-step-dot">2</div>
          <div class="reg-step-lbl">Verify</div>
        </div>
        <div class="reg-step-line"></div>
        <div class="reg-step">
          <div class="reg-step-dot">3</div>
          <div class="reg-step-lbl">Done</div>
        </div>
      </div>

      <div class="auth-form-title">Create Account</div>
      <div class="auth-form-sub">Join SuccuTrack — it's free &amp; takes 30 seconds</div>

      <?php if ($error): ?>
        <div class="alert alert-error">⚠️ <?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="POST" id="regForm" novalidate>

        <div class="auth-fg">
          <label for="username">Username</label>
          <input type="text" id="username" name="username" required
                 placeholder="Letters, numbers, underscores"
                 value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                 autocomplete="username" minlength="3" maxlength="30">
        </div>

        <div class="auth-fg">
          <label for="email">Email Address</label>
          <input type="email" id="email" name="email" required
                 placeholder="your@email.com"
                 value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                 autocomplete="email">
          <div style="font-size:.62rem;color:var(--text-3);margin-top:2px;">A verification code will be sent here</div>
        </div>

        <div class="auth-fg">
          <label for="password">Password</label>
          <input type="password" id="password" name="password" required
                 placeholder="At least 6 characters"
                 autocomplete="new-password" minlength="6">
        </div>

        <div class="auth-fg" style="margin-bottom:16px;">
          <label for="password_confirm">Confirm Password</label>
          <input type="password" id="password_confirm" name="password_confirm" required
                 placeholder="Re-enter your password"
                 autocomplete="new-password">
          <div class="pw-match-hint" id="pwHint"></div>
        </div>

        <button type="submit" class="auth-submit" id="submitBtn">
          Send Verification Code →
        </button>
      </form>

      <div class="auth-link">Already have an account? <a href="index.php">Sign in here</a></div>
    </div>
  </div>

</div>

<script>
const pw   = document.getElementById('password');
const pw2  = document.getElementById('password_confirm');
const hint = document.getElementById('pwHint');
function checkMatch() {
  if (!pw2.value) { hint.textContent=''; hint.className='pw-match-hint'; return; }
  if (pw.value === pw2.value) { hint.textContent='✓ Passwords match'; hint.className='pw-match-hint ok'; }
  else { hint.textContent='✗ Passwords do not match'; hint.className='pw-match-hint err'; }
}
pw.addEventListener('input', checkMatch);
pw2.addEventListener('input', checkMatch);

const form = document.getElementById('regForm');
const btn  = document.getElementById('submitBtn');
form.addEventListener('submit', function(e) {
  if (pw.value !== pw2.value) {
    e.preventDefault();
    hint.textContent = '✗ Passwords do not match';
    hint.className   = 'pw-match-hint err';
    pw2.focus();
    return;
  }
  btn.disabled    = true;
  btn.textContent = 'Sending code…';
});
</script>
</body>
</html>
