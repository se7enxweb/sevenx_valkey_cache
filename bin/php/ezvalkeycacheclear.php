#!/usr/bin/env php
<?php
/**
 * Clear all eZ/Exponential caches and flush Redis/Valkey-backed cache keys.
 *
 * Run from the Exponential docroot:
 *   php extension/sevenx_valkey_cache/bin/php/ezvalkeycacheclear.php
 *
 * This is useful after disabling or removing an extension (e.g. AdminAid)
 * from settings/override/site.ini.append.php. The admin GUI "Clear all caches"
 * does not regenerate the extension autoload map and cannot flush a cache
 * backend whose extension is no longer active, so stale entries can remain.
 * This script fills those gaps from the command line.
 */

require_once 'autoload.php';

$cli = eZCLI::instance();
$script = eZScript::instance(
    array(
        'description' => "Exponential Valkey + eZ cache clear\n" .
                         "Clears all eZ caches, flushes Redis/Valkey cache keys, and regenerates extension autoloads.\n",
        'use-session' => false,
        'use-modules' => false,
        'use-extensions' => true
    )
);

$script->startup();

$options = $script->getOptions(
    "[skip-ezcache][skip-valkey][skip-autoload][dry-run]",
    "",
    array(
        'skip-ezcache' => 'Do not clear eZ file/content/view/template/INI caches',
        'skip-valkey'  => 'Do not flush Redis/Valkey keys',
        'skip-autoload' => 'Do not regenerate extension autoloads',
        'dry-run'      => 'Show what would be done without deleting anything'
    )
);

$dryRun = (bool)$options['dry-run'];
$script->initialize();

if ( $dryRun )
{
    $cli->output( "*** DRY RUN: no changes will be made ***" );
}

// 1. Clear all eZ file/content/view/template/INI caches.
if ( !$options['skip-ezcache'] )
{
    $cacheList = eZCache::fetchList();

    if ( $dryRun )
    {
        $ids = array();
        foreach ( $cacheList as $cacheEntry )
        {
            $ids[] = $cacheEntry['id'];
        }
        $cli->output( "Would clear eZ caches: " . implode( ', ', $ids ) );
    }
    else
    {
        $helper = new eZCacheHelper( $cli, $script );
        $helper->clearItems( $cacheList, false );
        $cli->output( "eZ caches cleared." );
    }
}

// 2. Flush Redis/Valkey keys created by sevenx_valkey_cache.
if ( !$options['skip-valkey'] )
{
    flushValkeyCache( $cli, $dryRun );
}

// 3. Regenerate extension autoloads so removed or disabled extensions disappear
//    from var/autoload/ezp_extension.php. We pass --exclude for every
//    extension directory that is not listed in ActiveExtensions[], which makes
//    the autoload map match the active extensions even when files are still
//    physically present.
if ( !$options['skip-autoload'] )
{
    $autoloadScript = __DIR__ . '/../../../../bin/php/ezpgenerateautoloads.php';
    if ( file_exists( $autoloadScript ) )
    {
        $excludeList = getDisabledExtensionExcludeList();
        $excludeArg = $excludeList !== '' ? ' --exclude ' . escapeshellarg( $excludeList ) : '';

        if ( $dryRun )
        {
            $cli->output( "Would regenerate extension autoloads with: $autoloadScript -e$excludeArg" );
        }
        else
        {
            $cli->output( "Regenerating extension autoloads..." );
            $phpBinary = defined( 'PHP_BINARY' ) && PHP_BINARY ? PHP_BINARY : ( PHP_BINDIR . '/php' );
            $command = escapeshellarg( $phpBinary ) . ' ' . escapeshellarg( $autoloadScript ) . ' -e' . $excludeArg;
            passthru( $command, $returnCode );
            if ( $returnCode !== 0 )
            {
                $cli->error( "Autoload regeneration failed with exit code $returnCode." );
                $script->shutdown( $returnCode );
            }
            $cli->output( "Extension autoloads regenerated." );
        }
    }
    else
    {
        $cli->error( "Could not find $autoloadScript; autoloads not regenerated." );
    }
}

$script->shutdown( 0 );


/**
 * Build a comma-separated --exclude argument for ezpgenerateautoloads.php
 * containing every extension directory under extension/ that is not in the
 * current ActiveExtensions[] list. This prevents disabled-but-present
 * extensions (like AdminAid or sevenx_valkey_cache) from being mapped in
 * var/autoload/ezp_extension.php.
 */
