<?php
error_reporting(-1);
ini_set('display_errors', true);

class test
{
    public function __construct(stdClass $a, stdClass $b = null, int $c = null, stdClass $d = null)
    {
        print_r(func_get_args());
    }
}

require dirname(dirname(__FILE__)).'/src/includes/classes/Core.php';

$dicer = new WebSharks\Dicer\Core();
$test  = $dicer->get(test::class, ['a' => (object) ['a' => true], 'b' => (object) ['b' => true], 'c' => 1, 'd' => (object) ['d' => true]]);
