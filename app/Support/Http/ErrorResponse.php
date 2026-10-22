<?php

namespace App\Support\Http;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class ErrorResponse
{
    public static function render(Throwable $exception): JsonResponse
    {
        $status = match (true) {
            $exception instanceof ValidationException => $exception->status,
            $exception instanceof AuthenticationException => 401,
            $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
            default => 500,
        };

        [$code, $message] = match ($status) {
            400 => ['BAD_REQUEST', 'The request could not be processed.'],
            401 => ['UNAUTHENTICATED', 'Authentication is required.'],
            403 => ['FORBIDDEN', 'This action is forbidden.'],
            404 => ['NOT_FOUND', 'Not found.'],
            405 => ['METHOD_NOT_ALLOWED', 'This request method is not allowed.'],
            409 => ['CONFLICT', 'This action conflicts with the current state.'],
            419 => ['CSRF_TOKEN_MISMATCH', 'The session has expired. Please try again.'],
            422 => ['VALIDATION_ERROR', 'The given data was invalid.'],
            429 => ['RATE_LIMITED', 'Too many requests. Please try again later.'],
            503 => ['SERVICE_UNAVAILABLE', 'The service is temporarily unavailable.'],
            default => $status >= 500
                ? ['SERVER_ERROR', 'An unexpected error occurred.']
                : ['HTTP_ERROR', 'The request could not be processed.'],
        };

        if ($exception instanceof ApiException) {
            $code = $exception->errorCode;
            $message = $exception->getMessage();
        }

        $errors = match (true) {
            $exception instanceof ApiException => $exception->errors,
            $exception instanceof ValidationException => $exception->errors(),
            default => [],
        };

        return response()->json([
            'message' => $message,
            'code' => $code,
            'errors' => (object) $errors,
        ], $status, $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : []);
    }
}
