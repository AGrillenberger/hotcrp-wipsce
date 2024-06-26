<?php
// apispec.php -- HotCRP script for generating OpenAPI specification
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(APISpec_Batch::make_args($argv)->run());
}

class APISpec_Batch {
    /** @var Conf */
    public $conf;
    /** @var Contact */
    public $user;
    /** @var array<string,list<object>> */
    public $api_map;

    function __construct(Conf $conf, $arg) {
        $this->conf = $conf;
        $this->user = $conf->root_user();
        $this->api_map = $conf->api_map();
    }

    /** @return int */
    function run() {
        $fns = array_keys($this->api_map);
        sort($fns);
        $paths = [];
        foreach ($fns as $fn) {
            $aj = [];
            foreach ($this->api_map[$fn] as $j) {
                if (!isset($j->alias))
                    $aj[] = $j;
            }
            if (!empty($aj)) {
                $path = ($aj[0]->paper ?? false) ? "/{p}/{$fn}" : "/{$fn}";
                $paths[$path] = $this->expand($fn);
            }
        }
        fwrite(STDOUT, json_encode([
            "openapi" => "3.0.0",
            "info" => [
                "title" => "HotCRP"
            ],
            "paths" => $paths
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
        return 0;
    }

    /** @return mixed */
    private function expand($fn) {
        $x = [];
        foreach (["GET", "POST"] as $method) {
            if (($j = $this->conf->api($fn, null, $method))) {
                $x[strtolower($method)] = $this->expand1($fn, $method, $j);
            }
        }
        return $x;
    }

    /** @return object */
    private function expand1($fn, $method, $j) {
        $x = (object) [];
        $params = [];
        if ($j->paper ?? false) {
            $params["p"] = [
                "name" => "p",
                "in" => "path",
                "required" => true
            ];
        }
        $mparameters = strtolower($method) . "_parameters";
        foreach ($j->$mparameters ?? $j->parameters ?? [] as $p) {
            $optional = str_starts_with($p, "?");
            $name = $optional ? substr($p, 1) : $p;
            $params[$name] = [
                "name" => $name,
                "in" => "query",
                "required" => !$optional
            ];
        }
        if (!empty($params)) {
            $x->parameters = $params;
        }
        return $x;
    }

    /** @return APISpec_Batch */
    static function make_args($argv) {
        $arg = (new Getopt)->long(
            "name:,n: !",
            "config: !",
            "help,h !"
        )->description("Generate an OpenAPI specification.
Usage: php batch/apispec.php")
         ->helpopt("help")
         ->maxarg(0)
         ->parse($argv);

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        return new APISpec_Batch($conf, $arg);
    }
}
