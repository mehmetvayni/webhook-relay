<?php
// ============================================================
// PayTR Webhook Relay (Render.com) - TUNEL MIMARISI
// InfinityFree hem gelen webhook POST'unu engelliyor, hem de
// disaridan MySQL baglantisini engelliyor. Cozum:
//   PayTR -> relay (hash dogrula) -> InfinityFree paytr_callback.php
// Relay DB'ye DOKUNMAZ; sadece bildirimi siteye iletir.
// Site kendi sunucusunda oldugu icin DB'ye sorunsuz baglanir.
// ============================================================
error_reporting(0);
ini_set('display_errors', 0);
header("Content-Type: text/plain");
date_default_timezone_set('Europe/Istanbul');

$merchant_key  = getenv("PAYTR_MERCHANT_KEY")  ?: "5SnY5gCkE1G9tRZt";
$merchant_salt = getenv("PAYTR_MERCHANT_SALT") ?: "8DbPU9eLdaf8z4cq";

// Sitedeki callback dosyasi (relay buraya iletecek)
$site_callback = getenv("SITE_CALLBACK_URL") ?: "https://korecekolay.com/paytr_callback.php";

// Relay'in siteye gonderecegi gizli anahtar (sahte istekleri engeller)
$relay_secret  = getenv("RELAY_SECRET") ?: "kk_relay_2026_gizli_anahtar";

// --- POST verisini al ---
$post = $_POST;
if (empty($post)) {
    $raw = file_get_contents("php://input");
    if ($raw) parse_str($raw, $post);
}

// POST yoksa (saglik kontrolu / tarayicidan acma) sadece OK don
if (empty($post)) { echo "OK"; exit; }

// Gerekli alanlar
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
    // Sahte istek - PayTR'ye OK donme
    echo "FAIL: bad hash";
    exit;
}

// --- Hash dogru: bildirimi siteye ilet ---
// Siteye gonderirken relay_secret ekliyoruz (site bunu kontrol edecek)
$ilet = $post;
$ilet['relay_secret'] = $relay_secret;

$ch = curl_init($site_callback);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($ilet));
curl_setopt($ch, CURLOPT_TIMEOUT, 25);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
// InfinityFree bot/otomasyon engeline takilmamak icin tarayici gibi davran
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; KKRelay/1.0)');
$site_cevap = curl_exec($ch);
$site_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Site "OK" dondurduyse PayTR'ye de OK don
if (strpos((string)$site_cevap, 'OK') !== false) {
    echo "OK";
    exit;
}

// Site cevap vermediyse PayTR'ye OK donme ki tekrar denesin
// (Render free uykudaysa veya site gecici hata verdiyse PayTR tekrar gonderir)
http_response_code(500);
echo "site error: code=" . $site_code;
exit;
