<?php

declare(strict_types=1);

namespace Pushery\FortifyHardening\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Fortify;

/**
 * The registration rules of Fortify's own stub, without the `unique` rule on the address, and with
 * a refusal of a carriage return or a line feed in it.
 *
 * Validation runs before the address is looked up, for a free address and a taken one alike, so a
 * deliberately invalid field cannot tell them apart. A `unique` rule would answer the very question
 * the registration keeps closed. Laravel's `email` rule refuses a carriage return and a line feed
 * from 13.10.0 on, and the address goes into the headers of the mails a registration sends, so the
 * refusal is a rule of its own here, for every Laravel 13 this package supports. If your
 * registration takes more fields, extend this class or point `fortify-hardening.registration.request`
 * at your own form request, and keep its rules the ones your `CreatesNewUsers` action checks.
 */
class RegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string|ValidationRule|Password>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            Fortify::email() => ['required', 'string', 'email', 'not_regex:/[\r\n]/', 'max:255'],
            'password' => ['required', 'string', Password::default(), 'confirmed'],
        ];
    }
}
