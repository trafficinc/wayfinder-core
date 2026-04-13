<?php

declare(strict_types=1);

namespace App\Controllers;

use Wayfinder\Http\CsrfTokenManager;
use Wayfinder\Http\Request;
use Wayfinder\Http\Response;
use Wayfinder\Support\Config;
use Wayfinder\View\View;

final class HomeController
{
    public function __construct(
        private readonly View $view,
        private readonly CsrfTokenManager $csrf,
        private readonly Config $config,
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->view->response('home.index', [
            'appName' => (string) $this->config->get('app.name', 'Wayfinder'),
            'appVersion' => \Wayfinder\Foundation\Version::VALUE,
            'request' => $request,
            'method' => $request->method(),
            'path' => $request->path(),
            'csrfToken' => $this->csrf->token($request->session()),
            'flashMessage' => $request->session()->pull('status'),
        ]);
    }

    public function submit(Request $request): Response
    {
        $data = $request->validate([
            'message' => 'required|string|min:3',
        ], [], 'contact');

        return Response::back($request)
            ->withFlash($request->session(), 'status', sprintf(
                'Form submitted successfully: %s',
                $data['message'],
            ));
    }
}
