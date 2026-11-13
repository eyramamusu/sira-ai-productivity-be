<?php

namespace App\Http\Controllers;

use App\Services\PasswordResetter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

/**
 * The page that password reset emails link to. It works in any browser, so the email link
 * doesn't depend on the app being installed or on deep links being set up.
 */
class PasswordResetPageController extends Controller
{
    public function show(Request $request): View
    {
        return view('reset-password', [
            'token' => (string) $request->query('token'),
            'email' => (string) $request->query('email'),
            'done' => false,
        ]);
    }

    public function store(Request $request, PasswordResetter $resetter): View|RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $result = $resetter->reset($data['email'], $data['token'], $data['password']);
        if ($result !== true) {
            return back()->withInput($request->only('email', 'token'))->withErrors(['email' => $result]);
        }

        return view('reset-password', ['token' => '', 'email' => $data['email'], 'done' => true]);
    }
}
