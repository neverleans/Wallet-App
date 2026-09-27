<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

class LoginTest extends TestCase
{
   use RefreshDatabase;
   public function test_a_user_can_login_with_valid_credentials(): void
   {
       // Cria um usuário
       $user = User::factory()->create([
           'password' => Hash::make('password123'), // define a senha
       ]);

       // Realiza o login com as credenciais corretas
       $response = $this->post('/login', [
           'email' => $user->email,
           'password' => 'password123',
       ]);

       // Verifica se o redirecionamento está correto
       $response->assertRedirect(route('home'));

       // Verifica se o usuário está autenticado
       $this->assertAuthenticatedAs($user);
   }

   public function test_a_user_cannot_login_with_invalid_credentials(): void
   {
       // Tenta realizar o login com credenciais inválidas
       $response = $this->post('/login', [
           'email' => 'invalid@example.com',
           'password' => 'wrongpassword',
       ]);

       // Verifica se a resposta não redireciona para /home
       $response->assertSessionHasErrors();
       $this->assertGuest();
   }
}
