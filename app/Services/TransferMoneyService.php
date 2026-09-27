<?php

namespace App\Services;

use App\Events\TransactionCompletedEvent;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\InactiveUserException;
use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\SelfTransferException;
use App\Exceptions\WalletNotFoundException;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class TransferMoneyService
{
    /**
     * Execute financial money transfer between users with ACID isolation and idempotency.
     *
     * @param User $sender
     * @param string $recipientEmail
     * @param float|string $amount
     * @param string|null $idempotencyKey
     * @return array{transaction: Transaction, is_idempotent_replay: bool}
     *
     * @throws InactiveUserException
     * @throws SelfTransferException
     * @throws WalletNotFoundException
     * @throws InsufficientBalanceException
     * @throws IdempotencyConflictException
     * @throws InvalidArgumentException
     */
    public function execute(
        User $sender,
        string $recipientEmail,
        float|string $amount,
        ?string $idempotencyKey = null
    ): array {
        $formattedAmount = number_format((float) $amount, 2, '.', '');

        if (bccomp($formattedAmount, '0.00', 2) <= 0) {
            throw new InvalidArgumentException('O valor da transferência deve ser maior que zero.');
        }

        // 1. Verificação de Idempotência Prévia
        if (! empty($idempotencyKey)) {
            $existingTransaction = Transaction::where('idempotency_key', $idempotencyKey)->first();

            if ($existingTransaction) {
                // Valida se os parâmetros correspondem à transação original gravada
                $recipient = User::where('email', $recipientEmail)->first();

                if (
                    $existingTransaction->sender_id !== $sender->id ||
                    ($recipient && $existingTransaction->receiver_id !== $recipient->id) ||
                    bccomp((string) $existingTransaction->amount, $formattedAmount, 2) !== 0
                ) {
                    throw new IdempotencyConflictException(
                        'Chave de idempotência reutilizada com parâmetros divergentes.'
                    );
                }

                return [
                    'transaction' => $existingTransaction,
                    'is_idempotent_replay' => true,
                ];
            }
        }

        // 2. Validações de Borda do Remetente e Destinatário
        if (! $sender->isActive()) {
            throw new InactiveUserException('Sua conta está inativa. Operação não autorizada.');
        }

        $recipient = User::where('email', $recipientEmail)->first();

        if (! $recipient) {
            throw new WalletNotFoundException('Este usuário não existe, tente outro email!');
        }

        if ($sender->id === $recipient->id) {
            throw new SelfTransferException('Não é permitido realizar transferências para a própria conta.');
        }

        if (! $recipient->isActive()) {
            throw new InactiveUserException('A conta do destinatário está inativa.');
        }

        // 3. Execução Atômica com Bloqueio Pessimista e Ordenação Anti-Deadlock
        $processTransfer = function () use ($sender, $recipient, $formattedAmount, $idempotencyKey) {
            return DB::transaction(function () use ($sender, $recipient, $formattedAmount, $idempotencyKey) {
                // Idempotência conferida novamente dentro da transação caso concorrência extrema
                if (! empty($idempotencyKey)) {
                    $existing = Transaction::where('idempotency_key', $idempotencyKey)
                        ->lockForUpdate()
                        ->first();

                    if ($existing) {
                        return [
                            'transaction' => $existing,
                            'is_idempotent_replay' => true,
                        ];
                    }
                }

                // Carrega ou inicializa as carteiras
                $senderWallet = Wallet::firstOrCreate(
                    ['user_id' => $sender->id],
                    ['balance' => 0.00]
                );

                $receiverWallet = Wallet::firstOrCreate(
                    ['user_id' => $recipient->id],
                    ['balance' => 0.00]
                );

                // Anti-Deadlock: Ordenar IDs de carteiras de forma determinística
                $orderedWalletIds = [$senderWallet->id, $receiverWallet->id];
                sort($orderedWalletIds);

                $lockedWallets = Wallet::whereIn('id', $orderedWalletIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                /** @var Wallet $lockedSenderWallet */
                $lockedSenderWallet = $lockedWallets->get($senderWallet->id);
                /** @var Wallet $lockedReceiverWallet */
                $lockedReceiverWallet = $lockedWallets->get($receiverWallet->id);

                // Verificação de saldo sob bloqueio pessimista
                if (bccomp((string) $lockedSenderWallet->balance, $formattedAmount, 2) < 0) {
                    throw new InsufficientBalanceException('Saldo insuficiente, recarregue sua carteira!');
                }

                // Debitar do remetente e creditar ao destinatário usando precisão arbitrária
                $lockedSenderWallet->balance = bcsub((string) $lockedSenderWallet->balance, $formattedAmount, 2);
                $lockedSenderWallet->save();

                $lockedReceiverWallet->balance = bcadd((string) $lockedReceiverWallet->balance, $formattedAmount, 2);
                $lockedReceiverWallet->save();

                // Registrar a transação financeira com chave de idempotência
                $transaction = Transaction::create([
                    'sender_id' => $sender->id,
                    'receiver_id' => $recipient->id,
                    'amount' => $formattedAmount,
                    'status' => 'completed',
                    'idempotency_key' => $idempotencyKey,
                ]);

                return [
                    'transaction' => $transaction,
                    'is_idempotent_replay' => false,
                ];
            }, 5);
        };

        // Se houver idempotency key, protege contra race-condition na mesma chave via atomic lock
        if (! empty($idempotencyKey)) {
            $lock = Cache::lock("idempotency:{$sender->id}:{$idempotencyKey}", 15);
            $result = $lock->block(10, $processTransfer);
        } else {
            $result = $processTransfer();
        }

        // 4. Disparo do Evento de Domínio apenas se for uma nova transação
        if (! $result['is_idempotent_replay']) {
            TransactionCompletedEvent::dispatch($result['transaction']);
        }

        return $result;
    }
}
