<?php
$secret = "testing123";
$auth = hex2bin("20202020202031373737333833303536");
$passRaw = hex2bin("7a238f573401cc96f6a4e795a1273747");

$temp = md5($secret . $auth, true);
$plain = $passRaw ^ $temp;

echo "Decrypted: [" . $plain . "]\n";
echo "Hex: " . bin2hex($plain) . "\n";
echo "Len: " . strlen($plain) . "\n";
