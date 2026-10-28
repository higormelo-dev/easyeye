<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\{RedirectResponse, Request};
use Illuminate\Support\Facades\{Auth, Redirect, Storage, Vite};
use Inertia\{Inertia, Response as InertiaResponse};

class ProfileController extends Controller
{
    public function edit(Request $request): InertiaResponse
    {
        $user          = $request->user();
        $userPhotoPath = 'users/' . $user->id . '.jpg';

        return Inertia::render('Panel/Profile/Edit', [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('actions.edit_profile'), 'url' => '#', 'active' => true],
            ],
            'user' => [
                'id'        => (string) $user->id,
                'name'      => $user->name,
                'email'     => $user->email,
                'photo_url' => Storage::disk('public')->exists($userPhotoPath)
                    ? Storage::disk('public')->url($userPhotoPath) . '?v=' . Storage::disk('public')->lastModified($userPhotoPath)
                    : Vite::asset('resources/img/system/team.png'),
                'must_verify_email'      => $user instanceof MustVerifyEmail,
                'email_verified'         => $user->hasVerifiedEmail(),
                'has_two_factor_enabled' => $user->hasTwoFactorEnabled(),
            ],
            'urls' => [
                'update'            => route('panel.profile.update'),
                'password_update'   => route('password.update'),
                'destroy'           => route('panel.profile.destroy'),
                'send_verification' => route('verification.send'),
                'two_factor_setup'  => route('security.two-factor.setup'),
                'dashboard'         => route('panel.dashboard'),
            ],
        ]);
    }

    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->safe()->only(['name', 'email']));

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        if ($request->hasFile('photo')) {
            $request->file('photo')->storeAs('users', $request->user()->id . '.jpg', 'public');
        }

        return Redirect::route('panel.profile.edit')->with('status', 'profile-updated');
    }

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
