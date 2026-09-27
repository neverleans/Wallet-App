<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SelfTransferException extends Exception
{
    protected $message = 'Não é permitido realizar transferências para a própria conta.';
    protected $code = 422;

    public function render(Request $request): JsonResponse|\Illuminate\Http\RedirectResponse
    {
        if (! $request->expectsJson() && ! $request->is('api/*')) {
            return redirect()->back()->with('transfer_error', $this->getMessage());
        }

        return response()->json([
            'error' => 'SELF_TRANSFER_NOT_ALLOWED',
            'message' => $this->getMessage(),
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
