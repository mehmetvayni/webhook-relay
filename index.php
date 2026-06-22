<?php
// ============================================================
// PayTR Webhook Relay (Render.com) - kk_kayitlar
// ============================================================
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

// --- GECICI TESHIS: ?diag=1 ile DB baglantisini test et ---
if (isset($_GET['diag'])) {
    $c = @new mysqli($db_host, $db_user, $db_pass, $db_name);
    if ($c->connect_error) {
        echo "DB BAGLANTI HATASI:\n" . $c->connect_errno . " - " . $c->connect_error . "\n";
        echo "host=" . $db_host . "\nuser=" . $db_user . "\nname=" . $db_name . "\npass_uzunluk=" . strlen($db_pass) . "\n";
    } else {
        echo "DB BAGLANTI BASARILI!\n";
        $r = $c->query("SELECT COUNT(*) AS n FROM kk_kayitlar");
        $row = $r ? $r->fetch_assoc() : null;
        echo "kk_kayitlar satir sayisi: " . ($row['n'] ?? '?') . "\n";
        $c->close();
    }
    exit;
}

$post = $_POST;
if (empty($post)) {
    $raw = file_get_contents("php://input");
    if ($raw) parse_str($raw, $post);
}
if (empty($post)) { echo "OK"; exit; }

if (empty($post['merchant_oid']) || !isset($post['status']) || !isset($post['total_amount']) || empty($post['hash'])) {
    echo "OK"; exit;
}

$merchant_oid = $post['merchant_oid'];
$status       = $post['status'];
$total_amount = $post['total_amount'];

$hesaplanan = base64_encode(hash_hmac('sha256',
    $merchant_oid . $merchant_salt . $status . $total_amount, $merchant_key, true));
if ($hesaplanan !== $post['hash']) { echo "FAIL: bad hash"; exit; }

$conn = @new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) { http_response_code(500); echo "DB error"; exit; }
$conn->set_charset('utf8mb4');
$conn->query("SET time_zone = '+03:00'");

$stmt = $conn->prepare("SELECT id, kurs, durum, odeme_durum FROM kk_kayitlar WHERE merchant_oid = ? LIMIT 1");
$stmt->bind_param("s", $merchant_oid);
$stmt->execute();
$res = $stmt->get_result();
$kayit = $res->fetch_assoc();
$stmt->close();

if (!$kayit) { $conn->close(); echo "OK"; exit; }
if ($kayit['odeme_durum'] === 'basarili') { $conn->close(); echo "OK"; exit; }

$now = date('Y-m-d H:i:s');

if ($status === 'success') {
    if ($kayit['kurs'] === 'ozel') {
        $upd = $conn->prepare("UPDATE kk_kayitlar SET durum='onaylandi', odeme_durum='basarili', odeme_tarihi=?, erisim_bitis=NULL, paytr_yanit=? WHERE merchant_oid=?");
        $yanit = json_encode($post, JSON_UNESCAPED_UNICODE);
        $upd->bind_param("sss", $now, $yanit, $merchant_oid);
    } else {
        $bitis = date('Y-m-d H:i:s', strtotime("+30 days"));
        $upd = $conn->prepare("UPDATE kk_kayitlar SET durum='onaylandi', odeme_durum='basarili', odeme_tarihi=?, erisim_bitis=?, paytr_yanit=? WHERE merchant_oid=?");
        $yanit = json_encode($post, JSON_UNESCAPED_UNICODE);
        $upd->bind_param("ssss", $now, $bitis, $yanit, $merchant_oid);
    }
    $upd->execute();
    $upd->close();
} else {
    $upd = $conn->prepare("UPDATE kk_kayitlar SET odeme_durum='basarisiz', paytr_yanit=? WHERE merchant_oid=?");
    $yanit = json_encode($post, JSON_UNESCAPED_UNICODE);
    $upd->bind_param("ss", $yanit, $merchant_oid);
    $upd->execute();
    $upd->close();
}

$conn->close();
echo "OK";
exit;
