<?php

namespace App\Actions\Register;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

class CreateUserAction
{
    public function execute(array $data): User
    {
        return User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => Hash::make($data['password']),
            'locale'   => $data['locale'] ?? app()->getLocale(),
            // WhatsApp do responsável (só dígitos, DDD + número) — verificado
            // por código OTP após o registro (PhoneVerificationService).
            'phone' => $data['company_phone'] ?? null,
        ]);
    }
}
