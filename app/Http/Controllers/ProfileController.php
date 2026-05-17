<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Http\{RedirectResponse, Request};
use Illuminate\Support\Facades\{Auth, Redirect, Storage, Vite};
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        $user          = $request->user();
        $userPhotoPath = 'users/' . $user->id . '.jpg';

        return view('profile.edit', [
            'user'         => $user,
            'userPhotoUrl' => Storage::disk('public')->exists($userPhotoPath)
                ? Storage::disk('public')->url($userPhotoPath) . '?v=' . Storage::disk('public')->lastModified($userPhotoPath)
                : Vite::asset('resources/img/system/team.png'),
            'meta' => [
                'title'       => __('actions.edit_profile'),
                'breadcrumbs' => [
                    ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                    ['label' => __('actions.edit_profile'), 'url' => 'javascript:void(0);', 'active' => true],
                ],
            ],
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
            $request->file('photo')->storeAs('users', $request->user()->id . '.jpg', 'public');
        }

        return Redirect::route('panel.profile.edit')->with('status', 'profile-updated');
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
