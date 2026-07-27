<?php
require_once __DIR__ . '/mailer.php';

echo "Testing OKR mailer...\n";

$result = sendOkrSuspensionEmail(
    'test@example.com',
    'Test Staff',
    999,
    'Sample Objective Title',
    'This is just a test suspension reason.',
    'CEO Name'
);

var_dump($result);