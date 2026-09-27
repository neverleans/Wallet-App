<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InsufficientBalanceException extends Exception
{
    protected $message = 'Saldo insuficiente para realizar a transferência.';
    protected $code = 422;

    public function render(Request $request): JsonResponse|\Illuminate\Http\RedirectResponse
    {
        if (! $request->expectsJson() && ! $request->is('api/*')) {
            return redirect()->back()->with('transfer_error', $this->getMessage());
        }

        return response()->json([
            'error' => 'INSUFFICIENT_BALANCE',
            'message' => $this->getMessage(),
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
