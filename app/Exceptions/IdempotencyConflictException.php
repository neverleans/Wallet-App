<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IdempotencyConflictException extends Exception
{
    protected $message = 'Chave de idempotência reutilizada com parâmetros divergentes.';
    protected $code = 409;

    public function render(Request $request): JsonResponse|\Illuminate\Http\RedirectResponse
    {
        if (! $request->expectsJson() && ! $request->is('api/*')) {
            return redirect()->back()->with('transfer_error', $this->getMessage());
        }

        return response()->json([
            'error' => 'IDEMPOTENCY_CONFLICT',
            'message' => $this->getMessage(),
        ], Response::HTTP_CONFLICT);
    }
}
