<?php /* #?ini charset="utf-8"?

[ValkeyCacheSettings]
# Redis/Valkey connection settings. Defaults assume a local Valkey or Redis server.
Host=127.0.0.1
Port=6379
Database=0

# Prefix applied to every key written by this extension. Keep it unique per
# installation when several sites share a single Redis/Valkey instance.
KeyPrefix=sevenx_valkey_cache:

# Unique identifier for this installation. Set to "auto" to generate a hash
# from the database name, site name, filesystem root and hostname. Use a fixed
# string when you want several servers to share the same cache.
InstallationHash=auto

# Default TTL in seconds for cache entries when no expiry is specified.
DefaultTTL=3600

# TTL in seconds for generation locks. If a generating process crashes the lock
# will be released after this time.
LockTTL=30

# How long, in milliseconds, a request waits for a cache entry that another request is generating before it
# generates the entry itself, and the pause between two looks. Keep the wait short: requests that wait on each other
# chain their waits, and a page with several locked entries must still finish well inside the web server's timeout
# (the old fixed wait of 10 s per entry made pages time out under load). LockWait=0 never waits.
LockWait=2000
LockRetryDelay=100

# Use Redis/Valkey persistent connections (pconnect). May reduce connect time
# but can cause issues if several databases share a php-fpm pool. Use with care.
Persistent=disabled

# Keep a request-scoped in-memory copy of retrieved cache entries. This avoids
# repeated Redis round-trips when the same key is requested more than once per
# page. Set to disabled only when debugging cache consistency.
LocalCache=enabled

# Cache compiled eZINI arrays in Redis/Valkey instead of local PHP files.
# This removes per-request disk touches for var/cache/ini/ files and makes
# INI caches cluster-safe. Disable this if you prefer the default filesystem
# cache or if Redis is not available.
IniCache=enabled
# 1.0.2.3: keep the compiled INI arrays in APCu as well, under the generation that a clear raises, so a request reads
# one key from Valkey (the generation) instead of one per INI file. Ignored where APCu is not usable.
IniLocalCache=enabled
# One copy of the compiled INI arrays for every web host of the installation instead of one per host name (with
# InstallationHash=auto). A compiled INI file is the same for every host of a siteaccess: its name already says which
# files and siteaccess it was built from.
IniSharedAcrossHosts=enabled
# Seconds a compiled INI array is kept (they change only with the settings, and a clear raises the generation), and
# the largest random number of seconds added to each, so the arrays written together do not expire together.
IniTTL=21600
IniTTLJitter=3600

*/ ?>