<?php

declare(strict_types=1);

namespace App\Core;

use Lib\Settings;

/**
 * Base controller for the admin panel (session-authenticated HTML pages).
 */
abstract class Controller
{
    protected Request $request;

    /** Permission required to reach any action of this controller. */
    protected ?string $permission = null;

    /** Set to false for public pages (login, register, verify). */
    protected bool $requiresAuth = true;

    public function __construct(Request $request)
    {
        $this->request = $request;

        Session::start();

        if ($this->requiresAuth) {
            $this->ensureAuthenticated();
            $this->ensurePasswordChanged();
            if ($this->permission !== null) {
                $this->authorize($this->permission);
            }
        }
    }

    protected function ensureAuthenticated(): void
    {
        if (Auth::check()) {
            return;
        }
        Session::set('_intended', $this->request->path());
        Session::flash('warning', 'Please sign in to continue.');
        Response::redirect(View::url('login'));
    }

    /** Force a password reset when the account is flagged. */
    protected function ensurePasswordChanged(): void
    {
        $user = Auth::user();
        if ($user === null || (int) ($user['must_change_password'] ?? 0) !== 1) {
            return;
        }
        $path = $this->request->path();
        if (in_array($path, ['password/change', 'logout'], true)) {
            return;
        }
        Session::flash('warning', 'For security, please set a new password before continuing.');
        Response::redirect(View::url('password/change'));
    }

    protected function authorize(string $permission): void
    {
        if (Auth::can($permission)) {
            return;
        }
        Audit::security('access.denied', 'Blocked access to ' . $this->request->path()
            . ' (missing permission ' . $permission . ')');
        Response::html(
            View::render('errors/403', ['permission' => $permission]),
            403
        );
        exit;
    }

    /** @param array<string,mixed> $data */
    protected function view(string $template, array $data = [], string $layout = 'app'): void
    {
        $data += [
            'pageTitle'  => Settings::getString('company.app_name', 'LRMS'),
            'flashes'    => Session::takeFlashes(),
            'authUser'   => Auth::user(),
            'currentPath' => $this->request->path(),
        ];
        Session::clearOldInput();
        Response::html(View::render($template, $data, $layout));
    }

    protected function redirect(string $path, ?string $flashType = null, ?string $flashMessage = null): void
    {
        if ($flashType !== null && $flashMessage !== null) {
            Session::flash($flashType, $flashMessage);
        }
        Response::redirect(View::url($path));
    }

    protected function back(string $flashType, string $flashMessage): void
    {
        Session::flash($flashType, $flashMessage);
        $referer = $_SERVER['HTTP_REFERER'] ?? null;
        if (is_string($referer) && $referer !== '' && str_starts_with($referer, Config::baseUrl())) {
            Response::redirect($referer);
        }
        Response::redirect(View::url('dashboard'));
    }

    /** Guard every POST action. */
    protected function verifyCsrf(): void
    {
        Csrf::verify($this->request);
    }

    /**
     * Pagination helper.
     * @return array{page:int,perPage:int,offset:int}
     */
    protected function paginate(int $defaultPerPage = 25): array
    {
        $page = max(1, $this->request->int('page', 1));
        $perPage = $this->request->int('per_page', $defaultPerPage);
        $perPage = max(10, min(200, $perPage));
        return ['page' => $page, 'perPage' => $perPage, 'offset' => ($page - 1) * $perPage];
    }

    /** @return array{total:int,page:int,perPage:int,pages:int} */
    protected function paginationMeta(int $total, array $p): array
    {
        return [
            'total'   => $total,
            'page'    => $p['page'],
            'perPage' => $p['perPage'],
            'pages'   => $p['perPage'] > 0 ? (int) ceil($total / $p['perPage']) : 1,
        ];
    }
}
