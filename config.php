<?php
// ── Timezone ──────────────────────────────────────────────────────────────────
define('APP_TIMEZONE', 'Asia/Manila');
date_default_timezone_set(APP_TIMEZONE);

// ── Database ──────────────────────────────────────────────────────────────────
$host = 'localhost';
$db   = 'u442411629_succulent';
$user = 'u442411629_dev_succulent';
$pass = '%oV0p(24rNz7';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET time_zone = '+08:00'");
} catch (PDOException $e) {
    error_log("DB Connection failed: " . $e->getMessage());
    die("Database unavailable. Please try again later.");
}

// ── Schema migrations (safe to re-run on every request) ───────────────────────
try {
    // OTP verification table
    $pdo->exec("CREATE TABLE IF NOT EXISTS email_verifications (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        email      VARCHAR(255) NOT NULL,
        username   VARCHAR(80)  NOT NULL,
        password   VARCHAR(255) NOT NULL,
        otp_code   CHAR(6)      NOT NULL,
        attempts   TINYINT UNSIGNED NOT NULL DEFAULT 0,
        expires_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_email (email),
        INDEX idx_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // is_verified column (backward-compatible default = 1 so existing users aren't locked out)
    $pdo->exec("ALTER TABLE users
        ADD COLUMN IF NOT EXISTS is_verified TINYINT(1) NOT NULL DEFAULT 1");

    // status column: pending → recommended → active
    $pdo->exec("ALTER TABLE users
        ADD COLUMN IF NOT EXISTS status ENUM('pending','recommended','active') NOT NULL DEFAULT 'active'");

    // In-app notifications for manager + admin
    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
        notif_id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        for_role    ENUM('admin','manager') NOT NULL,
        type        VARCHAR(40) NOT NULL,
        title       VARCHAR(160) NOT NULL,
        body        TEXT NOT NULL,
        ref_user_id INT UNSIGNED NULL,
        is_read     TINYINT(1) NOT NULL DEFAULT 0,
        created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_role_unread (for_role, is_read),
        INDEX idx_ref (ref_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

} catch (PDOException $e) {
    // Non-fatal — column may already exist; silently continue
}

// ── SMTP ──────────────────────────────────────────────────────────────────────
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'your_gmail@gmail.com');
define('SMTP_PASS', 'xxxx xxxx xxxx xxxx');
define('SMTP_FROM', 'your_gmail@gmail.com');
define('SMTP_NAME', 'SuccuTrack');

// OTP settings
define('OTP_EXPIRY_MINUTES', 10);
define('OTP_MAX_ATTEMPTS',    5);
define('OTP_LENGTH',          6);
// true  → show OTP on screen (local dev)
// false → send real email (production)
define('OTP_DEV_MODE', false);

// ── Pure PHP SMTP sender ──────────────────────────────────────────────────────
function smtp_send(string $to, string $toName, string $subject, string $htmlBody, string $textBody): array
{
    $host=$_h=SMTP_HOST; $port=SMTP_PORT; $user=SMTP_USER;
    $pass=SMTP_PASS; $from=SMTP_FROM; $name=SMTP_NAME; $timeout=15;
    if (strpos($user,'your_gmail')!==false||strpos($pass,'xxxx')!==false)
        return ['ok'=>false,'error'=>'SMTP credentials not configured in config.php'];
    try {
        $ctx=stream_context_create(['ssl'=>['verify_peer'=>false,'verify_peer_name'=>false]]);
        $sock=stream_socket_client("tcp://{$host}:{$port}",$errno,$errstr,$timeout,STREAM_CLIENT_CONNECT,$ctx);
        if (!$sock) return ['ok'=>false,'error'=>"Cannot connect to {$host}:{$port} — {$errstr}"];
        stream_set_timeout($sock,$timeout);
        $cmd=function(string $c) use($sock):string {
            if($c!=='') fwrite($sock,$c."\r\n");
            $r=''; while($line=fgets($sock,512)){$r.=$line; if(isset($line[3])&&$line[3]===' ')break;} return $r;
        };
        $expect=function(string $r,string $code){
            if(substr(trim($r),0,3)!==$code) throw new RuntimeException("SMTP error (expected $code): ".trim($r));
        };
        $expect($cmd(''),'220');
        $expect($cmd("EHLO ".gethostname()),'250');
        $expect($cmd("STARTTLS"),'220');
        if(!stream_socket_enable_crypto($sock,true,STREAM_CRYPTO_METHOD_TLS_CLIENT))
            throw new RuntimeException("TLS handshake failed");
        $expect($cmd("EHLO ".gethostname()),'250');
        $expect($cmd("AUTH LOGIN"),'334');
        $expect($cmd(base64_encode($user)),'334');
        $expect($cmd(base64_encode($pass)),'235');
        $expect($cmd("MAIL FROM:<{$from}>"),'250');
        $expect($cmd("RCPT TO:<{$to}>"),'250');
        $b='=_'.md5(uniqid('',true));
        $h ="Date: ".date('r')."\r\nMessage-ID: <".uniqid('st',true)."@st.local>\r\n";
        $h.="From: {$name} <{$from}>\r\nTo: {$toName} <{$to}>\r\nSubject: {$subject}\r\n";
        $h.="MIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=\"{$b}\"\r\nX-Mailer: SuccuTrack/PHP\r\n";
        $body ="--{$b}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n";
        $body.=quoted_printable_encode($textBody)."\r\n--{$b}\r\n";
        $body.="Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n";
        $body.=quoted_printable_encode($htmlBody)."\r\n--{$b}--\r\n";
        $expect($cmd("DATA"),'354');
        $msg=str_replace("\n.","\n..",$h."\r\n".$body);
        $expect($cmd($msg."\r\n."),'250');
        $cmd("QUIT"); fclose($sock);
        return ['ok'=>true];
    } catch (RuntimeException $e) {
        if(isset($sock)&&is_resource($sock)) fclose($sock);
        error_log("[SuccuTrack SMTP] ".$e->getMessage());
        return ['ok'=>false,'error'=>$e->getMessage()];
    }
}

// ── send_otp_email() ──────────────────────────────────────────────────────────
function send_otp_email(PDO $pdo, string $email, string $username, string $hashed_password): array
{
    $pdo->prepare("DELETE FROM email_verifications WHERE email=? OR expires_at < NOW()")->execute([$email]);
    $otp=''; for($i=0;$i<OTP_LENGTH;$i++) $otp.=(string)random_int(0,9);
    $expires=date('Y-m-d H:i:s',time()+OTP_EXPIRY_MINUTES*60);
    $pdo->prepare("INSERT INTO email_verifications (email,username,password,otp_code,attempts,expires_at) VALUES(?,?,?,?,0,?)")
        ->execute([$email,$username,$hashed_password,$otp,$expires]);
    if (OTP_DEV_MODE) {
        error_log("[SuccuTrack DEV] OTP for {$email}: {$otp}");
        return ['success'=>true,'otp'=>$otp,'dev_mode'=>true];
    }
    $year=date('Y'); $expMin=OTP_EXPIRY_MINUTES;
    $subj="[SuccuTrack] Your verification code: {$otp}";
    $html='<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:0;background:#f1f3f7;font-family:Arial,sans-serif;"><div style="max-width:480px;margin:32px auto;background:#fff;border-radius:10px;overflow:hidden;border:1px solid #e2e8f0;"><div style="background:linear-gradient(135deg,#0d1f14,#1a5235);padding:26px 30px 22px;"><div style="font-size:1.2rem;font-weight:700;color:#fff;">🌵 SuccuTrack</div></div><div style="padding:26px 30px;"><h2 style="font-size:1rem;color:#0f172a;margin:0 0 8px;">Hi '.htmlspecialchars($username,ENT_QUOTES).',</h2><p style="font-size:.85rem;color:#64748b;margin:0 0 20px;">Enter the code below to verify your email.</p><div style="text-align:center;margin:0 0 20px;"><div style="display:inline-block;background:#f0f5f2;border:2px solid #8fceaa;border-radius:10px;padding:14px 34px;"><div style="font-size:.65rem;font-weight:700;color:#1a6e3c;letter-spacing:.1em;text-transform:uppercase;margin-bottom:6px;">Verification Code</div><div style="font-size:2.5rem;font-weight:700;color:#1a6e3c;letter-spacing:.22em;font-family:monospace;">'.$otp.'</div></div></div><p style="font-size:.79rem;color:#94a3b8;margin:0;">Expires in <strong style="color:#0f172a;">'.$expMin.' minutes</strong>.</p></div><div style="padding:13px 30px;border-top:1px solid #e2e8f0;background:#f6f7fa;"><p style="font-size:.7rem;color:#94a3b8;margin:0;">&copy; '.$year.' SuccuTrack</p></div></div></body></html>';
    $text="Hi {$username},\n\nYour SuccuTrack verification code is:\n\n  {$otp}\n\nExpires in {$expMin} minutes.\n\n© {$year} SuccuTrack";
    $result=smtp_send($email,$username,$subj,$html,$text);
    if($result['ok']) return ['success'=>true,'otp'=>$otp];
    $pdo->prepare("DELETE FROM email_verifications WHERE email=?")->execute([$email]);
    return ['success'=>false,'error'=>$result['error']??'Unknown error'];
}

// ── Notification helpers ──────────────────────────────────────────────────────

/** Fire a notification to all managers when a new user registers */
function notify_managers_new_user(PDO $pdo, int $newUserId, string $username): void
{
    $pdo->prepare("
        INSERT INTO notifications (for_role, type, title, body, ref_user_id)
        VALUES ('manager','new_user',?,?,?)
    ")->execute([
        "New user registered: @{$username}",
        "User @{$username} has just registered and is waiting for review. Open the Pending Users tab to recommend them to the Admin for plant assignment.",
        $newUserId
    ]);
}

/** Fire a notification to all admins when a manager recommends a user */
function notify_admins_recommended(PDO $pdo, int $userId, string $username, string $managerName): void
{
    $pdo->prepare("
        INSERT INTO notifications (for_role, type, title, body, ref_user_id)
        VALUES ('admin','recommended',?,?,?)
    ")->execute([
        "User ready for plant assignment: @{$username}",
        "Manager @{$managerName} has reviewed and recommended @{$username}. Please assign plants to their account so they can start monitoring.",
        $userId
    ]);
}

/** Count unread notifications for a given role */
function get_unread_count(PDO $pdo, string $role): int
{
    $s=$pdo->prepare("SELECT COUNT(*) FROM notifications WHERE for_role=? AND is_read=0");
    $s->execute([$role]);
    return (int)$s->fetchColumn();
}

/** Count users with status = pending (for manager badge) */
function count_pending_users(PDO $pdo): int
{
    return (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='user' AND status='pending'")->fetchColumn();
}

/** Count users with status = pending OR recommended (for admin badge) */
function count_actionable_users(PDO $pdo): int
{
    return (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='user' AND status IN('pending','recommended')")->fetchColumn();
}
