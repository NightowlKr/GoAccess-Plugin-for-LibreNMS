<?php

// Run with a LibreNMS checkout containing installed Composer dependencies.
// No production database or existing checkout files are modified.
$core = $argv[1] ?? getenv('LIBRENMS_PATH');
if (! $core || ! is_file($core . '/vendor/autoload.php')) {
    fwrite(STDERR, "Usage: php tests/run.php /path/to/librenms\n");
    exit(2);
}
require $core . '/vendor/autoload.php';
spl_autoload_register(function (string $class): void {
    $prefix = 'App\\Plugins\\GoAccess\\';
    if (str_starts_with($class, $prefix)) {
        require __DIR__ . '/../GoAccess/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
}, true, true);

use App\Models\User;
use App\Plugins\GoAccess\Menu;
use App\Plugins\GoAccess\Page;
use App\Plugins\GoAccess\Settings;
use App\Plugins\GoAccess\Support\Report;
use Illuminate\Foundation\Application;

$count = 0;
function check(bool $ok, string $message): void
{
    global $count;
    if (! $ok) {
        throw new RuntimeException($message);
    }
    $count++;
}
function rejects(callable $fn, string $label): void
{
    try {
        $fn();
    } catch (RuntimeException $e) {
        check(true, $label);
        return;
    }
    check(false, $label);
}

$app = new Application($core);
$db = new \Illuminate\Database\Capsule\Manager;
$db->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$db->bootEloquent();
$app->instance('config', new \Illuminate\Config\Repository([
    'auth' => ['defaults' => ['guard' => 'web'], 'guards' => ['web' => ['driver' => 'session', 'provider' => 'users']], 'providers' => ['users' => ['driver' => 'eloquent', 'model' => User::class]]],
    'permission' => require $core . '/config/permission.php',
]));
$auth = new class implements \Illuminate\Contracts\Auth\Factory {
    public ?User $current = null;
    public function guard($name = null) { return $this; }
    public function shouldUse($name) {}
    public function user() { return $this->current; }
};
$app->instance('auth', $auth);
$gate = new \Illuminate\Auth\Access\Gate($app, fn () => $auth->current);
$gate->define('global-read', fn (User $user) => $user->hasAnyRole('admin', 'global-read'));
$gate->define('plugin.admin', fn (User $user) => $user->hasRole('admin'));
$app->instance(\Illuminate\Contracts\Auth\Access\Gate::class, $gate);
function user(string $role): User
{
    $user = new User;
    $user->exists = true;
    $user->setRelation('roles', collect([new \Spatie\Permission\Models\Role(['name' => $role, 'guard_name' => 'web'])]));
    return $user;
}

// Real Hook classes and final handle() dependency injection, not interface stubs.
$page = new Page;
$settings = new Settings;
$menu = new Menu;
foreach (['admin' => true, 'global-read' => true, 'user' => false] as $role => $allowed) {
    $auth->current = user($role);
    check($page->authorize($auth->current) === $allowed, "Page role $role");
    check($menu->authorize($auth->current) === $allowed, "Menu role $role");
    check($settings->authorize($auth->current) === ($role === 'admin'), "Settings role $role");
}
$auth->current = null;
check($page->data()['report'] === null, 'Guest direct invocation fails closed');
check($settings->data()['reportPath'] === '', 'Guest settings does not disclose path');
check(! $menu->authorize(new User), 'An unsaved default user has no menu');

$dir = sys_get_temp_dir() . '/goaccess-test-' . bin2hex(random_bytes(6));
mkdir($dir);
$file = $dir . '/report.html';
file_put_contents($file, '<html><head></head><body><script>window.reportLoaded=true</script><p>Traffic</p></body></html>');
// Windows PHP accepts slash-rooted paths on the current drive; plugin deployment is Linux.
$path = preg_replace('/^[A-Za-z]:/', '', str_replace('\\', '/', $file));
try {
    foreach ([null, [], 42, '', 'report.html', 'https://example.org/r.html', 'php://filter/r.html',
        '//host/r.html', '/tmp/../r.html', '/tmp/./r.html', "/tmp/a\0.html", '/tmp/a.php', '/tmp/a\\b.html',
        str_repeat('a', 4097), $dir . '/missing.html'] as $bad) {
        rejects(fn () => Report::resolve($bad), 'Reject malformed/missing path');
    }
    $auth->current = user('global-read');
    $data = $page->handle('GoAccess', ['report_path' => $path], $app);
    check($data['content_view'] === 'GoAccess::resources.views.page', 'Actual page view namespace');
    check(str_contains($data['report'], 'Traffic'), 'Actual handle injects stored settings');
    check(strpos($data['report'], 'Content-Security-Policy') < strpos($data['report'], 'window.reportLoaded'), 'CSP precedes report scripts');
    check(str_contains($data['report'], "connect-src &#039;none&#039;"), 'Report network connections blocked');
    $auth->current = user('user');
    check($page->data(['report_path' => $path])['report'] === null, 'Direct call rejects ordinary user');
    $auth->current = user('admin');
    check($settings->handle('GoAccess', ['report_path' => $path], $app)['pathError'] === null, 'Settings handle validates readable path');
    check($settings->data(['report_path' => []])['pathError'] !== null, 'Malformed persisted setting handled without crash');
    check($page->data(['report_path' => []])['report'] === null, 'Malformed persisted setting never displayed');

    // Compile and execute actual Blade views, checking attribute escaping.
    $files = new \Illuminate\Filesystem\Filesystem;
    $compiler = new \Illuminate\View\Compilers\BladeCompiler($files, $dir);
    $blade = $compiler->compileString(file_get_contents(__DIR__ . '/../GoAccess/resources/views/page.blade.php'));
    $reportError = null;
    $report = '\"><img src=x onerror=alert(1)>';
    ob_start();
    eval('?>' . $blade);
    $rendered = ob_get_clean();
    check(str_contains($rendered, 'sandbox="allow-scripts"'), 'Sandbox enables only scripts');
    check(! str_contains($rendered, 'allow-same-origin'), 'Report cannot access parent origin');
    check(! str_contains($rendered, '<img'), 'Report cannot escape srcdoc attribute');
    check(str_contains($rendered, '&lt;img'), 'Blade escapes report HTML');
    $reportError = '<script>alert(1)</script>';
    ob_start();
    eval('?>' . $blade);
    $rendered = ob_get_clean();
    check(! str_contains($rendered, '<iframe'), 'Error does not render report iframe');
    check(! str_contains($rendered, '<script>'), 'Errors are escaped');
    foreach (['menu', 'settings'] as $name) {
        $compiled = $compiler->compileString(file_get_contents(__DIR__ . '/../GoAccess/resources/views/' . $name . '.blade.php'));
        check(strlen($compiled) > 0, "$name Blade compiles");
    }
    check(str_contains(file_get_contents(__DIR__ . '/../GoAccess/resources/views/settings.blade.php'), '@csrf'), 'Settings uses host CSRF protection');

    // Actual LibreNMS Plugin model and PluginManager, isolated SQLite DB.
    $db->getConnection()->getSchemaBuilder()->create('plugins', function ($table): void {
        $table->increments('plugin_id');
        $table->string('plugin_name');
        $table->boolean('plugin_active');
        $table->integer('version');
        $table->text('settings')->nullable();
    });
    $manager = new \App\Plugins\PluginManager;
    check($manager->setSettings('GoAccess', ['report_path' => $path]), 'Actual manager saves DB settings');
    check((new \App\Plugins\PluginManager)->getSettings('GoAccess')['report_path'] === $path, 'New manager reads DB settings');
    foreach ([Menu::class, Page::class, Settings::class] as $hook) {
        $interfaces = array_filter(class_implements($hook), fn ($i) => str_starts_with($i, 'LibreNMS\\Interfaces\\Plugins\\Hooks\\'));
        check(count($interfaces) === 1, 'Exactly one discoverable Hook per top-level class');
        check($manager->publishHook('GoAccess', array_values($interfaces)[0], $hook), 'Actual manager registers Hook');
    }

    file_put_contents($file, '');
    rejects(fn () => Report::document($path), 'Empty report rejected');
    $handle = fopen($file, 'wb');
    ftruncate($handle, Report::MAX_BYTES + 1);
    fclose($handle);
    rejects(fn () => Report::document($path), 'Oversized report rejected');
    if (PHP_OS_FAMILY !== 'Windows') {
        file_put_contents($dir . '/secret.txt', 'private');
        symlink($dir . '/secret.txt', $dir . '/link.html');
        rejects(fn () => Report::resolve($dir . '/link.html'), 'Symlink to non-HTML denied');
        unlink($dir . '/link.html');
        unlink($dir . '/secret.txt');
    }
    echo "PASS: $count checks using real LibreNMS Hooks, Blade, models and PluginManager.\n";
} finally {
    foreach (glob($dir . '/*') as $temporary) {
        unlink($temporary);
    }
    rmdir($dir);
}
