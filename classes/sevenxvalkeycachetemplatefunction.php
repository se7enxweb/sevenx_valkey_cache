<?php
//
// Sevenx Valkey Cache extension for Exponential / eZ Publish Legacy
// Copyright (C) 2025 7x (https://se7enx.com)
//
// This program is free software; you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation; either version 2 of the License, or
// (at your option) any later version.
//

/*!
  \class sevenxValkeyCacheTemplateFunction sevenxvalkeycachetemplatefunction.php
  \brief Template function {valkey-block} that stores rendered blocks in Redis/Valkey.

  Usage example:
  {valkey-block expiry=3600 subtree_expiry=$node.node_id keys=array( 'my-block', $node.node_id )}
    ... expensive template logic ...
  {/valkey-block}
*/

class sevenxValkeyCacheTemplateFunction
{
    const DEFAULT_TTL = 7200;

    /// Name of the template function.
    public $BlockName;

    function __construct( $blockName = 'valkey-block' )
    {
        $this->BlockName = $blockName;
    }

    function functionList()
    {
        return array( $this->BlockName );
    }

    function functionTemplateHints()
    {
        return array( $this->BlockName => array(
            'parameters' => true,
            'static' => false,
            'transform-children' => true,
            'tree-transformation' => true,
            'transform-parameters' => true
        ) );
    }

