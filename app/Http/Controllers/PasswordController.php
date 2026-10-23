<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class PasswordController extends Controller
{
    public function edit()
    {
        return view('account.password');
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password:web'],
            'password' => ['required', 'confirmed', 'different:current_password', Password::min(12)->letters()->numbers(), 'max:72'],
        ]);
        $user = $request->user();
        DB::transaction(function () use ($user, $data) {
            $user->password = $data['password'];
            $user->must_change_password = false;
            $user->remember_token = Str::random(60);
            $user->session_version++;
            $user->save();
            $user->tokens()->delete();
        });
        $request->session()->regenerate();
        $request->session()->put('user_version', $user->session_version);

        return redirect()->route('dashboard')->with('status', 'Password changed. Other sessions and API tokens have been revoked.');
    }
}
