<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class AuthController extends Controller
{
    public function showLoginForm()
    {
        return view('admin.auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            return back()->withErrors([
                'email' => 'Las credenciales son incorrectas.',
            ])->withInput($request->only('email'));
        }

        // Verificar que el usuario tenga rol de super_master o master
        if ($user->role !== 'super_master' && $user->role !== 'master') {
            return back()->withErrors([
                'email' => 'No tienes permisos para acceder al panel de administración.',
            ])->withInput($request->only('email'));
        }

        $request->session()->regenerate();

        Auth::login($user);

        $request->session()->put('user_role', $user->role);

        return redirect()->intended(route('admin.resultados.index'));
    }

    public function logout(Request $request)
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
