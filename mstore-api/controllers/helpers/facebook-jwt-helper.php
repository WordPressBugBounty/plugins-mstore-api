<?php

class FacebookJWTHelper
{
    // Facebook's JWKS endpoint.
    // https://developers.facebook.com/docs/facebook-login/limited-login/token/validating/
    private static $jwksEndpoint = 'https://limited.facebook.com/.well-known/oauth/openid/jwks/';
    
    // Cache for JWKS keys
    private static $jwksCache = null;
    private static $jwksCacheExpiry = 0;
    private static $jwksCacheLifetime = 2592000; // Cache for 30 days

    public static function validateJWT($token, $expectedNonce = null, $appId = null) {
        // 1. Check that the JWT consists of three parts separated by periods
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return [
                'success' => false,
                'message' => 'Invalid JWT format: Token must contain 3 parts separated by periods'
            ];
        }

        // Extract the three parts
        list($header, $payload, $signature) = $parts;

        // 2. Check that each part is a valid Base64Url-encoded string
        if (!self::isBase64UrlEncoded($header) || !self::isBase64UrlEncoded($payload) || !self::isBase64UrlEncoded($signature)) {
            return [
                'success' => false,
                'message' => 'Invalid JWT format: Each part must be Base64Url-encoded'
            ];
        }

        // 3. Decode the header and verify it is a valid JSON object
        $decodedHeader = self::base64UrlDecode($header);
        $jsonHeader = json_decode($decodedHeader);
        
