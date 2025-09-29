<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSmtpConfigurationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Or use auth()->check() or similar for admin-only access
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:255',
            'driver' => 'required|string|in:smtp,sendmail,log,ses,postmark,mailgun', // Add other supported drivers if needed
            'host' => 'required|string|max:255',
            'port' => 'required|integer|min:1|max:65535',
            'username' => 'nullable|string|max:255',
            'password' => 'nullable|string|max:255', // Will be encrypted by the model
            'encryption' => 'nullable|string|in:tls,ssl,starttls',
            'from_address' => 'required|email|max:255',
            'from_name' => 'required|string|max:255',
            'provider_details' => 'nullable|array', // Validate specific keys if needed, e.g., 'provider_details.domain'
            'provider_details.mailgun_domain' => 'nullable|string|max:255', // Example for Mailgun
            'provider_details.region' => 'nullable|string|max:255', // Example for Mailgun/SES region
            'is_default' => 'sometimes|boolean',
        ];
    }
}
