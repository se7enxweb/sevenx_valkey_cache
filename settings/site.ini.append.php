<?php /* #?ini charset="utf-8"?

[TemplateSettings]
# Load template function / operator autoloads from this extension.
ExtensionAutoloadPath[]=sevenx_valkey_cache

[ContentSettings]
# Enable the static cache handler so node/subtree changes can purge cached content.
StaticCache=enabled
StaticCacheHandler=sevenxValkeyCacheHandler

# Keep view caching enabled so the patched kernel/content/view.php uses the
# Valkey cache block for full view cache storage.
ViewCaching=enabled

# For public/anonymous sites the per-user role, limit and discount lists do not
# affect the rendered full view. Skipping them avoids several DB queries and
# cuts page generation time. If your site shows role-dependent content, remove
# these lines or adjust them per node.
ViewCacheTweaks[]
ViewCacheTweaks[global]=ignore_userroles;ignore_userlimitedlist;ignore_discountlist

*/ ?>