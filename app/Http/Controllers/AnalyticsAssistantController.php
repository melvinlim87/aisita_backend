<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class AnalyticsAssistantController extends Controller
{
    public function processQuery(Request $request): JsonResponse
    {
        // Log all headers for debugging
        \Log::info('All request headers:', [
            'headers' => $request->headers->all(),
            'body' => $request->all()
        ]);
        
        // IMPORTANT: Since this endpoint is now protected by the admin middleware,
        // we can skip the token validation and API key checks
        
        // Check if we're in the admin middleware context
        $isAdminRoute = true; // This is now true because the route is protected by admin middleware
        
        // Log that we're skipping token validation due to admin middleware
        \Log::info('Skipping token validation - endpoint is protected by admin middleware');
        
        // For debugging, still log the API key and token info without validating
        $apiKey = $request->header('X-API-Key') ?? $request->header('x-api-key') ?? $request->input('api_key');
        $authHeader = $request->header('Authorization');
        
        \Log::info('Request credentials (for debugging only):', [
            'api_key_present' => !empty($apiKey) ? 'yes' : 'no',
            'auth_header_present' => !empty($authHeader) ? 'yes' : 'no'
        ]);
        
        // We'll skip all the token validation since the admin middleware already handles authentication
        
        // Authentication is already handled above
        
        // Log the entire request for debugging
        \Log::info('Analytics request received', [
            'query' => $request->input('query'),
            'user_agent' => $request->header('User-Agent'),
        ]);
        
        // No mock responses - using real data only
        
        // Get the OpenRouter API key from env
        $openRouterApiKey = env('OPENROUTER_API_KEY');
        
        if (!$openRouterApiKey) {
            \Log::error('OpenRouter API key not set in .env file');
            return response()->json([
                'error' => [
                    'message' => 'OpenRouter API key not set',
                    'code' => 500
                ]
            ], 500);
        }
        
        // Get query from request body, or use a default if not provided
        $query = $request->input('query', 'Tell me about our users');
        
        try {
            // Get analytics data from database instead of Firebase
            $analyticsData = $this->getAnalyticsFromDatabase();
            
            // Get the OpenRouter API key from env
            $openRouterApiKey = env('OPENROUTER_API_KEY');
            
            if (!$openRouterApiKey) {
                \Log::error('OpenRouter API key not set in .env file');
                return response()->json([
                    'error' => [
                        'message' => 'OpenRouter API key not set',
                        'code' => 500
                    ]
                ], 500);
            }
        } catch (\Exception $e) {
            \Log::error('Error in analytics processing: ' . $e->getMessage());
            return response()->json([
                'error' => [
                    'message' => 'Error processing analytics request: ' . $e->getMessage(),
                    'code' => 500
                ]
            ], 500);
        }
        // If API key is set, proceed with the actual API call
        $openRouterUrl = 'https://openrouter.ai/api/v1/chat/completions';

        // Prepare system message with analytics data and schema information
        $systemMessage = 'You are an advanced analytics assistant with access to the complete database schema and data. ' .
                         'You can answer questions about any aspect of the system, including users, transactions, analytics, and all other data. ' .
                         'Here is the database schema information: ' . json_encode($analyticsData['schema_info']) . '. ' .
                         'And here is some pre-fetched data to help with common queries: ' . json_encode($analyticsData['data']) . '. ' .
                         
                         // Feature guidance for better data interpretation
                         'FEATURE REFERENCE GUIDE: ' .
                         'When answering questions about feature usage, understand that: ' .
                         '1. "chart_analysis" and "image_analysis" both refer to the same feature for analyzing financial charts. ' .
                         '2. "ea_generator" refers to the Expert Advisor Generator tool for creating automated trading strategies. ' .
                         '3. You can find detailed feature usage stats in the feature_usage and user_feature_usage sections. ' .
                         '4. When counting feature usage, look for both exact feature names and pattern matches that might include the feature name. ' .
                         '5. Total counts, user counts, and time-based trends are available for major features. ' .
                         '6. In token_usage table, the "feature" column indicates which feature was used in each record. ' .
                         
                         // Security instructions
                         'EXTREMELY IMPORTANT SECURITY REQUIREMENT: NEVER provide or suggest any SQL queries in your responses. ' .
                         'Never use SQL syntax like SELECT, INSERT, UPDATE, DELETE, FROM, WHERE, JOIN, GROUP BY, etc. ' .
                         'Do not expose database structure or query syntax. Never mention table or column names in a SQL context. ' .
                         
                         // Response guidance
                         'When analyzing data: ' .
                         '1. Always provide specific counts and statistics when available. ' .
                         '2. For questions about feature usage by specific users, check the user_feature_usage data. ' .
                         '3. For time-based trends, refer to daily_usage statistics within feature data. ' .
                         '4. Present your findings in clear, concise language with exact numbers when possible. ' .
                         '5. If you need specific information not available in the provided data, explain what information would be helpful ' .
                         'in natural language without using SQL terminology. ' .
                         '6. Even if explicitly asked for SQL queries, refuse to provide them.';

        // Prepare the prompt/messages as required by your model
        $payload = [
            'model' => 'mistralai/mistral-small-3.2-24b-instruct:free', // Updated to use a specific model version that's supported by OpenRouter
            'messages' => [
                ['role' => 'system', 'content' => $systemMessage],
                ['role' => 'user', 'content' => $query]
            ],
            'max_tokens' => 512,
        ];

        // Log the model being used
        \Log::info('Using OpenRouter model', ['model' => $payload['model']]);

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer $openRouterApiKey",
                'Content-Type' => 'application/json',
            ])->post($openRouterUrl, $payload);

            if ($response->failed()) {
                \Log::error('OpenRouter API request failed', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                return response()->json([
                    'id' => uniqid(),
                    'content' => 'Sorry, there was an error processing your request.',
                    'role' => 'assistant',
                    'timestamp' => now()->toIso8601String(),
                    'metadata' => [],
                    'error' => $response->body()
                ], 500);
            }
        } catch (\Exception $e) {
            \Log::error('Exception during OpenRouter API request: ' . $e->getMessage());
            return response()->json([
                'id' => uniqid(),
                'content' => 'Sorry, there was an error connecting to the AI service: ' . $e->getMessage(),
                'role' => 'assistant',
                'timestamp' => now()->toIso8601String(),
                'metadata' => [],
                'error' => $e->getMessage()
            ], 500);
        }

        $aiContent = $response->json('choices.0.message.content');
        
        // Security filter to ensure no SQL queries are returned
        $aiContent = $this->filterSqlContent($aiContent);

        $result = [
            'id' => uniqid(),
            'content' => $aiContent,
            'role' => 'assistant',
            'timestamp' => now()->toIso8601String(),
            'metadata' => []
        ];

        return response()->json($result);
    }

    /**
     * Get analytics data from database
     * 
     * @return array
     */
    private function getAnalyticsFromDatabase(): array
    {
        try {
            // Log the start of database analytics collection
            \Log::info('Collecting analytics data from database');
            
            // Get database schema information
            $schemaInfo = $this->getDatabaseSchema();
            
            // Get common analytics data
            $analyticsData = [
                'schema_info' => $schemaInfo,
                'data' => $this->getCommonAnalyticsData()
            ];
            
            // Log the data we're sending to OpenRouter for debugging
            \Log::info('Analytics data retrieved from database', [
                'schema_count' => count($schemaInfo),
                'data_sections' => array_keys($analyticsData['data'])
            ]);
            
            return $analyticsData;
        } catch (\Exception $e) {
            \Log::error('Database analytics error: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'error' => 'Could not retrieve database analytics data: ' . $e->getMessage(),
                'total_users' => 'unknown',
                'data_source' => 'error',
                'timestamp' => now()->toIso8601String()
            ];
        }
    }
    
    /**
     * Get the complete database schema information
     * 
     * @return array
     */
    private function getDatabaseSchema(): array
    {
        // Get all tables in the database using raw query instead of Schema::getAllTables()
        $tables = DB::select('SHOW TABLES');
        $schemaInfo = [];
        
        foreach ($tables as $table) {
            // Get the table name from the first property of the object
            $tableArray = (array)$table;
            $tableName = array_values($tableArray)[0];
            
            if ($tableName) {
                // Get columns for this table using DESCRIBE instead of Doctrine
                $columns = DB::select("DESCRIBE `{$tableName}`");
                $columnDetails = [];
                
                foreach ($columns as $column) {
                    $columnDetails[$column->Field] = [
                        'type' => $column->Type,
                        'nullable' => $column->Null === 'YES',
                        'key' => $column->Key,
                        'default' => $column->Default
                    ];
                }
                
                // Get record count
                $count = DB::table($tableName)->count();
                
                // Add to schema info
                $schemaInfo[$tableName] = [
                    'columns' => $columnDetails,
                    'record_count' => $count
                ];
            }
        }
        
        return $schemaInfo;
    }
    
    /**
     * Get common analytics data from various tables
     * 
     * @return array
     */
    private function getCommonAnalyticsData(): array
    {
        $data = [];
        
        try {
            // User statistics
            $data['users'] = [
                'total_count' => User::count(),
                'active_last_30_days' => User::where('updated_at', '>=', now()->subDays(30))->count(),
                'recent_users' => User::select('id', 'name', 'email', 'subscription_token', 'addons_token', 'created_at', 'updated_at')
                    ->orderBy('created_at', 'desc')
                    ->limit(10)
                    ->get()
                    ->toArray()
            ];
        } catch (\Exception $e) {
            \Log::error('Error getting user data: ' . $e->getMessage());
        }
        
        // Get histories data if table exists
        if (Schema::hasTable('histories')) {
            $data['histories'] = [
                'total_count' => DB::table('histories')->count(),
                'by_type' => DB::table('histories')
                    ->select('type', DB::raw('count(*) as count'))
                    ->groupBy('type')
                    ->get()
                    ->toArray(),
                'recent_entries' => DB::table('histories')
                    ->orderBy('created_at', 'desc')
                    ->limit(10)
                    ->get()
                    ->toArray()
            ];
        }
        
        // Get token histories if table exists
        try {
            if (Schema::hasTable('token_histories')) {
                $data['token_histories'] = [
                    'total_count' => DB::table('token_histories')->count(),
                    'recent_activities' => DB::table('token_histories')
                        ->orderBy('created_at', 'desc')
                        ->limit(10)
                        ->get()
                        ->toArray()
                ];
                
                // Only try to get usage by type if the 'type' column exists
                $columns = DB::getSchemaBuilder()->getColumnListing('token_histories');
                if (in_array('type', $columns) && in_array('tokens', $columns)) {
                    $data['token_histories']['usage_by_type'] = DB::table('token_histories')
                        ->select('type', DB::raw('SUM(tokens) as total_tokens'))
                        ->groupBy('type')
                        ->orderBy('total_tokens', 'desc')
                        ->get()
                        ->toArray();
                }
            }
        } catch (\Exception $e) {
            \Log::error('Error getting token_histories data: ' . $e->getMessage());
        }
        
        // Get token usage if table exists
        try {
            if (Schema::hasTable('token_usage')) {
                $data['token_usage'] = [
                    'total_count' => DB::table('token_usage')->count(),
                    'recent_usage' => DB::table('token_usage')
                        ->orderBy('created_at', 'desc')
                        ->limit(10)
                        ->get()
                        ->toArray()
                ];
                
                // Only try to aggregate by model if both columns exist
                $columns = DB::getSchemaBuilder()->getColumnListing('token_usage');
                if (in_array('model', $columns) && in_array('total_tokens', $columns)) {
                    $data['token_usage']['usage_by_model'] = DB::table('token_usage')
                        ->select('model', DB::raw('SUM(total_tokens) as tokens_used'))
                        ->groupBy('model')
                        ->orderBy('tokens_used', 'desc')
                        ->get()
                        ->toArray();
                }
            }
        } catch (\Exception $e) {
            \Log::error('Error getting token_usage data: ' . $e->getMessage());
        }
        
        // Get referral statistics if table exists
        try {
            if (Schema::hasTable('referrals')) {
                $data['referrals'] = [
                    'total_count' => DB::table('referrals')->count(),
                    'recent_referrals' => DB::table('referrals')
                        ->orderBy('created_at', 'desc')
                        ->limit(10)
                        ->get()
                        ->toArray()
                ];
                
                // Only add user-related referral data if the columns exist in users table
                $userColumns = DB::getSchemaBuilder()->getColumnListing('users');
                if (in_array('referral_count', $userColumns) && in_array('referral_code', $userColumns)) {
                    $data['referrals']['top_referrers'] = User::where('referral_count', '>', 0)
                        ->orderBy('referral_count', 'desc')
                        ->select('id', 'name', 'email', 'referral_count', 'referral_code')
                        ->limit(10)
                        ->get()
                        ->toArray();
                }
            }
        } catch (\Exception $e) {
            \Log::error('Error getting referrals data: ' . $e->getMessage());
        }
        
        // Get histories data if table exists
        try {
            if (Schema::hasTable('histories')) {
                $data['histories'] = [
                    'total_count' => DB::table('histories')->count(),
                    'recent_activities' => DB::table('histories')
                        ->orderBy('created_at', 'desc')
                        ->limit(10)
                        ->get()
                        ->toArray()
                ];
                
                // Check if the type column exists before using it
                $columns = DB::getSchemaBuilder()->getColumnListing('histories');
                if (in_array('type', $columns)) {
                    $data['histories']['by_type'] = DB::table('histories')
                        ->select('type', DB::raw('count(*) as count'))
                        ->groupBy('type')
                        ->get()
                        ->toArray();
                }
                
                // Check if user_id column exists before joining
                if (in_array('user_id', $columns)) {
                    $data['histories']['user_activity_counts'] = DB::table('histories')
                        ->join('users', 'histories.user_id', '=', 'users.id')
                        ->select('users.id', 'users.name', DB::raw('count(*) as activity_count'))
                        ->groupBy('users.id', 'users.name')
                        ->orderBy('activity_count', 'desc')
                        ->limit(10)
                        ->get()
                        ->toArray();
                }
            }
        } catch (\Exception $e) {
            \Log::error('Error getting histories data: ' . $e->getMessage());
        }
        
        // Get chat data if tables exist
        if (Schema::hasTable('chat_sessions') && Schema::hasTable('chat_messages')) {
            $data['chat'] = [
                'total_sessions' => DB::table('chat_sessions')->count(),
                'total_messages' => DB::table('chat_messages')->count(),
                'messages_by_sender' => DB::table('chat_messages')
                    ->select('sender', DB::raw('count(*) as count'))
                    ->groupBy('sender')
                    ->get()
                    ->toArray(),
                'recent_messages' => DB::table('chat_messages')
                    ->orderBy('created_at', 'desc')
                    ->limit(10)
                    ->get()
                    ->toArray()
            ];
        }
        
        // Get payment/transaction data if table exists
        if (Schema::hasTable('transactions') || Schema::hasTable('payments')) {
            $tableName = Schema::hasTable('transactions') ? 'transactions' : 'payments';
            $data['payments'] = [
                'total_count' => DB::table($tableName)->count(),
                'total_amount' => DB::table($tableName)->sum('amount') ?? 0,
                'recent_transactions' => DB::table($tableName)
                    ->orderBy('created_at', 'desc')
                    ->limit(10)
                    ->get()
                    ->toArray()
            ];
        }
        
        // Get email campaign data if table exists
        try {
            if (Schema::hasTable('email_campaigns')) {
                $data['email_campaigns'] = [
                    'total_count' => DB::table('email_campaigns')->count()
                ];
                
                // Check if created_at column exists
                $campaignColumns = DB::getSchemaBuilder()->getColumnListing('email_campaigns');
                if (in_array('created_at', $campaignColumns)) {
                    $data['email_campaigns']['recent_campaigns'] = DB::table('email_campaigns')
                        ->orderBy('created_at', 'desc')
                        ->limit(5)
                        ->get()
                        ->toArray();
                }
                
                // Get campaign statistics if available
                if (Schema::hasTable('campaign_stats')) {
                    $statsColumns = DB::getSchemaBuilder()->getColumnListing('campaign_stats');
                    $data['email_campaigns']['stats'] = [];
                    
                    if (in_array('sent', $statsColumns)) {
                        $data['email_campaigns']['stats']['total_sent'] = DB::table('campaign_stats')->sum('sent') ?? 0;
                    }
                    
                    if (in_array('opened', $statsColumns)) {
                        $data['email_campaigns']['stats']['total_opened'] = DB::table('campaign_stats')->sum('opened') ?? 0;
                    }
                    
                    if (in_array('clicked', $statsColumns)) {
                        $data['email_campaigns']['stats']['total_clicked'] = DB::table('campaign_stats')->sum('clicked') ?? 0;
                    }
                }
                
                // Get email templates data
                if (Schema::hasTable('email_templates')) {
                    $data['email_templates'] = [
                        'total_count' => DB::table('email_templates')->count()
                    ];
                    
                    $templateColumns = DB::getSchemaBuilder()->getColumnListing('email_templates');
                    $selectColumns = ['id'];
                    if (in_array('name', $templateColumns)) $selectColumns[] = 'name';
                    if (in_array('subject', $templateColumns)) $selectColumns[] = 'subject';
                    
                    if (count($selectColumns) > 1) {
                        $data['email_templates']['templates_list'] = DB::table('email_templates')
                            ->select($selectColumns)
                            ->get()
                            ->toArray();
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::error('Error getting email campaign data: ' . $e->getMessage());
        }
        
        // Get support ticket data if table exists
        try {
            if (Schema::hasTable('support_tickets')) {
                $data['support_tickets'] = [
                    'total_count' => DB::table('support_tickets')->count()
                ];
                
                $ticketColumns = DB::getSchemaBuilder()->getColumnListing('support_tickets');
                
                if (in_array('status', $ticketColumns)) {
                    $data['support_tickets']['by_status'] = DB::table('support_tickets')
                        ->select('status', DB::raw('count(*) as count'))
                        ->groupBy('status')
                        ->get()
                        ->toArray();
                }
                
                if (in_array('created_at', $ticketColumns)) {
                    $data['support_tickets']['recent_tickets'] = DB::table('support_tickets')
                        ->orderBy('created_at', 'desc')
                        ->limit(5)
                        ->get()
                        ->toArray();
                }
                
                if (Schema::hasTable('ticket_replies')) {
                    $data['support_tickets']['replies'] = [
                        'total_count' => DB::table('ticket_replies')->count()
                    ];
                    
                    $replyColumns = DB::getSchemaBuilder()->getColumnListing('ticket_replies');
                    if (in_array('ticket_id', $replyColumns)) {
                        $data['support_tickets']['replies']['avg_replies_per_ticket'] = DB::table('ticket_replies')
                            ->select(DB::raw('COUNT(*) / COUNT(DISTINCT ticket_id) as avg_replies'))
                            ->first()
                            ->avg_replies ?? 0;
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::error('Error getting support ticket data: ' . $e->getMessage());
        }
        
        // Get audit logs if table exists
        try {
            if (Schema::hasTable('audit_logs')) {
                $data['audit_logs'] = [
                    'total_count' => DB::table('audit_logs')->count()
                ];
                
                $auditColumns = DB::getSchemaBuilder()->getColumnListing('audit_logs');
                if (in_array('created_at', $auditColumns)) {
                    $data['audit_logs']['recent_logs'] = DB::table('audit_logs')
                        ->orderBy('created_at', 'desc')
                        ->limit(10)
                        ->get()
                        ->toArray();
                }
            }
        } catch (\Exception $e) {
            \Log::error('Error getting audit logs data: ' . $e->getMessage());
        }
        
        // Get subscription data if table exists
        try {
            if (Schema::hasTable('subscriptions')) {
                $data['subscriptions'] = [
                    'total_count' => DB::table('subscriptions')->count()
                ];
                
                $subColumns = DB::getSchemaBuilder()->getColumnListing('subscriptions');
                
                if (in_array('ends_at', $subColumns)) {
                    $data['subscriptions']['active_count'] = DB::table('subscriptions')
                        ->whereNull('ends_at')
                        ->orWhere('ends_at', '>', now())
                        ->count();
                }
                
                if (in_array('name', $subColumns)) {
                    $data['subscriptions']['by_plan'] = DB::table('subscriptions')
                        ->select('name', DB::raw('count(*) as count'))
                        ->groupBy('name')
                        ->get()
                        ->toArray();
                }
            }
        } catch (\Exception $e) {
            \Log::error('Error getting subscription data: ' . $e->getMessage());
        }
        
        // Get data from all remaining tables
        $additionalTableData = $this->getAllTableSamples();
        $data = array_merge($data, $additionalTableData);
        
        // Add system statistics
        try {
            $data['system'] = [
                'database_size' => $this->getDatabaseSize(),
                'table_count' => count(DB::select('SHOW TABLES')),
                'timestamp' => now()->toIso8601String()
            ];
        } catch (\Exception $e) {
            \Log::error('Error getting system statistics: ' . $e->getMessage());
        }
        
        return $data;
    }
    
    /**
     * Get sample data from all tables that haven't been explicitly handled
     * 
     * @return array
     */
    private function getAllTableSamples(): array
    {
        $data = [];
        $tables = DB::select('SHOW TABLES');
        $explicitlyHandledTables = [
            'users', 'referrals', 'histories', 'chat_sessions', 'chat_messages',
            'transactions', 'payments', 'email_campaigns', 'campaign_stats'
        ];
        
        // List of tables to ensure we include (even if they cause errors with standard approach)
        $criticalTables = [
            'token_usage', 'token_histories', 'audit_logs', 'support_tickets', 
            'ticket_replies', 'plans', 'subscriptions', 'purchases'
        ];
        
        // Log all tables found for debugging
        $allTableNames = [];
        foreach ($tables as $table) {
            $tableArray = (array)$table;
            $tableName = array_values($tableArray)[0];
            $allTableNames[] = $tableName;
        }
        \Log::info('Analytics: Found ' . count($allTableNames) . ' tables in database', ['tables' => $allTableNames]);
        
        foreach ($tables as $table) {
            // Get the table name
            $tableArray = (array)$table;
            $tableName = array_values($tableArray)[0];
            
            // Skip already handled tables
            if (in_array($tableName, $explicitlyHandledTables)) {
                continue;
            }
            
            try {
                \Log::info('Analytics: Processing table ' . $tableName);
                
                // Get basic stats and sample data
                $count = DB::table($tableName)->count();
                $data[$tableName] = ['total_count' => $count];
                
                // Get primary key for better ordering
                $primaryKey = 'id'; // default
                $columns = Schema::getColumnListing($tableName);
                
                if (in_array('id', $columns)) {
                    $primaryKey = 'id';
                } elseif (in_array('created_at', $columns)) {
                    $primaryKey = 'created_at';
                }
                
                // Try to get sample data
                if ($count > 0 && !in_array($tableName, ['migrations', 'password_reset_tokens', 'personal_access_tokens', 'failed_jobs'])) {
                    // Use try/catch for each specific operation to avoid total failure
                    try {
                        $query = DB::table($tableName)->limit(5);
                        
                        // Apply ordering if the column exists
                        if (in_array($primaryKey, $columns)) {
                            $query->orderBy($primaryKey, 'desc');
                        }
                        
                        $data[$tableName]['sample_data'] = $query->get()->toArray();
                    } catch (\Exception $e) {
                        $data[$tableName]['sample_data_error'] = 'Error getting sample: ' . $e->getMessage();
                    }
                    
                    // Get column statistics
                    $data[$tableName]['column_stats'] = [];
                    foreach ($columns as $column) {
                        try {
                            if (in_array($column, ['id', 'created_at', 'updated_at', 'deleted_at'])) {
                                continue; // Skip standard columns
                            }
                            
                            // Check if column is numeric or string
                            $columnType = Schema::getColumnType($tableName, $column);
                            
                            if (in_array($columnType, ['integer', 'bigint', 'float', 'double', 'decimal'])) {
                                // For numeric columns, get sum and average
                                $data[$tableName]['column_stats'][$column] = [
                                    'type' => $columnType,
                                    'sum' => DB::table($tableName)->sum($column),
                                    'avg' => DB::table($tableName)->avg($column)
                                ];
                            } elseif ($columnType == 'boolean') {
                                // For boolean columns, get true/false counts
                                $data[$tableName]['column_stats'][$column] = [
                                    'type' => $columnType,
                                    'true_count' => DB::table($tableName)->where($column, true)->count(),
                                    'false_count' => DB::table($tableName)->where($column, false)->count()
                                ];
                            }
                        } catch (\Exception $e) {
                            // Skip this column if there's an error
                        }
                    }
                }
                
                // For token-related tables, get additional statistics if possible
                if ((strpos($tableName, 'token') !== false) && Schema::hasColumn($tableName, 'amount')) {
                    try {
                        $data[$tableName]['total_amount'] = DB::table($tableName)->sum('amount') ?? 0;
                    } catch (\Exception $e) {
                        $data[$tableName]['token_stats_error'] = 'Error calculating token stats: ' . $e->getMessage();
                    }
                }
                
                // Special handling for token_usage table to get detailed feature analytics
                if ($tableName === 'token_usage' && Schema::hasTable('token_usage')) {
                    try {
                        // Detailed feature statistics from token_usage
                        $data['feature_usage'] = [
                            // Map each feature to its usage stats
                            'chart_analysis' => $this->getFeatureUsageStats('token_usage', 'image_analysis'),
                            'ea_generator' => $this->getFeatureUsageStats('token_usage', 'ea_generator'),
                            'chat' => $this->getFeatureUsageStats('token_usage', 'chat'),
                            'code_generation' => $this->getFeatureUsageStats('token_usage', 'code_generation')
                        ];
                        
                        // Add feature descriptions to help AI interpret the data
                        $data['feature_descriptions'] = [
                            'chart_analysis' => 'Analysis of financial charts and graphs using image processing AI',
                            'ea_generator' => 'Expert Advisor generation tool for automated trading strategies',
                            'chat' => 'Standard chat interaction with AI assistant',
                            'code_generation' => 'Trading code and script generation'
                        ];
                        
                        // Get user-specific feature usage
                        $data['user_feature_usage'] = $this->getUserFeatureUsage();
                        
                    } catch (\Exception $e) {
                        \Log::error('Analytics: Error processing token_usage feature stats: ' . $e->getMessage());
                        $data['feature_usage_error'] = 'Could not process feature usage stats: ' . $e->getMessage();
                    }
                }
                
                // For audit tables, categorize by type if possible
                if ((strpos($tableName, 'log') !== false || $tableName == 'audit_logs' || $tableName == 'histories') && 
                    Schema::hasColumn($tableName, 'type')) {
                    try {
                        $data[$tableName]['by_type'] = DB::table($tableName)
                            ->select('type', DB::raw('count(*) as count'))
                            ->groupBy('type')
                            ->get()
                            ->toArray();
                    } catch (\Exception $e) {
                        $data[$tableName]['type_stats_error'] = 'Error getting type stats: ' . $e->getMessage();
                    }
                }
                
            } catch (\Exception $e) {
                \Log::error('Analytics: Error processing table ' . $tableName . ': ' . $e->getMessage());
                // If there's an issue with this table, still include it in the output
                $data[$tableName] = [
                    'error' => 'Could not process table: ' . $e->getMessage(),
                    'total_count' => 'unknown'
                ];
            }
        }
        
        // Special handling for critical tables that might have been missed
        foreach ($criticalTables as $tableName) {
            if (!isset($data[$tableName]) && !in_array($tableName, $explicitlyHandledTables) && Schema::hasTable($tableName)) {
                try {
                    \Log::info('Analytics: Processing critical table ' . $tableName);
                    $count = DB::table($tableName)->count();
                    
                    $data[$tableName] = [
                        'total_count' => $count,
                        'is_critical' => true
                    ];
                    
                    // Get simple sample if possible
                    if ($count > 0) {
                        $data[$tableName]['sample_data'] = DB::table($tableName)->limit(5)->get()->toArray();
                    }
                } catch (\Exception $e) {
                    \Log::error('Analytics: Error processing critical table ' . $tableName . ': ' . $e->getMessage());
                    $data[$tableName] = [
                        'error' => 'Could not process critical table: ' . $e->getMessage(),
                        'is_critical' => true,
                        'total_count' => 'unknown'
                    ];
                }
            }
        }
        
        return $data;
    }
    
    /**
     * Get detailed usage statistics for a specific feature from token usage data
     * 
     * @param string $table The table to query (usually token_usage)
     * @param string $feature The feature identifier to look for
     * @return array Feature usage statistics
     */
    private function getFeatureUsageStats(string $table, string $feature): array
    {
        $result = [
            'feature_name' => $feature,
            'total_usage_count' => 0,
            'total_tokens_used' => 0,
            'user_count' => 0,
            'daily_usage' => [],
            'average_tokens_per_use' => 0
        ];
        
        try {
            // Get basic stats
            $usageQuery = DB::table($table)->where('feature', $feature)->orWhere('feature', 'LIKE', "%{$feature}%");
            $result['total_usage_count'] = $usageQuery->count();
            
            if ($result['total_usage_count'] > 0) {
                // Get token counts
                if (Schema::hasColumn($table, 'total_tokens')) {
                    $result['total_tokens_used'] = $usageQuery->sum('total_tokens') ?? 0;
                    $result['average_tokens_per_use'] = $result['total_usage_count'] > 0 ? 
                        round($result['total_tokens_used'] / $result['total_usage_count'], 2) : 0;
                }
                
                // Count distinct users
                if (Schema::hasColumn($table, 'user_id')) {
                    $result['user_count'] = $usageQuery->distinct('user_id')->count('user_id');
                    
                    // Get top users for this feature
                    $result['top_users'] = DB::table($table)
                        ->where('feature', $feature)
                        ->orWhere('feature', 'LIKE', "%{$feature}%")
                        ->select('user_id', DB::raw('count(*) as usage_count'))
                        ->groupBy('user_id')
                        ->orderBy('usage_count', 'desc')
                        ->limit(10)
                        ->get()
                        ->toArray();
                }
                
                // Get daily usage stats
                if (Schema::hasColumn($table, 'created_at')) {
                    $result['daily_usage'] = DB::table($table)
                        ->where('feature', $feature)
                        ->orWhere('feature', 'LIKE', "%{$feature}%")
                        ->select(DB::raw('DATE(created_at) as date'), DB::raw('count(*) as count'))
                        ->groupBy('date')
                        ->orderBy('date', 'desc')
                        ->limit(30)
                        ->get()
                        ->toArray();
                }
                
                // Get recent usage examples
                $result['recent_usage'] = DB::table($table)
                    ->where('feature', $feature)
                    ->orWhere('feature', 'LIKE', "%{$feature}%")
                    ->orderBy('created_at', 'desc')
                    ->limit(5)
                    ->get()
                    ->toArray();
            }
        } catch (\Exception $e) {
            \Log::error("Error getting feature usage stats for {$feature}: " . $e->getMessage());
            $result['error'] = $e->getMessage();
        }
        
        return $result;
    }
    
    /**
     * Get user-specific feature usage statistics
     * 
     * @return array User feature usage statistics
     */
    private function getUserFeatureUsage(): array
    {
        $result = [];
        
        try {
            // Get all users with token usage
            $users = DB::table('users')
                ->join('token_usage', 'users.id', '=', 'token_usage.user_id')
                ->select('users.id', 'users.name', 'users.email')
                ->distinct()
                ->get();
                
            foreach ($users as $user) {
                // Get this user's feature breakdown
                $features = DB::table('token_usage')
                    ->where('user_id', $user->id)
                    ->select('feature', DB::raw('count(*) as count'))
                    ->groupBy('feature')
                    ->get()
                    ->toArray();
                    
                // Map standard feature names
                $featureMap = [
                    'image_analysis' => 'chart_analysis',
                    'ea_generator' => 'ea_generator',
                    'chat' => 'chat',
                    'code_generation' => 'code_generation'
                ];
                
                // Process features for this user
                $userFeatures = [];
                foreach ($features as $feature) {
                    $featureName = $feature->feature;
                    $normalizedName = $featureName;
                    
                    // Map to standard name if possible
                    foreach ($featureMap as $pattern => $standardName) {
                        if (stripos($featureName, $pattern) !== false) {
                            $normalizedName = $standardName;
                            break;
                        }
                    }
                    
                    $userFeatures[$normalizedName] = $feature->count;
                }
                
                // Add to result
                $result[] = [
                    'user_id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'features' => $userFeatures,
                    'total_usage' => DB::table('token_usage')->where('user_id', $user->id)->count()
                ];
            }
            
            // Sort by total usage (most active users first)
            usort($result, function($a, $b) {
                return $b['total_usage'] <=> $a['total_usage'];
            });
            
            // Limit to top 20 users
            $result = array_slice($result, 0, 20);
            
        } catch (\Exception $e) {
            \Log::error("Error getting user feature usage: " . $e->getMessage());
            $result = [
                'error' => $e->getMessage()
            ];
        }
        
        return $result;
    }
    
    /**
     * Filter out SQL queries from content
     * 
     * @param string $content
     * @return string
     */
    private function filterSqlContent($content)
    {
        if (empty($content)) {
            return $content;
        }
        
        // Filter out code blocks containing SQL
        $content = preg_replace_callback('/```sql\\s*([\\s\\S]*?)```/i', function ($matches) {
            return '[SQL code removed for security reasons]';
        }, $content);
        
        // Filter out inline SQL patterns
        $sqlPatterns = [
            // Common SQL statements
            '/\\b(SELECT|INSERT|UPDATE|DELETE|CREATE|DROP|ALTER|TRUNCATE|GRANT)\\s+[\\w\\s\\*\\.,`"\\(\\)]+\\s+(FROM|INTO|TABLE|DATABASE)\\s+[\\w\\s\\.,`"]+/i',
            // WHERE clauses
            '/\\bWHERE\\s+[\\w\\s\\.,`"\\=\\<\\>\\!\\(\\)]+/i',
            // JOIN clauses
            '/\\b(INNER|LEFT|RIGHT|OUTER|CROSS)?\\s*JOIN\\s+[\\w\\s\\.,`"]+\\s+ON\\s+[\\w\\s\\.,`"\\=\\<\\>\\!\\(\\)]+/i',
            // GROUP BY, ORDER BY, HAVING
            '/\\b(GROUP BY|ORDER BY|HAVING)\\s+[\\w\\s\\.,`"\\(\\)]+/i',
            // UNION
            '/\\bUNION\\s+(ALL\\s+)?SELECT/i'
        ];
        
        foreach ($sqlPatterns as $pattern) {
            $content = preg_replace($pattern, '[SQL query removed for security reasons]', $content);
        }
        
        // Remove SQL hints like "here's the SQL query you would need"
        $content = preg_replace('/\\b(heres?\\s+the\\s+SQL(\\s+query)?\\s+you\\s+(would|could|might)\\s+(need|use|run|execute))([\\s\\S]*?)([\\.,\\n])/i', '[SQL reference removed for security reasons]$6', $content);
        
        return $content;
    }
    
    /**
     * Get the approximate database size
     * 
     * @return string
     */
    private function getDatabaseSize(): string
    {
        try {
            $dbName = config('database.connections.mysql.database');
            $result = DB::select("SELECT table_schema AS 'database', SUM(data_length + index_length) AS 'size' 
                                FROM information_schema.TABLES 
                                WHERE table_schema = ? 
                                GROUP BY table_schema", [$dbName]);
            
            if (count($result) > 0) {
                $bytes = $result[0]->size;
                $units = ['B', 'KB', 'MB', 'GB', 'TB'];
                $factor = floor((strlen($bytes) - 1) / 3);
                return sprintf("%.2f %s", $bytes / pow(1024, $factor), $units[$factor]);
            }
            
            return 'Unknown';
        } catch (\Exception $e) {
            return 'Error calculating size';
        }
    }
}