<?php
// ============================================================
// PayTR Webhook Relay - GET TUNEL
// Render DB hostname'ini cozemiyor (DNS kapali) ve InfinityFree
// gelen POST'u kesiyor. Cozum: relay, dogrulanmis bildirimi
// siteye GET ile iletir (InfinityFree GET'i engellemiyor).
//   PayTR -> relay (hash dogrula) -> site callback (GET) -> DB
// ============================================================
error_reporting(0);
ini_set('display_errors', 0);
header("Content-Type: text/plain");
date_default_timezone_set('Europe/Istanbul');

$merchant_key  = getenv("PAYTR_MERCHANT_KEY")  ?: "5SnY5gCkE1G9tRZt";
$merchant_salt = getenv("PAYTR_MERCHANT_SALT") ?: "8DbPU9eLdaf8z4cq";
$site_callback = getenv("SITE_CALLBACK_URL") ?: "https://korecekolay.com/paytr_callback.php";
$relay_secret  = getenv("RELAY_SECRET") ?: "kk_relay_2026_gizli_anahtar";

// --- POST verisini al (PayTR POST ile gonderir) ---
$post = $_POST;
if (empty($post)) { $raw = file_get_contents("php://input"); if ($raw) parse_str($raw, $post); }
if (empty($post)) { echo "OK"; exit; }

if (empty($post['merchant_oid']) || !isset($post['status']) || !isset($post['total_amount']) || empty($post['hash'])) {
    echo "OK"; exit;
}

$merchant_oid = $post['merchant_oid'];
$status       = $post['status'];
$total_amount = $post['total_amount'];

// --- Hash dogrulama ---
$hesaplanan = base64_encode(hash_hmac('sha256',
    $merchant_oid . $merchant_salt . $status . $total_amount, $merchant_key, true));
if ($hesaplanan !== $post['hash']) { echo "FAIL: bad hash"; exit; }

// --- Dogrulandi: siteye GET ile ilet ---
$qs = http_build_query([
    'relay_secret' => $relay_secret,
    'merchant_oid' => $merchant_oid,
    'status'       => $status,
    'total_amount' => $total_amount,
    'hash'         => $post['hash'],
]);
$url = $site_callback . '?' . $qs;

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPGET, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 25);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36');
$site_cevap = curl_exec($ch);
$site_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (strpos((string)$site_cevap, 'OK') !== false) { echo "OK"; exit; }

http_response_code(500);
echo "site error: code=" . $site_code . " resp=" . substr((string)$site_cevap, 0, 200);
exit;
