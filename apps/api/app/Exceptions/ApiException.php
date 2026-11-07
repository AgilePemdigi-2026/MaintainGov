<?php

namespace App\Exceptions;

use Exception;

/**
 * Domain/API error with a stable machine-readable code and HTTP status.
 *
 * The global exception renderer turns this into the shared envelope:
 * { success: false, message, error: { code, message }, data }.
 * Internal exception messages and GitHub tokens are never exposed.
 */
class ApiException extends Exception
{
    /**
     * @param  array<string, mixed>|null  $data
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
        public readonly ?array $data = null,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message);
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function notFound(
        string $message = 'Data tidak ditemukan.',
        string $code = 'NOT_FOUND',
        ?array $data = null,
    ): self {
        return new self($code, $message, 404, $data);
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function forbidden(
        string $message = 'Akses ditolak.',
        string $code = 'FORBIDDEN',
        ?array $data = null,
    ): self {
        return new self($code, $message, 403, $data);
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function conflict(
        string $message,
        string $code,
        ?array $data = null,
    ): self {
        return new self($code, $message, 409, $data);
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function unprocessable(
        string $message,
        string $code,
        ?array $data = null,
    ): self {
        return new self($code, $message, 422, $data);
    }
}
