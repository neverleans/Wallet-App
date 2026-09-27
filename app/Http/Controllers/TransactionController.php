<?php

namespace App\Http\Controllers;

use App\Http\Requests\TransferMoneyRequest;
use App\Services\TransferMoneyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

class TransactionController extends Controller
{
    /**
     * Handle the money transfer request.
     */
    public function send(
        TransferMoneyRequest $request,
        TransferMoneyService $transferService
    ): JsonResponse|RedirectResponse {
        $result = $transferService->execute(
            sender: $request->user(),
            recipientEmail: $request->recipientEmail(),
            amount: $request->amount(),
            idempotencyKey: $request->idempotencyKey()
        );

        $transaction = $result['transaction'];
        $isReplay = $result['is_idempotent_replay'];

        if ($request->expectsJson() || $request->is('api/*')) {
            $statusCode = $isReplay ? Response::HTTP_OK : Response::HTTP_CREATED;

            return response()->json([
                'message' => $isReplay
                    ? 'Transação recuperada com sucesso (reprodução idempotente).'
                    : 'Transferência realizada com sucesso!',
                'data' => [
                    'id' => $transaction->id,
                    'sender_id' => $transaction->sender_id,
                    'receiver_id' => $transaction->receiver_id,
                    'amount' => number_format((float) $transaction->amount, 2, '.', ''),
                    'status' => $transaction->status,
                    'idempotency_key' => $transaction->idempotency_key,
                    'created_at' => $transaction->created_at?->toIso8601String(),
                ],
                'is_idempotent_replay' => $isReplay,
            ], $statusCode)->header('Idempotent-Replay', $isReplay ? 'true' : 'false');
        }

        return redirect()->back()->with('transfer_success', 'Transferência realizada com sucesso!');
    }
}
