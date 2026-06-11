<?php

namespace App\Controllers;

/**
 * Base controller.
 *
 * Provides convenience methods for emitting JSON responses and
 * reading/validating the JSON request body.
 */
abstract class BaseController
{
    /**
     * Send a JSON response and stop execution.
     *
     * @param mixed $data    Payload to encode
     * @param int   $status  HTTP status code
     */
    protected function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /** Shorthand for 404 responses. */
    protected function notFound(string $message = 'Not found'): never
    {
        $this->json(['error' => $message], 404);
    }

    /** Shorthand for 422 validation-error responses. */
    protected function validationError(string $message): never
    {
        $this->json(['error' => $message], 422);
    }

    /** Shorthand for 500 server-error responses. */
    protected function serverError(string $message = 'Internal server error'): never
    {
        $this->json(['error' => $message], 500);
    }

    /**
     * Decode the raw request body as JSON.
     *
     * @param bool $require  When true, halts with 400 if body is missing or invalid.
     * @return array
     */
    protected function getRequestBody(bool $require = true): array
    {
        $raw  = file_get_contents('php://input');
        $data = json_decode($raw ?: '{}', true);

        if ($require && (json_last_error() !== JSON_ERROR_NONE || empty($data))) {
            $this->json(['error' => 'Invalid or empty JSON body'], 400);
        }

        return $data ?? [];
    }
}
