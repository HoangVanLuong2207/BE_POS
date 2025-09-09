<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cloudinary Configuration
    |--------------------------------------------------------------------------
    |
    | Cloudinary là dịch vụ quản lý file ảnh/video. Ở đây bạn chỉ cần trỏ đến
    | CLOUDINARY_URL trong .env (được cấp từ Dashboard Cloudinary).
    |
    */

    'cloud_url' => env('CLOUDINARY_URL'),

    'cloud_name' => env('CLOUDINARY_CLOUD_NAME'),
    'api_key' => env('CLOUDINARY_API_KEY'),
    'api_secret' => env('CLOUDINARY_API_SECRET'),
    'secure' => env('CLOUDINARY_SECURE', true),

    /*
    |--------------------------------------------------------------------------
    | Notification URL
    |--------------------------------------------------------------------------
    | Nếu bạn muốn Cloudinary gọi webhook khi upload/delete hoàn tất.
    */
    'notification_url' => env('CLOUDINARY_NOTIFICATION_URL'),

    /*
    |--------------------------------------------------------------------------
    | Upload Preset
    |--------------------------------------------------------------------------
    | Nếu bạn cấu hình upload preset trong Dashboard Cloudinary thì khai báo ở đây.
    */
    'upload_preset' => env('CLOUDINARY_UPLOAD_PRESET'),

    /*
    |--------------------------------------------------------------------------
    | Upload Widget (chỉ dùng khi upload từ frontend qua Blade widget)
    |--------------------------------------------------------------------------
    */
    'upload_route' => env('CLOUDINARY_UPLOAD_ROUTE'),
    'upload_action' => env('CLOUDINARY_UPLOAD_ACTION'),
];
