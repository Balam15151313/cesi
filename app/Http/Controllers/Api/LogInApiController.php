<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Responsable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Archivo: LoginApiController.php
 * Propósito: Controlador para gestionar datos relacionados con autenticación y login.
 * Autor: José Balam González Rojas
 * Fecha de Creación: 2024-11-19
 * Última Modificación: 2024-11-27
 */

class LogInApiController extends Controller
{
    /**
     * Iniciar sesión y generar un token.
     */

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        if (!Auth::attempt($request->only('email', 'password'))) {
            return response()->json([
                'message' => 'Credenciales inválidas.',
            ], 401);
        }

        $user = Auth::user();

        if ($user->role === 'responsable') {
            $responsable = Responsable::where('responsable_usuario', $user->email)
                ->where('responsable_activacion', 1)
                ->first();

            if (!$responsable) {
                return response()->json([
                    'message' => 'Tu cuenta no está activada o no existe.',
                ], 403);
            }
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Inicio de sesión exitoso.',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
        ], 200);
    }


    /**
     * Cerrar sesión y eliminar el token.
     */
    public function logout(Request $request)
    {
        $user = Auth::user();
        if ($user) {
            $user->tokens()->delete();
        }
        $cookie = cookie('api_token', '', -1);
        Auth::logout();
        return response()->json(['message' => 'Sesión cerrada exitosamente.'], 200)->cookie($cookie);
    }
}
