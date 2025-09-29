<?php

namespace App\Http\Controllers;

use App\Models\SmtpConfiguration;
use App\Http\Requests\StoreSmtpConfigurationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB; // For transaction

class SmtpConfigurationController extends Controller
{
    /**
     * Display a listing of the SMTP configurations.
     */
    public function index(): JsonResponse
    {
        $configs = SmtpConfiguration::all();

        if ($configs->isEmpty()) {
            // Return 200 status with empty data array instead of 404
            return response()->json([
                'message' => 'No SMTP configurations found.',
                'data' => [],
                'success' => true
            ], 200);
        }

        // Passwords will be decrypted by the model's accessor.
        // Consider if you want to send decrypted passwords for all configs to the frontend.
        // For an admin listing, this might be acceptable, or you might want to mask them.
        return response()->json($configs);
    }

    /**
     * Store or update the default SMTP configuration.
     */
    public function store(StoreSmtpConfigurationRequest $request): JsonResponse
    {
        $validatedData = $request->validated();
        $validatedData['is_default'] = true; // Ensure this is always the default

        // If provider_details is empty from form, ensure it's null not an empty array for DB
        if (isset($validatedData['provider_details']) && empty($validatedData['provider_details'])) {
            $validatedData['provider_details'] = null;
        }

        try {
            DB::transaction(function () use ($validatedData) {
                // Set any existing default to false
                SmtpConfiguration::where('is_default', true)->update(['is_default' => false]);

                // Update or create the new default configuration
                // We use a known ID or a specific condition if you want to ensure only one row exists for 'default'
                // For simplicity, we'll update the one that was previously default (if any) or create a new one.
                // A more robust way for a single system-wide config is to use updateOrCreate on a fixed condition.
                
                // Let's assume we are updating the one that was previously default, or creating if none existed.
                // A simpler approach for a single default config: delete old, create new.
                // Or, use updateOrCreate with a specific identifier if you only ever want one row.

                // For this example, let's find if there was an old default to update, otherwise create.
                // This logic can be refined based on whether you allow multiple named configs or just one default.
                // Given the UI, it seems like managing a single, system-wide default SMTP.

                SmtpConfiguration::updateOrCreate(
                    ['name' => $validatedData['name'] ?? 'Default System SMTP'], // Use a consistent name or a fixed ID if you only ever have one
                    $validatedData
                );
            });

            // Fetch the newly saved/updated default configuration to return it
            $newConfig = SmtpConfiguration::where('is_default', true)->first();
            return response()->json($newConfig, 200);

        } catch (\Exception $e) {
            // Log the exception $e->getMessage()
            return response()->json(['message' => 'Failed to save SMTP configuration.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Update the specified SMTP configuration in storage.
     */
    public function update(StoreSmtpConfigurationRequest $request, SmtpConfiguration $smtpConfiguration): JsonResponse
    {
        $validatedData = $request->validated();

        // If provider_details is empty from form, ensure it's null not an empty array for DB
        if (isset($validatedData['provider_details']) && empty($validatedData['provider_details'])) {
            $validatedData['provider_details'] = null;
        }

        try {
            DB::transaction(function () use ($validatedData, $smtpConfiguration) {
                // If this configuration is being set as default, unset other defaults
                if (isset($validatedData['is_default']) && $validatedData['is_default']) {
                    SmtpConfiguration::where('id', '!=', $smtpConfiguration->id)
                                     ->where('is_default', true)
                                     ->update(['is_default' => false]);
                } elseif (!isset($validatedData['is_default'])) {
                    // If 'is_default' is not in the request, ensure it doesn't change from current value
                    // Or, if you want 'is_default' to be explicitly false if not provided:
                    // $validatedData['is_default'] = false;
                    $validatedData['is_default'] = $smtpConfiguration->is_default; 
                }

                $smtpConfiguration->update($validatedData);
            });

            return response()->json($smtpConfiguration->fresh(), 200);

        } catch (\Exception $e) {
            // Log the exception $e->getMessage()
            return response()->json(['message' => 'Failed to update SMTP configuration.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Set the specified SMTP configuration as the default.
     */
    public function setDefault(SmtpConfiguration $smtpConfiguration): JsonResponse
    {
        try {
            DB::transaction(function () use ($smtpConfiguration) {
                // Set all other configurations to not be default
                SmtpConfiguration::where('id', '!=', $smtpConfiguration->id)
                                 ->where('is_default', true)
                                 ->update(['is_default' => false]);

                // Set the specified configuration as default
                if (!$smtpConfiguration->is_default) {
                    $smtpConfiguration->is_default = true;
                    $smtpConfiguration->save();
                }
            });

            return response()->json(['message' => 'SMTP configuration set as default successfully.', 'data' => $smtpConfiguration->fresh()]);
        } catch (\Exception $e) {
            // Log the exception $e->getMessage()
            return response()->json(['message' => 'Failed to set SMTP configuration as default.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Remove the specified SMTP configuration from storage.
     */
    public function destroy(SmtpConfiguration $smtpConfiguration): JsonResponse
    {
        if ($smtpConfiguration->is_default) {
            return response()->json(['message' => 'Cannot delete the default SMTP configuration.'], 400);
        }

        try {
            $smtpConfiguration->delete();
            return response()->json(['message' => 'SMTP configuration deleted successfully.']);
        } catch (\Exception $e) {
            // Log the exception $e->getMessage()
            return response()->json(['message' => 'Failed to delete SMTP configuration.', 'error' => $e->getMessage()], 500);
        }
    }
}
