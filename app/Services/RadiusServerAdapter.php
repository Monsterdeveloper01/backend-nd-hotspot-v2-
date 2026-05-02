<?php

namespace App\Services;

class RadiusServerAdapter
{
    const TYPE_ACCESS_REQUEST      = 1;
    const TYPE_ACCESS_ACCEPT       = 2;
    const TYPE_ACCESS_REJECT       = 3;
    const TYPE_ACCOUNTING_REQUEST  = 4;
    const TYPE_ACCOUNTING_RESPONSE = 5;

    public function decodePacket($packet, $secret)
    {
        // 3. Validasi Panjang Paket (Minimum RADIUS = 20 bytes)
        if (strlen($packet) < 20) {
            return null;
        }

        $code = ord(substr($packet, 0, 1));
        $identifier = ord(substr($packet, 1, 1));
        $length = unpack('n', substr($packet, 2, 2))[1];
        
        if ($length < 20 || $length > 4096 || $length > strlen($packet)) {
            return null;
        }

        $authenticator = substr($packet, 4, 16);
        $attrContent = substr($packet, 20, $length - 20);

        $attributes = [];
        $tempContent = $attrContent;
        
        while (strlen($tempContent) >= 2) {
            $attrType = ord(substr($tempContent, 0, 1));
            $attrLength = ord(substr($tempContent, 1, 1));
            
            if ($attrLength < 2 || $attrLength > strlen($tempContent)) {
                break;
            }

            $attrValueRaw = substr($tempContent, 2, $attrLength - 2);
            
            // Basic decoding logic inside class
            $attributes[$attrType] = $this->decodeAttributeValue($attrType, $attrValueRaw, $secret, $authenticator);
            
            $tempContent = substr($tempContent, $attrLength);
        }

        return [
            'code' => $code,
            'identifier' => $identifier,
            'authenticator' => $authenticator,
            'attributes' => $attributes
        ];
    }

    private function decodeAttributeValue($type, $value, $secret, $authenticator)
    {
        switch ($type) {
            case 1: // User-Name
            case 11: // Filter-Id
            case 18: // Reply-Message
            case 32: // NAS-Identifier
            case 31: // Calling-Station-Id (MAC)
                return $value;
            
            case 2: // User-Password (PAP)
                return $this->decryptPapPassword($value, $secret, $authenticator);
                
            case 4: // NAS-IP-Address
            case 8: // Framed-IP-Address
                if (strlen($value) < 4) return "0.0.0.0";
                return long2ip(unpack('N', $value)[1]);
                
            case 5: // NAS-Port
            case 6: // Service-Type
            case 27: // Session-Timeout
            case 40: // Acct-Status-Type
            case 41: // Acct-Delay-Time
            case 42: // Acct-Input-Octets
            case 43: // Acct-Output-Octets
            case 45: // Acct-Authentic
            case 46: // Acct-Session-Time
            case 47: // Acct-Input-Packets
            case 48: // Acct-Output-Packets
            case 49: // Acct-Terminate-Cause
            case 52: // Acct-Input-Gigawords
            case 53: // Acct-Output-Gigawords
                // 5. decodeAttribute() untuk Integer — Guard Length
                if (strlen($value) < 4) return 0;
                $val = unpack('N', $value);
                return $val !== false ? $val[1] : 0;

            default:
                return $value;
        }
    }

    // 1. decodePassword() — Standalone with Injected Dependencies
    public function decryptPapPassword($encrypted, $secret, $requestAuthenticator)
    {
        if (!$encrypted) return '';
        $password = '';
        $previous = $requestAuthenticator;
        
        for ($i = 0; $i < strlen($encrypted); $i += 16) {
            $temp = md5($secret . $previous, true);
            $block = substr($encrypted, $i, 16);
            $plain = $block ^ $temp;
            $password .= $plain;
            $previous = $block;
        }
        
        return rtrim($password, "\x00");
    }

    public function encodePacket($code, $identifier, $secret, $requestAuthenticator, $attributes)
    {
        $attrBinary = '';
        foreach ($attributes as $type => $value) {
            if ($type === 'Vendor-Specific') {
                foreach ($value as $vId => $vAttrs) {
                    foreach ($vAttrs as $vType => $vVal) {
                        $vData = pack('N', $vId) . pack('CC', $vType, strlen($vVal) + 2) . $vVal;
                        $attrBinary .= pack('CC', 26, strlen($vData) + 2) . $vData;
                    }
                }
            } else {
                $isNumeric = in_array($type, [5, 6, 27, 28, 40]);
                if ($isNumeric) {
                    $val = (int)$value;
                    $attrBinary .= pack('CC', $type, 6) . pack('N', $val);
                } else {
                    $attrBinary .= pack('CC', $type, strlen($value) + 2) . $value;
                }
            }
        }

        // Add Message-Authenticator (80) if it's an Access response or request
        $hasMsgAuth = in_array($code, [self::TYPE_ACCESS_REQUEST, self::TYPE_ACCESS_ACCEPT, self::TYPE_ACCESS_REJECT, 11]);
        if ($hasMsgAuth) {
            $attrBinary .= pack('CC', 80, 18) . str_repeat("\x00", 16);
        }

        $length = 20 + strlen($attrBinary);
        $header = pack('CCn', $code, $identifier, $length);

        if ($hasMsgAuth) {
            // Compute HMAC-MD5 over (Header + RequestAuth + AttributesWithZeroMsgAuth)
            $hmacData = $header . $requestAuthenticator . $attrBinary;
            $msgAuthHash = hash_hmac('md5', $hmacData, $secret, true);
            // Replace the last 16 bytes (which are the zeroed Message-Authenticator) with the computed hash
            $attrBinary = substr($attrBinary, 0, -16) . $msgAuthHash;
        }

        if ($code == self::TYPE_ACCESS_ACCEPT || $code == self::TYPE_ACCESS_REJECT || $code == self::TYPE_ACCOUNTING_RESPONSE) {
            $auth = md5($header . $requestAuthenticator . $attrBinary . $secret, true);
        } elseif ($code == self::TYPE_ACCOUNTING_REQUEST) {
            $auth = md5($header . str_repeat("\x00", 16) . $attrBinary . $secret, true);
        } else {
            $auth = $requestAuthenticator;
        }

        return $header . $auth . $attrBinary;
    }

    public function encryptPapPassword($password, $secret, $requestAuthenticator)
    {
        if ($password === '') return '';
        
        $paddedPassword = $password . str_repeat("\x00", 16 - (strlen($password) % 16));
        if (strlen($password) % 16 === 0) {
            $paddedPassword = $password;
        }

        $encrypted = '';
        $previous = $requestAuthenticator;
        
        for ($i = 0; $i < strlen($paddedPassword); $i += 16) {
            $temp = md5($secret . $previous, true);
            $block = substr($paddedPassword, $i, 16);
            $cipher = $block ^ $temp;
            $encrypted .= $cipher;
            $previous = $cipher;
        }
        
        return $encrypted;
    }
}
