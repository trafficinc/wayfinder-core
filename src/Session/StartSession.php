<?php

declare(strict_types=1);

namespace Wayfinder\Session;

use Wayfinder\Contracts\Middleware;
use Wayfinder\Http\Cookie;
use Wayfinder\Http\Request;
use Wayfinder\Http\Response;
use Wayfinder\Http\ValidationException;

final class StartSession implements Middleware
{
    public function __construct(
        private readonly SessionStore $store,
        private readonly SessionManager $sessions,
        private readonly string $cookieName = 'wayfinder_session',
        private readonly int $lifetime = 7200,
        private readonly string $path = '/',
        private readonly string $domain = '',
        private readonly bool $secure = false,
        private readonly bool $httpOnly = true,
        private readonly string $sameSite = 'Lax',
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        $id = $request->cookie($this->cookieName);
        $session = is_string($id) && preg_match('/^[a-f0-9]{40}$/', $id) === 1
            ? $this->store->load($id)
            : new Session(bin2hex(random_bytes(20)));

        $session->ageFlashData();

        $this->sessions->setCurrent($session);
        $request = $request->withSession($session);

        try {
            $response = $next($request);
        } catch (ValidationException $exception) {
            if ($request->expectsJson()) {
                throw $exception;
            }

            $input = $request->request();
            unset($input['_token']);

            $errors = $session->get('_errors', []);

            if (! is_array($errors)) {
                $errors = [];
            }

            $errors[$exception->bag()] = $exception->errors();
            $oldInput = $session->get('_old_input', []);

            if (! is_array($oldInput)) {
                $oldInput = [];
            }

            $session->flash('_errors', $errors);
            $oldInput[$exception->bag()] = $input;
            $session->flash('_old_input', $oldInput);

            $response = Response::redirect($request->redirectTarget());
        } finally {
            $this->sessions->clearCurrent();
        }

        if ($session->isDirty() || ! $session->exists()) {
            $this->store->save($session);

            return $response->withCookie(Cookie::make(
                $this->cookieName,
                $session->id(),
                time() + $this->lifetime,
                $this->path,
                $this->domain,
                $this->secure,
                $this->httpOnly,
                $this->sameSite,
            ));
        }

        return $response;
    }
}
