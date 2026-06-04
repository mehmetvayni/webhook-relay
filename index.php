<?php
error_reporting(0);
ini_set('display_errors', 0);
header("Content-Type: text/plain");

$merchant_key  = "5SnY5gCkE1G9tRZt";
$merchant_salt = "8DbPU9eLdaf8z4cq";

// POST verisini al
$post = $_POST;

// Eğer POST boşsa raw body'den dene
if (empty($post)) {
    $raw = file_get_contents("php://input");
    if ($raw) {
        parse_str($raw, $post);
    }
}

if (empty($post)) {
    echo "OK";
    exit;
}

// paytr_token yoksa OK dön (PayTR tekrar dener)
if (empty($post['paytr_token']) || empty($post['merchant_oid']) || empty($post['status']) || empty($post['total_amount'])) {
    echo "OK";
    exit;
}

// Token doğrula
$hash = base64_encode(
    hash_hmac('sha256',
        $post['merchant_oid'] . $merchant_salt . $post['status'] . $post['total_amount'],
        $merchant_key,
        true
    )
);

if ($hash !== $post['paytr_token']) {
    echo "FAIL";
    exit;
}

if ($post['status'] === 'success') {
    $oid = $post['merchant_oid'];

    // merchant_oid: KK{user_id}{si|go|pl}{timestamp}
    if (!preg_match('/^KK(\d+)(si|go|pl)\d+$/', $oid, $m)) {
        echo "OK"; exit;
    }

    $user_id = intval($m[1]);
    $prefix  = $m[2];
    $plan    = ["si"=>"silver","go"=>"gold","pl"=>"plus"][$prefix] ?? "";

    if (!$user_id || !$plan) {
        echo "OK"; exit;
    }

    $db_host = getenv("DB_HOST") ?: "sql203.infinityfree.com";
    $db_user = getenv("DB_USER") ?: "if0_42077234";
    $db_pass = getenv("DB_PASS") ?: "";
    $db_name = getenv("DB_NAME") ?: "";

    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

    if ($conn->connect_error) {
        echo "OK"; exit;
    }

    $oid_esc = $conn->real_escape_string($oid);
    $check = $conn->query("SELECT id FROM odeme_log WHERE merchant_oid='$oid_esc' LIMIT 1");
    if ($check->num_rows > 0) {
        $conn->close();
        echo "OK"; exit;
    }

    if ($plan === "silver") {
        $bitis = "'" . date("Y-m-d H:i:s", strtotime("+45 days")) . "'";
    } elseif ($plan === "gold") {
        $bitis = "'" . date("Y-m-d H:i:s", strtotime("+180 days")) . "'";
    } else {
        $bitis = "NULL";
    }

    $tutar = intval($post['total_amount']);
    $conn->query("UPDATE users SET plan='$plan', plan_bitis=$bitis WHERE id=$user_id");
    $conn->query("INSERT IGNORE INTO odeme_log (user_id, plan, merchant_oid, tutar) VALUES ($user_id,'$plan','$oid_esc',$tutar)");
    $conn->close();
}

echo "OK";
