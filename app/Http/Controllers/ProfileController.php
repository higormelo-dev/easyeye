<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Inertia\{Inertia, Response as InertiaResponse};

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): InertiaResponse
    {
        $user          = $request->user();
        $userPhotoPath = 'system/images/users/' . $user->id . '.jpg';

        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail
                && ! $user->hasVerifiedEmail(),
            'userPhotoUrl'   => file_exists(public_path($userPhotoPath))
                ? asset($userPhotoPath) . '?v=' . filemtime(public_path($userPhotoPath))
                : asset('system/images/team.png'),
            'profileUrl'     => route('panel.profile.update'),
            'passwordUrl'    => route('password.update'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->safe()->only(['name', 'email']));

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        if ($request->hasFile('photo')) {
            $dir = public_path('system/images/users');
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $request->file('photo')->move($dir, $request->user()->id . '.jpg');
        }

        return Redirect::route('panel.profile.edit')->with('success', __('actions.profile.saved'));
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
