<?php
// php tests/run.php  — offline tests: every HTTP call is answered by FakeHttp.
require __DIR__.'/bootstrap.php';
foreach (glob(__DIR__.'/*Test.php') as $f) { require $f; }
echo "{$GLOBALS['__passes']} passed, {$GLOBALS['__fails']} failed\n";
exit($GLOBALS['__fails'] ? 1 : 0);
