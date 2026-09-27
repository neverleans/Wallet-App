<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;

use App\Models\User;

class AuthController extends Controller
{
    public function show()
    {
        return view('login');
    }

    public function authenticate(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => 'Credenciais inválidas.'], 401);
            }
            return redirect()->back()->withErrors(['email' => 'Credenciais inválidas'])->withInput();
        }

        if (Auth::attempt($credentials)) {
            $user = Auth::user();
            $token = $user->createToken('api_token')->plainTextToken;

            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => 'Login realizado com sucesso!',
                    'token' => $token,
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                    ],
                ]);
            }
    
            return redirect()->route('home')->with('success', 'Login realizado com sucesso!');
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['message' => 'Credenciais inválidas.'], 401);
        }

        return redirect()->back()->withErrors(['email' => 'Credenciais inválidas'])->withInput();
    }
}