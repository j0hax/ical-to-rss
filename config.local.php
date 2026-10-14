<?php

return [
    // Metadata for the combined RSS feed.
    'title' => 'Aggregated calendar events',
    'description' => 'Upcoming events from multiple iCalendar feeds.',
    'link' => 'https://example.org/',
    'self_url' => 'https://example.org/events.xml',

    // Used for floating dates without an explicit time zone.
    'timezone' => 'Europe/Berlin',

    // Include events starting in this window.
    'days_back' => 1,
    'days_ahead' => 180,
    'max_items' => 100,

    // Cache the generated RSS response for this many seconds.
    'cache_ttl' => 300,

    // Public or secret-token ICS URLs. Use HTTPS where possible.
    'feeds' => [
        [
            'title' => 'Example calendar',
            'url' => 'https://example.org/calendar.ics',
            // Optional per-feed time zone:
            // 'timezone' => 'Europe/Berlin',
        ],
        // [ 'title' => 'Another calendar', 'url' => 'https://example.net/events.ics' ],
    ],
];
