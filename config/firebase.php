<?php

return [
    'credentials' => [
        'file' => env('FIREBASE_CREDENTIALS', base_path('firebase-credentials.json')),
    ],
    'project_id' => env('FIREBASE_PROJECT_ID', 'ai-crm-windsurf'),
    'storage' => [
        'bucket' => env('FIREBASE_STORAGE_BUCKET', 'ai-crm-windsurf.firebasestorage.app'), // Updated default
    ],
];
