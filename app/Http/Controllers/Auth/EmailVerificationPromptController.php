<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Concerns\RedirectsToHome;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmailVerificationPromptController extends Controller
{
    use RedirectsToHome;

    /**
     * Display the email verification prompt.
     */
    public function __invoke(Request $request): RedirectResponse|Response
    {
        return $request->user()->hasVerifiedEmail()
                    ? redirect()->intended($this->homeRouteFor($request->user()))
                    : Inertia::render('Auth/VerifyEmail', ['status' => session('status')]);
    }
}
