<?php

return [
    'code' => 'Error :code',
    'home' => 'Go to the start page',
    'retry' => 'Try again',
    'route_label' => 'Line interrupted',
    '401' => ['title' => 'Sign in to continue', 'text' => 'This page is for signed-in users. Sign in with your account and try again.'],
    '403' => ['title' => 'Access not allowed', 'text' => 'Your account does not have permission to see this page. If you think it should, ask whoever manages your organization.'],
    '404' => ['title' => 'Page not found', 'text' => 'The address may have changed or been mistyped. Check the link or go back to the start page.'],
    '419' => ['title' => 'This page expired', 'text' => 'The form was open for too long. Reload the page and send it again.'],
    '429' => ['title' => 'Too many attempts in a row', 'text' => 'We received too many requests in a short time. Wait a minute and try again.'],
    '500' => ['title' => 'Something went wrong on our side', 'text' => 'An unexpected error stopped this request. It has been logged. Try again in a moment.'],
    '503' => ['title' => 'Down for maintenance', 'text' => 'We are doing a short maintenance. Try again in a few minutes.'],
    '4xx' => ['title' => 'This request could not be completed', 'text' => 'Something in the request prevented a response. Go back to the start page and try again.'],
    '5xx' => ['title' => 'Service unavailable right now', 'text' => 'A problem on our side stopped this request. Try again in a moment.'],
];
