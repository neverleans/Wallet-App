# Digital Wallet Core API

<p align="center">
  <img src="https://img.shields.io/badge/PHP-8.3-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP 8.3" />
  <img src="https://img.shields.io/badge/Laravel-11.x-FF2D20?style=for-the-badge&logo=laravel&logoColor=white" alt="Laravel 11" />
  <img src="https://img.shields.io/badge/MySQL-8.0-4479A1?style=for-the-badge&logo=mysql&logoColor=white" alt="MySQL 8.0" />
  <img src="https://img.shields.io/badge/Redis-7-DC382D?style=for-the-badge&logo=redis&logoColor=white" alt="Redis 7" />
  <img src="https://img.shields.io/badge/Docker-Enabled-2496ED?style=for-the-badge&logo=docker&logoColor=white" alt="Docker" />
  <img src="https://img.shields.io/badge/Tests-100%25%20Passing-success?style=for-the-badge&logo=phpunit&logoColor=white" alt="Tests" />
  <img src="https://img.shields.io/badge/Standard-Fintech%20ACID-blueviolet?style=for-the-badge" alt="Fintech Standard" />
</p>

---

## 📌 Visão Geral da Arquitetura & Problema de Negócio

A **Digital Wallet Core API** é uma engine financeira de alta integridade desenvolvida em **Laravel 11** e **PHP 8.3**, desenhada para processar operações de saldo e transferências peer-to-peer (P2P) entre carteiras digitais.

Em sistemas financeiros, vulnerabilidades de concorrência podem causar danos críticos:
1. **Condições de Corrida (Race Conditions / Double-Spending):** Duas requisições simultâneas de débito lêem o mesmo saldo anterior à dedução, permitindo saques ou transferências duplicadas que deixam a carteira com saldo negativo.
2. **Deadlocks em Concorrência Cruzada:** Quando o Usuário A envia dinheiro para o Usuário B ao mesmo tempo em que o Usuário B envia para o Usuário A, bloqueios adquiridos em ordem arbitrária causam travamento mútuo no banco de dados (*deadlock*).
3. **Retentativas e Falhas de Rede:** Cliques múltiplos pelo cliente ou reenvios automáticos em caso de timeout de rede sem idempotência causam cobranças duplicadas.
4. **Erros de Ponto Flutuante:** Cálculos com tipos `float` sofrem de imprecisão binária segundo o padrão IEEE 754.

### 🛡️ Soluções de Engenharia Aplicadas
- **Transações ACID & Bloqueio Pessimista (`lockForUpdate`):** Garantia de isolamento rigoroso durante a leitura e dedução do saldo.
- **Prevenção Determinística de Deadlocks:** Ordenação ascendente dos identificadores de carteira antes da aquisição dos locks de linha no banco de dados.
- **Chave de Idempotência (`Idempotency-Key`):** Suporte nativo a chave de idempotência via HTTP Header, impedindo débitos duplicados e retornando respostas salvas (*idempotent replay*).
- **Aritmética de Precisão Arbitrária (`BCMath`):** Todas as operações matemáticas de débito, crédito e comparação utilizam `bcsub`, `bcadd` e `bccomp` com precisão decimal exata.
- **Arquitetura em Camadas (Clean Code):** Separação de responsabilidades em Form Requests, Services (`TransferMoneyService`), Custom Exceptions semânticas, Domain Events e Listeners assíncronos via fila (Redis).

---

## 🔄 Fluxo de Transferência com ACID e Idempotência

```mermaid
sequenceDiagram
    autonumber
    actor Client as Cliente / App
    participant Controller as TransactionController
    participant Service as TransferMoneyService
    participant Cache as Redis (Atomic Lock)
    participant DB as Banco de Dados (MySQL)
    participant Queue as Worker de Fila (Redis)

    Client->>Controller: POST /api/transfer (Idempotency-Key: uuid)
    Controller->>Service: execute(sender, recipientEmail, amount, key)
    
    rect rgb(240, 248, 255)
    Note over Service,Cache: 1. Verificação de Idempotência
    Service->>DB: Consulta transação existente por Idempotency-Key
    alt Transação já existe (Replay)
        DB-->>Service: Registro encontrado
        Service-->>Controller: Retorna transação original (is_idempotent_replay: true)
        Controller-->>Client: 200 OK (Header: Idempotent-Replay: true)
    end
    end

    rect rgb(255, 250, 240)
    Note over Service,DB: 2. Bloqueio Pessimista & Isolamento ACID
    Service->>Cache: Bloqueio atômico de chave de idempotência
    Service->>DB: Início de DB::transaction()
    Service->>DB: Ordena Wallet IDs (min, max) para evitar DEADLOCK
    Service->>DB: SELECT ... FOR UPDATE (Lock das carteiras ordenadas)
    DB-->>Service: Retorna registros bloqueados com saldo atualizado
    
    alt Saldo Insuficiente
        Service-->>Controller: Lança InsufficientBalanceException
        Controller-->>Client: 422 Unprocessable Entity
    end

    Service->>DB: UPDATE sender wallet (bcsub: decrementa saldo)
    Service->>DB: UPDATE receiver wallet (bcadd: credita saldo)
    Service->>DB: INSERT into transactions (amount, status, idempotency_key)
    Service->>DB: DB::commit()
    end

    rect rgb(240, 255, 240)
    Note over Service,Queue: 3. Mensageria Assíncrona
    Service->>Queue: Dispatch TransactionCompletedEvent (ShouldQueue)
    Queue-->>Queue: SendTransactionNotificationListener processa notificação
    end

    Service-->>Controller: Transação executada com sucesso
    Controller-->>Client: 201 Created (JSON com payload completo)
```

