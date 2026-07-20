# sevenx_valkey_cache optimization TODO

Concrete ways to speed up page display with this extension.

Items marked with `[x]` are already implemented in the current version.

## [x] 0. Cache compiled INI arrays through eZINI kernel hooks
`sevenxValkeyINICache` stores compiled `eZINI` arrays in Redis/Valkey instead of
`var/cache/ini/*.php`. This removes per-request disk touches and makes INI caches
cluster-safe. It preloads all INI arrays used by the current request with a
single `MGET`.

Configuration: `valkeycache.ini` — `IniCache=enabled` (default).

**Kernel dependency:** This feature relies on `eZINI` hooks in Exponential
6.0.15-alpha (not yet released). The hooks call `sevenxValkeyINICache` from
`eZINI::loadCache()`, `eZINI::saveCache()` and `eZINI::resetCache()`. On older
kernels the option is ignored and INI caches remain file-based.

## [x] 2. Add a per-request in-memory cache layer
Many templates render the same block several times per request. `sevenxValkeyCacheBlock`
now keeps a request-scoped in-memory cache to short-circuit repeated Redis round-trips
for the same key within one page render.

Configuration: `valkeycache.ini` — `LocalCache=enabled` (default).

## [x] 8. Persist the Redis connection
`sevenxValkeyCacheBlock` now supports persistent Redis/Valkey connections via
`Redis::pconnect()`. This removes per-request TCP connect latency when enabled.

Configuration: `valkeycache.ini` — `Persistent=enabled` (default is `disabled`;
use with care when several databases share a php-fpm pool).

## 1. Compile `{valkey-block}` instead of runtime interpretation
The current `sevenxValkeyCacheTemplateFunction` uses `tree-transformation => false`
and executes `process()` at runtime. Implement `templateNodeTransformation()` so
the cache `get`/`put` logic is emitted directly into the compiled PHP template.
This removes per-render function dispatch and `eZINI` / `eZExpiryHandler`
lookups.

## 3. Batch reads with `MGET`
Collect keys for all `{valkey-block}` calls on a page and fetch them in a single
`Redis::mGet()` / pipeline call. This turns N network round-trips into one.

## 4. Non-blocking locks with stale-while-revalidate
If a process loses the generation lock, return a stale/expired entry if one
exists and let the winning process regenerate in the background. This prevents
cache stampedes from slowing the site under load.

## 5. Compress large view cache payloads
`eZNodeviewfunctions::contentViewGenerate()` returns a PHP array that can be
large. After `igbinary` serialization, `gzencode()` the payload before `setEx()`
and `gzdecode()` it on `get()` to reduce Redis memory and network transfer.

## 6. Move `expiry.php` metadata to Redis/Valkey
`eZExpiryHandler` still reads `expiry.php` from disk. Storing expiry timestamps
in Redis removes another disk touch per request and makes global expiry
purging cluster-safe.

## 7. Use Lua scripts for atomic read/write paths
Collapse `get()` lock + fetch and `put()` pipeline into single `Redis::eval()`
Lua scripts. This makes lock check, content fetch, and lock set atomic and
saves round-trips.

## 9. Tune Redis/Valkey server-side
- Disable Transparent HugePages (`never`).
- Set `maxmemory-policy` to `allkeys-lru` or `volatile-lru` so Redis evicts
  least-used cache entries instead of failing.
- Use a local Unix domain socket if Redis/Valkey is local.
- Enable `TCP_NODELAY` and keepalive.

## 10. Cache warming for published content
Hook into the static cache handler or add a cronjob that pre-generates
`content/view` cache entries for recently published and high-traffic nodes, so
the first visitor never hits a cold cache.

## 11. Extend the backend to other compiled PHP caches
`eZINI` compiled arrays are already handled by `sevenxValkeyINICache` when the
Exponential 6.0.15-alpha `eZINI` hooks are present. Other `eZPHPCreator`
generated files (class id cache, sort key cache, template tree cache) still hit
disk. A `sevenxValkeyCacheFileHandler` implementing `eZClusterFileHandlerInterface`
could store those compiled PHP strings in Redis and write them to a temp local
file only when `include()` or `file_get_contents()` is needed.

## 12. Reduce lock contention
- Shorten `LockTTL` to a few seconds for fast blocks.
- Use exponential backoff in `retryGet()` instead of a fixed `usleep()`.
- Cap retries and return an un-cached result immediately if Redis is unreachable,
  rather than waiting indefinitely.

## 13. Avoid `flush()` in production
`flush()` uses `SCAN`/`DEL` and can be slow. Prefer targeted purging with
`purgeByNode()` / `purgeBySubTree()`. When a full clear is needed, use
`FLUSHDB` on the dedicated `Database` configured for the site.

## 14. Profile first
Use `redis-cli --latency` and Exponential's debug accumulator to identify the
actual bottleneck: network latency, serialization time, lock contention, or
cache misses. Optimize the hot path first.

## Recommended next steps
1. Implement compile-time transformation for `{valkey-block}` (#1).
2. Add `MGET` batching for multiple blocks on the same page (#3).
3. Add non-blocking stale-while-revalidate locks (#4) if the site is high traffic.

These three changes will remove most remaining per-request CPU and Redis
round-trips.
