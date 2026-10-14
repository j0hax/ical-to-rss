<?php

declare(strict_types=1);

use ICal\ICal;

require __DIR__ . '/vendor/autoload.php';

$config = require __DIR__ . '/config.php';
$timezone = (string) ($config['timezone'] ?? 'UTC');
date_default_timezone_set($timezone);

// Avoid a stalled upstream feed holding a PHP worker for too long.
ini_set('default_socket_timeout', '12');
set_time_limit(30);

$cacheTtl = max(0, (int) ($config['cache_ttl'] ?? 300));
$cachePath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
    . DIRECTORY_SEPARATOR
    . 'ical-to-rss-' . hash('sha256', serialize($config)) . '.xml';

function sendRss(string $xml, int $statusCode = 200): never
{
    http_response_code($statusCode);
    header('Content-Type: application/rss+xml; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: public, max-age=300');
    echo $xml;
    exit;
}

function addTextElement(DOMDocument $document, DOMElement $parent, string $name, string $value): DOMElement
{
    $element = $document->createElement($name);
    $element->appendChild($document->createTextNode($value));
    $parent->appendChild($element);
    return $element;
}

function eventText(object $event, string $property): string
{
    $value = $event->{$property} ?? '';

    if ($value instanceof DateTimeInterface) {
        return $value->format(DATE_ATOM);
    }
    if (is_array($value)) {
        $value = implode(', ', array_map(static fn ($part): string => is_scalar($part) ? (string) $part : '', $value));
    }
    return trim(is_scalar($value) ? (string) $value : '');
}

function safeHttpUrl(string $url): bool
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    return $scheme === 'http' || $scheme === 'https';
}

function dateTimeForEvent(ICal $calendar, object $event, string $property): ?DateTimeImmutable
{
    $dateArray = $event->{$property . '_array'} ?? null;
    if (!is_array($dateArray) || !isset($dateArray[3]) || !is_string($dateArray[3])) {
        return null;
    }

    try {
        $date = $calendar->iCalDateToDateTime($dateArray[3]);
        if ($date instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($date);
        }
    } catch (Throwable $error) {
        error_log('ical-to-rss: Could not parse ' . $property . ': ' . $error->getMessage());
    }

    return null;
}

