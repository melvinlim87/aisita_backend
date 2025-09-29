<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreEmailCampaignRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Authorization handled by middleware/controller
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'subject' => 'required|string|max:255',
            'from_email' => 'required|email|max:255',
            'from_name' => 'required|string|max:255',
            'html_content' => 'required|string',
            'email_template_id' => 'nullable|integer|exists:email_templates,id',
            'status' => 'sometimes|in:draft,scheduled,archived', // Default is 'draft' if not provided
            'all_users' => 'sometimes|boolean',
            'user_ids' => 'sometimes|array|nullable',
            'user_ids.*' => 'exists:users,id',
        ];
    }
}
