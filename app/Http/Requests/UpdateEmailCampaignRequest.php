<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEmailCampaignRequest extends FormRequest
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
            'name' => 'sometimes|required|string|max:255',
            'subject' => 'sometimes|required|string|max:255',
            'from_email' => 'sometimes|required|email|max:255',
            'from_name' => 'sometimes|required|string|max:255',
            'html_content' => 'sometimes|required|string',
            'email_template_id' => 'nullable|integer|exists:email_templates,id',
            'status' => 'sometimes|required|in:draft,scheduled,archived',
            'scheduled_at' => 'nullable|date|after_or_equal:now',
            'all_users' => 'sometimes|boolean',
            'user_ids' => 'sometimes|array|nullable',
            'user_ids.*' => 'exists:users,id',
        ];
    }
}