    /**
     * Compile-time transformation of {valkey-block}.
     *
     * Emits PHP code that checks Redis/Valkey for a cached render and only
     * executes the children on a miss. This removes per-render function
     * dispatch and per-parameter template evaluation overhead.
     */
    function templateNodeTransformation( $functionName, &$node, $tpl, $parameters, $privateData )
    {
        if ( $functionName !== $this->BlockName )
        {
            return false;
        }

        $ini = eZINI::instance();
        $children = eZTemplateNodeTool::extractFunctionNodeChildren( $node );
        if ( $ini->variable( 'TemplateSettings', 'TemplateCache' ) != 'enabled' )
        {
            return $children;
        }

        $functionPlacement = eZTemplateNodeTool::extractFunctionNodePlacement( $node );
        $placementString = eZTemplateCacheBlock::placementString( $functionPlacement );
        $placementHash = md5( $placementString );

        $newNodes = array();

        $ttlCode = $this->compileTtl( $parameters, $newNodes, $placementHash );
        $keysCode = $this->compileKeys( $parameters, $newNodes, $placementHash );
        $subTreeExpiryCode = $this->compileNodeId( $parameters, 'subtree_expiry', $newNodes, $placementHash );
        $nodeExpiryCode = $this->compileNodeId( $parameters, 'node_expiry', $newNodes, $placementHash );
        $lockCode = $this->compileLock( $parameters, $newNodes, $placementHash );
        $ignoreContentExpiryCode = $this->compileIgnoreContentExpiry( $parameters, $newNodes, $placementHash );

        $placementStringText = eZPHPCreator::variableText( $placementString, 0, 0, false );
        $accessNameCode = "isset( \$GLOBALS['eZCurrentAccess']['name'] ) ? \$GLOBALS['eZCurrentAccess']['name'] : false";

        $cacheKeysVar = "\$valkeyCacheKeys_{$placementHash}";
        $cacheBlockVar = "\$valkeyCacheBlock_{$placementHash}";
        $contentVar = "\$valkeyContent_{$placementHash}";
        $cachedTextVar = "\$valkeyCachedText_{$placementHash}";

        $code  = $cacheKeysVar . " = array(\n";
        $code .= "    'accessName' => " . $accessNameCode . ",\n";
        $code .= "    'placementKeyString' => " . $placementStringText . ",\n";
        $code .= ");\n";

        if ( $keysCode !== null )
        {
            $code .= "if ( " . $keysCode . " !== null )\n";
            $code .= "    " . $cacheKeysVar . "['custom'] = " . $keysCode . ";\n";
        }

        $code .= "if ( !" . $ignoreContentExpiryCode . " )\n";
        $code .= "{\n";
        $code .= "    " . $cacheKeysVar . "['globalExpiry'] = max(\n";
        $code .= "        eZExpiryHandler::getTimestamp( 'global-template-block-cache', -1 ),\n";
        $code .= "        eZExpiryHandler::getTimestamp( 'template-block-cache', -1 )\n";
        $code .= "    );\n";
        $code .= "}\n";

        $code .= $cacheBlockVar . " = sevenxValkeyCacheBlock::instance();\n";
        $code .= $contentVar . " = " . $cacheBlockVar . "->get( " . $cacheKeysVar . ", " . $lockCode . ", 0, " . $nodeExpiryCode . ", " . $subTreeExpiryCode . " );\n";
        $code .= "if ( " . $contentVar . " !== false )\n";
        $code .= "{\n";

        $newNodes[] = eZTemplateNodeTool::createCodePieceNode( $code, array( 'spacing' => 0 ) );
        $newNodes[] = eZTemplateNodeTool::createWriteToOutputVariableNode( "valkeyContent_{$placementHash}", array( 'spacing' => 4 ) );
        $newNodes[] = eZTemplateNodeTool::createCodePieceNode( "    unset( " . $contentVar . " );\n}\nelse\n{\n    unset( " . $contentVar . " );" );

        $newNodes[] = eZTemplateNodeTool::createOutputVariableIncreaseNode( array( 'spacing' => 4 ) );
        $newNodes[] = eZTemplateNodeTool::createSpacingIncreaseNode( 4 );
        $newNodes = array_merge( $newNodes, $children );
        $newNodes[] = eZTemplateNodeTool::createSpacingDecreaseNode( 4 );
        $newNodes[] = eZTemplateNodeTool::createAssignFromOutputVariableNode( "valkeyCachedText_{$placementHash}", array( 'spacing' => 4 ) );

        $code = $cacheBlockVar . "->put( " . $cacheKeysVar . ", " . $cachedTextVar . ", " . $ttlCode . ", " . $subTreeExpiryCode . ", " . $nodeExpiryCode . " );\n";
        $newNodes[] = eZTemplateNodeTool::createCodePieceNode( $code, array( 'spacing' => 4 ) );
        $newNodes[] = eZTemplateNodeTool::createOutputVariableDecreaseNode( array( 'spacing' => 4 ) );
        $newNodes[] = eZTemplateNodeTool::createWriteToOutputVariableNode( "valkeyCachedText_{$placementHash}", array( 'spacing' => 4 ) );
        $newNodes[] = eZTemplateNodeTool::createCodePieceNode( "    unset( " . $cachedTextVar . ", " . $cacheBlockVar . " );\n}\n" );

        return $newNodes;
    }

