<?php

// Telegram notification messages (#189). Values are inserted HTML-escaped.
return [
    'opened' => 'Down: :monitor',
    'resolved' => 'Back up: :monitor',
    'other' => 'Notification: :monitor',
    'since' => 'Since :time (:timezone)',
    'severity' => 'severity :severity',
    'down_for' => 'Was down for :duration (:start–:end).',
    'open_app' => 'Open StatusConnect',
    'test' => 'StatusConnect test: this chat receives the alerts of the :environment environment.',
    'date_format' => 'M j, H:i',
    'time_format' => 'H:i',
    'linked' => 'Connected to the :environment environment of :organization. Incidents will be posted here.',
    'link_expired' => 'This link has expired or was already used. Create a new one in StatusConnect.',
    'start_without_code' => 'To get StatusConnect alerts here, open Notification channels in StatusConnect and choose Connect Telegram.',
];
