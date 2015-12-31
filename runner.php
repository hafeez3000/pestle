<?php
namespace Pulsestorm\Pestle\Runner;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use function Pulsestorm\Cli\Token_Parse\getFunctionFromCode;
use function Pulsestorm\Cli\Build_Command_List\buildCommandList;

include 'vendor/autoload.php';
use ReflectionFunction;

function extractFunctionNameAndNamespace($full)
{
    $full       = strToLower($full);
    $parts      = explode('\\', $full);
    $short_name = array_pop($parts);
    $namespace  = implode('/',$parts);
    
    return [
        'short_name'=>$short_name,
        'namespace' =>$namespace
    ];
}

function includeModule($function_name)
{
    $function_name = strToLower($function_name);
    $parts         = explode('\\', $function_name);
    $short_name    = array_pop($parts);
    $namespace     = implode('/',$parts);
    $file          = $namespace . '/module.php';
    return include_once('modules/' . $file);
}

function createFunctionForGlobalExport($function_name)
{
    $info = extractFunctionNameAndNamespace($function_name);
    //include in the library
    // $code = '<' . '?php' . "\n" .
    $code = 
    'function ' . $info['short_name'] . '(){
        $args = func_get_args();
        return call_user_func_array(\'\\'.$function_name.'\', $args);
    }';
    return $code;
}

function extractFunction($function_name)
{
    includeModule($function_name);
    $code = createFunctionForGlobalExport($function_name);
    return $code;
}

function getCacheDir()
{
    $cache_dir = realpath(__DIR__) . '/cache';
    if(!is_dir($cache_dir)){ exit("No cache Dir"); }
    return $cache_dir;
}

function includeCode($namespace, $code)
{    
    $cache_dir = getCacheDir();
    $full_dir  = $cache_dir . '/' . str_replace('\\','/',strToLower($namespace));
    $full_path = $full_dir . '/'  . md5($code) . '.php';
    if(file_exists($full_path))
    {
        include_once $full_path;
        return;
    }
    
    if(!is_dir($full_dir))
    {
        mkdir($full_dir, 0755, true);
    }
    
    file_put_contents($full_path,
        '<' . '?' . 'php' . "\n" . $code);        
    include_once $full_path;  
}

function pestle_import($thing_to_import, $as=false)
{
    $thing_to_import = trim($thing_to_import, '\\');
    //include_once 'modules/pulsestorm/magento2/cli/library/module.php';
    $function = extractFunction($thing_to_import);    
    includeCode($thing_to_import, $function);                 
    return true;
}


function output($string)
{
    echo $string,"\n";
}

function getNamespacesAndFunctions()
{
    $functions = get_defined_functions();
    $return    = [];
    foreach($functions['user'] as $function)
    {
        $parts      = explode('\\', $function);
        $function   = array_pop($parts);
        $namespace  = implode('\\', $parts);
        
        $return[$namespace][] = $function;
    }
    return $return;

}

function getNamespaces()
{
    $namespaces = getNamespacesAndFunctions();
    return array_keys($namespaces);
}

function stripDocComment($comment)
{
    $comment = preg_replace(['%^\*/%m', '%^/\*\*%m','%^\* %m','%^\*%m'], '', $comment);
    $parts   = explode('@', $comment);
    return trim($parts[0]);
    // return (string) $comment;
}

function parseDocCommentAts($r)
{
    $comment = $r->getDocComment();
    $comment = preg_replace(['%^\*/%m', '%^/\*\*%m','%^\* %m','%^\*%m'], '', $comment);    
    $parts   = explode('@', $comment);
    array_shift($parts);
    $parsed  = [];
    foreach($parts as $part)
    {
        $part = trim($part);
        $parts2 = preg_split('%\s%', $part);
        $name   = array_shift($parts2);
        $parsed[$name] = implode('',$parts2);
    }
    $parsed = array_map(function($thing){
        return trim($thing);
    }, $parsed);
    
    return $parsed;
}


function getAtCommandFromDocComment($r)
{
    $props = parseDocCommentAts($r);
    if(array_key_exists('command', $props))
    {
        return $props['command'];
    }
    return null;
}

function loadSerializedCommandListFromCache()
{
    $path = getCacheDir() . '/command-list.ser';   
    if(!file_exists($path))
    {
        return [];
    } 
    $command_list = unserialize(
        file_get_contents($path)
    );
    return $command_list;
}

function includeLibraryForCommand($argv, $try_again=true)
{
    $command = array_key_exists(1, $argv) ? $argv[1] : 'help';
    $command_list = loadSerializedCommandListFromCache();
    if(!array_key_exists($command, $command_list) && $try_again)
    {
        include_once 'modules/pulsestorm/cli/build_command_list/module.php';
        buildCommandList();
        return includeLibraryForCommand($argv, false);
    }    
    if(!array_key_exists($command, $command_list))
    {    
        output("Can't find [$command]");
        exit;    
    }
    
    include_once $command_list[$command];
}

function getListOfDefinedCliFunctions()
{
    $namespaces_and_functions = getNamespacesAndFunctions();
    $namespaces = getNamespaces();
    $commands = [];
    $current_namespace = strToLower(__NAMESPACE__);
    foreach($namespaces as $namespace)
    {            
        if(in_array($namespace, ['',$current_namespace,'composer\autoload']))        
        {
            //skip self
            continue;
        }
        $main = $namespace . '\\pestle_cli'; 
        if(!function_exists($main)) { 
            output("Skippng $main -- no such function");
            continue;
        }
        $r = new ReflectionFunction($main);
        $command = getAtCommandFromDocComment($r);
        if($command)
        {
            $commands[$command] = $r;        
        }
        else
        {
            output("Skippng $main -- no @command");
        }
        //output("Importing " . $main);
    }
    return $commands;
}

/**
* Main entry point
*/
function main($argv)
{
    //include in the library for this command
    includeLibraryForCommand($argv);        
    $commands = getListOfDefinedCliFunctions();        
    
    $command = array_key_exists(1, $argv) ? $argv[1] : 'help';
    if(!array_key_exists($command, $commands))
    {
        output("No such command $command");
        exit;
    }
    $command = $commands[$command];
    
    //get arguments
    $to_pass = $argv;
    array_shift($to_pass);    
    array_shift($to_pass);
        
    $command->invoke($to_pass);
}
main($argv);