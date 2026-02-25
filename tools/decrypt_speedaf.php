<?php
// Small helper to decrypt Speedaf sample data
$data = 'YGhaWcpzzQvJAadyA3mGk9sWM4EfmsgID0FB6K1UN4rgpH8y50FkRpwQCHlST6WN';
$key = 'uYMGr8eU';
$iv = hex2bin('1234567890ABCDEF');
$raw = openssl_decrypt(base64_decode($data), 'DES-CBC', $key, OPENSSL_RAW_DATA, $iv);
if ($raw === false) {
    echo "DECRYPT_FAILED\n";
    exit(1);
}
echo "RAW:\n" . $raw . "\n\n";
echo "HEX:\n" . bin2hex($raw) . "\n\n";
$len = strlen($raw);
$last = ord($raw[$len-1]);
echo "LAST_PAD_BYTE: $last\n";
echo "TRAILING_BYTES_HEX: " . bin2hex(substr($raw, -8)) . "\n";
if ($last > 0 && $last <= 8) {
    $unpadded = substr($raw, 0, $len - $last);
    echo "UNPADDED:\n" . $unpadded . "\n\n";
    $json = json_decode($unpadded, true);
    echo "JSON_ERR: " . json_last_error() . " => " . json_last_error_msg() . "\n";
    var_dump($json);
} else {
    echo "NO_VALID_PKCS5_PAD\n";
    $json = json_decode($raw, true);
    echo "JSON_ERR_RAW: " . json_last_error() . " => " . json_last_error_msg() . "\n";
    var_dump($json);
}
?>