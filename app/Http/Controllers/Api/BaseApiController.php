<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class BaseApiController extends Controller
{
    /**
     * Return a success JSON response
     *
     * @param mixed $data The data to include in the response
     * @param string $message Success message
     * @param int $status HTTP status code
     * @return JsonResponse
     */
    protected function successResponse(mixed $data = null, string $message = '', int $status = 200): JsonResponse
    {
        $response = ['success' => true];

        if (!empty($message)) {
            $response['message'] = $message;
        }

        if ($data !== null) {
            $response['data'] = $data;
        }

        return response()->json($response, $status);
    }

    /**
     * Return an error JSON response
     *
     * @param string $message Error message
     * @param int $status HTTP status code
     * @param mixed $errors Additional error details
     * @return JsonResponse
     */
    protected function errorResponse(string $message, int $status = 400, mixed $errors = null): JsonResponse
    {
        $response = [
            'success' => false,
            'message' => $message
        ];

        if ($errors !== null) {
            $response['errors'] = $errors;
        }

        return response()->json($response, $status);
    }

    /**
     * Return unauthorized response
     *
     * @param string $message Unauthorized message
     * @return JsonResponse
     */
    protected function unauthorizedResponse(string $message = 'Unauthorized'): JsonResponse
    {
        // Add "Unauthorized. " prefix for consistency with existing tests
        if (!str_starts_with($message, 'Unauthorized')) {
            $message = 'Unauthorized. ' . $message;
        }

        return $this->errorResponse($message, 401);
    }

    /**
     * Return not found response
     *
     * @param string $message Not found message
     * @return JsonResponse
     */
    protected function notFoundResponse(string $message = 'Resource not found'): JsonResponse
    {
        return $this->errorResponse($message, 404);
    }

    /**
     * Return forbidden response
     *
     * @param string $message Forbidden message
     * @return JsonResponse
     */
    protected function forbiddenResponse(string $message = 'Forbidden'): JsonResponse
    {
        return $this->errorResponse($message, 403);
    }

    /**
     * Return validation error response
     *
     * @param string $message Validation error message
     * @param mixed $errors Validation errors
     * @return JsonResponse
     */
    protected function validationErrorResponse(string $message = 'Validation failed', mixed $errors = null): JsonResponse
    {
        return $this->errorResponse($message, 422, $errors);
    }

    /**
     * Return server error response
     *
     * @param string $message Server error message
     * @return JsonResponse
     */
    protected function serverErrorResponse(string $message = 'Internal server error'): JsonResponse
    {
        return $this->errorResponse($message, 500);
    }

    /**
     * Return created response
     *
     * @param mixed $data The created resource data
     * @param string $message Success message
     * @return JsonResponse
     */
    protected function createdResponse(mixed $data = null, string $message = 'Resource created successfully'): JsonResponse
    {
        return $this->successResponse($data, $message, 201);
    }

    /**
     * Return no content response
     *
     * @param string $message Optional message
     * @return JsonResponse
     */
    protected function noContentResponse(string $message = ''): JsonResponse
    {
        $response = ['success' => true];

        if (!empty($message)) {
            $response['message'] = $message;
        }

        return response()->json($response, 204);
    }

    /**
     * Return accepted response for async operations
     *
     * @param mixed $data Optional data
     * @param string $message Accepted message
     * @return JsonResponse
     */
    protected function acceptedResponse(mixed $data = null, string $message = 'Request accepted for processing'): JsonResponse
    {
        return $this->successResponse($data, $message, 202);
    }
}
