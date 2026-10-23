<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            // Which sign-in screen sent this: the applicant portal, the admission office or the clinic
            'portal' => ['required', 'in:applicant,office,clinic'],
        ];
    }
}