---

## 🏛️ Estrutura Arquitetural do Projeto

```text
app/
├── DTOs/
├── Events/
│   └── TransactionCompletedEvent.php       # Evento de domínio disparado pós-commit
├── Exceptions/
│   ├── IdempotencyConflictException.php    # HTTP 409 (Idempotência com payload conflitante)
│   ├── InactiveUserException.php           # HTTP 403 (Usuário inativo)
│   ├── InsufficientBalanceException.php    # HTTP 422 (Saldo insuficiente)
│   ├── SelfTransferException.php           # HTTP 422 (Transferência para si mesmo)
│   └── WalletNotFoundException.php         # HTTP 404 (Carteira inexistente)
├── Http/
│   ├── Controllers/
│   │   ├── Auth/AuthController.php         # Login Web e API (Sanctum Tokens)
│   │   ├── TransactionController.php       # Controller enxuto para transferências
│   │   └── WalletController.php            # Recarga atômica de saldo
│   └── Requests/
│       └── TransferMoneyRequest.php        # Validação estrita e extração de headers
├── Listeners/
│   └── SendTransactionNotificationListener.php # Worker assíncrono enfileirado
├── Models/
│   ├── Transaction.php                     # Model de transação com chave única
│   ├── User.php                            # Usuário com flag is_active e carteira
│   └── Wallet.php                          # Carteira com casts decimais
└── Services/
    └── TransferMoneyService.php            # Core Engine: ACID, Locks e Idempotência
```

---

## 📡 Endpoints da API

### 1. Autenticação (Obtenção de Token Sanctum)
- **Rota:** `POST /api/login`
- **Headers:** `Accept: application/json`, `Content-Type: application/json`

**Payload de Requisição:**
```json
{
  "email": "lucas@example.com",
  "password": "secret_password"
}
```

**Resposta de Sucesso (`200 OK`):**
```json
{
  "message": "Login realizado com sucesso!",
  "token": "1|nO1K...sample_token",
  "user": {
    "id": 1,
    "name": "Lucas Silva",
    "email": "lucas@example.com"
  }
}
```

---

### 2. Transferência Financeira (P2P Transfer)
- **Rota:** `POST /api/transfer`
- **Headers:**
  - `Authorization: Bearer <SEU_TOKEN>`
  - `Accept: application/json`
  - `Content-Type: application/json`
  - `Idempotency-Key: 9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d` *(Obrigatório para idempotência)*

**Payload de Requisição:**
```json
{
  "recipient_email": "destino@example.com",
  "amount": 250.00
}
```

**Resposta de Sucesso (`201 Created`):**
```json
{
  "message": "Transferência realizada com sucesso!",
  "data": {
    "id": 42,
    "sender_id": 1,
    "receiver_id": 2,
    "amount": "250.00",
    "status": "completed",
    "idempotency_key": "9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d",
    "created_at": "2026-09-27T15:45:00.000000Z"
  },
  "is_idempotent_replay": false
}
```

**Resposta de Reprodução Idempotente (`200 OK` com Header `Idempotent-Replay: true`):**
```json
{
  "message": "Transação recuperada com sucesso (reprodução idempotente).",
  "data": {
    "id": 42,
    "sender_id": 1,
    "receiver_id": 2,
    "amount": "250.00",
    "status": "completed",
    "idempotency_key": "9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d",
    "created_at": "2026-09-27T15:45:00.000000Z"
  },
  "is_idempotent_replay": true
}
```

