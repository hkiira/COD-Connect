<?php

return [
    'enabled' => env('GOOGLE_SHEETS_ENABLED', false),
    'spreadsheet_id' => env('GOOGLE_SHEETS_SPREADSHEET_ID', ''),
    'sheet_name' => env('GOOGLE_SHEETS_SHEET_NAME', ''),
    'credentials_path' => env('GOOGLE_SHEETS_CREDENTIALS_PATH', storage_path('app/google/credentials.json')),
    'app_name' => env('GOOGLE_SHEETS_APP_NAME', env('APP_NAME', 'Laravel')),
];
