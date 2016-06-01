<?php
/*
 * Run from php command line. Configure Redis below.
 */
require_once('./RedisTree.php');

$redis = new Redis();
$redis->pconnect('127.0.0.1', 1000);
$tree = new RedisTree($redis);
$cmd = array_shift($argv);
$cmd = array_shift($argv);
print_r(call_user_func_array([$tree,$cmd], $argv));
print_r($redis->getLastError());

?>