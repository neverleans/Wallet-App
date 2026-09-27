<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WalletNotFoundException extends Exception
{
    protected $message = 'Carteira não encontrada para o usuário informado.';
    protected $code = 404;

    public function render(Request $request): JsonResponse|\Illuminate\Http\RedirectResponse
    {
        if (! $request->expectsJson() && ! $request->is('api/*')) {
            return redirect()->back()->with('transfer_error', $this->getMessage());
        }

        return response()->json([
            'error' => 'WALLET_NOT_FOUND',
            'message' => $this->getMessage(),
        ], Response::HTTP_NOT_FOUND);
    }
}
