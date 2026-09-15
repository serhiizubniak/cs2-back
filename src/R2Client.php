<?php

/**
 * Just enough of the S3 API to manage the highlight clips bucket on Cloudflare
 * R2: list every object and delete in batches. Uploads are the recorder's job,
 * so there is no PUT here.
 *
 * Requests are signed with AWS Signature Version 4 by hand rather than through
 * the AWS SDK: the backend has no Composer dependencies and deploys straight
 * from the repository, and two calls do not justify changing that. The signer
 * is a pure static function so it can be checked against AWS's published
 * examples (tests/highlights_test.php).
 *
 * Works against any S3-compatible endpoint — locally, a MinIO container.
 */
class R2Client
{
    /** R2 ignores the region, but SigV4 needs one; "auto" is what Cloudflare documents. */
    private const REGION  = 'auto';
    private const SERVICE = 's3';

    /** DeleteObjects accepts at most this many keys per request. */
    private const DELETE_BATCH = 1000;

    private string $endpoint;
    private string $bucket;
    private string $accessKeyId;
    private string $secretAccessKey;

    public function __construct(string $endpoint, string $bucket, string $accessKeyId, string $secretAccessKey)
    {
        $this->endpoint        = rtrim($endpoint, '/');
        $this->bucket          = $bucket;
        $this->accessKeyId     = $accessKeyId;
        $this->secretAccessKey = $secretAccessKey;
    }

    /**
     * Client from the R2_* environment variables, or null when a required
     * setting is missing. R2_ENDPOINT may be empty: it is then derived from
     * R2_ACCOUNT_ID, exactly as the recorder does.
     */
    public static function fromEnv(): ?self
    {
        $endpoint = getenv('R2_ENDPOINT') ?: '';
        $account  = getenv('R2_ACCOUNT_ID') ?: '';
        if ($endpoint === '' && $account !== '') {
            $endpoint = "https://$account.r2.cloudflarestorage.com";
        }
        $bucket = getenv('R2_BUCKET') ?: '';
        $keyId  = getenv('R2_ACCESS_KEY_ID') ?: '';
        $secret = getenv('R2_SECRET_ACCESS_KEY') ?: '';

        if ($endpoint === '' || $bucket === '' || $keyId === '' || $secret === '') {
            return null;
        }
        return new self($endpoint, $bucket, $keyId, $secret);
    }

    /**
     * Playable address of an object: R2_PUBLIC_BASE_URL plus the key, each
     * segment percent-encoded. Null when no public origin is configured.
     */
    public static function publicUrl(?string $baseUrl, string $key): ?string
    {
        $baseUrl = rtrim((string) $baseUrl, '/');
        if ($baseUrl === '') {
            return null;
        }
        return $baseUrl . '/' . implode('/', array_map('rawurlencode', explode('/', $key)));
    }

    /**
     * Every object in the bucket, following ListObjectsV2 pagination.
     *
     * @return array{key:string,size:int,lastModified:string}[]
     */
    public function listObjects(): array
    {
        $objects = [];
        $token   = null;

        do {
            $query = ['list-type' => '2', 'max-keys' => '1000'];
            if ($token !== null) {
                $query['continuation-token'] = $token;
            }
            $xml = self::parseXml($this->request('GET', $query));

            foreach ($xml->Contents as $object) {
                $objects[] = [
                    'key'          => (string) $object->Key,
                    'size'         => (int) (string) $object->Size,
                    'lastModified' => (string) $object->LastModified,
                ];
            }

            $token = (string) $xml->IsTruncated === 'true' ? (string) $xml->NextContinuationToken : null;
        } while ($token !== null && $token !== '');

        return $objects;
    }

