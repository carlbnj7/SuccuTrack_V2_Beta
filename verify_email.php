<?php
session_start();
if (empty($_SESSION['pending_email'])) { header("Location: create_user.php"); exit; }
if (isset($_SESSION['user_id'])) {
    header("Location: " . match($_SESSION['role']) {
        'admin' => 'admin_dashboard.php', 'manager' => 'manager_dashboard.php', default => 'dashboard.php'
    }); exit;
}
require 'config.php';

$pendingEmail = $_SESSION['pending_email'];
$devOtp       = $_SESSION['dev_otp'] ?? null;
$error = ''; $success = false; $resent = isset($_GET['resent']);

function loadPending(PDO $pdo, string $email): ?array {
    $s = $pdo->prepare("SELECT * FROM email_verifications WHERE email=? AND expires_at > NOW() ORDER BY created_at DESC LIMIT 1");
    $s->execute([$email]); return $s->fetch() ?: null;
}

$pending = loadPending($pdo, $pendingEmail);
if (!$pending) {
    unset($_SESSION['pending_email'], $_SESSION['pending_username'], $_SESSION['dev_otp']);
    header("Location: create_user.php?expired=1"); exit;
}

$isLocked    = (int)$pending['attempts'] >= OTP_MAX_ATTEMPTS;
$secondsLeft = max(0, strtotime($pending['expires_at']) - time());

if (isset($_GET['action']) && $_GET['action'] === 'resend') {
    $res = send_otp_email($pdo, $pendingEmail, $pending['username'], $pending['password']);
    if ($res['success']) {
        if (!empty($res['dev_mode'])) { $_SESSION['dev_otp'] = $res['otp']; $devOtp = $res['otp']; }
        else { unset($_SESSION['dev_otp']); $devOtp = null; }
        header("Location: verify_email.php?resent=1"); exit;
    }
    $error = "Failed to resend: " . htmlspecialchars($res['error'] ?? '');
}

$verifiedUsername = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isLocked) {
    $parts = [];
    for ($i = 1; $i <= OTP_LENGTH; $i++) $parts[] = trim($_POST["otp_$i"] ?? '');
    $entered = implode('', $parts);

    if (strlen($entered) < OTP_LENGTH || !ctype_digit($entered)) {
        $error = "Please enter all " . OTP_LENGTH . " digits.";
    } elseif ($entered !== $pending['otp_code']) {
        $newAtt = (int)$pending['attempts'] + 1;
        $pdo->prepare("UPDATE email_verifications SET attempts=? WHERE id=?")->execute([$newAtt, $pending['id']]);
        $rem = OTP_MAX_ATTEMPTS - $newAtt;
        $isLocked = $rem <= 0;
        $error = $isLocked
            ? "Too many incorrect attempts. Please request a new code."
            : "Incorrect code. {$rem} attempt" . ($rem === 1 ? '' : 's') . " remaining.";
        $pending = loadPending($pdo, $pendingEmail) ?? $pending;
    } else {
        // ── Correct OTP ──────────────────────────────────────────────────────
        try {
            // Create user with status='pending' — awaiting manager review
            $pdo->prepare("
                INSERT INTO users (username, email, password, role, is_verified, status)
                VALUES (?, ?, ?, 'user', 1, 'pending')
            ")->execute([$pending['username'], $pendingEmail, $pending['password']]);

            $newUserId = (int)$pdo->lastInsertId();

            // Clean up OTP record
            $pdo->prepare("DELETE FROM email_verifications WHERE email=?")->execute([$pendingEmail]);

            // Notify all managers
            notify_managers_new_user($pdo, $newUserId, $pending['username']);

            $verifiedUsername = $pending['username'];
            unset($_SESSION['pending_email'], $_SESSION['pending_username'], $_SESSION['dev_otp']);
            $success = true;

        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000') {
                // Race condition — account already exists, just redirect
                $pdo->prepare("DELETE FROM email_verifications WHERE email=?")->execute([$pendingEmail]);
                unset($_SESSION['pending_email'], $_SESSION['pending_username'], $_SESSION['dev_otp']);
                header("Location: index.php?registered=1"); exit;
            }
            $error = "Account creation failed. Please try again.";
        }
    }
}

