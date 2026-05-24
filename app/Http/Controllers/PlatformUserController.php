<?php

namespace App\Http\Controllers;

use App\Models\PlatformUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class PlatformUserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): View
    {
        $users = PlatformUser::orderBy('created_at', 'desc')->paginate(10);

        return view('app', [
            'users' => $users,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): View
    {
        return view('app');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:master.platform_users,email'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
        ], [
            'name.min' => 'The name must be at least 2 characters.',
            'email.unique' => 'This email address is already in use.',
            'password.confirmed' => 'The password confirmation does not match.',
        ]);
        $validated['email'] = strtolower(trim($validated['email']));
        $validated['password'] = Hash::make($validated['password']);

        try {
            PlatformUser::create($validated);

            return redirect()->route('platform-users.index')->with('success', 'Platform User created successfully.');
        } catch (\Exception $e) {
            return redirect()->back()->withInput()->with('error', 'Failed to create user. Please try again.');
        }
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(PlatformUser $platformUser): View
    {
        return view('app', [
            'user' => $platformUser,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, PlatformUser $platformUser)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('master.platform_users', 'email')->ignore($platformUser->id),
            ],
            'password' => ['nullable', 'string', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
        ], [
            'name.min' => 'The name must be at least 2 characters.',
            'email.unique' => 'This email address is already in use.',
            'password.confirmed' => 'The password confirmation does not match.',
        ]);

        $validated['email'] = strtolower(trim($validated['email']));

        if (! empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        try {
            $platformUser->update($validated);

            return redirect()->route('platform-users.index')->with('success', 'Platform User updated successfully.');
        } catch (\Exception $e) {
            return redirect()->back()->withInput()->with('error', 'Failed to update user. Please try again.');
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(PlatformUser $platformUser)
    {
        try {
            $platformUser->delete();

            return redirect()->back()->with('success', 'Platform User deleted successfully.');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Failed to delete user. Please try again.');
        }
    }
}