function makeRss(array $config): array
{
    $now = new DateTimeImmutable('now');
    $daysBack = max(0, (int) ($config['days_back'] ?? 1));
    $daysAhead = max(1, (int) ($config['days_ahead'] ?? 180));
    $rangeStart = $now->modify('-' . $daysBack . ' days');
    $rangeEnd = $now->modify('+' . $daysAhead . ' days');

    $events = [];
    $failedFeeds = 0;
    $successfulFeeds = 0;

    foreach (($config['feeds'] ?? []) as $feed) {
        $feedTitle = trim((string) ($feed['title'] ?? 'Calendar'));
        $url = trim((string) ($feed['url'] ?? ''));

        if (!safeHttpUrl($url)) {
            $failedFeeds++;
            error_log('ical-to-rss: Ignoring feed with invalid HTTP(S) URL: ' . $feedTitle);
            continue;
        }

        try {
            $calendar = new ICal($url, [
                'defaultTimeZone' => (string) ($feed['timezone'] ?? $config['timezone'] ?? 'UTC'),
                'defaultSpan' => max(1, (int) ($config['default_span'] ?? 2)),
                // A slightly wider parser window accounts for pre-filter time-zone fuzziness.
                'filterDaysBefore' => $daysBack + 2,
                'filterDaysAfter' => $daysAhead + 2,
                'verifySsl' => true,
                'httpUserAgent' => 'ical-to-rss/1.0',
            ]);

            $successfulFeeds++;
            foreach ($calendar->eventsFromRange($rangeStart->format('Y-m-d H:i:s'), $rangeEnd->format('Y-m-d H:i:s')) as $event) {
                $start = dateTimeForEvent($calendar, $event, 'dtstart');
                if ($start === null) {
                    continue;
                }

                $title = eventText($event, 'summary');
                if ($title === '') {
                    $title = '(Untitled event)';
                }

                $uid = eventText($event, 'uid');
                // UID should be stable across refreshes. Include start time so recurring
                // instances of the same VEVENT have distinct RSS GUIDs.
                $dedupeKey = $uid !== ''
                    ? $uid . "\0" . $start->getTimestamp()
                    : $url . "\0" . $title . "\0" . $start->getTimestamp();

                $descriptionParts = [];
                $description = eventText($event, 'description');
                if ($description !== '') {
                    $descriptionParts[] = $description;
                }

                $end = dateTimeForEvent($calendar, $event, 'dtend');
                if ($end !== null) {
                    $descriptionParts[] = 'Ends: ' . $end->format('D, d M Y H:i T');
                }

                $location = eventText($event, 'location');
                if ($location !== '') {
                    $descriptionParts[] = 'Location: ' . $location;
                }

                $eventUrl = eventText($event, 'url');
                if (!safeHttpUrl($eventUrl)) {
                    $eventUrl = '';
                }

                // Keep the first copy if different source feeds contain the same UID/start.
                if (!isset($events[$dedupeKey])) {
                    $events[$dedupeKey] = [
                        'title' => $title,
                        'description' => implode("\n\n", $descriptionParts),
                        'start' => $start,
                        'url' => $eventUrl,
                        'source' => $feedTitle,
                        'guid' => hash('sha256', $dedupeKey),
                    ];
                }
            }
        } catch (Throwable $error) {
            $failedFeeds++;
            error_log('ical-to-rss: Failed to read feed "' . $feedTitle . '": ' . $error->getMessage());
        }
    }

    $items = array_values($events);
    usort($items, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);
    $items = array_slice($items, 0, max(1, (int) ($config['max_items'] ?? 100)));

    $document = new DOMDocument('1.0', 'UTF-8');
    $document->formatOutput = true;
    $rss = $document->createElement('rss');
    $rss->setAttribute('version', '2.0');
    $rss->setAttribute('xmlns:atom', 'http://www.w3.org/2005/Atom');
    $document->appendChild($rss);

    $channel = $document->createElement('channel');
    $rss->appendChild($channel);
    addTextElement($document, $channel, 'title', (string) ($config['title'] ?? 'Calendar events'));
    addTextElement($document, $channel, 'description', (string) ($config['description'] ?? 'Aggregated calendar events'));

    $feedLink = (string) ($config['link'] ?? '');
    if (safeHttpUrl($feedLink)) {
        addTextElement($document, $channel, 'link', $feedLink);
    }
    $selfUrl = (string) ($config['self_url'] ?? '');
    if (safeHttpUrl($selfUrl)) {
        $atomLink = $document->createElementNS('http://www.w3.org/2005/Atom', 'atom:link');
        $atomLink->setAttribute('href', $selfUrl);
        $atomLink->setAttribute('rel', 'self');
        $atomLink->setAttribute('type', 'application/rss+xml');
        $channel->appendChild($atomLink);
    }
    addTextElement($document, $channel, 'lastBuildDate', $now->format(DATE_RSS));
    addTextElement($document, $channel, 'generator', 'ical-to-rss (PHP)');

    foreach ($items as $item) {
        $node = $document->createElement('item');
        $channel->appendChild($node);
        addTextElement($document, $node, 'title', $item['title']);
        if ($item['url'] !== '') {
            addTextElement($document, $node, 'link', $item['url']);
        }
        $guid = addTextElement($document, $node, 'guid', $item['guid']);
        $guid->setAttribute('isPermaLink', 'false');
        addTextElement($document, $node, 'pubDate', $item['start']->format(DATE_RSS));
        addTextElement($document, $node, 'category', $item['source']);

        $startLabel = 'Starts: ' . $item['start']->format('D, d M Y H:i T');
        $body = $startLabel;
        if ($item['description'] !== '') {
            $body .= "\n\n" . $item['description'];
        }
        addTextElement($document, $node, 'description', $body);
    }

    return [
        'xml' => $document->saveXML(),
        'failed_feeds' => $failedFeeds,
        'successful_feeds' => $successfulFeeds,
    ];
}

// Return a fresh cached response immediately when available.
if (is_file($cachePath) && (time() - (int) filemtime($cachePath)) < $cacheTtl) {
    $cachedXml = file_get_contents($cachePath);
    if (is_string($cachedXml) && $cachedXml !== '') {
        sendRss($cachedXml);
    }
}

$result = makeRss($config);

// If all sources failed, use the last known-good aggregate instead of serving an empty feed.
if ($result['successful_feeds'] === 0) {
    if (is_file($cachePath)) {
        $staleXml = file_get_contents($cachePath);
        if (is_string($staleXml) && $staleXml !== '') {
            header('Warning: 110 - "Response is stale; all calendar sources failed"');
            sendRss($staleXml);
        }
    }
    sendRss("<?xml version=\"1.0\" encoding=\"UTF-8\"?><error>All calendar sources failed; check server logs.</error>", 502);
}

// Only replace the last known-good cache when every configured feed could be read.
if ($result['failed_feeds'] === 0 && is_string($result['xml']) && $result['xml'] !== '') {
    $temporaryPath = $cachePath . '.' . bin2hex(random_bytes(5)) . '.tmp';
    if (file_put_contents($temporaryPath, $result['xml'], LOCK_EX) !== false) {
        @chmod($temporaryPath, 0600);
        @rename($temporaryPath, $cachePath);
    } else {
        @unlink($temporaryPath);
    }
}

sendRss((string) $result['xml']);
