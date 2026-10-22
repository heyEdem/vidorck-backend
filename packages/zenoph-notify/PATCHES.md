# Zenoph Notify SDK (SMSOnlineGH) 2.25.08

Vendored from `zenoph.notify-2.25.08-php`: `lib/Zenoph` and `README.txt` only.
Excluded: `cacert.pem` (we use the system CA store), `AutoLoader.php` (Composer PSR-4
autoloads `Zenoph\Notify\`), `examples/`. The package is excluded from Pint and PHPStan.

## Patches

1. `lib/Zenoph/Notify/Request/NotifyRequest.php`, `submit()`: added
   `CURLOPT_CONNECTTIMEOUT` 3 and `CURLOPT_TIMEOUT` 10 so a dead API cannot hang a queue worker.

## PHP 8.4 deprecations

None found. `SMSRequest` was instantiated and a destination added under
`php -d error_reporting=E_ALL` with no notices or deprecations.

## Behaviour to know (unpatched)

- `MessageRequest::getMessageId()` throws `TypeError` for unscheduled messages (no client id
  is generated), so the app reads the id from the response report instead.
- `MessageDestination` getters read typed properties that are uninitialised when the server
  omits the field, which throws `Error`.

## Response content-type parameters

`Request/NotifyRequest.php` (`submit()`): the response `Content-Type` is trimmed to its media type
before `APIResponse::createFromArray()`, which matches labels exactly. Without this, a header such as
`application/xml; charset=utf-8` would make a delivered SMS look like a failure and the queued job
would retry, sending a duplicate.
