<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Login Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles authenticating users for the application and
    | redirecting them to your home screen. The controller uses a trait
    | to conveniently provide its functionality to your applications.
    |
    */

    use AuthenticatesUsers;

    /**
     * Where to redirect users after login.
     *
     * @var string
     */
    protected $redirectTo = '/';

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('guest')->except('logout');
        $this->middleware('auth')->only('logout');
    }

    /**
     * Send the user to their role's area; refuse inactive accounts and accounts with no role yet.
     *
     * @param  User  $user
     *
     * @throws ValidationException
     */
    protected function authenticated(Request $request, $user): RedirectResponse
    {
        if (! $user->isActive()) {
            $this->refuse($request, __('app.account_inactive'));
        }

        $route = $user->homeRoute();

        if ($route === null) {
            $this->refuse($request, __('app.no_role'));
        }

        $request->session()->put('field_pin_fresh_login', true);

        return redirect()->intended(route($route));
    }

    /**
     * Undo the sign-in that just happened and show why on the login form.
     *
     * @throws ValidationException
     */
    private function refuse(Request $request, string $message): never
    {
        $this->guard()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        throw ValidationException::withMessages([$this->username() => $message]);
    }

    /**
     * Go straight to the login form after signing out.
     */
    protected function loggedOut(Request $request): RedirectResponse
    {
        return redirect()->route('login');
    }
}
