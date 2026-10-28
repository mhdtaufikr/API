<?php

declare(strict_types=1);

final class ReceiverHttp
{
    private const DEFAULT_RESPONSE_CONTRACT = [
        'success' => 'OK',
        'duplicate' => 'OK',
        'failure' => 'ERROR',
    ];

    /**
     * Operator access is not restricted by source IP.
     *
     * IMPORTANT:
     * Dashboard authentication must be enforced separately.
     */
    public static function localOnly(): void
    {
        return;
    }

    /**
     * Validate incoming attendance machine requests.
     */
    public static function validateTransport(array $server, array $input): void
    {
        // Check receiver status.
        if (!enabled('MACHINE_RECEIVER_ENABLED')) {
            compat_throw(
                new DomainException('Receiver disabled.', 503)
            );
        }

        // Support wildcard (*) to allow requests from all IPs.
        $remoteIp = $server['REMOTE_ADDR'] ?? '';

        if (!self::sourceAllowed($remoteIp)) {
            compat_throw(
                new DomainException('Source IP is not allowed.', 403)
            );
        }

        // Reject cross-site browser requests.
        if (($server['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') {
            compat_throw(
                new DomainException(
                    'Cross-site browser requests are not accepted.',
                    403
                )
            );
        }

        // Only GET requests are supported.
        if (($server['REQUEST_METHOD'] ?? '') !== 'GET') {
            compat_throw(
                new DomainException(
                    'Only the defined GET protocol is supported.',
                    405
                )
            );
        }

        // Enforce request size limits.
        if (
            strlen($server['QUERY_STRING'] ?? '') > 1024 ||
            (int) ($server['CONTENT_LENGTH'] ?? 0) > 0
        ) {
            compat_throw(
                new DomainException(
                    'Request exceeds receiver limits.',
                    413
                )
            );
        }

        // Reject unknown or duplicate query parameters.
        $keys = [];

        foreach (explode('&', $server['QUERY_STRING'] ?? '') as $pair) {
            $key = urldecode(explode('=', $pair, 2)[0]);

            if (
                !in_array($key, ['cardno', 'd', 'h', 'm'], true) ||
                isset($keys[$key])
            ) {
                compat_throw(
                    new DomainException(
                        'Unknown or repeated query parameter.',
                        422
                    )
                );
            }

            $keys[$key] = true;
        }

        // Require exactly four protocol parameters.
        if (count($input) !== 4) {
            compat_throw(
                new DomainException(
                    'Exactly four protocol parameters are required.',
                    422
                )
            );
        }
    }

    public static function sourceAllowed(string $remoteIp, ?string $configured = null): bool
    {
        if ($remoteIp === '') {
            return false;
        }

        $allowed = array_map(
            'trim',
            explode(',', $configured ?? env('MACHINE_ALLOWED_IPS', '127.0.0.1,::1'))
        );

        return in_array('*', $allowed, true) || in_array($remoteIp, $allowed, true);
    }

    public static function responseContract(?string $path = null): array
    {
        $contract = self::DEFAULT_RESPONSE_CONTRACT;
        $path = $path ?? ROOT . '/config/receiver-response.json';

        if (!is_file($path)) {
            return $contract;
        }

        $custom = jsonFile($path);

        foreach ($contract as $key => $value) {
            if (
                !isset($custom[$key]) ||
                !is_string($custom[$key]) ||
                strlen($custom[$key]) > 4096
            ) {
                compat_throw(new RuntimeException('Invalid response contract.'));
            }

            $contract[$key] = $custom[$key];
        }

        return $contract;
    }

    /**
     * Return receiver response to attendance machine.
     */
    public static function respond(
        int $http,
        string $status,
        string $requestId,
        ?array $validatedContract = null
    ): void {
        $contract = $validatedContract;
        if ($contract === null) {
            try {
                $contract = self::responseContract();
            } catch (Throwable $_ignored) {
                $contract = self::DEFAULT_RESPONSE_CONTRACT;
                $http = 503;
                $status = 'error';
            }
        }

        http_response_code($http);

        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('X-Receiver-Request-Id: ' . $requestId);
        header('X-Receiver-Result: ' . $status);

        if ($http === 405) {
            header('Allow: GET');
        }

        if ($http === 503) {
            header('Retry-After: 30');
        }

        echo $http === 200
            ? $contract[$status === 'duplicate' ? 'duplicate' : 'success']
            : $contract['failure'];

        exit;
    }
}
