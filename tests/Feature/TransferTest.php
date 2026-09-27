<?php

namespace Tests\Feature;

use App\Events\TransactionCompletedEvent;
use App\Exceptions\InactiveUserException;
use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\SelfTransferException;
use App\Listeners\SendTransactionNotificationListener;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\TransferMoneyService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TransferTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 1. Teste de transferência bem-sucedida entre dois usuários e validação dos saldos finais.
     */
    public function test_successful_transfer_between_two_users_and_balances_updated(): void
    {
        Event::fake([TransactionCompletedEvent::class]);

        $sender = User::factory()->create(['email' => 'sender@example.com']);
        $receiver = User::factory()->create(['email' => 'receiver@example.com']);

        $senderWallet = Wallet::factory()->create([
            'user_id' => $sender->id,
            'balance' => 500.00,
        ]);

        $receiverWallet = Wallet::factory()->create([
            'user_id' => $receiver->id,
            'balance' => 100.00,
        ]);

        $response = $this->actingAs($sender, 'sanctum')->postJson(route('transaction.send'), [
            'recipient_email' => 'receiver@example.com',
            'amount' => 150.50,
        ]);

        $response->assertCreated();
        $response->assertJson([
            'message' => 'Transferência realizada com sucesso!',
            'data' => [
                'sender_id' => $sender->id,
                'receiver_id' => $receiver->id,
                'amount' => '150.50',
                'status' => 'completed',
            ],
            'is_idempotent_replay' => false,
        ]);

        // Validação estrita dos saldos finais no banco de dados
        $this->assertEquals(349.50, (float) $senderWallet->fresh()->balance);
        $this->assertEquals(250.50, (float) $receiverWallet->fresh()->balance);

        // Validação da persistência do registro da transação
        $this->assertDatabaseHas('transactions', [
            'sender_id' => $sender->id,
            'receiver_id' => $receiver->id,
            'amount' => '150.50',
            'status' => 'completed',
        ]);

        Event::assertDispatched(TransactionCompletedEvent::class, function ($event) use ($sender, $receiver) {
            return $event->transaction->sender_id === $sender->id
                && $event->transaction->receiver_id === $receiver->id
                && bccomp((string) $event->transaction->amount, '150.50', 2) === 0;
        });
    }

    /**
     * 2. Teste de tentativa de transferência com saldo insuficiente.
     */
    public function test_transfer_fails_when_insufficient_balance(): void
    {
        $sender = User::factory()->create();
        $receiver = User::factory()->create();

        Wallet::factory()->create([
            'user_id' => $sender->id,
            'balance' => 50.00,
        ]);

        Wallet::factory()->create([
            'user_id' => $receiver->id,
            'balance' => 0.00,
        ]);

        $response = $this->actingAs($sender, 'sanctum')->postJson(route('transaction.send'), [
            'recipient_email' => $receiver->email,
            'amount' => 100.00,
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'error' => 'INSUFFICIENT_BALANCE',
        ]);

        // Saldos devem permanecer inalterados
        $this->assertEquals(50.00, (float) $sender->fresh()->wallet->balance);
        $this->assertEquals(0.00, (float) $receiver->fresh()->wallet->balance);

        // Nenhuma transação criada
        $this->assertDatabaseCount('transactions', 0);
    }

    /**
     * 3. Teste de tentativa de transferência para si mesmo.
     */
    public function test_transfer_fails_when_transferring_to_oneself(): void
    {
        $sender = User::factory()->create();

        Wallet::factory()->create([
            'user_id' => $sender->id,
            'balance' => 300.00,
        ]);

        $response = $this->actingAs($sender, 'sanctum')->postJson(route('transaction.send'), [
            'recipient_email' => $sender->email,
            'amount' => 50.00,
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'error' => 'SELF_TRANSFER_NOT_ALLOWED',
        ]);

        $this->assertEquals(300.00, (float) $sender->fresh()->wallet->balance);
        $this->assertDatabaseCount('transactions', 0);
    }

    /**
     * 4. Teste de reversão (Rollback) caso ocorra erro no meio do processo.
     */
    public function test_transfer_rolls_back_atomically_on_unexpected_exception(): void
    {
        $sender = User::factory()->create();
        $receiver = User::factory()->create();

        $senderWallet = Wallet::factory()->create([
            'user_id' => $sender->id,
            'balance' => 500.00,
        ]);

        $receiverWallet = Wallet::factory()->create([
            'user_id' => $receiver->id,
            'balance' => 100.00,
        ]);

        // Forçar falha no momento de criar o registro de Transaction para simular falha no meio da transação ACID
        $service = new TransferMoneyService();

        // Escutando a criação de transação para disparar uma exceção não tratada dentro do DB::transaction
        Transaction::saving(function () {
            throw new Exception('Simulação de falha catastrófica no banco de dados!');
        });

        try {
            $service->execute(
                sender: $sender,
                recipientEmail: $receiver->email,
                amount: 100.00
            );
            $this->fail('Deveria ter lançado uma exceção!');
        } catch (Exception $e) {
            $this->assertEquals('Simulação de falha catastrófica no banco de dados!', $e->getMessage());
        }

        // Saldos devem estar 100% preservados graças ao DB::rollBack()
        $this->assertEquals(500.00, (float) $senderWallet->fresh()->balance);
        $this->assertEquals(100.00, (float) $receiverWallet->fresh()->balance);
        $this->assertDatabaseCount('transactions', 0);
    }

    /**
     * 5. Teste de idempotência (mesma chave de idempotência retorna a mesma transação sem debitar duas vezes).
     */
    public function test_idempotency_key_returns_same_transaction_without_deducting_twice(): void
    {
        $sender = User::factory()->create();
        $receiver = User::factory()->create();

        $senderWallet = Wallet::factory()->create([
            'user_id' => $sender->id,
            'balance' => 1000.00,
        ]);

        $receiverWallet = Wallet::factory()->create([
            'user_id' => $receiver->id,
            'balance' => 200.00,
        ]);

        $idempotencyKey = 'idemp_key_unit_test_' . uniqid();

        // Primeira requisição com Idempotency-Key
        $firstResponse = $this->actingAs($sender, 'sanctum')
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson(route('transaction.send'), [
                'recipient_email' => $receiver->email,
                'amount' => 200.00,
            ]);

        $firstResponse->assertCreated();
        $firstResponse->assertHeader('Idempotent-Replay', 'false');
        $transactionId = $firstResponse->json('data.id');

        // Saldos após a primeira execução
        $this->assertEquals(800.00, (float) $senderWallet->fresh()->balance);
        $this->assertEquals(400.00, (float) $receiverWallet->fresh()->balance);
        $this->assertDatabaseCount('transactions', 1);

        // Segunda requisição idêntica com a MESMA Idempotency-Key (ex: retry ou clique duplo)
        $secondResponse = $this->actingAs($sender, 'sanctum')
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson(route('transaction.send'), [
                'recipient_email' => $receiver->email,
                'amount' => 200.00,
            ]);

        $secondResponse->assertOk();
        $secondResponse->assertHeader('Idempotent-Replay', 'true');
        $this->assertEquals($transactionId, $secondResponse->json('data.id'));
        $this->assertTrue($secondResponse->json('is_idempotent_replay'));

        // Validação crítica: os saldos NÃO foram debitados novamente!
        $this->assertEquals(800.00, (float) $senderWallet->fresh()->balance);
        $this->assertEquals(400.00, (float) $receiverWallet->fresh()->balance);
        $this->assertDatabaseCount('transactions', 1);
    }

    /**
     * 6. Teste de rejeição quando a mesma Idempotency-Key é reutilizada com parâmetros alterados.
     */
    public function test_reusing_idempotency_key_with_different_payload_fails_with_conflict(): void
    {
        $sender = User::factory()->create();
        $receiver1 = User::factory()->create();
        $receiver2 = User::factory()->create();

        Wallet::factory()->create(['user_id' => $sender->id, 'balance' => 1000.00]);
        Wallet::factory()->create(['user_id' => $receiver1->id, 'balance' => 0.00]);
        Wallet::factory()->create(['user_id' => $receiver2->id, 'balance' => 0.00]);

        $idempotencyKey = 'idemp_conflict_' . uniqid();

        // Primeira requisição: 100.00 para receiver1
        $this->actingAs($sender, 'sanctum')
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson(route('transaction.send'), [
                'recipient_email' => $receiver1->email,
                'amount' => 100.00,
            ])->assertCreated();

        // Segunda requisição com a mesma chave, mas valor diferente (300.00)
        $conflictResponse = $this->actingAs($sender, 'sanctum')
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson(route('transaction.send'), [
                'recipient_email' => $receiver1->email,
                'amount' => 300.00,
            ]);

        $conflictResponse->assertStatus(409);
        $conflictResponse->assertJson(['error' => 'IDEMPOTENCY_CONFLICT']);
    }

    /**
     * 7. Teste de bloqueio de usuário inativo (remetente).
     */
    public function test_transfer_fails_when_sender_is_inactive(): void
    {
        $sender = User::factory()->create(['is_active' => false]);
        $receiver = User::factory()->create(['is_active' => true]);

        Wallet::factory()->create(['user_id' => $sender->id, 'balance' => 500.00]);
        Wallet::factory()->create(['user_id' => $receiver->id, 'balance' => 0.00]);

        $response = $this->actingAs($sender, 'sanctum')->postJson(route('transaction.send'), [
            'recipient_email' => $receiver->email,
            'amount' => 50.00,
        ]);

        $response->assertStatus(403);
        $response->assertJson(['error' => 'INACTIVE_USER']);
    }

    /**
     * 8. Teste de bloqueio de usuário inativo (destinatário).
     */
    public function test_transfer_fails_when_recipient_is_inactive(): void
    {
        $sender = User::factory()->create(['is_active' => true]);
        $receiver = User::factory()->create(['is_active' => false]);

        Wallet::factory()->create(['user_id' => $sender->id, 'balance' => 500.00]);
        Wallet::factory()->create(['user_id' => $receiver->id, 'balance' => 0.00]);

        $response = $this->actingAs($sender, 'sanctum')->postJson(route('transaction.send'), [
            'recipient_email' => $receiver->email,
            'amount' => 50.00,
        ]);

        $response->assertStatus(403);
        $response->assertJson(['error' => 'INACTIVE_USER']);
    }

    /**
     * 9. Teste de validação de valores negativos ou zero.
     */
    public function test_transfer_fails_with_invalid_amounts(): void
    {
        $sender = User::factory()->create();
        $receiver = User::factory()->create();

        Wallet::factory()->create(['user_id' => $sender->id, 'balance' => 500.00]);

        // Valor zero
        $this->actingAs($sender, 'sanctum')->postJson(route('transaction.send'), [
            'recipient_email' => $receiver->email,
            'amount' => 0,
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['amount']);

        // Valor negativo
        $this->actingAs($sender, 'sanctum')->postJson(route('transaction.send'), [
            'recipient_email' => $receiver->email,
            'amount' => -25.50,
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['amount']);
    }

    /**
     * 10. Teste do listener assíncrono de notificação enfileirado.
     */
    public function test_transaction_completed_event_triggers_queued_listener(): void
    {
        Queue::fake();

        $sender = User::factory()->create();
        $receiver = User::factory()->create();

        Wallet::factory()->create(['user_id' => $sender->id, 'balance' => 200.00]);
        Wallet::factory()->create(['user_id' => $receiver->id, 'balance' => 50.00]);

        $this->actingAs($sender, 'sanctum')->postJson(route('transaction.send'), [
            'recipient_email' => $receiver->email,
            'amount' => 50.00,
        ])->assertCreated();

        Queue::assertPushed(\Illuminate\Events\CallQueuedListener::class, function ($job) {
            return $job->class === SendTransactionNotificationListener::class;
        });
    }

    /**
     * 11. Teste de recarga atômica de carteira via WalletController.
     */
    public function test_wallet_can_be_recharged_atomically(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create(['user_id' => $user->id, 'balance' => 100.00]);

        $response = $this->actingAs($user, 'sanctum')->postJson(route('wallet.update'), [
            'amount' => 250.75,
        ]);

        $response->assertOk();
        $response->assertJson([
            'message' => 'Carteira recarregada com sucesso!',
            'data' => [
                'user_id' => $user->id,
                'balance' => '350.75',
            ],
        ]);

        $this->assertEquals(350.75, (float) $wallet->fresh()->balance);
    }
}