    /**
     * Delete keys in batches. Returns the keys the storage refused, mapped to
     * its error message — an empty array means every key is gone (deleting a
     * key that does not exist counts as success in S3). A failed HTTP call
     * throws RuntimeException.
     *
     * @return array<string,string>
     */
    public function deleteObjects(array $keys): array
    {
        $failed = [];

        foreach (array_chunk(array_values($keys), self::DELETE_BATCH) as $chunk) {
            $body = '<?xml version="1.0" encoding="UTF-8"?><Delete><Quiet>true</Quiet>';
            foreach ($chunk as $key) {
                $body .= '<Object><Key>' . htmlspecialchars((string) $key, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</Key></Object>';
            }
            $body .= '</Delete>';

            // S3 requires an integrity header on DeleteObjects.
            $xml = self::parseXml($this->request('POST', ['delete' => ''], $body, [
                'content-md5'  => base64_encode(md5($body, true)),
                'content-type' => 'application/xml',
            ]));

            // Quiet mode reports only the failures.
            foreach ($xml->Error as $error) {
                $failed[(string) $error->Key] = trim((string) $error->Code . ': ' . (string) $error->Message, ': ');
            }
        }

        return $failed;
    }

    /**
     * Authorization header value for one request, per AWS SigV4 (payload
     * signed as a single chunk). $headers must hold every header to sign,
     * including host, x-amz-date and x-amz-content-sha256; names are
     * case-insensitive. $path must already be URI-encoded — S3 signs the path
     * exactly as sent, without encoding it a second time.
     */
    public static function authorization(
        string $method,
        string $path,
        array $query,
        array $headers,
        string $payloadHash,
        string $accessKeyId,
        string $secretAccessKey,
        string $region,
        string $service
    ): string {
        $canonical = [];
        foreach ($headers as $name => $value) {
            $canonical[strtolower(trim((string) $name))] = preg_replace('/\s+/', ' ', trim((string) $value));
        }
        ksort($canonical, SORT_STRING);

        $amzDate = $canonical['x-amz-date'] ?? '';
        $date    = substr($amzDate, 0, 8);

        $canonicalHeaders = '';
        foreach ($canonical as $name => $value) {
            $canonicalHeaders .= "$name:$value\n";
        }
        $signedHeaders = implode(';', array_keys($canonical));

        $canonicalRequest = implode("\n", [
            $method,
            $path,
            self::canonicalQuery($query),
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        $scope        = "$date/$region/$service/aws4_request";
        $stringToSign = "AWS4-HMAC-SHA256\n$amzDate\n$scope\n" . hash('sha256', $canonicalRequest);

        $key = hash_hmac('sha256', $date, 'AWS4' . $secretAccessKey, true);
        $key = hash_hmac('sha256', $region, $key, true);
        $key = hash_hmac('sha256', $service, $key, true);
        $key = hash_hmac('sha256', 'aws4_request', $key, true);

        $signature = hash_hmac('sha256', $stringToSign, $key);

        return "AWS4-HMAC-SHA256 Credential=$accessKeyId/$scope,SignedHeaders=$signedHeaders,Signature=$signature";
    }

    /** Query string with keys sorted and keys/values RFC 3986-encoded, as SigV4 signs it. */
    private static function canonicalQuery(array $query): string
    {
        $pairs = [];
        foreach ($query as $name => $value) {
            $pairs[rawurlencode((string) $name)] = rawurlencode((string) $value);
        }
        ksort($pairs, SORT_STRING);

        $parts = [];
        foreach ($pairs as $name => $value) {
            $parts[] = "$name=$value";
        }
        return implode('&', $parts);
    }

    /** Signed request against the bucket itself (path-style). Returns the body. */
    private function request(string $method, array $query, string $body = '', array $extraHeaders = []): string
    {
        $url  = parse_url($this->endpoint);
        $host = ($url['host'] ?? '') . (isset($url['port']) ? ':' . $url['port'] : '');
        $path = rtrim($url['path'] ?? '', '/') . '/' . rawurlencode($this->bucket);

        $payloadHash = hash('sha256', $body);
        $headers     = array_merge($extraHeaders, [
            'host'                 => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date'           => gmdate('Ymd\THis\Z'),
        ]);
        $headers['authorization'] = self::authorization(
            $method, $path, $query, $headers, $payloadHash,
            $this->accessKeyId, $this->secretAccessKey, self::REGION, self::SERVICE
        );

        $queryString = self::canonicalQuery($query);
        $fullUrl     = ($url['scheme'] ?? 'https') . '://' . $host . $path . ($queryString !== '' ? "?$queryString" : '');

        $httpHeaders = ['Expect:']; // no 100-continue round-trip for small XML bodies
        foreach ($headers as $name => $value) {
            $httpHeaders[] = "$name: $value";
        }

        $ch = curl_init($fullUrl);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $httpHeaders,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 60,
        ]);
        if ($body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        if ($response === false) {
            throw new RuntimeException("R2 $method failed: " . curl_error($ch));
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("R2 $method returned HTTP $status: " . self::errorMessage((string) $response));
        }

        return (string) $response;
    }

    private static function parseXml(string $body): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        $xml      = simplexml_load_string($body);
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            throw new RuntimeException('R2 returned a response that is not XML');
        }
        return $xml;
    }

    /** "Code: Message" from an S3 error document, or the start of the raw body. */
    private static function errorMessage(string $body): string
    {
        $previous = libxml_use_internal_errors(true);
        $xml      = simplexml_load_string($body);
        libxml_use_internal_errors($previous);

        if ($xml !== false && isset($xml->Code)) {
            return trim((string) $xml->Code . ': ' . (string) $xml->Message, ': ');
        }
        return substr($body, 0, 200);
    }
}
