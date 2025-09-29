<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Define the mapping of old price IDs to new price IDs
        $priceIdMappings = [
            // Monthly plans
            'price_1RglWc2M8t4BtQyfNHU50BxD' => 'price_1RkiEe2MDBrgqcCA0Jxhk1Rk', // Basic Monthly
            'price_1RglX22M8t4BtQyfKJX7kOSD' => 'price_1RkiFF2MDBrgqcCAN3oY0l3I', // Pro Monthly
            'price_1RglXQ2M8t4BtQyf0MXagSJ6' => 'price_1RkiFq2MDBrgqcCATo5wJSbT', // Enterprise Monthly
            
            // Yearly plans
            'price_1RglY62M8t4BtQyfGdkjDJtd' => 'price_1RkiGS2MDBrgqcCAPu1ILnrx', // Basic Annual
            'price_1RglYP2M8t4BtQyftYpip03A' => 'price_1RkiH32MDBrgqcCAyJTES8hR', // Pro Annual
            'price_1RglYo2M8t4BtQyfT6wD1saP' => 'price_1RkiHb2MDBrgqcCAHx1ADJn9'  // Enterprise Annual
        ];

        // Update each plan with its new stripe_price_id
        foreach ($priceIdMappings as $oldPriceId => $newPriceId) {
            DB::table('plans')
                ->where('stripe_price_id', $oldPriceId)
                ->update(['stripe_price_id' => $newPriceId]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Define the mapping of new price IDs to old price IDs (reverse of the above)
        $priceIdMappings = [
            // Monthly plans
            'price_1RkiEe2MDBrgqcCA0Jxhk1Rk' => 'price_1RglWc2M8t4BtQyfNHU50BxD', // Basic Monthly
            'price_1RkiFF2MDBrgqcCAN3oY0l3I' => 'price_1RglX22M8t4BtQyfKJX7kOSD', // Pro Monthly
            'price_1RkiFq2MDBrgqcCATo5wJSbT' => 'price_1RglXQ2M8t4BtQyf0MXagSJ6', // Enterprise Monthly
            
            // Yearly plans
            'price_1RkiGS2MDBrgqcCAPu1ILnrx' => 'price_1RglY62M8t4BtQyfGdkjDJtd', // Basic Annual
            'price_1RkiH32MDBrgqcCAyJTES8hR' => 'price_1RglYP2M8t4BtQyftYpip03A', // Pro Annual
            'price_1RkiHb2MDBrgqcCAHx1ADJn9' => 'price_1RglYo2M8t4BtQyfT6wD1saP'  // Enterprise Annual
        ];

        // Restore each plan with its old stripe_price_id
        foreach ($priceIdMappings as $newPriceId => $oldPriceId) {
            DB::table('plans')
                ->where('stripe_price_id', $newPriceId)
                ->update(['stripe_price_id' => $oldPriceId]);
        }
    }
};
