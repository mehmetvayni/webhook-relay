<?php
// PayTR Webhook Relay - DB direkt (eski calisan mimari) + saglam diag
error_reporting(0);
ini_set('display_errors', 0);
header("Content-Type: text/plain");
date_default_timezone_set('Europe/Istanbul');

$merchant_key  = getenv("PAYTR_MERCHANT_KEY")  ?: "5SnY5gCkE1G9tRZt";
$merchant_salt = getenv("PAYTR_MERCHANT_SALT") ?: "8DbPU9eLdaf8z4cq";

$db_host = getenv("DB_HOST") ?: "sql203.infinityfree.com";
$db_user = getenv("DB_USER") ?: "if0_42077234";
$db_pass = getenv("DB_PASS") ?: "";
$db_name = getenv("DB_NAME") ?: "if0_42077234_korecekolay";

// --- SAGLAM DIAG: ?diag=1 ---
if (isset($_GET['diag'])) {
    echo "=== DIAG BASLADI ===\n";
    echo "host=$db_host user=$db_user name=$db_name pass_uzunluk=" . strlen($db_pass) . "\n";
    echo "mysqli var mi: " . (function_exists('mysqli_connect') ? 'EVET' : 'HAYIR') . "\n";
    echo "Baglaniliyor...\n";
    flush();
    // mysqli_report kapali, manuel kontrol
    mysqli_report(MYSQLI_REPORT_OFF);
    $c = mysqli_init();
    mysqli_options($c, MYSQLI_OPT_CONNECT_TIMEOUT, 10);
    $ok = @mysqli_real_connect($c, $db_host, $db_user, $db_pass, $db_name);
    if (!$ok) {
        echo "BAGLANTI BASARISIZ\n";
        echo "errno: " . mysqli_connect_errno() . "\n";
        echo "error: " . mysqli_connect_error() . "\n";
    } else {
        echo "BAGLANTI BASARILI!\n";
        $r = mysqli_query($c, "SELECT COUNT(*) AS n FROM kk_kayitlar");
        $row = $r ? mysqli_fetch_assoc($r) : null;
        echo "kk_kayitlar: " . ($row['n'] ?? '?') . " satir\n";
        mysqli_close($c);
    }
    echo "=== DIAG BITTI ===\n";
    exit;
}

// --- Normal calisma: PayTR bildirimi ---
$post = $_POST;
if (empty($post)) { $raw = file_get_contents("php://input"); if ($raw) parse_str($raw, $post); }
if (empty($post)) { echo "OK"; exit; }
if (empty($post['merchant_oid']) || !isset($post['status']) || !isset($post['total_amount']) || empty($post['hash'])) { echo "OK"; exit; }

$merchant_oid = $post['merchant_oid'];
$status       = $post['status'];
$total_amount = $post['total_amount'];

$hesaplanan = base64_encode(hash_hmac('sha256', $merchant_oid . $merchant_salt . $status . $total_amount, $merchant_key, true));
if ($hesaplanan !== $post['hash']) { echo "FAIL: bad hash"; exit; }

mysqli_report(MYSQLI_REPORT_OFF);
$conn = mysqli_init();
mysqli_options($conn, MYSQLI_OPT_CONNECT_TIMEOUT, 10);
if (!@mysqli_real_connect($conn, $db_host, $db_user, $db_pass, $db_name)) {
    http_response_code(500); echo "DB error: " . mysqli_connect_errno(); exit;
}
mysqli_set_charset($conn, 'utf8mb4');
mysqli_query($conn, "SET time_zone = '+03:00'");

$stmt = mysqli_prepare($conn, "SELECT id, kurs, odeme_durum FROM kk_kayitlar WHERE merchant_oid = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, "s", $merchant_oid);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$kayit = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

if (!$kayit) { mysqli_close($conn); echo "OK"; exit; }
if ($kayit['odeme_durum'] === 'basarili') { mysqli_close($conn); echo "OK"; exit; }

$now = date('Y-m-d H:i:s');
$yanit = json_encode($post, JSON_UNESCAPED_UNICODE);

if ($status === 'success') {
    if ($kayit['kurs'] === 'ozel') {
        $stmt = mysqli_prepare($conn, "UPDATE kk_kayitlar SET durum='onaylandi', odeme_durum='basarili', odeme_tarihi=?, erisim_bitis=NULL, paytr_yanit=? WHERE merchant_oid=?");
        mysqli_stmt_bind_param($stmt, "sss", $now, $yanit, $merchant_oid);
    } else {
        $bitis = date('Y-m-d H:i:s', strtotime("+30 days"));
        $stmt = mysqli_prepare($conn, "UPDATE kk_kayitlar SET durum='onaylandi', odeme_durum='basarili', odeme_tarihi=?, erisim_bitis=?, paytr_yanit=? WHERE merchant_oid=?");
        mysqli_stmt_bind_param($stmt, "ssss", $now, $bitis, $yanit, $merchant_oid);
    }
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
} else {
    $stmt = mysqli_prepare($conn, "UPDATE kk_kayitlar SET odeme_durum='basarisiz', paytr_yanit=? WHERE merchant_oid=?");
    mysqli_stmt_bind_param($stmt, "ss", $yanit, $merchant_oid);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}
mysqli_close($conn);
echo "OK";
exit;
