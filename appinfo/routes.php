<?php
return [
    'routes' => [
        ['name' => 'share#upload', 'url' => '/upload', 'verb' => 'POST'],
        ['name' => 'share#sendMail', 'url' => '/sendmail', 'verb' => 'POST'],
        ['name' => 'admin#saveSettings', 'url' => '/admin/settings', 'verb' => 'POST'],
    ]
];