**Respostas de Erro Semânticas:**
- `422 Unprocessable Entity` (Saldo insuficiente):
  ```json
  {
    "error": "INSUFFICIENT_BALANCE",
    "message": "Saldo insuficiente, recarregue sua carteira!"
  }
  ```
- `422 Unprocessable Entity` (Auto-transferência):
  ```json
  {
    "error": "SELF_TRANSFER_NOT_ALLOWED",
    "message": "Não é permitido realizar transferências para a própria conta."
  }
  ```
- `403 Forbidden` (Conta inativa):
  ```json
  {
    "error": "INACTIVE_USER",
    "message": "Sua conta está inativa. Operação não autorizada."
  }
  ```
- `409 Conflict` (Chave reutilizada com payload divergente):
  ```json
  {
    "error": "IDEMPOTENCY_CONFLICT",
    "message": "Chave de idempotência reutilizada com parâmetros divergentes."
  }
  ```

---

### 3. Recarga de Carteira
- **Rota:** `POST /api/wallet`
- **Headers:** `Authorization: Bearer <SEU_TOKEN>`, `Accept: application/json`

**Payload de Requisição:**
```json
{
  "amount": 500.00
}
```

**Resposta de Sucesso (`200 OK`):**
```json
{
  "message": "Carteira recarregada com sucesso!",
  "data": {
    "wallet_id": 1,
    "user_id": 1,
    "balance": "500.00",
    "updated_at": "2026-09-27T15:46:10.000000Z"
  }
}
```

---

## 🐳 Execução Local com Docker

A aplicação conta com um ambiente pronto e orquestrado via `docker-compose.yml`, englobando:
- **App:** PHP 8.3 CLI com extensões `bcmath`, `pdo_mysql`, `pcntl` e extensão PECL `redis`.
- **Queue Worker:** Worker em segundo plano processando a fila de notificações no Redis.
- **MySQL 8.0:** Banco de dados relacional com volume persistente e checagem de saúde.
- **Redis 7:** Armazenamento em memória para locks de concorrência, cache e filas.

### Passo a Passo

1. **Clonar o Repositório:**
   ```bash
   git clone https://github.com/neverleans/Wallet-App.git
   cd Wallet-App
   ```

2. **Inicializar o Ambiente (Comando Único):**
   Execute o script automatizado:
   ```bash
   chmod +x setup.sh
   ./setup.sh
   ```
   *Ou utilizando o `Makefile`:*
   ```bash
   make setup
   ```

3. **Comandos Úteis do `Makefile`:**
   | Comando | Descrição |
   |---|---|
   | `make up` | Inicia todos os containers em background |
   | `make down` | Para e remove os containers |
   | `make logs` | Visualiza os logs em tempo real da aplicação web |
   | `make queue-logs` | Visualiza os logs do processamento assíncrono de notificações |
   | `make migrate` | Executa as migrações no banco MySQL |
   | `make fresh-migrate`| Reseta o banco de dados e executa seeds |
   | `make test` | Executa a suíte de testes automatizados dentro do container |
   | `make shell` | Abre o terminal interativo no container da aplicação |

---

## 🧪 Testes Automatizados

A suíte de testes cobre integralmente os requisitos de consistência, concorrência e integridade financeira:

1. **Transferência Bem-Sucedida:** Valida a integridade dos saldos de ambos os lados e o registro em banco.
2. **Saldo Insuficiente:** Impede saques a descoberto e garante que nenhuma transação seja gravada.
3. **Bloqueio de Auto-Transferência:** Impede que o usuário envie fundos para sua própria conta.
4. **Rollback Atômico em Exceções:** Força falha catastrófica no meio da execução para comprovar que nenhuma alteração de saldo é commitada no banco.
5. **Garantia de Idempotência:** Dispara requisições com a mesma chave e valida que os saldos não sofrem dedução dupla.
6. **Conflito de Idempotência:** Rejeita requisições que alteram parâmetros usando uma chave já utilizada.
7. **Validações de Usuário Inativo:** Impede remetente ou destinatário desativados de operarem.
8. **Validação de Limites de Valor:** Bloqueia valores negativos, zerados ou formatos inválidos.
9. **Disparo de Evento & Fila:** Valida que `TransactionCompletedEvent` e o listener `SendTransactionNotificationListener` são enfileirados corretamente.
10. **Recarga Atômica de Carteira:** Valida recarga concorrente e bloqueios de atualização.

Para rodar todos os testes localmente:
```bash
php artisan test
```

Para rodar via Docker:
```bash
make test
```

---

## 📄 Licença

Este projeto está sob a licença [MIT](LICENSE).
