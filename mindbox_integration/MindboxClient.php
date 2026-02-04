<?php

class MindboxClientException extends \RuntimeException
{
    protected $httpStatus;
    protected $responseStatus;
    protected $errorId;
    protected $errorMessage;
    protected $validationMessages;
    protected $responseBody;

    public function __construct(
        string $message,
        int $httpStatus = 0,
        ?string $responseStatus = null,
        ?string $errorId = null,
        ?string $errorMessage = null,
        $validationMessages = null,
        ?string $responseBody = null,
        \Throwable $previous = null
    ) {
        $this->httpStatus = $httpStatus;
        $this->responseStatus = $responseStatus;
        $this->errorId = $errorId;
        $this->errorMessage = $errorMessage;
        $this->validationMessages = $validationMessages;
        $this->responseBody = $responseBody;
        parent::__construct($message, $httpStatus, $previous);
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function getResponseStatus(): ?string
    {
        return $this->responseStatus;
    }

    public function getErrorId(): ?string
    {
        return $this->errorId;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getValidationMessages()
    {
        return $this->validationMessages;
    }

    public function getResponseBody(): ?string
    {
        return $this->responseBody;
    }
}

class MindboxTransportException extends MindboxClientException {}
class MindboxInvalidResponseException extends MindboxClientException {}
class MindboxTransactionAlreadyProcessedException extends MindboxClientException {}
class MindboxValidationException extends MindboxClientException {}
class MindboxProtocolException extends MindboxClientException {}
class MindboxInternalServerException extends MindboxClientException {}
class MindboxHttpException extends MindboxClientException {}

class MindboxClient
{
    private $apiUrl;
    private $endpointId;
    private $secretKey;
    private $timeout;

    public function __construct(string $apiUrl, string $endpointId, ?string $secretKey = null, int $timeout = 5)
    {
        $apiUrl = trim($apiUrl);
        $endpointId = trim($endpointId);

        if ($apiUrl === '') {
            throw new MindboxClientException('apiUrl is required');
        }
        if ($endpointId === '') {
            throw new MindboxClientException('endpointId is required');
        }
        if ($timeout <= 0) {
            throw new MindboxClientException('timeout must be greater than 0');
        }

        if (!preg_match('~^https?://~i', $apiUrl)) {
            $apiUrl = 'https://' . $apiUrl;
        }

        $this->apiUrl = rtrim($apiUrl, '/');
        $this->endpointId = $endpointId;
        $this->secretKey = $secretKey !== null ? trim($secretKey) : null;
        $this->timeout = $timeout;
    }

    public function executeSyncOperation(
        string $operation,
        $data,
        ?string $deviceUUID = null,
        bool $authorization = false,
        ?string $transactionId = null
    )
    {
        return $this->executeOperation('sync', $operation, $data, $deviceUUID, $authorization, $transactionId);
    }

    public function executeAsyncOperation(
        string $operation,
        $data,
        ?string $deviceUUID = null,
        bool $authorization = false,
        ?string $transactionId = null
    )
    {
        return $this->executeOperation('async', $operation, $data, $deviceUUID, $authorization, $transactionId);
    }

    private function executeOperation(
        string $mode,
        string $operation,
        $data,
        ?string $deviceUUID,
        bool $authorization,
        ?string $transactionId
    )
    {
        $operation = trim($operation);
        if ($operation === '') {
            throw new MindboxClientException('operation is required');
        }

        $jsonBody = $this->encodeJson($data);

        $headers = [
            'Content-Type: application/json; charset=utf-8',
            'Accept: application/json',
        ];

        if ($authorization) {
            if ($this->secretKey === null || $this->secretKey === '') {
                throw new MindboxClientException('secretKey is required for authorized requests');
            }
            $headers[] = 'Authorization: SecretKey ' . $this->secretKey;
        }

        $url = $this->buildUrl($mode, $operation, $deviceUUID, $transactionId);

        list($httpStatus, $body) = $this->sendRequest($url, $jsonBody, $headers);

        return $this->handleResponse($httpStatus, $body);
    }

    private function buildUrl(
        string $mode,
        string $operation,
        ?string $deviceUUID,
        ?string $transactionId
    ): string
    {
        $query = [
            'endpointId' => $this->endpointId,
            'operation' => $operation,
        ];
        if ($deviceUUID !== null && $deviceUUID !== '') {
            $query['deviceUUID'] = $deviceUUID;
        }
        if ($transactionId !== null && $transactionId !== '') {
            $query['transactionId'] = $transactionId;
        }

        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return $this->apiUrl . '/v3/operations/' . $mode . '?' . $queryString;
    }

    private function encodeJson($data): string
    {
        if (is_string($data)) {
            $data = trim($data);
            if ($data === '') {
                throw new MindboxClientException('data must be a non-empty JSON string');
            }
            json_decode($data, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new MindboxClientException('data contains invalid JSON: ' . json_last_error_msg());
            }
            return $data;
        }

        if (is_array($data) || is_object($data)) {
            $json = json_encode(
                $data,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
            );
            if ($json === false) {
                throw new MindboxClientException('failed to encode data to JSON: ' . json_last_error_msg());
            }
            return $json;
        }

        throw new MindboxClientException('data must be JSON string, array, or object');
    }

    private function sendRequest(string $url, string $body, array $headers): array
    {
        if (function_exists('curl_init')) {
            return $this->sendRequestWithCurl($url, $body, $headers);
        }

        return $this->sendRequestWithStreams($url, $body, $headers);
    }

    private function sendRequestWithCurl(string $url, string $body, array $headers): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->timeout);

        $responseBody = curl_exec($ch);
        $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($responseBody === false) {
            $errorMessage = curl_error($ch);
            $errorCode = curl_errno($ch);
            curl_close($ch);

            $message = 'request failed';
            if ($errorMessage !== '') {
                $message .= ': ' . $errorMessage;
            }
            if ($errorCode === CURLE_OPERATION_TIMEDOUT) {
                $message = 'request timed out';
            }

            throw new MindboxTransportException($message, 0, null, null, null, null, null);
        }

        curl_close($ch);

        return [$httpStatus, $responseBody];
    }

