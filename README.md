# sevenx_valkey_cache

A Redis/Valkey backed cache handler for Exponential / eZ Publish Legacy.

It replaces disk file storage for:

* `{valkey-block ...}` template blocks
* content `content/view` full view cache (requires a small kernel patch)
* compiled `eZINI` cache files (requires Exponential 6.0.15-alpha `eZINI` hooks or an equivalent kernel patch)

Cache entries are stored in Redis/Valkey with TTL based expiry, generation locks
and reverse node / subtree indexes so that publishing content selectively
purges the affected cache entries.

## License

This program is free software; you can redistribute it and/or modify it under
the terms of the GNU General Public License as published by the Free Software
Foundation; either version 2 of the License, or (at your option) any later
version.

See the `LICENSE` file or <http://www.gnu.org/licenses/> for the full license
text.

## Author

Developed and maintained by **7x** — https://se7enx.com

## Requirements

* Exponential / eZ Publish Legacy 4.x or compatible (for `{valkey-block}`, static cache handler and the content view patch)
* Exponential 6.0.15-alpha or later, or a patched `lib/ezutils/classes/ezini.php`, for compiled INI cache support
* PHP redis or igbinary extension (php-redis is recommended)
* A running Redis or Valkey server reachable from the web server

### Kernel dependencies

Two kernel integration points are required for the full feature set:

1. `kernel/content/view.php` must call `sevenxValkeyCacheBlock` for the
   content view cache. The extension ships `doc/kernel_content_view.patch`
   for Exponential / eZ Publish Legacy 4.x and compatible versions.
2. `lib/ezutils/classes/ezini.php` must call `sevenxValkeyINICache` from
   `loadCache()`, `saveCache()` and `resetCache()`. These hooks are present in
   Exponential 6.0.15-alpha (not yet released); earlier versions can use an
   equivalent back-ported patch. When the hooks are absent, setting
   `IniCache=enabled` has no effect.

## Installation

### Manual install

1. Copy or checkout the extension into `extension/sevenx_valkey_cache`.

2. Make the extension active in `settings/override/site.ini.append.php`:

```ini
[ExtensionSettings]
ActiveExtensions[]=sevenx_valkey_cache
```

3. Regenerate autoloads:

```bash
php bin/php/ezpgenerateautoloads.php
```

4. (Optional) Clear caches:

```bash
php bin/php/ezcache.php --clear-all --allow-root-user
```

### Composer install

```bash
composer require se7enxweb/sevenx_valkey_cache
```

Then activate the extension and regenerate autoloads as described above.

## Configuration

Configure the Redis/Valkey connection in `settings/override/valkeycache.ini.append.php`:

```ini
[ValkeyCacheSettings]
Host=127.0.0.1
Port=6379
Database=0
KeyPrefix=sevenx_valkey_cache:

# Unique identifier for this installation. Set to "auto" to derive a hash from
# the database name and site name, or use a fixed string when sharing a cache
# between servers.
InstallationHash=auto

DefaultTTL=3600
LockTTL=30

# Persistent connections may reduce connect time but should be used with care
# when several databases share a php-fpm pool.
Persistent=disabled

# Request-scoped in-memory cache. Keeps a per-request copy of retrieved entries
# to avoid repeated Redis round-trips for the same key on the same page.
LocalCache=enabled

# Cache compiled eZINI arrays in Redis/Valkey instead of local PHP files.
# Requires Exponential 6.0.15-alpha eZINI hooks (or an equivalent back-port).
IniCache=enabled
```

## Content view cache

To store full content view caches in Redis/Valkey instead of disk, apply the
patch in `doc/kernel_content_view.patch` to `kernel/content/view.php`:

```bash
cd /path/to/exponential
patch -p0 < extension/sevenx_valkey_cache/doc/kernel_content_view.patch
```

The patch is based on the `mugo_memcache` approach and changes the view script
to use `sevenxValkeyCacheBlock` as the content view cache backend.

## Compiled INI cache

Exponential 6.0.15-alpha (not yet released) adds `eZINI` hooks that let the
extension store compiled INI arrays in Redis/Valkey through `sevenxValkeyINICache`.
This removes the per-request `var/cache/ini/*.php` disk touches and makes INI
caches cluster-safe.

To enable it, set `IniCache=enabled` in `valkeycache.ini` (shown in the
configuration example above). The extension also preloads all INI arrays used
by the current request with a single `MGET`.

For Exponential versions older than 6.0.15-alpha, the same behaviour can be
achieved by back-porting the `eZINI::loadCache()`, `eZINI::saveCache()` and
`eZINI::resetCache()` calls to `sevenxValkeyINICache`. When the kernel hooks are
absent, `IniCache=enabled` is ignored and INI caches continue to be stored as
local PHP files.

## Usage

### `{valkey-block}` template function

Use `{valkey-block}` anywhere you would use the built-in `{cache-block}` but want
the rendered output stored in Redis/Valkey.

```eztemplate
{valkey-block expiry=3600 subtree_expiry=$node.node_id keys=array( 'my-block', $node.node_id )}
    ... expensive template logic ...
{/valkey-block}
```

Parameters:

* `expiry` — TTL in seconds (default 7200).
* `subtree_expiry` — Node id to use as a subtree dependency. When any node under
  this subtree changes, the block is purged.
* `node_expiry` — Node id to use as a node dependency. When the node changes,
  the block is purged.
* `keys` — Additional cache key components (array or scalar).
* `lock` — Use generation locks to avoid cache stampedes (default `true`).
* `ignore_content_expiry` — If `true`, ignore the global `template-block-cache`
  and `global-template-block-cache` expiry timestamps.

### Manual cache management

The `sevenxValkeyCacheBlock` singleton can be used directly in custom code:

```php
$cache = sevenxValkeyCacheBlock::instance();
$cache->put( 'my-key', $content, 3600 );
$content = $cache->get( 'my-key' );
$cache->purge( 'my-key' );
$cache->flush();
```

### Static cache handler

`sevenxValkeyCacheHandler` is registered as `ContentSettings.StaticCacheHandler`.
It is invoked when content changes and purges the Redis/Valkey view cache and
block cache entries that are indexed by the affected node ids and their
ancestors.

## Implementation notes

The extension deliberately follows the architecture of the `mugo_memcache`
proof-of-concept: a lightweight cache block class, a static cache handler for
selective purging, a template function for block caching, a small patch for
the content view cache, and a `sevenxValkeyINICache` backend for compiled INI
caches on Exponential 6.0.15-alpha (or equivalent back-ported `eZINI` hooks).
The storage backend has been changed from Memcache to Redis/Valkey, with added
support for TTL based expiry, atomic generation locks, and node/subtree reverse
indexes without requiring a relational database.
