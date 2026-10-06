<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class Handler extends ExceptionHandler
{
    protected $levels = [
        //
    ];

    protected $dontReport = [
        //
    ];

    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    public function render($request, Throwable $e)
    {
        if ($this->isApiRequest($request)) {
            if ($e instanceof AuthenticationException) {
                return response()->json(['error' => 'Unauthenticated.'], 401);
            }

            if ($e instanceof ValidationException) {
                return response()->json([
                    'error' => 'Validation failed.',
                    'errors' => $e->errors(),
                ], 422);
            }

            $message = $e->getMessage() ?: 'Unexpected server error';
            $statusCode = str_contains(strtolower($message), 'not implemented') ? 501 : 500;

            return response()->json(['error' => $message], $statusCode);
        }

        return parent::render($request, $e);
    }

    protected function unauthenticated($request, AuthenticationException $exception)
    {
        if ($this->isApiRequest($request)) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }

        return parent::unauthenticated($request, $exception);
    }

    private function isApiRequest(Request $request): bool
    {
        return $request->is('api/*') || $request->is('api');
    }
}
