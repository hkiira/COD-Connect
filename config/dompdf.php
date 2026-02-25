<?php
return [
    'font_dir' => storage_path('fonts/'),
    'font_cache' => storage_path('fonts/'),
    'default_font' => 'NotoSansArabic',
    'dpi' => 96,
    'options' => [
        'fontDir' => storage_path('fonts/'),
        'fontCache' => storage_path('fonts/'),
    ],
    'fontdata' => [
        'NotoSansArabic' => [
            'R' => 'NotoSansArabic-Regular.ttf',
            'B' => 'NotoSansArabic-Bold.ttf',
            'useOTL' => 0xFF,
            'useKashida' => 75,
        ],
    ],
];