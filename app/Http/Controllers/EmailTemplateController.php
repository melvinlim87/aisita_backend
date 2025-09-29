<?php

namespace App\Http\Controllers;

use App\Models\EmailTemplate;
use App\Http\Requests\StoreEmailTemplateRequest;
use App\Http\Requests\UpdateEmailTemplateRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EmailTemplateController extends Controller
{
    /**
     * Display a listing of the email templates.
     */
    public function index(): JsonResponse
    {
        // Eager load the tags relationship
        $templates = EmailTemplate::with('tags')->latest()->paginate(15);
        return response()->json($templates);
    }

    /**
     * Store a newly created email template in storage.
     */
    public function store(StoreEmailTemplateRequest $request): JsonResponse
    {
        $validatedData = $request->validated();

        try {
            $template = DB::transaction(function () use ($validatedData) {
                // Create the template
                $template = EmailTemplate::create($validatedData);

                // Handle tags if they are provided
                if (!empty($validatedData['tags'])) {
                    $tags = collect($validatedData['tags'])->map(function ($tag) {
                        return ['tag' => $tag];
                    });
                    $template->tags()->createMany($tags->all());
                }

                return $template;
            });

            // Load the tags relationship for the response
            return response()->json($template->load('tags'), 201);

        } catch (\Exception $e) {
            Log::error('Error creating email template: ' . $e->getMessage());
            return response()->json(['message' => 'Error creating email template', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Display the specified email template.
     */
    public function show(EmailTemplate $template): JsonResponse
    {
        // Eager load tags for the single template view
        return response()->json($template->load('tags'));
    }

    /**
     * Update the specified email template in storage.
     */
    public function update(UpdateEmailTemplateRequest $request, EmailTemplate $template): JsonResponse
    {
        $validatedData = $request->validated();

        try {
            DB::transaction(function () use ($validatedData, $template) {
                // Update the template's main fields
                $template->update($validatedData);

                // Handle tags if they are provided in the request
                if (isset($validatedData['tags'])) {
                    // Delete existing tags
                    $template->tags()->delete();
                    // Create new tags
                    if (!empty($validatedData['tags'])) {
                        $tags = collect($validatedData['tags'])->map(function ($tag) {
                            return ['tag' => $tag];
                        });
                        $template->tags()->createMany($tags->all());
                    }
                }
            });

            // Return the fresh template with its tags
            return response()->json($template->fresh()->load('tags'));

        } catch (\Exception $e) {
            Log::error("Error updating email template {$template->id}: " . $e->getMessage());
            return response()->json(['message' => 'Error updating email template', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Remove the specified email template from storage.
     */
    public function destroy(EmailTemplate $template): JsonResponse
    {
        // The check for campaigns using the template is still relevant
        if ($template->emailCampaigns()->exists()) {
            return response()->json(['message' => 'Cannot delete template as it is currently used by one or more campaigns.'], 400);
        }

        try {
            $template->delete(); // Deleting the template will cascade delete tags due to the foreign key constraint
            return response()->json(null, 204);
        } catch (\Exception $e) {
            Log::error("Error deleting email template {$template->id}: " . $e->getMessage());
            return response()->json(['message' => 'Error deleting email template', 'error' => $e->getMessage()], 500);
        }
    }
}
