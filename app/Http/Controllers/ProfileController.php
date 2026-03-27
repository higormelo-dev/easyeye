<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        $user = $request->user();
        $userPhotoPath = 'system/images/users/' . $user->id . '.jpg';

        return view('profile.edit', [
            'user'         => $user,
            'userPhotoUrl' => file_exists(public_path($userPhotoPath))
                ? asset($userPhotoPath) . '?v=' . filemtime(public_path($userPhotoPath))
                : asset('system/images/team.png'),
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
            $dir = public_path('system/images/users');
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $request->file('photo')->move($dir, $request->user()->id . '.jpg');
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
