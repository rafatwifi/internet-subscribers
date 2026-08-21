<?php

/**
 * AES encryption compatible with SAS Radius 4 API (OpenSSL EVP_BytesToKey).
 * Source: hasanenalbana/sasconnector-php
 */
if (!class_exists('AESController')) {
class AESController
{
    public static function encrypt($data, $passphrase, $salt = null)
    {
        $salt = $salt ?: openssl_random_pseudo_bytes(8);
        list($key, $iv) = self::evpkdf($passphrase, $salt);
        $ct = openssl_encrypt($data, 'aes-256-cbc', $key, true, $iv);
        return self::encode($ct, $salt);
    }

    public static function decrypt($base64, $passphrase)
    {
        list($ct, $salt) = self::decode($base64);
        list($key, $iv) = self::evpkdf($passphrase, $salt);
        return openssl_decrypt($ct, 'aes-256-cbc', $key, true, $iv);
    }

    public static function evpkdf($passphrase, $salt)
    {
        $salted = '';
        $dx = '';
        while (strlen($salted) < 48) {
            $dx = md5($dx . $passphrase . $salt, true);
            $salted .= $dx;
        }
        return array(substr($salted, 0, 32), substr($salted, 32, 16));
    }

    public static function decode($base64)
    {
        $data = base64_decode($base64);
        if (substr($data, 0, 8) !== 'Salted__') {
            throw new InvalidArgumentException('Invalid SAS encrypted payload');
        }
        $salt = substr($data, 8, 8);
        $ct = substr($data, 16);
        return array($ct, $salt);
    }

    public static function encode($ct, $salt)
    {
        return base64_encode('Salted__' . $salt . $ct);
    }
}
}