function maskEmail(string $email): string {
    [$local, $domain] = explode('@', $email, 2);
    return substr($local, 0, min(2, strlen($local))) . str_repeat('*', max(0, strlen($local) - 2)) . '@' . $domain;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SuccuTrack – Verify Email</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="auth-wrap">

  <!-- Left panel -->
  <div class="auth-panel">
    <div class="auth-panel-dots"></div>
    <div class="auth-panel-top">
      <div class="auth-panel-icon">🌵</div>
      <span class="auth-panel-name">SuccuTrack</span>
    </div>
    <div class="auth-panel-body">
      <div class="auth-panel-hl">One Quick<br><em>Verification</em> Step.</div>
      <p class="auth-panel-desc">Enter the 6-digit code sent to your email to confirm your account and get started.</p>
      <div class="auth-features">
        <div class="auth-feature"><div class="auth-feature-ic">🔒</div>Secure OTP verification</div>
        <div class="auth-feature"><div class="auth-feature-ic">⏱</div>Code valid for <?= OTP_EXPIRY_MINUTES ?> minutes</div>
        <div class="auth-feature"><div class="auth-feature-ic">🔁</div>Request a new code any time</div>
        <div class="auth-feature"><div class="auth-feature-ic">✅</div>Account active after verification</div>
      </div>
    </div>
    <div class="auth-panel-foot">© <?= date('Y') ?> SuccuTrack · Manolo Fortich, Bukidnon</div>
  </div>

  <!-- Right form panel -->
  <div class="auth-form-side">
    <div class="auth-form-box">

      <?php if ($success): ?>
      <!-- ── SUCCESS STATE ─────────────────────────────────────────────────── -->
      <div class="reg-steps" style="margin-bottom:20px;">
        <div class="reg-step done"><div class="reg-step-dot">✓</div><div class="reg-step-lbl">Details</div></div>
        <div class="reg-step-line done"></div>
        <div class="reg-step done"><div class="reg-step-dot">✓</div><div class="reg-step-lbl">Verify</div></div>
        <div class="reg-step-line done"></div>
        <div class="reg-step active"><div class="reg-step-dot">3</div><div class="reg-step-lbl">Done</div></div>
      </div>
      <div style="text-align:center;padding:10px 0 4px;">
        <div style="width:60px;height:60px;border-radius:50%;background:var(--ideal-lt);border:2px solid var(--ideal-md);display:flex;align-items:center;justify-content:center;font-size:1.6rem;margin:0 auto 14px;">✅</div>
        <div style="font-family:var(--font-d);font-size:1.3rem;font-weight:700;color:var(--text);letter-spacing:-.025em;margin-bottom:6px;">You're Registered!</div>
        <p style="font-size:.8rem;color:var(--text-3);line-height:1.6;margin-bottom:20px;">
          Welcome, <strong><?= htmlspecialchars($verifiedUsername) ?></strong>!<br>
          A Manager has been notified and will review your account. Once approved,
          the Admin will assign your plants and you'll be ready to monitor.
        </p>

        <!-- Onboarding progress -->
        <div style="background:var(--sf2);border:1px solid var(--border);border-radius:var(--r-sm);padding:14px 16px;margin-bottom:20px;text-align:left;">
          <div style="font-size:.63rem;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.08em;margin-bottom:12px;">Your onboarding progress</div>
          <div style="display:flex;align-items:flex-start;gap:0;">
            <div style="display:flex;flex-direction:column;align-items:center;gap:3px;">
              <div style="width:22px;height:22px;border-radius:50%;background:var(--ideal);border:2px solid var(--ideal);color:#fff;display:flex;align-items:center;justify-content:center;font-size:.67rem;font-weight:700;">✓</div>
              <div style="font-size:.58rem;color:var(--ideal);font-weight:700;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap;">Registered</div>
            </div>
            <div style="flex:1;height:2px;background:var(--ideal-md);margin:10px 4px 0;min-width:12px;"></div>
            <div style="display:flex;flex-direction:column;align-items:center;gap:3px;">
              <div style="width:22px;height:22px;border-radius:50%;background:#f59e0b;border:2px solid #f59e0b;color:#fff;display:flex;align-items:center;justify-content:center;font-size:.67rem;font-weight:700;">2</div>
              <div style="font-size:.58rem;color:#92400e;font-weight:600;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap;">Mgr Review</div>
            </div>
            <div style="flex:1;height:2px;background:var(--border);margin:10px 4px 0;min-width:12px;"></div>
            <div style="display:flex;flex-direction:column;align-items:center;gap:3px;">
              <div style="width:22px;height:22px;border-radius:50%;background:var(--surface);border:2px solid var(--border-2);color:var(--text-4);display:flex;align-items:center;justify-content:center;font-size:.67rem;font-weight:700;">3</div>
              <div style="font-size:.58rem;color:var(--text-4);font-weight:600;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap;">Plant Assign</div>
            </div>
            <div style="flex:1;height:2px;background:var(--border);margin:10px 4px 0;min-width:12px;"></div>
            <div style="display:flex;flex-direction:column;align-items:center;gap:3px;">
              <div style="width:22px;height:22px;border-radius:50%;background:var(--surface);border:2px solid var(--border-2);color:var(--text-4);display:flex;align-items:center;justify-content:center;font-size:.67rem;font-weight:700;">4</div>
              <div style="font-size:.58rem;color:var(--text-4);font-weight:600;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap;">Active</div>
            </div>
          </div>
        </div>

        <a href="index.php" class="auth-submit" style="display:block;text-align:center;text-decoration:none;">
          Sign In to Your Account →
        </a>
      </div>

      <?php elseif ($isLocked): ?>
      <!-- ── LOCKED ─────────────────────────────────────────────────────────── -->
      <div style="text-align:center;padding:10px 0;">
        <div style="width:56px;height:56px;border-radius:50%;background:var(--dry-lt);border:2px solid var(--dry-md);display:flex;align-items:center;justify-content:center;font-size:1.4rem;margin:0 auto 14px;">🔒</div>
        <div style="font-family:var(--font-d);font-size:1.2rem;font-weight:700;color:var(--text);margin-bottom:6px;">Too Many Attempts</div>
        <p style="font-size:.79rem;color:var(--text-3);margin-bottom:20px;">You've used all <?= OTP_MAX_ATTEMPTS ?> attempts. Request a new code to continue.</p>
        <a href="verify_email.php?action=resend" class="auth-submit" style="display:block;text-align:center;text-decoration:none;">Send a New Code →</a>
        <div class="auth-link"><a href="create_user.php">← Start over</a></div>
      </div>

      <?php else: ?>
      <!-- ── VERIFY FORM ─────────────────────────────────────────────────────── -->
      <div class="reg-steps">
        <div class="reg-step done"><div class="reg-step-dot">✓</div><div class="reg-step-lbl">Details</div></div>
        <div class="reg-step-line done"></div>
        <div class="reg-step active"><div class="reg-step-dot">2</div><div class="reg-step-lbl">Verify</div></div>
        <div class="reg-step-line"></div>
        <div class="reg-step"><div class="reg-step-dot">3</div><div class="reg-step-lbl">Done</div></div>
      </div>

      <div style="text-align:center;padding:6px 0 14px;">
        <div style="width:52px;height:52px;border-radius:50%;background:var(--accent-lt);border:2px solid var(--accent-md);display:flex;align-items:center;justify-content:center;font-size:1.35rem;margin:0 auto 10px;">✉️</div>
        <div style="font-family:var(--font-d);font-size:1.25rem;font-weight:700;color:var(--text);margin-bottom:5px;">Check Your Email</div>
        <?php if ($resent): ?>
          <div class="alert alert-success" style="display:inline-flex;margin:0 auto 10px;font-size:.74rem;">✅ New code sent!</div>
        <?php endif; ?>
        <p style="font-size:.78rem;color:var(--text-3);">
          We sent a <?= OTP_LENGTH ?>-digit code to<br>
          <span style="display:inline-flex;align-items:center;gap:4px;background:var(--ideal-lt);border:1px solid var(--ideal-md);color:var(--ideal);font-size:.74rem;font-weight:600;padding:2px 9px;border-radius:20px;margin-top:5px;">
            <?= htmlspecialchars(maskEmail($pendingEmail)) ?>
          </span>
        </p>
      </div>

      <!-- Dev mode banner -->
      <?php if ($devOtp): ?>
      <div style="background:#fffbeb;border:2px dashed #f59e0b;border-radius:8px;padding:11px 14px;margin-bottom:12px;text-align:center;">
        <div style="font-size:.62rem;font-weight:700;color:#92400e;text-transform:uppercase;letter-spacing:.07em;margin-bottom:5px;">🔧 Dev Mode — code shown on screen</div>
        <div style="font-size:1.9rem;font-weight:700;letter-spacing:.25em;color:#b45309;font-family:monospace;"><?= htmlspecialchars($devOtp) ?></div>
        <div style="font-size:.64rem;color:#92400e;margin-top:5px;">Set <code>OTP_DEV_MODE = false</code> in config.php for production.</div>
      </div>
      <?php endif; ?>

      <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="POST" id="otpForm" autocomplete="off">
        <div class="otp-inputs" id="otpInputs">
          <?php for ($i = 1; $i <= OTP_LENGTH; $i++): ?>
          <input type="text" inputmode="numeric" pattern="[0-9]" maxlength="1"
                 class="otp-digit" id="otp<?= $i ?>" name="otp_<?= $i ?>"
                 aria-label="Digit <?= $i ?>"<?= $i === 1 ? ' autofocus' : '' ?>>
          <?php endfor; ?>
        </div>
        <button type="submit" class="auth-submit" id="otpSubmitBtn" disabled>Verify Code</button>
      </form>

      <div style="text-align:center;margin-top:12px;">
        <div class="otp-timer" id="timerLine">Code expires in <strong id="timerVal"><?= gmdate('i:s', $secondsLeft) ?></strong></div>
        <div style="margin-top:6px;font-size:.73rem;color:var(--text-3);">
          Didn't receive it? <a href="verify_email.php?action=resend" class="otp-resend-btn">Resend code</a>
        </div>
      </div>
      <div class="auth-link" style="margin-top:12px;"><a href="create_user.php">← Use a different email</a></div>
      <?php endif; ?>

    </div>
  </div>
</div>

<script>
const digits = Array.from(document.querySelectorAll('.otp-digit'));
const submitBtn = document.getElementById('otpSubmitBtn');
function refreshBtn() { if (submitBtn) submitBtn.disabled = !digits.every(d => /^\d$/.test(d.value)); }
digits.forEach((inp, idx) => {
  inp.addEventListener('input', function() {
    this.value = this.value.replace(/\D/g, '').slice(-1);
    this.classList.toggle('filled', !!this.value);
    if (this.value && idx < digits.length - 1) digits[idx + 1].focus();
    refreshBtn();
  });
  inp.addEventListener('keydown', function(e) {
    if (e.key === 'Backspace' && !this.value && idx > 0) {
      digits[idx-1].value = ''; digits[idx-1].classList.remove('filled'); digits[idx-1].focus(); refreshBtn();
    }
    if (e.key === 'ArrowLeft'  && idx > 0)              digits[idx-1].focus();
    if (e.key === 'ArrowRight' && idx < digits.length-1) digits[idx+1].focus();
  });
  inp.addEventListener('paste', function(e) {
    e.preventDefault();
    const p = (e.clipboardData||window.clipboardData).getData('text').replace(/\D/g,'');
    p.split('').forEach((ch,i) => { if(digits[idx+i]){digits[idx+i].value=ch;digits[idx+i].classList.add('filled');} });
    digits[Math.min(idx+p.length,digits.length-1)].focus(); refreshBtn();
  });
  inp.addEventListener('focus', function() { this.select(); });
});
<?php if ($devOtp && strlen($devOtp) === OTP_LENGTH): ?>
(function(){ const c=<?= json_encode($devOtp) ?>; c.split('').forEach((ch,i)=>{if(digits[i]){digits[i].value=ch;digits[i].classList.add('filled');}}); refreshBtn(); })();
<?php endif; ?>
<?php if ($error && !$success && !$isLocked): ?>
digits.forEach(d=>d.classList.add('error')); setTimeout(()=>digits.forEach(d=>d.classList.remove('error')),400);
<?php endif; ?>
let secs=<?= $secondsLeft ?>;
const tv=document.getElementById('timerVal'),tl=document.getElementById('timerLine');
(function tick(){
  if(secs<=0){if(tl){tl.className='otp-timer expired';tl.innerHTML='Code has <strong>expired</strong>.';}if(submitBtn)submitBtn.disabled=true;return;}
  secs--; const m=Math.floor(secs/60),s=secs%60;
  if(tv)tv.textContent=String(m).padStart(2,'0')+':'+String(s).padStart(2,'0');
  if(secs<=60&&tl)tl.style.color='var(--dry)';
  setTimeout(tick,1000);
})();
const form=document.getElementById('otpForm');
if(form)form.addEventListener('submit',function(){if(submitBtn){submitBtn.disabled=true;submitBtn.textContent='Verifying…';}});
</script>
</body>
</html>
