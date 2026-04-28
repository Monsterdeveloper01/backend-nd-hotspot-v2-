<?php

// Unified RADIUS Tester
// Jalankan dari folder backend: php test_radius.php

$server = '127.0.0.1';
$port = 1812;
$secret = 'testing123';
$username = 'TESTING';
$password = 'TESTING';

echo "Memulai Test RADIUS...\n";

// 1. Buat Packet
$identifier = 1; // Fixed ID untuk test
$authenticator = str_repeat('A', 16); // Fixed Authenticator (AAAAAAAAAAAAAAAA)

$attrs = "";
$attrs .= pack('CC', 1, strlen($username) + 2) . $username;

// Enkripsi PAP
$paddedPass = str_pad($password, 16, "\0");
$temp = md5($secret . $authenticator, true);
$encryptedPass = $paddedPass ^ $temp;
$attrs .= pack('CC', 2, strlen($encryptedPass) + 2) . $encryptedPass;

$length = 20 + strlen($attrs);
$packet = pack('CCn', 1, $identifier, $length) . $authenticator . $attrs;

echo "HEX SECRET: " . bin2hex($secret) . "\n";
echo "HEX AUTH:   " . bin2hex($authenticator) . "\n";
echo "HEX PASS:   " . bin2hex($encryptedPass) . "\n";

// 2. Kirim
$sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
socket_sendto($sock, $packet, strlen($packet), 0, $server, $port);

// 3. Terima
$response = "";
$from = "";
$fromPort = 0;
socket_set_option($sock, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 2, 'usec' => 0]);
if (@socket_recvfrom($sock, $response, 1024, 0, $from, $fromPort)) {
    $code = ord(substr($response, 0, 1));
    if ($code == 2) echo "\n[HASIL] LOGIN BERHASIL (Access-Accept)!\n";
    elseif ($code == 3) echo "\n[HASIL] LOGIN DITOLAK (Access-Reject).\n";
    else echo "\n[HASIL] Kode Unknown: $code\n";
} else {
    echo "\n[HASIL] Timeout - Server tidak merespon.\n";
}
socket_close($sock);
