# Stackmint

Core repository for the Stackmint PHP framework.

This repo is intentionally kept separate from the test application. The framework is being built around explicit wiring and no hidden framework magic.

The runtime package is currently published as `wayfinder/core`, and the namespaces remain `Wayfinder\\...`. For package naming, starter distribution, and local Composer override workflow. To install the framework starter, go here: [trafficinc/stackmint](https://github.com/trafficinc/stackmint)

## Current state

Stackmint currently includes:

- an explicit request lifecycle through `Wayfinder\Foundation\AppKernel`
- HTTP request and response objects in `Wayfinder\Http`
- cookie primitives, file-backed sessions, and session-backed auth primitives
- a router with route params, named routes, controller actions, middleware, and event hooks in `Wayfinder\Routing`
- a fluent PDO-backed database adapter in `Wayfinder\Database`
- a small container, config repository, and event dispatcher in `Wayfinder\Support`
- a PHP-based view renderer in `Wayfinder\View`
- a small CLI and migration runner
- an in-process HTTP testing client in `Wayfinder\Testing`
- a thin file-based logger in `Wayfinder\Logging`
- a thin runtime cache layer in `Wayfinder\Cache`
- a thin file-backed queue and mail layer
- an explicit `APP_KEY`-backed encrypter in `Wayfinder\Security`
- an application-level module system with discovery, providers, routes, views, config, and migrations
- environment loading through `.env`

## Request lifecycle

The front controller should create or load an `AppKernel` and call `run()`:

```php
<?php

use Wayfinder\Foundation\AppKernel;

$app = require __DIR__ . '/../bootstrap/app.php';
$app->run();
```

`AppKernel` creates a `Request` from PHP globals, passes it to the router, and sends the returned `Response`.
It also catches uncaught exceptions and converts them into `500 Internal Server Error` responses. When debug mode is enabled, the response includes exception details and a stack trace.
Validation exceptions are converted into `422` JSON responses with an `errors` payload.

`Response` can also queue cookies explicitly:

```php
<?php

use Wayfinder\Http\Cookie;
use Wayfinder\Http\Response;

return Response::json(['ok' => true])
    ->withCookie(Cookie::make('theme', 'light'));
```

Redirect responses are available through `Response::redirect()` and can carry session flash data:

```php
<?php

return Response::redirect('/')
    ->withFlash($request->session(), 'status', 'Saved successfully.');
```

## Request helpers and validation

`Wayfinder\Http\Request` now includes small input helpers:

- `all()`
- `input()`
- `string()`
- `integer()`
- `boolean()`
- `old()`
- `errors()`
- `validate()`

Example:

```php
<?php

$data = $request->validate([
    'name' => 'required|string',
    'email' => 'required|email',
    'age' => 'nullable|integer',
]);
```

Supported validation rules:

| Rule | Example | Notes |
|---|---|---|
| `required` | `required` | Fails if the field is absent or empty |
| `nullable` | `nullable` | Allows null/empty; stores `null` in validated output |
| `string` | `string` | Must be a PHP string |
| `integer` | `integer` | Must pass `FILTER_VALIDATE_INT` |
| `numeric` | `numeric` | Must pass `is_numeric()` (allows floats) |
| `boolean` | `boolean` | Must be coercible to a boolean |
| `array` | `array` | Must be a PHP array |
| `email` | `email` | Must be a valid email address |
| `url` | `url` | Must be a valid URL |
| `date` | `date` | Must be parseable by `strtotime()` |
| `min` | `min:3` | Strings: min char length; numerics: min value; arrays: min item count |
| `max` | `max:255` | Strings: max char length; numerics: max value; arrays: max item count |
| `confirmed` | `confirmed` | Input must have a matching `{field}_confirmation` field |
| `same` | `same:other_field` | Must equal another field's value |
| `exists` | `exists:table,column` | Value must exist in the given table/column |
| `unique` | `unique:table,column` | Value must not already exist in the given table/column |

`unique` also supports an ignore value and id column for update flows:

```php
'email' => 'required|email|unique:users,email,{$id},id'
```

Placeholders like `{$id}` are resolved from route parameters first, then request input.

For reusable validation, extend `Wayfinder\Http\FormRequest` and type-hint it in a controller action. The router will resolve it from the current request and run validation before the controller executes.

```php
<?php

use Wayfinder\Http\FormRequest;

final class StoreUserRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:100',
            'email' => 'required|email',
            'password' => 'required|string|min:8|confirmed',
            'age' => 'required|integer|min:18',
            'website' => 'nullable|url',
        ];
    }
}
```

Override `messages()` to provide custom error text keyed as `field.rule`:

```php
public function messages(): array
{
    return [
        'name.required'      => 'Please enter your name.',
        'password.min'       => 'Password must be at least 8 characters.',
        'password.confirmed' => 'Passwords do not match.',
        'age.min'            => 'You must be at least 18 to register.',
        'website.url'        => 'Please enter a valid website URL.',
    ];
}
```

That means an update request object can safely ignore the current record:

```php
<?php

use Wayfinder\Http\FormRequest;

final class UpdateUserRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'email' => 'required|email|unique:users,email,{$id},id',
        ];
    }
}
```

For browser-style form posts running inside the session middleware, validation failures redirect back and flash:

- `_errors` into the session
- `_old_input` into the session

That lets controllers and views read the previous form state through:

```php
$message = $request->old('message');
$errors = $request->errors();
```

Validation can also target a named error bag:

```php
$request->validate([
    'email' => 'required|email',
], [], 'login');

$errors = $request->errors('login');
$email = $request->old('email', '', 'login');
```

Wayfinder also autoloads a small set of plain PHP template helpers:

- `e($value)` for HTML escaping
- `attrs([...])` for rendering HTML attributes
- `checked($current, $expected = true, $strict = false)`
- `selected($current, $expected = true, $strict = false)`
- `disabled($condition = true)`

Examples:

```php
<h1><?= e($title) ?></h1>

<a <?= attrs([
    'href' => '/catalog',
    'class' => ['btn', 'btn-primary'],
    'data-id' => 42,
]) ?>>Catalog</a>

<input type="checkbox" name="terms" value="1" <?= checked($request->old('terms'), '1') ?>>

<option value="card" <?= selected($request->old('payment_option'), 'card') ?>>Card</option>

<button type="submit" <?= disabled($isLocked) ?>>Save</button>
```

## Bootstrap and configuration

Wayfinder now supports explicit app bootstrap through a small set of support services:

- `Wayfinder\Support\Config` loads `config/*.php`
- `Wayfinder\Support\Env` loads `.env`
- `Wayfinder\Support\Container` manages bindings, singletons, and instances
- `Wayfinder\Support\EventDispatcher` handles in-process event listeners

Config can also be loaded from a cached PHP array file when present.

## Modules

Wayfinder now supports explicit application modules under `Modules/*`.

The framework provides:

- filesystem discovery of module directories
- optional manifest caching for discovered modules
- per-module config loading
- per-module routes, views, and migration paths
- module service providers with `register()` and `boot()`
- config-driven enable/disable flags and module registration order
- CLI helpers for module install and uninstall

Each module can expose:

- `module.php`
- `config/*.php`
- `routes/web.php`
- `resources/views`
- `database/migrations`
- `ModuleServiceProvider.php`

The minimal metadata file looks like:

```php
<?php

return [
    'provider' => Modules\Blog\ModuleServiceProvider::class,
];
```

Views are namespaced by module key, so a `Blog` module view can be rendered as:

```php
$view->response('blog::index');
```

Module providers extend `Wayfinder\Module\ServiceProvider`:

```php
<?php

use Wayfinder\Module\Module;
use Wayfinder\Module\ServiceProvider;
use Wayfinder\Routing\Router;
use Wayfinder\Support\Config;
use Wayfinder\Support\Container;

final class ModuleServiceProvider extends ServiceProvider
{
    public function register(Container $container, Config $config, Module $module): void
    {
        // Bind module services.
    }

    public function boot(Container $container, Router $router, Config $config, Module $module): void
    {
        // Register middleware groups, listeners, etc.
    }
}
```

Wayfinder apps can install packaged modules into `Modules/` with:

```bash
php wayfinder module:install auth
php wayfinder module:uninstall auth
```

Built-in aliases like `auth` come from the app's `config/modules.php` package map.

Those aliases are application-level installer shortcuts, not package metadata. A package like `trafficinc/stackmint-auth` should not carry the host app's `auth` alias mapping inside the package repo.

Generic packaged modules can be installed with:

```bash
php wayfinder module:install vendor/package --module=Blog
```

If the package is not on Packagist, pass a repository URL:

```bash
php wayfinder module:install vendor/package --module=Blog --repository=https://github.com/acme/wayfinder-blog
```

For local custom modules that are not packaged yet, you can link a directory directly:

```bash
php wayfinder module:install /absolute/path/to/MyModule --module=MyModule
php wayfinder module:uninstall MyModule
```

The installer uses Composer for packaged modules and then creates a symlink into the app's `Modules/` directory.

For auth-style modules, the post-login and post-registration destination should remain application-controlled. A package like `trafficinc/stackmint-auth` can read `auth.home_route`, but the host app decides whether that should be `/dashboard`, `/projects`, or another authenticated landing page.

## Module Distribution

For local app development, modules can live under `Modules/*` inside the application.

For GitHub distribution, the recommended path is to package a module as its own Composer library instead of copying folders between apps.

Recommended structure for a distributable module package:

```text
stackmint-auth/
  composer.json
  module.php
  ModuleServiceProvider.php
  Controllers/
  Requests/
  Middleware/
  Support/
  config/
  routes/
  resources/views/
  database/migrations/
  README.md
```

Keep foundational app schema out of the module package. For example, the `users` table should stay in the starter app or host application, while a module like `trafficinc/stackmint-auth` should only own auth-specific tables if it truly needs them.

Recommended `composer.json` shape:

```json
{
  "name": "trafficinc/stackmint-auth",
  "type": "library",
  "require": {
    "php": "^8.2",
    "wayfinder/core": "^0.1"
  },
  "autoload": {
    "psr-4": {
      "WayfinderAuth\\": "src/"
    }
  }
}
```

Practical workflow:

1. build and test the module inside an app under `Modules/*`
2. extract it into its own repo
3. install it back into apps through Composer path repositories during development
4. distribute it later from GitHub or Packagist

Current note: Wayfinder’s module system is app-folder based first. Package-based module loading can be added later, but the clean distribution model should still be Composer packages.

The expected pattern is:

```php
<?php

use Wayfinder\Database\Database;
use Wayfinder\Foundation\AppKernel;
use Wayfinder\Routing\Router;
use Wayfinder\Support\Config;
use Wayfinder\Support\Container;
use Wayfinder\Support\EventDispatcher;
use Wayfinder\Support\Events;

$config = Config::fromDirectory(__DIR__ . '/../config');
$container = new Container();
$events = new EventDispatcher();
Events::setDispatcher($events);

$container->instance(Config::class, $config);
$container->singleton(Database::class, fn () => new Database($config->get('database.default')));

$router = new Router($container, $events, 'App\\Controllers\\');

return new AppKernel($router);
```

Once the dispatcher is registered, application and module code can emit domain events with the global helper:

```php
event('cart.submitted', $cart);
event('order.created', $order);
event('rfq.submitted', $rfq);

listen('order.created', function (array $order): void {
    // send mail, write audit log, enqueue follow-up work
});
```

## Sessions, Cookies, and Auth

Wayfinder includes explicit HTTP state primitives instead of relying directly on PHP session globals:

- `Wayfinder\Http\Cookie`
- `Wayfinder\Http\CsrfTokenManager`
- `Wayfinder\Http\VerifyCsrfToken`
- `Wayfinder\Session\Session`
- `Wayfinder\Session\FileSessionStore`
- `Wayfinder\Session\DatabaseSessionStore`
- `Wayfinder\Session\SessionStore`
- `Wayfinder\Session\StartSession`
- `Wayfinder\Auth\AuthManager`
- `Wayfinder\Auth\Authenticate`
- `Wayfinder\Auth\Gate`
- `Wayfinder\Auth\Can`

The intended flow is:

1. bind a session store and `StartSession` middleware in the container
2. attach the session middleware to a route group such as `web` or `api`
3. attach `VerifyCsrfToken` anywhere browser-backed state-changing requests should be protected
3. access the active session from the request with `$request->session()`
4. use `AuthManager` to `login()`, `logout()`, `check()`, `id()`, and `user()`

`AuthManager` rotates the session id on both `login()` and `logout()` to reduce session fixation risk.

Session length is configured through `session.lifetime` / `SESSION_LIFETIME`.

Wayfinder now supports two session drivers:

- `file`
- `database`

Typical config:

```php
<?php

return [
    'driver' => $_ENV['SESSION_DRIVER'] ?? 'file',
    'lifetime' => (int) ($_ENV['SESSION_LIFETIME'] ?? 7200),
    'table' => $_ENV['SESSION_TABLE'] ?? 'sessions',
    'files_path' => __DIR__ . '/../storage/framework/sessions',
];
```

Use `SESSION_DRIVER=database` and create a `sessions` table with `id`, `payload`, and `last_activity` columns to persist sessions in the database.

For new apps, generate the migration with:

```bash
php wayfinder make:session-table
```

## Starter Landing Page

Stackmint now keeps the default starter landing page in framework-owned stubs instead of only in `test-app`:

- `framework/stubs/starter-app/app/Controllers/HomeController.php`
- `framework/stubs/starter-app/app/Views/home/index.php`
- `framework/stubs/starter-app/routes/web.php`

The landing page intentionally keeps its CSS inline in the view so a developer can customize the first screen quickly, the same way many framework starters do, without tracing through shared layout assets first.

Create a new application from the default skeleton with:

```bash
wayfinder new my-app
```

That copies the local starter scaffold into `./my-app`, rewrites the app package name in `composer.json`, and leaves the starter files fully app-owned. For a published starter repo, users should clone `trafficinc/stackmint` directly to begin a new project. After scaffolding:

```bash
cd my-app
cp .env.example .env
composer install
php wayfinder key:generate
php wayfinder migrate
php -S localhost:8000 -t public
```

For authorization, define abilities on `Wayfinder\Auth\Gate` and protect routes with `can:ability` middleware:

```php
<?php

$gate->define('admin.reports', static function (?array $user): bool {
    return (bool) ($user['is_admin'] ?? false);
});

$router->get('/admin/reports', ReportsController::class, 'admin.reports', [
    'auth',
    'can:admin.reports',
]);
```

`Authenticate` returns `401` when no authenticated user is present. `Can` returns `403` when the ability check fails.

`Session` also supports one-request flash data through `flash()` and `pull()`:

```php
$request->session()->flash('status', 'Saved successfully.');
$message = $request->session()->pull('status');
```

`VerifyCsrfToken` automatically seeds a session token for safe requests and expects the same token on unsafe requests through either:

- a `_token` request field
- an `X-CSRF-Token` header
- an `X-XSRF-Token` header

Invalid or missing tokens return `419` JSON:

```json
{
    "message": "CSRF token mismatch."
}
```

Example:

```php
<?php

use Wayfinder\Auth\AuthManager;
use Wayfinder\Http\Request;
use Wayfinder\Http\Response;

final class LoginController
{
    public function __invoke(Request $request, AuthManager $auth): Response
    {
        $auth->login(1);

        return Response::json([
            'authenticated' => $auth->check(),
            'user_id' => $auth->id(),
        ]);
    }
}
```

Protected routes can use the built-in `Wayfinder\Auth\Authenticate` middleware, which returns `401` JSON when no authenticated user is present.

## View Helpers

When a view is rendered with a `request` entry in its data, Wayfinder exposes a thin `$form` helper object to the template:

- `$form->csrfField()`
- `$form->old($key, $default = null, $bag = 'default')`
- `$form->error($key, $bag = 'default')`

Example:

```php
<?= $form->csrfField() ?>
<input name="email" value="<?= htmlspecialchars((string) $form->old('email', '', 'login'), ENT_QUOTES, 'UTF-8') ?>">

<?php if ($error = $form->error('email', 'login')): ?>
    <div><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
```

## Routing

The router is designed to stay explicit. Route registration happens in userland code, typically in `routes/web.php`.

Supported route features:

- static and parameterized routes like `/hello/{name}`
- named routes with `urlFor()`
- closure handlers
- controller handlers via `[Controller::class, 'method']` or `"Controller@method"`
- global middleware and per-route middleware
- middleware aliases and middleware groups
- nested route groups with shared prefixes, names, and middleware
- pre/post dispatch event hooks through an injected event dispatcher

Example:

```php
<?php

use Wayfinder\Http\Request;
use Wayfinder\Http\Response;

$router->get('/', static function (Request $request): Response {
    return Response::text('Hello from Wayfinder');
});

$router->get('/hello/{name}', static function (Request $request, string $name): Response {
    return Response::text("Hello, {$name}");
}, 'hello.show');

$router->aliasMiddleware('auth', App\Middleware\Authenticate::class);
$router->middlewareGroup('web', ['auth']);

$router->group([
    'prefix' => '/admin',
    'name' => 'admin.',
    'middleware' => ['web'],
], static function (Wayfinder\Routing\Router $router): void {
    $router->get('/reports', [Admin\ReportsController::class, 'index'], 'reports');
});

$url = $router->urlFor('hello.show', ['name' => 'ron']);
```

```php
$router->get('/profile', [AccountController::class, 'profile'], 'profile');
$router->post('/password', [AccountController::class, 'savePassword'], 'password.save');
```

If no route matches, the router returns a 404 response.

## Testing

Wayfinder includes a small in-process HTTP test harness:

- `Wayfinder\Testing\TestClient`
- `Wayfinder\Testing\TestResponse`

`TestClient` can:

- make `get`, `post`, `put`, `patch`, and `delete` requests
- persist cookies across requests automatically
- attach headers and cookies explicitly
- seed an authenticated session with `actingAs()`

`TestResponse` can assert:

- status codes
- headers
- redirects
- plain-text fragments
- JSON fragments

Example:

```php
<?php

use Wayfinder\Testing\TestClient;

$client = new TestClient($kernel, $container);

$client->get('/health')
    ->assertStatus(200)
    ->assertSee('Wayfinder request lifecycle is running.');

$client->actingAs(1)
    ->get('/admin/reports')
    ->assertStatus(200)
    ->assertSee('Admin reports');

$client->post('/contact', [
    '_token' => '...',
    'message' => 'x',
])->assertRedirect('/');
```

## Logging

Wayfinder includes a thin logging layer with:

- `Wayfinder\Logging\Logger`
- `Wayfinder\Logging\FileLogger`
- `Wayfinder\Logging\NullLogger`

The logger is intended to stay explicit and small. Typical usage:

```php
<?php

use Wayfinder\Logging\Logger;

final class LogDemoController
{
    public function __construct(
        private readonly Logger $logger,
    ) {
    }

    public function __invoke(): void
    {
        $this->logger->info('Demo log entry written.', [
            'source' => 'api.log-demo',
        ]);
    }
}
```

`AppKernel` also logs uncaught exceptions through the configured logger with request path, method, file, line, and stack trace context.

## Cache

Wayfinder includes a small runtime cache layer with:

- `Wayfinder\Cache\Cache`
- `Wayfinder\Cache\FileCache`
- `Wayfinder\Cache\NullCache`

The intended API is thin and explicit:

```php
<?php

use Wayfinder\Cache\Cache;

final class CacheDemoController
{
    public function __construct(
        private readonly Cache $cache,
    ) {
    }

    public function __invoke(): void
    {
        $value = $this->cache->remember('demo.counter', 3600, static fn () => [
            'token' => bin2hex(random_bytes(6)),
        ]);

        $this->cache->put('other-key', 'value', 600);
        $this->cache->forget('stale-key');
    }
}
```

Supported operations:

- `get`
- `put`
- `has`
- `forget`
- `remember`

## Queue

Wayfinder includes a small queue layer with:

- `Wayfinder\Queue\Queue`
- `Wayfinder\Queue\DatabaseQueue`
- `Wayfinder\Queue\FileQueue`
- `Wayfinder\Queue\JobDispatcher`
- `Wayfinder\Queue\Worker`
- `Wayfinder\Queue\Job`

Jobs are plain classes implementing `Wayfinder\Queue\Job`:

```php
<?php

use Wayfinder\Queue\Job;

final class SendDemoMailJob implements Job
{
    public function handle(array $payload = []): void
    {
        // process payload
    }
}
```

Apps register the generic queue services and CLI commands through `Wayfinder\Queue\QueueBootstrapper`, which binds:

- `Wayfinder\Queue\Queue`
- `Wayfinder\Queue\JobDispatcher`
- `Wayfinder\Queue\Worker`
- `queue:work`
- `queue:recover`
- `queue:status`

Queue usage stays explicit:

```php
$dispatcher->dispatch(SendDemoMailJob::class, [
    'to' => 'user@example.com',
]);
```

Queue drivers supported today are:

- `sync`
- `file`
- `database`
- `redis`

Typical app config uses an environment-driven default:

```php
<?php

return [
    'default' => $_ENV['QUEUE_CONNECTION'] ?? 'sync',
    'max_attempts' => (int) ($_ENV['QUEUE_MAX_ATTEMPTS'] ?? 3),
    'connections' => [
        'sync' => [
            'driver' => 'sync',
        ],
        'file' => [
            'driver' => 'file',
            'path' => __DIR__ . '/../storage/framework/queue',
        ],
        'database' => [
            'driver' => 'database',
            'table' => $_ENV['QUEUE_DATABASE_TABLE'] ?? 'jobs',
        ],
        'redis' => [
            'driver' => 'redis',
            'host' => $_ENV['QUEUE_REDIS_HOST'] ?? '127.0.0.1',
            'port' => (int) ($_ENV['QUEUE_REDIS_PORT'] ?? 6379),
            'database' => (int) ($_ENV['QUEUE_REDIS_DATABASE'] ?? 0),
            'password' => $_ENV['QUEUE_REDIS_PASSWORD'] ?? null,
            'prefix' => $_ENV['QUEUE_REDIS_PREFIX'] ?? 'wayfinder_queue',
            'timeout' => (float) ($_ENV['QUEUE_REDIS_TIMEOUT'] ?? 1.5),
        ],
    ],
];
```

`QUEUE_CONNECTION=sync` runs jobs immediately inside the request. `file`, `database`, and `redis` queue them for later worker processing.

The Redis driver requires the `ext-redis` PHP extension and a reachable Redis server.

`Wayfinder\Queue\Worker` processes one queued job at a time. Apps that register the bootstrapper automatically get:

- `php wayfinder queue:work`
- `php wayfinder queue:recover`
- `php wayfinder queue:status`

If you use the database queue, generate the migration and run it first:

```bash
php wayfinder make:queue-table
php wayfinder migrate
```

## Mail

Wayfinder includes a small mail layer with:

- `Wayfinder\Mail\Mailer`
- `Wayfinder\Mail\FileMailer`
- `Wayfinder\Mail\MailMessage`

Typical usage:

```php
<?php

use Wayfinder\Mail\MailMessage;

$mailer->send(new MailMessage(
    'user@example.com',
    'Welcome',
    'Thanks for signing up.'
));
```

The file mailer writes outbound messages to disk for local development and testing.

## Database

`Wayfinder\Database\Database` is a small fluent query builder on top of PDO. It currently supports `mysql`, `pgsql`, and `sqlite`.

The preferred application-facing entrypoint is `Wayfinder\Database\DB`:

Example:

```php
<?php

use Wayfinder\Database\DB;

$users = DB::table('users')
    ->where('status', 'active')
    ->orderBy('id', 'DESC')
    ->limit(10)
    ->get();
```

Available operations include:

- `table`, `select`, `insert`, `update`, `delete`
- `where`, `orWhere`, grouped conditions, including `IN` and `NOT IN`
- `join`, `orderBy`, `limit`, `offset`
- `get`, `first`, `execute`, `forPage`
- `count`, `exists`, `sum`, `avg`, `min`, `max`
- `value`, `pluck`
- raw `raw`, `query`, and `statement`
- `transaction`, `lastInsertId`

Examples:

```php
$user = DB::table('users')
    ->where('email', $email)
    ->first();

DB::table('users')->insert([
    'name' => 'Ron',
    'email' => 'ron@example.com',
]);

DB::table('users')
    ->where('email', 'ron@example.com')
    ->update(['name' => 'Ron Biuya']);

$emails = DB::table('users')
    ->where(function ($query) {
        $query->where('email', 'ron@example.com')
            ->orWhere('email', 'ava@example.com');
    })
    ->pluck('email');

$count = DB::table('users')->count();

$rows = DB::raw(
    'SELECT id, email FROM users WHERE email LIKE ? ORDER BY id DESC LIMIT 5',
    ['%@example.com']
);
```

### Pagination

Wayfinder exposes two pagination layers:

- low-level query paging through `limit`, `offset`, and `forPage`
- a small immutable `Wayfinder\Pagination\Paginator` value object for returning paged result metadata to application code and views

Use `forPage()` when you already know the page size and only need a query slice:

```php
<?php

use Wayfinder\Database\DB;

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 8;

$users = DB::table('users')
    ->where('status', 'active')
    ->orderBy('name')
    ->forPage($page, $perPage)
    ->get();
```

If the UI also needs total counts and next/previous page state, pair the paged query with a `Paginator`:

```php
<?php

use Wayfinder\Database\DB;
use Wayfinder\Pagination\Paginator;

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 8;

$baseQuery = DB::table('users')->where('status', 'active');
$total = (clone $baseQuery)->count();
$items = (clone $baseQuery)
    ->orderBy('name')
    ->forPage($page, $perPage)
    ->get();

$paginator = new Paginator($items, $total, $perPage, $page);
```

`Paginator` exposes:

- `items()`
- `total()`
- `perPage()`
- `currentPage()`
- `lastPage()`
- `hasPages()`
- `hasPreviousPage()`
- `hasNextPage()`
- `previousPage()`
- `nextPage()`
- `from()`
- `to()`

`Paginator` does not execute queries itself. The repository or service layer is still responsible for running the count query and the paged item query.

#### Example pagination display in the view, just show it dont change code

```php
  <?php if ($paginator->hasPages()): ?>
      <nav class="pagination" aria-label="Results pagination">
          <?php if ($paginator->hasPreviousPage()): ?>
              <a href="/products?page=<?= $paginator->previousPage() ?>">Previous</a>
          <?php endif; ?>

          <span>
              Showing <?= $paginator->from() ?>-<?= $paginator->to() ?>
              of <?= $paginator->total() ?>
          </span>

          <?php for ($page = 1; $page <= $paginator->lastPage(); $page++): ?>
              <?php if ($page === $paginator->currentPage()): ?>
                  <strong><?= $page ?></strong>
              <?php else: ?>
                  <a href="/products?page=<?= $page ?>"><?= $page ?></a>
              <?php endif; ?>
          <?php endfor; ?>

          <?php if ($paginator->hasNextPage()): ?>
              <a href="/products?page=<?= $paginator->nextPage() ?>">Next</a>
          <?php endif; ?>
      </nav>
  <?php endif; ?>

  // If you need filters preserved:

  <?php
  $pageHref = static function (int $page) use ($filters): string {
      return '/products?' . http_build_query([
          'q' => $filters['q'] ?? '',
          'category' => $filters['category'] ?? '',
          'page' => $page,
      ]);
  };
  ?>
  ```

```php
  <?php if ($paginator->hasPages()): ?>
      <nav class="pagination" aria-label="Results pagination">
          <?php if ($paginator->hasPreviousPage()): ?>
              <a href="<?= e($pageHref($paginator->previousPage())) ?>">Previous</a>
          <?php endif; ?>

          <?php for ($page = 1; $page <= $paginator->lastPage(); $page++): ?>
              <a href="<?= e($pageHref($page)) ?>">
                  <?= $page ?>
              </a>
          <?php endfor; ?>

          <?php if ($paginator->hasNextPage()): ?>
              <a href="<?= e($pageHref($paginator->nextPage())) ?>">Next</a>
          <?php endif; ?>
      </nav>
  <?php endif; ?>
```

Use `DB::transaction()` to wrap multi-step workflows. It commits on success and rolls back on any exception, which is then re-thrown:

```php
$user = DB::transaction(function () use ($data) {
    $id = DB::table('users')->insert(['email' => $data['email']]);
    DB::table('profiles')->insert(['user_id' => $id, 'name' => $data['name']]);
    return DB::table('users')->where('id', $id)->first();
});
```

## Encryption

Wayfinder now includes `Wayfinder\Security\Encrypter`, which uses `APP_KEY` for authenticated encryption.

Generate a key with:

```bash
php wayfinder key:generate
```

Then resolve the encrypter from the container and use it explicitly:

```php
<?php

use Wayfinder\Security\Encrypter;

$payload = $encrypter->encrypt(['user_id' => 1]);
$data = $encrypter->decrypt($payload);

$secret = $encrypter->encryptString('top-secret');
$plain = $encrypter->decryptString($secret);
```

The current scope is intentionally small:

- authenticated encryption/decryption of strings and PHP values
- no automatic cookie encryption yet
- no automatic session encryption yet

## Signed URLs

Wayfinder also includes `Wayfinder\Security\UrlSigner` for HMAC-signed URLs backed by `APP_KEY`, plus `Wayfinder\Security\ValidateSignature` middleware for protected routes.

Generate a signed URL explicitly:

```php
<?php

$url = $signer->sign('/downloads/report', [
    'file' => 'monthly.csv',
], time() + 300);
```

Validate it later:

```php
<?php

if (! $signer->hasValidSignature($request)) {
    // reject request
}
```

Or protect a route with signature middleware:

```php
$router->get('/downloads/report', DownloadController::class, middleware: ['signed']);
```

Signed URLs support optional expiration through the `expires` query parameter. Missing, tampered, or expired signatures are rejected.
Opening the raw route without a signed query string is expected to fail with `403 Invalid signature`.

Nested calls run inside the active transaction — commit and rollback are left to the outermost caller:

```php
DB::transaction(function () use ($order, $items) {
    DB::table('orders')->insert($order);
    DB::transaction(fn () => DB::table('order_items')->insert($items));
});
```

## Schema builder

`Wayfinder\Database\Schema` provides a fluent API for creating and modifying tables without writing raw SQL. It works across MySQL, PostgreSQL, and SQLite, adapting types and syntax automatically.

```php
<?php

use Wayfinder\Database\Schema;

Schema::create('users', function (Wayfinder\Database\Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('email')->unique();
    $table->string('password');
    $table->enum('status', ['active', 'inactive'])->default('active');
    $table->timestamp('email_verified_at')->nullable();
    $table->timestamps();
});
```

Use this inside a migration:

```php
<?php

use Wayfinder\Database\Database;
use Wayfinder\Database\Migration;
use Wayfinder\Database\Schema;

return new class implements Migration
{
    public function up(Database $database): void
    {
        Schema::create('posts', function (Wayfinder\Database\Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->string('title');
            $table->longText('body');
            $table->boolean('published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->index('user_id');
        });
    }

    public function down(Database $database): void
    {
        Schema::dropIfExists('posts');
    }
};
```

`make:migration` generates the right shell automatically based on the migration name:

```bash
php wayfinder make:migration create_posts_table     # Schema::create() shell
php wayfinder make:migration add_slug_to_posts      # Schema::table() shell
```

### Column types

| Method | MySQL | PostgreSQL | SQLite |
|---|---|---|---|
| `id()` | `BIGINT UNSIGNED AUTO_INCREMENT` | `BIGSERIAL` | `INTEGER PRIMARY KEY AUTOINCREMENT` |
| `string($col, $len = 255)` | `VARCHAR(n)` | `VARCHAR(n)` | `TEXT` |
| `text($col)` | `TEXT` | `TEXT` | `TEXT` |
| `longText($col)` | `LONGTEXT` | `TEXT` | `TEXT` |
| `integer($col)` | `INT` | `INTEGER` | `INTEGER` |
| `tinyInteger($col)` | `TINYINT` | `SMALLINT` | `INTEGER` |
| `smallInteger($col)` | `SMALLINT` | `SMALLINT` | `INTEGER` |
| `bigInteger($col)` | `BIGINT` | `BIGINT` | `INTEGER` |
| `unsignedInteger($col)` | `INT UNSIGNED` | `INTEGER` | `INTEGER` |
| `unsignedBigInteger($col)` | `BIGINT UNSIGNED` | `BIGINT` | `INTEGER` |
| `foreignId($col)` | `BIGINT UNSIGNED` | `BIGINT` | `INTEGER` |
| `boolean($col)` | `TINYINT(1)` | `BOOLEAN` | `INTEGER` |
| `decimal($col, $p, $s)` | `DECIMAL(p,s)` | `DECIMAL(p,s)` | `NUMERIC` |
| `float($col)` | `FLOAT` | `REAL` | `REAL` |
| `double($col)` | `DOUBLE` | `DOUBLE PRECISION` | `REAL` |
| `date($col)` | `DATE` | `DATE` | `TEXT` |
| `dateTime($col)` | `DATETIME` | `TIMESTAMP(0) WITHOUT TIME ZONE` | `TEXT` |
| `timestamp($col)` | `TIMESTAMP` | `TIMESTAMP(0) WITHOUT TIME ZONE` | `TEXT` |
| `json($col)` | `JSON` | `JSONB` | `TEXT` |
| `uuid($col)` | `CHAR(36)` | `UUID` | `TEXT` |
| `enum($col, $values)` | `ENUM(...)` | `TEXT CHECK (col IN (...))` | `TEXT CHECK (col IN (...))` |
| `binary($col)` | `BLOB` | `BYTEA` | `BLOB` |
| `timestamps()` | nullable `created_at` + `updated_at` | — | — |
| `softDeletes($col)` | nullable `deleted_at` | — | — |

### Column modifiers

```php
$table->string('name')->nullable();
$table->integer('score')->default(0);
$table->string('role')->default('user')->unique();
$table->string('city')->nullable()->after('address'); // MySQL only
$table->string('title')->change();                    // modify existing column
```

### Indexes

```php
$table->unique('email');
$table->unique(['first_name', 'last_name'], 'users_fullname_unique');
$table->index('user_id');
$table->index(['status', 'created_at']);
$table->primary(['tenant_id', 'user_id']); // composite primary key
```

### Modifying tables

```php
Schema::table('users', function (Wayfinder\Database\Blueprint $table) {
    $table->string('avatar')->nullable();           // ADD COLUMN
    $table->integer('login_count')->default(0);

    $table->dropColumn('legacy_field');
    $table->dropColumn(['field_a', 'field_b']);
    $table->renameColumn('bio', 'about');
    $table->dropIndex('users_old_field_index');
    $table->dropUnique('users_slug_unique');
});
```

Modifying an existing column's type or constraints requires `->change()`:

```php
Schema::table('posts', function (Wayfinder\Database\Blueprint $table) {
    $table->string('title', 500)->change();           // MySQL, PostgreSQL only
});
```

### Table operations

```php
Schema::drop('old_table');
Schema::dropIfExists('temp_table');
Schema::rename('old_name', 'new_name');

if (Schema::hasTable('users')) { ... }
if (Schema::hasColumn('users', 'email')) { ... }
```

## Views

`Wayfinder\View\View` renders PHP templates from an explicit base path.

Example:

```php
<?php

use Wayfinder\View\View;

$view = new View(__DIR__ . '/../app/Views');

$html = $view->render('home.index', [
    'title' => 'Wayfinder',
]);
```

```php
  <?php

  namespace App\Controllers;

  use Wayfinder\Http\Request;
  use Wayfinder\Http\Response;
  use Wayfinder\View\View;

  final class HomeController
  {
      public function __construct(
          private readonly View $view,
      ) {
      }

      public function index(Request $request): Response
      {
          return $this->view->response('home.index', [
              'appName' => 'Wayfinder Test App',
              'method' => $request->method(),
              'path' => $request->path(),
          ]);
      }
  }
```

To return a response directly:

```php
return $view->response('home.index', ['title' => 'Wayfinder']);
```

## CLI and migrations

Wayfinder includes a small console application plus database migration support.

Available pieces:

- `Wayfinder\Console\Application`
- `Wayfinder\Console\ServeCommand`
- `Wayfinder\Console\TestCommand`
- `Wayfinder\Console\MakeControllerCommand`
- `Wayfinder\Console\MakeMiddlewareCommand`
- `Wayfinder\Console\MakeRequestCommand`
- `Wayfinder\Console\MakeViewCommand`
- `Wayfinder\Console\MakeMigrationCommand`
- `Wayfinder\Console\ConfigCacheCommand`
- `Wayfinder\Console\ConfigClearCommand`
- `Wayfinder\Console\MigrateCommand`
- `Wayfinder\Console\MigrateRefreshCommand`
- `Wayfinder\Console\MigrateResetCommand`
- `Wayfinder\Console\MigrateRollbackCommand`
- `Wayfinder\Console\MigrateStatusCommand`
- `Wayfinder\Console\RouteListCommand`
- `Wayfinder\Console\RouteCacheCommand`
- `Wayfinder\Console\RouteClearCommand`
- `Wayfinder\Database\Migrator`
- `Wayfinder\Database\MigrationRepository`

Migration files should return an object implementing `Wayfinder\Database\Migration`.

Start a local development server:

```bash
php wayfinder serve
php wayfinder serve --host=0.0.0.0 --port=8080
```

`ServeCommand` wraps PHP's built-in server (`php -S`) pointed at the configured document root. It is not intended for production use.

Print the framework version:

```bash
php wayfinder --version
php wayfinder -V
```

Run the PHPUnit test suite:

```bash
php wayfinder test
php wayfinder test tests/Http/RequestTest.php
php wayfinder test --filter=testSomething
php wayfinder test --testsuite=Unit
```

All arguments are forwarded directly to PHPUnit. If the binary is missing a helpful message is printed instead of a cryptic error.

Create one from the CLI:

```bash
php wayfinder make:controller Admin/ReportsController
php wayfinder make:middleware EnsureAdmin
php wayfinder make:request Api/StoreUserRequest
php wayfinder make:view admin/reports/index
php wayfinder make:migration create_posts_table
php wayfinder config:cache
php wayfinder config:clear
```

Inspect routes with:

```bash
php wayfinder route:list
```

Cache routes with:

```bash
php wayfinder route:cache
php wayfinder route:clear
```

Current restriction:

- route caching is disabled in `local` and `development`
- closure route handlers cannot be cached
- closure middleware cannot be cached

Example:

```php
<?php

use Wayfinder\Database\Database;
use Wayfinder\Database\Migration;
use Wayfinder\Database\Schema;

return new class implements Migration
{
    public function up(Database $database): void
    {
        Schema::create('posts', function (Wayfinder\Database\Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->timestamps();
        });
    }

    public function down(Database $database): void
    {
        Schema::dropIfExists('posts');
    }
};
```
