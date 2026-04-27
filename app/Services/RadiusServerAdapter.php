<?php

namespace App\Services;

use Dapphp\Radius\Radius;

class RadiusServerAdapter extends Radius
{
    public function decodePacket($packet, $secret)
    {
        $this->setSecret($secret);
        
        $code = ord(substr($packet, 0, 1));
        $identifier = ord(substr($packet, 1, 1));
        $length = unpack('n', substr($packet, 2, 2))[1];
        $authenticator = substr($packet, 4, 16);
        $attrContent = substr($packet, 20, $length - 20);

        $attributes = [];
        $tempContent = $attrContent;
        
        while (strlen($tempContent) > 2) {
            $attrType = ord(substr($tempContent, 0, 1));
            $attrLength = ord(substr($tempContent, 1, 1));
            $attrValueRaw = substr($tempContent, 2, $attrLength - 2);
            
            // Accessing protected method via reflection or just implementing the basic ones
            $attributes[$attrType] = $this->decodeAttribute($attrType, $attrValueRaw);
            
            $tempContent = substr($tempContent, $attrLength);
        }

        return [
            'code' => $code,
            'identifier' => $identifier,
            'authenticator' => $authenticator,
            'attributes' => $attributes
        ];
    }

    private function decodeAttribute($type, $value)
    {
        // Basic decoding for common RADIUS attributes
        switch ($type) {
            case 1: // User-Name
            case 11: // Filter-Id
            case 18: // Reply-Message
            case 32: // NAS-Identifier
                return $value;
            
            case 2: // User-Password (PAP)
                return $this->decodePassword($value);
                
            case 4: // NAS-IP-Address
            case 8: // Framed-IP-Address
                return long2ip(unpack('N', $value)[1]);
                
            case 5: // NAS-Port
            case 6: // Service-Type
            case 27: // Session-Timeout
            case 40: // Acct-Status-Type
                $val = unpack('N', $value);
                return $val ? $val[1] : 0;
            
            case 31: // Calling-Station-Id (MAC)
                return $value;

            default:
                return $value;
        }
    }

    private function decodePassword($encrypted)
    {
        $password = '';
        $secret = $this->getSecret();
        $authenticator = $this->getRequestAuthenticator(); // For server, this is the Request Authenticator from packet

        $previous = $authenticator;
        for ($i = 0; $i < strlen($encrypted); $i += 16) {
            $temp = md5($secret . $previous, true);
            $block = substr($encrypted, $i, 16);
            $plain = $block ^ $temp;
            $password .= $plain;
            $previous = $block;
        }

        return rtrim($password, "\x00");
    }

    // Overriding this to allow setting from incoming packet
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
                // Determine if it should be an integer or string based on type or value
                $isNumeric = in_array($type, [5, 6, 27, 28, 40]);
                
                if ($isNumeric) {
                    $val = (int)$value;
                    $attrBinary .= pack('CC', $type, 6) . pack('N', $val);
                } else {
                    $attrBinary .= pack('CC', $type, strlen($value) + 2) . $value;
                }
            }
        }

        $length = 20 + strlen($attrBinary);
        
        if ($code == self::TYPE_ACCESS_ACCEPT || $code == self::TYPE_ACCESS_REJECT || $code == self::TYPE_ACCOUNTING_RESPONSE) {
            // Response Authenticator = MD5(Code + ID + Length + RequestAuth + Attributes + Secret)
            $auth = md5(pack('CCn', $code, $identifier, $length) . $requestAuthenticator . $attrBinary . $secret, true);
        } else {
            $auth = str_repeat("\x00", 16);
        }

        return pack('CCn', $code, $identifier, $length) . $auth . $attrBinary;
    }
}