    /**
     * Runtime fallback for {valkey-block}. Used when compile-time transformation
     * is not available.
     */
    function process( $tpl, &$textElements, $functionName, $functionChildren, $functionParameters, $functionPlacement, $rootNamespace, $currentNamespace )
    {
        if ( $functionName !== $this->BlockName )
        {
            return;
        }

        $ini = eZINI::instance();
        if ( $ini->variable( 'TemplateSettings', 'TemplateCache' ) != 'enabled' )
        {
            $textElements[] = $this->processChildren( $tpl, $functionChildren, $rootNamespace, $currentNamespace );
            return;
        }

        $accessName = isset( $GLOBALS['eZCurrentAccess']['name'] ) ? $GLOBALS['eZCurrentAccess']['name'] : false;
        $placementString = eZTemplateCacheBlock::placementString( $functionPlacement );

        $cacheKeysData = array(
            'accessName'        => $accessName,
            'placementKeyString' => $placementString,
        );

        if ( isset( $functionParameters['keys'] ) )
        {
            $keys = $tpl->elementValue( $functionParameters['keys'], $rootNamespace, $currentNamespace, $functionPlacement );
            if ( $keys !== null )
            {
                $cacheKeysData['custom'] = $keys;
            }
        }

        $expiry = self::DEFAULT_TTL;
        if ( isset( $functionParameters['expiry'] ) )
        {
            $value = $tpl->elementValue( $functionParameters['expiry'], $rootNamespace, $currentNamespace, $functionPlacement );
            $value = (int) $value;
            if ( $value > 0 )
            {
                $expiry = $value;
            }
        }

        $subtreeExpiry = 0;
        if ( isset( $functionParameters['subtree_expiry'] ) )
        {
            $subtreeExpiry = $tpl->elementValue( $functionParameters['subtree_expiry'], $rootNamespace, $currentNamespace, $functionPlacement );
            $subtreeExpiry = (int) $subtreeExpiry;
        }

        $nodeExpiry = 0;
        if ( isset( $functionParameters['node_expiry'] ) )
        {
            $nodeExpiry = $tpl->elementValue( $functionParameters['node_expiry'], $rootNamespace, $currentNamespace, $functionPlacement );
            $nodeExpiry = (int) $nodeExpiry;
        }

        $ignoreContentExpiry = false;
        if ( isset( $functionParameters['ignore_content_expiry'] ) )
        {
            $ignoreContentExpiry = $tpl->elementValue( $functionParameters['ignore_content_expiry'], $rootNamespace, $currentNamespace, $functionPlacement ) === true;
        }
        if ( $subtreeExpiry || $nodeExpiry )
        {
            $ignoreContentExpiry = true;
        }

        if ( !$ignoreContentExpiry )
        {
            $globalExpiryTime = eZExpiryHandler::getTimestamp( 'template-block-cache', -1 );
            $globalExpiryTime = max( eZExpiryHandler::getTimestamp( 'global-template-block-cache', -1 ), $globalExpiryTime );
            if ( $globalExpiryTime > 0 )
            {
                $cacheKeysData['globalExpiry'] = $globalExpiryTime;
            }
        }

        $lock = true;
        if ( isset( $functionParameters['lock'] ) )
        {
            $lock = $tpl->elementValue( $functionParameters['lock'], $rootNamespace, $currentNamespace, $functionPlacement ) !== false;
        }

        $cache = sevenxValkeyCacheBlock::instance();
        $content = $cache->get( $cacheKeysData, $lock, 0, $nodeExpiry, $subtreeExpiry );

        if ( $content === false )
        {
            $text = $this->processChildren( $tpl, $functionChildren, $rootNamespace, $currentNamespace );
            $cache->put( $cacheKeysData, $text, $expiry, $subtreeExpiry, $nodeExpiry );
            $textElements[] = $text;
        }
        else
        {
            $textElements[] = $content;
        }
    }

    function hasChildren()
    {
        return true;
    }

    /**
     * Process child nodes and return the resulting text.
     */
    private function processChildren( $tpl, $functionChildren, $rootNamespace, $currentNamespace )
    {
        $childTextElements = array();
        if ( is_array( $functionChildren ) )
        {
            foreach ( array_keys( $functionChildren ) as $childKey )
            {
                $child =& $functionChildren[$childKey];
                $tpl->processNode( $child, $childTextElements, $rootNamespace, $currentNamespace );
            }
        }
        return implode( '', $childTextElements );
    }

    /**
     * Build PHP code for the expiry value. Adds a variable node if the value
     * is not constant.
     */
    private function compileTtl( $parameters, &$newNodes, $placementHash )
    {
        if ( isset( $parameters['expiry'] ) &&
             !eZTemplateNodeTool::isConstantElement( $parameters['expiry'] ) )
        {
            $newNodes[] = eZTemplateNodeTool::createVariableNode( false, $parameters['expiry'], false, array(), 'valkeyExpiry_' . $placementHash );
            return "( \$valkeyExpiry_{$placementHash} > 0 ? \$valkeyExpiry_{$placementHash} : null )";
        }

        $expiry = isset( $parameters['expiry'] ) ? eZTemplateNodeTool::elementConstantValue( $parameters['expiry'] ) : null;
        if ( $expiry !== null && (int)$expiry > 0 )
        {
            return eZPHPCreator::variableText( (int)$expiry, 0, 0, false );
        }
        return 'null';
    }

