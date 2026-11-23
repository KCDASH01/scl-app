<?php

namespace App\Services;

use App\Models\CompanySettings;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class AuthService
{
    public function login(string $email, string $password): array
    {
        $user = User::where('email', $email)->first();

        if (! $user || ! $user->password || ! Hash::check($password, $user->password)) {
            throw new \InvalidArgumentException('Invalid credentials.');
        }

        if (! $user->is_active) {
            throw new \InvalidArgumentException('Your account is inactive.');
        }

        $company = CompanySettings::current();

        if (! $company->is_active) {
            throw new \InvalidArgumentException('This CRM is currently inactive.');
        }

        if (! $user->isSuperAdmin()) {
            $user->loadMissing('branch');
            if (! $user->branch_id || ! $user->branch?->is_active) {
                throw new \InvalidArgumentException('This branch is currently inactive.');
            }
        }

        $user->loadMissing(['branch', 'manager']);

        $token = $user->createToken('auth_token')->plainTextToken;

        return [
            'user'  => $user,
            'token' => $token,
        ];
    }

    public function logout(User $user): void
    {
        $user->tokens()->delete();
    }

    public function sendResetLink(string $email): string
    {
        return Password::sendResetLink(['email' => $email]);
    }

    public function resetPassword(array $data): string
    {
        return Password::reset(
            $data,
            function (User $user, string $password) {
                $user->forceFill([
                    'password'       => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );
    }
}