function getDisabledExtensionExcludeList()
{
    $siteIni = eZINI::instance( 'site.ini' );
    $activeExtensions = array();
    if ( $siteIni->hasVariable( 'ExtensionSettings', 'ActiveExtensions' ) )
    {
        $activeExtensions = $siteIni->variable( 'ExtensionSettings', 'ActiveExtensions' );
        if ( !is_array( $activeExtensions ) )
        {
            $activeExtensions = array( $activeExtensions );
        }
    }

    $disabled = array();
    $extensionDir = eZSys::rootDir() . '/extension';
    if ( is_dir( $extensionDir ) )
    {
        $handle = opendir( $extensionDir );
        if ( $handle )
        {
            while ( ( $entry = readdir( $handle ) ) !== false )
            {
                if ( $entry[0] === '.' || !is_dir( $extensionDir . '/' . $entry ) )
                {
                    continue;
                }
                if ( !in_array( $entry, $activeExtensions, true ) )
                {
                    $disabled[] = 'extension/' . $entry;
                }
            }
            closedir( $handle );
        }
    }

    return implode( ',', $disabled );
}


/**
 * Flush all keys in Redis/Valkey that match the configured sevenx_valkey_cache
 * key prefix. This catches both the cache block/view keys and the INI cache
 * keys even if the sevenx_valkey_cache extension is currently disabled.
 */
function flushValkeyCache( eZCLI $cli, $dryRun = false )
{
    if ( !class_exists( 'Redis' ) )
    {
        $cli->warning( "PHP redis extension is not available; skipping Valkey flush." );
        return;
    }

    $settings = valkeyCacheSettings();
    $host = $settings['Host'];
    $port = (int)$settings['Port'];
    $db   = (int)$settings['Database'];
    $keyPrefix = rtrim( $settings['KeyPrefix'], ':' ) . ':';

    if ( $dryRun )
    {
        $cli->output( "Would connect to Redis/Valkey at $host:$port db $db and flush keys matching: $keyPrefix*" );
        return;
    }

    try
    {
        $redis = new Redis();
        $redis->connect( $host, $port );
        $redis->select( $db );

        $deleted = 0;
        $iterator = null;
        $pattern = $keyPrefix . '*';

        do
        {
            $keys = $redis->scan( $iterator, $pattern );
            if ( is_array( $keys ) && !empty( $keys ) )
            {
                $redis->del( $keys );
                $deleted += count( $keys );
            }
        } while ( $iterator > 0 );

        $redis->close();

        $cli->output( "Flushed $deleted Redis/Valkey key(s) matching $pattern" );
    }
    catch ( Exception $e )
    {
        $cli->error( "Could not flush Redis/Valkey cache: " . $e->getMessage() );
    }
}


/**
 * Read the extension's valkeycache.ini.append.php directly so this script works
 * even when the sevenx_valkey_cache extension is disabled and its settings are
 * not loaded through eZINI.
 */
function valkeyCacheSettings()
{
    $defaults = array(
        'Host'      => '127.0.0.1',
        'Port'      => 6379,
        'Database'  => 0,
        'KeyPrefix' => 'sevenx_valkey_cache:',
    );

    $file = __DIR__ . '/../../settings/valkeycache.ini.append.php';
    if ( !file_exists( $file ) )
    {
        return $defaults;
    }

    $content = file_get_contents( $file );
    if ( $content === false )
    {
        return $defaults;
    }

    // Strip the PHP wrapper used by eZ Publish .ini.append.php files.
    $content = preg_replace( '@^\s*<\?php\s*/\*\s*#\?ini[^\n]*\n@', '', $content );
    $content = preg_replace( '@\s*\*/\s*\?>\s*$@', '', $content );

    // PHP 8's parse_ini_string does not accept # as a comment character.
    $lines = explode( "\n", $content );
    $clean = array();
    foreach ( $lines as $line )
    {
        $line = preg_replace( '/^\s*#.*$/', '', $line );
        if ( trim( $line ) !== '' )
        {
            $clean[] = $line;
        }
    }
    $content = implode( "\n", $clean );

    $parsed = @parse_ini_string( $content, false, INI_SCANNER_RAW );
    if ( $parsed === false )
    {
        return $defaults;
    }

    foreach ( $parsed as $key => $value )
    {
        if ( isset( $defaults[$key] ) )
        {
            $defaults[$key] = $value;
        }
    }

    return $defaults;
}
