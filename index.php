<?php
// ============================================================
// PayTR Webhook Relay (Render.com)
// InfinityFree gelen POST'lari engelledigi icin PayTR bildirimi
// once buraya gelir, burasi InfinityFree DB'sine baglanip
// kk_kayitlar'i gunceller (durum='onaylandi', erisim acilir).
// ============================================================
error_reporting(0);
ini_set('display_errors', 0);
header("Content-Type: text/plain");

date_default_timezone_set('Europe/Istanbul');

// --- PayTR magaza bilgileri ---
$merchant_key  = getenv("PAYTR_MERCHANT_KEY")  ?: "5SnY5gCkE1G9tRZt";
$merchant_salt = getenv("PAYTR_MERCHANT_SALT") ?: "8DbPU9eLdaf8z4cq";

// --- InfinityFree DB bilgileri (Render ortam degiskenlerinden) ---
$db_host = getenv("DB_HOST") ?: "sql203.infinityfree.com";
$db_user = getenv("DB_USER") ?: "if0_42077234";
$db_pass = getenv("DB_PASS") ?: "";
$db_name = getenv("DB_NAME") ?: "if0_42077234_korecekolay";

// --- POST verisini al ---
$post = $_POST;
if (empty($post)) {
    $raw = file_get_contents("php://input");
    if ($raw) parse_str($raw, $post);
}
if (empty($post)) { echo "OK"; exit; }

// Gerekli alanlar var mi?
if (empty($post['merchant_oid']) || !isset($post['status']) || !isset($post['total_amount']) || empty($post['hash'])) {
    echo "OK"; exit;
}

$merchant_oid = $post['merchant_oid'];
$status       = $post['status'];
$total_amount = $post['total_amount'];

// --- Hash dogrulama (istegin gercekten PayTR'den geldigini kanitlar) ---
$hesaplanan = base64_encode(hash_hmac('sha256',
    $merchant_oid . $merchant_salt . $status . $total_amount, $merchant_key, true));

if ($hesaplanan !== $post['hash']) {
    echo "FAIL: bad hash";
    exit;
}

// --- DB'ye baglan ---
$conn = @new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    // Baglanamadiysa PayTR'ye OK DONME ki tekrar denesin
    http_response_code(500);
    echo "DB error";
    exit;
}
$conn->set_charset('utf8mb4');
$conn->query("SET time_zone = '+03:00'");

// --- Kaydi bul ---
$stmt = $conn->prepare("SELECT id, kurs, durum, odeme_durum FROM kk_kayitlar WHERE merchant_oid = ? LIMIT 1");
$stmt->bind_param("s", $merchant_oid);
$stmt->execute();
$res = $stmt->get_result();
$kayit = $res->fetch_assoc();
$stmt->close();

if (!$kayit) {
    // Kayit yoksa OK don (PayTR tekrar gondermesin)
    $conn->close();
    echo "OK";
    exit;
}

// Zaten islenmisse tekrar isleme (PayTR ayni bildirimi cok kez gonderebilir)
if ($kayit['odeme_durum'] === 'basarili') {
    $conn->close();
    echo "OK";
    exit;
}

$now = date('Y-m-d H:i:s');
$paytr_yanit = $conn->real_escape_string(json_encode($post, JSON_UNESCAPED_UNICODE));

if ($status === 'success') {
    // --- ODEME BASARILI: erisimi ac ---
    // Aylik paketler 30 gun, ozel ders icin erisim_bitis NULL
    if ($kayit['kurs'] === 'ozel') {
        $bitis_sql = "NULL";
    } else {
        $bitis = date('Y-m-d H:i:s', strtotime("+30 days"));
        $bitis_sql = "'" . $conn->real_escape_string($bitis) . "'";
    }

    $upd = $conn->prepare(
        "UPDATE kk_kayitlar
         SET durum = 'onaylandi',
             odeme_durum = 'basarili',
             odeme_tarihi = ?,
             erisim_bitis = " . ($kayit['kurs'] === 'ozel' ? "NULL" : "?") . ",
             paytr_yanit = ?
         WHERE merchant_oid = ?"
    );

    if ($kayit['kurs'] === 'ozel') {
        $yanit = json_encode($post, JSON_UNESCAPED_UNICODE);
        $upd->bind_param("sss", $now, $yanit, $merchant_oid);
    } else {
        $yanit = json_encode($post, JSON_UNESCAPED_UNICODE);
        $upd->bind_param("ssss", $now, $bitis, $yanit, $merchant_oid);
    }
    $upd->execute();
    $upd->close();

} else {
    // --- ODEME BASARISIZ ---
    $upd = $conn->prepare(
        "UPDATE kk_kayitlar
         SET odeme_durum = 'basarisiz', paytr_yanit = ?
         WHERE merchant_oid = ?"
    );
    $yanit = json_encode($post, JSON_UNESCAPED_UNICODE);
    $upd->bind_param("ss", $yanit, $merchant_oid);
    $upd->execute();
    $upd->close();
}

$conn->close();

// PayTR'ye MUTLAKA OK don
echo "OK";
exit;
