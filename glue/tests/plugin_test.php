<?php
declare(strict_types=1);

// Run: docker compose run --rm --no-deps glue php -d zend.assertions=1 tests/plugin_test.php
$dir = sys_get_temp_dir() . '/plugins-' . getmypid();
mkdir($dir);
putenv("PLUGINS_DIR=$dir");
putenv('PAYLOAD_LOG=off');
require __DIR__ . '/../src/sync.php';

$put = fn(string $name, string $code) => file_put_contents("$dir/$name.php", "<?php\nreturn $code;");
$put('10-upper', '["transform" => ["erp.write" => fn(array $p) => isset($p["CITY"]) ? [...$p, "CITY" => strtoupper($p["CITY"])] : null]]');
$put('20-broken', '["transform" => ["erp.write" => fn() => throw new RuntimeException("boom")]]');
$put('30-look', '["transform" => ["erp.write" => function (array $p) { $GLOBALS["seen"] = $p; return null; }],
                  "observe" => ["erp.write" => function (array $p, array $ctx) { $GLOBALS["told"] = [$p, $ctx["status"]]; throw new RuntimeException("observer down"); }]]');
file_put_contents("$dir/40-syntax-error.php", '<?php return [');
$put('45-wrong-shape', '["erp.write" => fn() => null]');
$ctx = ['event' => 'erp.write', 'system' => 'erp', 'method' => 'PUT', 'path' => '/api/customers/C-1', 'id' => 'x'];

// transforms run in file-name order: 30 sees what 10 made of it; failing or unloadable plugins do not stop the others
assert(transform('erp.write', ['CITY' => 'Wien'], $ctx) === ['CITY' => 'WIEN']);
assert($GLOBALS['seen'] === ['CITY' => 'WIEN']);
assert(array_keys(plugins()['transform']['erp.write']) === [0, 1, 2]);

// only hooks of that event run
assert(transform('wms.write', ['shipCity' => 'Wien'], $ctx) === ['shipCity' => 'Wien']);

// an observer gets the final payload; its failure does not propagate (the write already happened)
notify('erp.write', ['CITY' => 'WIEN'], [...$ctx, 'status' => 200]);
assert($GLOBALS['told'] === [['CITY' => 'WIEN'], 200]);

// disable = rename to .off, add = drop a file: both picked up on the next write, no restart
rename("$dir/10-upper.php", "$dir/10-upper.php.off");
assert(transform('erp.write', ['CITY' => 'Wien'], $ctx) === ['CITY' => 'Wien']);
$put('50-reject', '["transform" => ["erp.write" => fn() => throw new Permanent("not allowed", 422)]]');
try {
    transform('erp.write', ['CITY' => 'Wien'], $ctx);
    assert(false, 'Permanent from a transform must reject the write');
} catch (Permanent) {
}

array_map('unlink', glob("$dir/*"));
rmdir($dir);
echo "plugin tests passed\n";
