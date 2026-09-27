<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InactiveUserException extends Exception
{
    protected $message = 'Conta inativa. Operação não autorizada.';
    protected $code = 403;

    public function render(Request $request): JsonResponse|\Illuminate\Http\RedirectResponse
    {
        if (! $request->expectsJson() && ! $request->is('api/*')) {
            return redirect()->back()->with('transfer_error', $this->getMessage());
        }

        return response()->json([
            'error' => 'INACTIVE_USER',
            'message' => $this->getMessage(),
        ], Response::HTTP_FORBIDDEN);
    }
}