    /**
     * Build PHP code for the keys parameter. Always uses a runtime variable
     * because it is usually a template array.
     */
    private function compileKeys( $parameters, &$newNodes, $placementHash )
    {
        if ( !isset( $parameters['keys'] ) )
        {
            return null;
        }
        $newNodes[] = eZTemplateNodeTool::createVariableNode( false, $parameters['keys'], false, array(), 'valkeyKeys_' . $placementHash );
        return "\$valkeyKeys_{$placementHash}";
    }

    /**
     * Build PHP code for a node/subtree expiry parameter. Decodes non-numeric
     * values at runtime using eZTemplateCacheBlock::decodeNodeID().
     */
    private function compileNodeId( $parameters, $parameterName, &$newNodes, $placementHash )
    {
        if ( !isset( $parameters[$parameterName] ) )
        {
            return 'null';
        }

        if ( eZTemplateNodeTool::isConstantElement( $parameters[$parameterName] ) )
        {
            $value = eZTemplateNodeTool::elementConstantValue( $parameters[$parameterName] );
            if ( is_numeric( $value ) )
            {
                return (int) $value;
            }
            return 'eZTemplateCacheBlock::decodeNodeID( ' . eZPHPCreator::variableText( $value, 0, 0, false ) . ' )';
        }

        $suffix = $this->parameterSuffix( $parameterName );
        $newNodes[] = eZTemplateNodeTool::createVariableNode( false, $parameters[$parameterName], false, array(), 'valkey' . $suffix . '_' . $placementHash );
        $var = "\$valkey" . $suffix . '_' . $placementHash;
        return "( is_numeric( $var ) ? (int)$var : eZTemplateCacheBlock::decodeNodeID( $var ) )";
    }

    /**
     * Build PHP code for the lock parameter.
     */
    private function compileLock( $parameters, &$newNodes, $placementHash )
    {
        if ( !isset( $parameters['lock'] ) )
        {
            return 'true';
        }

        if ( eZTemplateNodeTool::isConstantElement( $parameters['lock'] ) )
        {
            $value = eZTemplateNodeTool::elementConstantValue( $parameters['lock'] );
            return $value ? 'true' : 'false';
        }

        $newNodes[] = eZTemplateNodeTool::createVariableNode( false, $parameters['lock'], false, array(), 'valkeyLock_' . $placementHash );
        return "( \$valkeyLock_{$placementHash} !== false )";
    }

    /**
     * Build PHP code for the ignore_content_expiry flag. If node or subtree
     * expiry is set this is always true.
     */
    private function compileIgnoreContentExpiry( $parameters, &$newNodes, $placementHash )
    {
        if ( isset( $parameters['subtree_expiry'] ) || isset( $parameters['node_expiry'] ) )
        {
            return 'true';
        }

        if ( !isset( $parameters['ignore_content_expiry'] ) )
        {
            return 'false';
        }

        if ( eZTemplateNodeTool::isConstantElement( $parameters['ignore_content_expiry'] ) )
        {
            $value = eZTemplateNodeTool::elementConstantValue( $parameters['ignore_content_expiry'] );
            return $value ? 'true' : 'false';
        }

        $newNodes[] = eZTemplateNodeTool::createVariableNode( false, $parameters['ignore_content_expiry'], false, array(), 'valkeyIgnoreContentExpiry_' . $placementHash );
        return "(bool) \$valkeyIgnoreContentExpiry_{$placementHash}";
    }

    private function parameterSuffix( $parameterName )
    {
        $map = array(
            'subtree_expiry' => 'SubTreeExpiry',
            'node_expiry'    => 'NodeExpiry',
        );
        return isset( $map[$parameterName] ) ? $map[$parameterName] : ucfirst( $parameterName );
    }
}
