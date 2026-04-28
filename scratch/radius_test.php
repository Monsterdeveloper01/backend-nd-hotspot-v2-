<?php

// ND-Hotspot RADIUS Tester (Simulates a Router/NAS)
// Run this from the project root: php backend/scratch/radius_test.php

$server = '127.0.0.1';
$port = 1812;
$secret = 'testing123';
$username = 'TESTING';
$password = 'TESTING';

echo "--- ND-HOTSPOT RADIUS TESTER ---\n";
echo "Target: $server:$port\n";
echo "User  : $username\n";
echo "Pass  : $password\n";
echo "Secret: $secret\n\n";

// 1. Create Access-Request Packet
$identifier = rand(0, 255);
$authenticator = openssl_random_pseudo_bytes(16);

// Attributes
$attrs = "";
// User-Name (Type 1)
$attrs .= pack('CC', 1, strlen($username) + 2) . $username;

// User-Password (Type 2) - PAP Encrypted (RFC 2865)
$paddedPass = str_pad($password, ceil(strlen($password)/16)*16, "\0");
$encryptedPass = "";
$prevChunk = $authenticator;

for ($i = 0; $i < strlen($paddedPass); $i += 16) {
    $temp = md5($secret . $prevChunk, true);
    $plainChunk = substr($paddedPass, $i, 16);
    $cipherChunk = $plainChunk ^ $temp;
    $encryptedPass .= $cipherChunk;
    $prevChunk = $cipherChunk;
}
$attrs .= pack('CC', 2, strlen($encryptedPass) + 2) . $encryptedPass;

// NAS-IP-Address (Type 4)
$attrs .= pack('CC', 4, 6) . pack('N', ip2long('127.0.0.1'));

$length = 20 + strlen($attrs);
$packet = pack('CCn', 1, $identifier, $length) . $authenticator . $attrs;

echo "SECRET HEX: " . bin2hex($secret) . "\n";
echo "AUTH HEX:   " . bin2hex($authenticator) . "\n";
echo "PASS RAW:   " . bin2hex($encryptedPass) . "\n";

// 2. Send Packet
$sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
socket_sendto($sock, $packet, strlen($packet), 0, $server, $port);

echo "Request Sent. Waiting for response...\n";

// 3. Receive Response
$response = "";
$from = "";
$fromPort = 0;
socket_set_option($sock, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 3, 'usec' => 0]);
if (@socket_recvfrom($sock, $response, 1024, 0, $from, $fromPort)) {
    $resCode = ord(substr($response, 0, 1));
    $resId = ord(substr($response, 1, 1));
    $resLen = unpack('n', substr($response, 2, 2))[1];
    
    if ($resCode == 2) echo "\n[SUCCESS] Access-Accept Received!\n";
    elseif ($resCode == 3) echo "\n[REJECT] Access-Reject Received.\n";
    else echo "\n[UNKNOWN] Code: $resCode\n";
    
    echo "Response ID: $resId\n";
    echo "Length: $resLen\n";
} else {
    echo "\n[TIMEOUT] No response from RADIUS server.\n";
}

socket_close($sock);
