<?php

class LuaScriptLoad {
    var $sha = null;
    var $commands = [];
    var $filepath = '.';

    function __construct($redis, $filepath = '.') {
        $this->redis = $redis;
        $this->filepath = $filepath;
        $commands = ['tinsert', 'tchildren', 'tparents', 'tpath', 'trem', 'tmrem',
  'tdestroy', 'texists', 'trename', 'tprune', 'tmovechildren'];
        $head = implode(' ', array_filter($this->loadScript('_head'), [$this,'isNotCommend']))." ";
        $deleteReference = implode(' ', array_filter($this->loadScript('_delete_reference'), [$this,'isNotCommend']))." ";
        $getPath = implode(' ', array_filter($this->loadScript('_get_path'), [$this,'isNotCommend']))." ";
        
        $this->commands = array_map(function ($command) use ($head, $deleteReference,$getPath) {
            $lua = implode(' ', array_filter($this->loadScript($command), [$this,'isNotCommend']))." ";
            if ($command === 'trem' || $command === 'tdestroy' || $command === 'tmrem' || $command === 'tprune') {
                $lua = $deleteReference . $lua;
            } else if ($command === 'tpath' || $command === 'tinsert' || $command === 'tmovechildren') {
                $lua = $getPath . $lua;
            }
        
            $ret = [
                'name' => $command,
                'lua' => $head . $lua,
                'sha' => $this->redis->script('load', $head . $lua),
            ];
            
            return $ret;
            
        }, $commands);
    }
    
    function loadScript($name, $asArray = true) {
        $fn = $this->filepath. '/'. $name . '.lua';
        return ( $asArray ? file($fn) : file_get_contents($fn));
    }

    function isNotCommend($line) {
        return (substr(trim($line),0, 2) !== '--');
    }

}



class RedisTree {
    var $redis;
    var $commands= [];
    function __construct($redis) {
        $commands = (new LuaScriptLoad($redis,'__DIR__'.'/lua'))->commands;
        $this->redis = $redis;
        
        foreach($commands as $command) {
            $name = $command['name'];
            $sha = $command['sha'];
            $this->commands[$name] = function($args) use ($sha) {
                return $this->redis->evalSha($sha, $args, 1);
            };
        }
    }

    function __call($name, $arguments) {
        return $this->commands[$name]($arguments);
    }
    function convertNode($node) {
        $ret = [
            'node' => $node[0],
            'hasChild'=> !!$node[1]
        ];

        if (count($node) > 2) {
            $ret['children'] = [];
            
            for ($i = 2; $i < count($node); $i++) {
                array_push($ret['children'],$this->convertNode($node[$i]));
            }
        }
        return $ret;
    }
    
    function tancestors ($key, $node, $options = null) {
      
      
      $argv = [$key, $node];
      
      if ($options && isset($options['level']) ) {
          array_push($argv, 'LEVEL', $options['level']);
      }
      
      return $this->commands['tancestors']($argv);
    }

    function tchildren ($key, $node, $options = null) {
        
        
        $argv = [$key, $node];
        if ($options && isset($options['level']) ) {
            array_push($argv, 'LEVEL', $options['level']);
        }
        
        $val = $this->commands['tchildren']($argv);
        if(!is_array($val)) {
            return $val;
        }
        return array_map([$this,'convertNode'], $val);
    }
    
    function tinsert($key, $parent, $node, $options = null) {
        $argv = [$key, $parent, $node];
        $options = (empty($options) ? []:$options);
        
        if (isset($options['index'])) {
            array_push($argv, 'INDEX', $options['index']);
        } else if (isset($options['before'])) {
            array_push($argv, 'BEFORE', $options['before']);
        } else if (isset($options['after'])) {
            array_push($argv, 'AFTER', $options['after']);
        } else {
            array_push($argv, 'INDEX', -1);
        }
        
        return $this->commands['tinsert']($argv);
    }

    function tmrem($key, $node, $options = null) {
        $argv = [$key, $node];
        $options = (empty($options) ? []:$options);

        if (isset($options['not'])) {
            array_push($argv, 'NOT', $options['not']);
        }
        return $this->commands['tmrem']($argv);
    }
}

