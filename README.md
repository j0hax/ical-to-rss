# iCal to RSS aggregator (PHP)

A small self-hosted PHP endpoint that merges multiple remote iCalendar/ICS feeds into one RSS 2.0 feed.

## Features

- Reads a configurable list of HTTP(S) ICS URLs.
- Expands recurring events using `johngrogg/ics-parser`.
- Filters to a configurable date window, sorts events by start time, and limits the number of items.
- Emits RSS 2.0, with event title, start/end time, description, location, category (source calendar), link when provided, and stable GUIDs.
- Deduplicates entries with the same iCalendar UID and start time.
- Caches output in the system temporary directory. If a source fails, the last known-good aggregate cache is retained.
- TLS certificate verification remains enabled.

## Requirements

- PHP 8.1 or newer
- PHP DOM extension (`ext-dom`)
- Composer

## Setup

```sh
composer install --no-dev --prefer-dist --optimize-autoloader
cp config.php config.local.php
```

Edit `config.php` (or replace the `require` near the top of `index.php` to load `config.local.php`) and set the feed metadata plus each feed's `title` and `url`. Use the HTTPS ICS URL for each calendar where possible. If a URL contains an access token, remember that anyone who can read the configuration can use that URL.

Publish the directory through a PHP-capable web server. The feed endpoint is `https://your-domain.example/path/`. Ideally, do not expose `config.php`, `composer.json`, or other project files directly; configure the web server's document root to this directory with deny rules, or place `config.php` and `vendor/` outside the public document root and adjust the paths in `index.php`.

For a quick local test:

```sh
php -S 127.0.0.1:8080
```

Then open `http://127.0.0.1:8080/index.php`.

## Configuration

- `title`, `description`, `link`, `self_url`: RSS channel metadata.
- `timezone`: default time zone for floating dates.
- `days_back`, `days_ahead`: include events whose start date lies within this window.
- `max_items`: maximum number of returned events.
- `cache_ttl`: response cache time in seconds.
- `feeds`: array of source calendars; each entry needs `title` and `url`, and may override `timezone`.

## Notes

- The RSS item's `pubDate` is set to the event start time so readers can sort events chronologically. Some RSS readers hide future-dated items until their `pubDate` is reached; if yours does that, a small change to publish all items with the current time may be preferable.
- iCalendar is a broad standard and calendar providers differ in their recurrence and time-zone behavior. Test the feeds you depend on, especially if they use complex recurrence rules or large historical calendars.
- This is a simple RSS feed generator, not a full calendar database. It fetches source feeds when the cached response expires rather than maintaining its own permanent event store.