        if ($jsonHeader === null && json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'message' => 'Invalid JWT header: Not a valid JSON object'
            ];
        }
        
        // 4. Decode the payload and verify it is a valid JSON object
        $decodedPayload = self::base64UrlDecode($payload);
        $jsonPayload = json_decode($decodedPayload);
        
        if ($jsonPayload === null && json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'message' => 'Invalid JWT payload: Not a valid JSON object'
            ];
        }
        
        // 5. Verify the signature
        $signatureVerification = self::verifySignature($header, $payload, $signature, $jsonHeader);
        if (!$signatureVerification['success']) {
            return $signatureVerification;
        }

        // 6. Validate standard claims
        // 6.1 Check expiration time (exp)
        if (!isset($jsonPayload->exp)) {
            return [
                'success' => false,
                'message' => 'Invalid JWT: Missing expiration claim (exp)'
            ];
        }
        
        $currentTime = time();
        if ($jsonPayload->exp < $currentTime) {
            return [
                'success' => false,
                'message' => 'Invalid JWT: Token has expired'
            ];
        }
        
        // 6.2 Check issuer (iss)
        if (!isset($jsonPayload->iss)) {
            return [
                'success' => false,
                'message' => 'Invalid JWT: Missing issuer claim (iss)'
            ];
        }
        
        // Validate issuer is from Facebook
        if ($jsonPayload->iss !== 'https://www.facebook.com') {
            return [
                'success' => false,
                'message' => 'Invalid JWT: Invalid issuer (iss)'
            ];
        }
        
        // 6.3 Check audience (aud)
        if (!isset($jsonPayload->aud)) {
            return [
                'success' => false,
                'message' => 'Invalid JWT: Missing audience claim (aud)'
            ];
        }
        
        // If appId is provided, validate that audience matches app ID
        if ($appId !== null && $jsonPayload->aud !== $appId) {
            return [
                'success' => false,
                'message' => 'Invalid JWT: Audience (aud) does not match app ID'
            ];
        }
        
        // 6.4 Check nonce if expected nonce is provided
        if ($expectedNonce !== null) {
            if (!isset($jsonPayload->nonce)) {
                return [
                    'success' => false,
                    'message' => 'Invalid JWT: Missing nonce claim'
                ];
            }
            
            if ($jsonPayload->nonce !== $expectedNonce) {
                return [
                    'success' => false,
                    'message' => 'Invalid JWT: Nonce does not match expected value'
                ];
            }
        }

        // JWT has passed structure validation, signature verification, and claims validation
        return [
            'success' => true,
            'decoded' => [
                'header' => $jsonHeader,
                'payload' => $jsonPayload,
                'signature' => $signature
            ]
        ];
    }
    
    /**
     * Verify the JWT signature using the appropriate key from Facebook's JWKS
     */
    private static function verifySignature($header, $payload, $signature, $decodedHeader) {
        // Check if the header contains the necessary information
        if (!isset($decodedHeader->alg) || !isset($decodedHeader->kid)) {
            return [
                'success' => false,
                'message' => 'Invalid JWT header: Missing algorithm (alg) or key ID (kid)'
            ];
        }
        
        // Currently only supporting RS256 algorithm
        if ($decodedHeader->alg !== 'RS256') {
            return [
                'success' => false,
                'message' => 'Unsupported JWT algorithm: Only RS256 is supported'
            ];
        }
        
        // Fetch the JWKS from Facebook
        $jwks = self::getJwks();
        if (!$jwks) {
            return [
                'success' => false,
                'message' => 'Failed to retrieve JWKS from Facebook'
            ];
        }
        
        // Find the key with the matching key ID
        $key = null;
        foreach ($jwks['keys'] as $jwk) {
            if ($jwk['kid'] === $decodedHeader->kid) {
                $key = $jwk;
                break;
            }
        }
        
        if (!$key) {
            return [
                'success' => false,
                'message' => 'Invalid JWT: Key ID (kid) not found in JWKS'
            ];
        }
        
        // Create a public key from the JWK components
        $publicKey = self::createPublicKeyFromJwk($key);
        if (!$publicKey) {
            return [
                'success' => false,
                'message' => 'Failed to create public key from JWK'
            ];
        }
        
        // Verify the signature
        $data = $header . '.' . $payload;
        $decodedSignature = self::base64UrlDecode($signature);
        
        if (openssl_verify($data, $decodedSignature, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
            return [
                'success' => false,
                'message' => 'Invalid JWT signature'
            ];
        }
        
        return [
            'success' => true
        ];
    }
    
    /**
     * Create a public key from JWK components
     */
    private static function createPublicKeyFromJwk($jwk) {
        // Make sure the key has the necessary components
        if (!isset($jwk['n']) || !isset($jwk['e'])) {
            return null;
        }
        
        // Convert the modulus and exponent from base64url to binary
        $modulus = self::base64UrlDecode($jwk['n']);
        $exponent = self::base64UrlDecode($jwk['e']);
        
        // Convert modulus and exponent to hex
        $modulusHex = bin2hex($modulus);
        $exponentHex = bin2hex($exponent);
        
        // Create an ASN.1 structure for the RSA public key
        $sequence = self::createAsn1Sequence([
            self::createAsn1Sequence([
                self::createAsn1Object(0x06, pack('H*', '2a864886f70d010101')), // OID for RSA encryption
                self::createAsn1Object(0x05, '') // NULL
            ]),
            self::createAsn1Object(0x03, chr(0) . self::createAsn1Sequence([
                self::createAsn1Object(0x02, pack('H*', '00' . $modulusHex)), // Modulus with leading 0
                self::createAsn1Object(0x02, pack('H*', $exponentHex)) // Exponent
            ]))
        ]);
        
        // Create a PEM-formatted public key
        $pem = "-----BEGIN PUBLIC KEY-----\n";
        $pem .= chunk_split(base64_encode($sequence), 64);
        $pem .= "-----END PUBLIC KEY-----\n";
        
        // Convert PEM to an OpenSSL resource
        return openssl_pkey_get_public($pem);
    }
    
    /**
     * Fetch the JWKS from Facebook and cache it
     */
    private static function getJwks() {
        // Check if we have a valid cache
        if (self::$jwksCache !== null && time() < self::$jwksCacheExpiry) {
            return self::$jwksCache;
        }
        
        // Fetch the JWKS from Facebook
        $response = @file_get_contents(self::$jwksEndpoint);
        if ($response === false) {
            return null;
        }
        
        $jwks = json_decode($response, true);
        if ($jwks === null || !isset($jwks['keys']) || !is_array($jwks['keys'])) {
            return null;
        }
        
        // Cache the JWKS
        self::$jwksCache = $jwks;
        self::$jwksCacheExpiry = time() + self::$jwksCacheLifetime;
        
        return $jwks;
    }
    
    /**
     * Helper function to create ASN.1 objects
     */
    private static function createAsn1Object($type, $data) {
        $len = strlen($data);
        
        // Length encoding
        if ($len < 128) {
            $lenBytes = chr($len);
        } else {
            $lenBytes = '';
            while ($len > 0) {
                $lenBytes = chr($len & 0xFF) . $lenBytes;
                $len >>= 8;
            }
            $lenBytes = chr(0x80 | strlen($lenBytes)) . $lenBytes;
        }
        
        return chr($type) . $lenBytes . $data;
    }
    
    /**
     * Helper function to create ASN.1 sequences
     */
    private static function createAsn1Sequence($objects) {
        return self::createAsn1Object(0x30, implode('', $objects));
    }

    /**
     * Check if a string is Base64Url encoded
     */
    private static function isBase64UrlEncoded($str) {
        // Replace URL-safe characters with standard Base64 characters
        $base64 = strtr($str, '-_', '+/');
        // Add padding if needed
        $padding = strlen($base64) % 4;
        if ($padding > 0) {
            $base64 .= str_repeat('=', 4 - $padding);
        }
        
        // Check if it's a valid base64 string
        return base64_decode($base64, true) !== false;
    }

    /**
     * Decode a Base64Url encoded string
     */
    private static function base64UrlDecode($input) {
        // Replace URL-safe characters with standard Base64 characters
        $base64 = strtr($input, '-_', '+/');
        // Add padding if needed
        $padding = strlen($base64) % 4;
        if ($padding > 0) {
            $base64 .= str_repeat('=', 4 - $padding);
        }
        
        return base64_decode($base64);
    }
    
    /**
     * Encode a string as Base64Url
     */
    private static function base64UrlEncode($input) {
        return rtrim(strtr(base64_encode($input), '+/', '-_'), '=');
    }
}