    private function sendRequestWithStreams(string $url, string $body, array $headers): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'timeout' => $this->timeout,
                'ignore_errors' => true,
            ],
        ]);

        $handle = @fopen($url, 'rb', false, $context);
        if (!$handle) {
            $error = error_get_last();
            $message = 'request failed';
            if (is_array($error) && !empty($error['message'])) {
                $message .= ': ' . $error['message'];
            }
            throw new MindboxTransportException($message, 0, null, null, null, null, null);
        }

        stream_set_timeout($handle, $this->timeout);
        $responseBody = stream_get_contents($handle);
        $meta = stream_get_meta_data($handle);
        fclose($handle);

        if (!empty($meta['timed_out'])) {
            throw new MindboxTransportException('request timed out', 0, null, null, null, null, null);
        }

        $httpStatus = 0;
        if (isset($http_response_header[0]) && preg_match('~HTTP/\S+\s+(\d{3})~', $http_response_header[0], $matches)) {
            $httpStatus = (int)$matches[1];
        }

        return [$httpStatus, $responseBody !== false ? $responseBody : ''];
    }

    private function handleResponse(int $httpStatus, string $body)
    {
        $trimmedBody = trim($body);
        $hasBody = $trimmedBody !== '';
        $decoded = null;
        $jsonError = null;

        if ($hasBody) {
            $decoded = json_decode($body, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $jsonError = json_last_error_msg();
                $decoded = null;
            }
        }

        if ($httpStatus >= 200 && $httpStatus < 300) {
            if ($hasBody && $jsonError !== null) {
                throw new MindboxInvalidResponseException('invalid JSON response: ' . $jsonError, $httpStatus, null, null, null, null, $body);
            }

            if (is_array($decoded) && isset($decoded['status'])) {
                $status = (string)$decoded['status'];
                if (in_array($status, ['TransactionAlreadyProcessed', 'ValidationError', 'ProtocolError', 'InternalServerError'], true)) {
                    throw $this->createMindboxException($httpStatus, $decoded, $body);
                }
            }

            return $hasBody ? $decoded : null;
        }

        if ($hasBody && $jsonError !== null) {
            return $this->throwHttpException($httpStatus, null, $body, 'invalid JSON error response: ' . $jsonError);
        }

        return $this->throwHttpException($httpStatus, is_array($decoded) ? $decoded : null, $body, null);
    }

    private function throwHttpException(int $httpStatus, ?array $decoded, string $body, ?string $fallbackMessage)
    {
        $exception = $this->createMindboxException($httpStatus, $decoded, $body, $fallbackMessage);
        throw $exception;
    }

    private function createMindboxException(int $httpStatus, ?array $decoded, string $body, ?string $fallbackMessage = null): MindboxClientException
    {
        if (!is_array($decoded)) {
            $decoded = [];
        }
        $status = $decoded['status'] ?? null;
        $errorId = $decoded['errorId'] ?? null;
        $errorMessage = $decoded['errorMessage'] ?? null;
        $validationMessages = $decoded['validationMessages'] ?? null;

        $message = 'Mindbox error';
        if (is_string($status) && $status !== '') {
            $message .= ': ' . $status;
        }
        if (is_string($errorMessage) && $errorMessage !== '') {
            $message .= ' - ' . $errorMessage;
        } elseif ($validationMessages !== null) {
            $message .= ' - Validation error';
        } elseif ($fallbackMessage !== null) {
            $message .= ' - ' . $fallbackMessage;
        }
        if ($httpStatus > 0) {
            $message .= ' (HTTP ' . $httpStatus . ')';
        }
        if (is_string($errorId) && $errorId !== '') {
            $message .= ' [errorId ' . $errorId . ']';
        }

        switch ($status) {
            case 'TransactionAlreadyProcessed':
                return new MindboxTransactionAlreadyProcessedException($message, $httpStatus, $status, $errorId, $errorMessage, $validationMessages, $body);
            case 'ValidationError':
                return new MindboxValidationException($message, $httpStatus, $status, $errorId, $errorMessage, $validationMessages, $body);
            case 'ProtocolError':
                return new MindboxProtocolException($message, $httpStatus, $status, $errorId, $errorMessage, $validationMessages, $body);
            case 'InternalServerError':
                return new MindboxInternalServerException($message, $httpStatus, $status, $errorId, $errorMessage, $validationMessages, $body);
            default:
                return new MindboxHttpException($message, $httpStatus, $status, $errorId, $errorMessage, $validationMessages, $body);
        }
    }
}

