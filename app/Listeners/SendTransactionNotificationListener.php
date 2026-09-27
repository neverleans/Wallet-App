<?php

namespace App\Listeners;

use App\Events\TransactionCompletedEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class SendTransactionNotificationListener implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * The number of times the queued listener may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 5;

    /**
     * Handle the event.
     */
    public function handle(TransactionCompletedEvent $event): void
    {
        $transaction = $event->transaction->loadMissing(['sender', 'receiver']);

        // Simula processamento assíncrono de notificação (E-mail, Webhook ou Push)
        Log::info('[NotificationService] Notificação de transferência enviada com sucesso.', [
            'transaction_id' => $transaction->id,
            'amount' => $transaction->amount,
            'sender_id' => $transaction->sender_id,
            'sender_email' => $transaction->sender?->email,
            'receiver_id' => $transaction->receiver_id,
            'receiver_email' => $transaction->receiver?->email,
            'idempotency_key' => $transaction->idempotency_key,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(TransactionCompletedEvent $event, \Throwable $exception): void
    {
        Log::error('[NotificationService] Falha ao despachar notificação da transferência.', [
            'transaction_id' => $event->transaction->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
