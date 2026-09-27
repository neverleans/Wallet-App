<?php

namespace App\Http\Controllers;

use App\Models\Wallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class WalletController extends Controller
{
    /**
     * Recharge the user's wallet with ACID transaction and pessimistic lock.
     */
    public function update(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
        ]);

        $user = $request->user();
        $rechargeAmount = number_format((float) $validated['amount'], 2, '.', '');

        $wallet = DB::transaction(function () use ($user, $rechargeAmount) {
            $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->first();

            if (! $wallet) {
                $wallet = Wallet::create([
                    'user_id' => $user->id,
                    'balance' => $rechargeAmount,
                ]);
            } else {
                $wallet->balance = bcadd((string) $wallet->balance, $rechargeAmount, 2);
                $wallet->save();
            }

            return $wallet;
        });

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'message' => 'Carteira recarregada com sucesso!',
                'data' => [
                    'wallet_id' => $wallet->id,
                    'user_id' => $wallet->user_id,
                    'balance' => number_format((float) $wallet->balance, 2, '.', ''),
                    'updated_at' => $wallet->updated_at?->toIso8601String(),
                ],
            ], Response::HTTP_OK);
        }

        return redirect()->back()->with('success', 'Carteira recarregada com sucesso!');
    }
}