class MindboxClientFactory
{
    private static $instances = [];

    public static function getClient(
        string $apiUrl,
        string $endpointId,
        ?string $secretKey = null,
        int $timeout = 5,
        ?callable $onError = null
    ) {
        $key = sha1($apiUrl . '|' . $endpointId . '|' . $secretKey . '|' . $timeout);
        if (isset(self::$instances[$key])) {
            return self::$instances[$key];
        }

        try {
            $client = new MindboxClient($apiUrl, $endpointId, $secretKey, $timeout);
            self::$instances[$key] = $client;
            return $client;
        } catch (\Throwable $e) {
            if ($onError !== null) {
                $onError($e);
            }
            return null;
        }
    }

    public static function executeSyncOperation(
        string $apiUrl,
        string $endpointId,
        string $operation,
        $data,
        ?string $deviceUUID = null,
        bool $authorization = false,
        ?string $transactionId = null,
        ?string $secretKey = null,
        int $timeout = 5,
        ?callable $onError = null
    ) {
        $client = self::getClient($apiUrl, $endpointId, $secretKey, $timeout, $onError);
        if ($client === null) {
            return null;
        }

        try {
            return $client->executeSyncOperation($operation, $data, $deviceUUID, $authorization, $transactionId);
        } catch (\Throwable $e) {
            if ($onError !== null) {
                $onError($e);
            }
            return null;
        }
    }

    public static function executeAsyncOperation(
        string $apiUrl,
        string $endpointId,
        string $operation,
        $data,
        ?string $deviceUUID = null,
        bool $authorization = false,
        ?string $transactionId = null,
        ?string $secretKey = null,
        int $timeout = 5,
        ?callable $onError = null
    ) {
        $client = self::getClient($apiUrl, $endpointId, $secretKey, $timeout, $onError);
        if ($client === null) {
            return null;
        }

        try {
            return $client->executeAsyncOperation($operation, $data, $deviceUUID, $authorization, $transactionId);
        } catch (\Throwable $e) {
            if ($onError !== null) {
                $onError($e);
            }
            return null;
        }
    }
}
