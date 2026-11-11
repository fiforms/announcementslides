<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Widgets\WidgetInstaller;
use App\Services\Widgets\WidgetPackageException;
use App\Support\FirstRunSetup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * First-run wizard: creates the first admin and installs the bundled widgets.
 * Reachable only while the users table is empty (EnsureSetupIsOpen), and, when
 * SETUP_TOKEN is set, only with that token.
 */
class SetupController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Setup/Index', [
            'tokenRequired' => FirstRunSetup::tokenRequired(),
            'widgets'       => $this->availableWidgets(),
        ]);
    }

    public function store(Request $request, WidgetInstaller $installer): RedirectResponse
    {
        $rules = [
            'name'            => 'required|string|max:255',
            'email'           => 'required|string|lowercase|email|max:255',
            'password'        => ['required', 'confirmed', Rules\Password::defaults()],
            'install_widgets' => 'boolean',
        ];
        if (FirstRunSetup::tokenRequired()) {
            $rules['token'] = 'required|string';
        }
        $data = $request->validate($rules);

        if (FirstRunSetup::tokenRequired() && ! hash_equals((string) config('setup.token'), (string) $data['token'])) {
            throw ValidationException::withMessages(['token' => 'That setup token is not correct.']);
        }

        // Two simultaneous submissions must not both create an admin: take a
        // lock, then re-check that there still are no users.
        $user = Cache::lock('first-run-setup', 15)->block(10, function () use ($data) {
            if (! FirstRunSetup::needed()) {
                return null;
            }

            $user = User::create([
                'name'     => $data['name'],
                'email'    => $data['email'],
                'password' => Hash::make($data['password']),
                'role'     => 'admin',
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();

            return $user;
        });

        abort_if($user === null, 404);

        Auth::login($user);
        $request->session()->regenerate();

        $flash = ['success' => 'Setup complete. You are signed in as the first admin.'];
        if ($request->boolean('install_widgets')) {
            [$installed, $failed] = $this->installWidgets($installer, $user);
            if ($failed) {
                $flash['error'] = 'These widgets could not be installed: '.implode(', ', $failed).'. You can install them from Admin → Widgets.';
            }
        }

        return redirect()->route('admin.dashboard')->with($flash);
    }

    /** @return list<string> bundled widget folders that are present in this checkout/image */
    private function availableWidgets(): array
    {
        return array_values(array_filter(
            config('setup.widgets'),
            fn (string $slug) => is_dir(resource_path("widgets/{$slug}")),
        ));
    }

    /** @return array{0: list<string>, 1: list<string>} installed and failed widget folders */
    private function installWidgets(WidgetInstaller $installer, User $by): array
    {
        $installed = $failed = [];

        foreach ($this->availableWidgets() as $slug) {
            try {
                $installer->installDirectory(resource_path("widgets/{$slug}"), $by);
                $installed[] = $slug;
            } catch (WidgetPackageException) {
                $failed[] = $slug;
            }
        }

        return [$installed, $failed];
    }
}
