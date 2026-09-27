<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TransferMoneyRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'recipient_email' => ['required', 'string', 'email', 'max:255', 'exists:users,email'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
        ];
    }

    /**
     * Custom messages for validation errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'recipient_email.required' => 'O e-mail do destinatário é obrigatório.',
            'recipient_email.email' => 'Informe um endereço de e-mail válido.',
            'recipient_email.exists' => 'Este usuário não existe, tente outro email!',
            'amount.required' => 'O valor da transferência é obrigatório.',
            'amount.numeric' => 'O valor deve ser numérico.',
            'amount.gt' => 'O valor da transferência deve ser maior que zero.',
            'amount.decimal' => 'O valor pode ter no máximo duas casas decimais.',
        ];
    }

    public function recipientEmail(): string
    {
        return (string) $this->validated('recipient_email');
    }

    public function amount(): float
    {
        return (float) $this->validated('amount');
    }

    public function idempotencyKey(): ?string
    {
        return $this->header('Idempotency-Key')
            ?? $this->header('X-Idempotency-Key')
            ?? $this->input('idempotency_key');
    }
}
