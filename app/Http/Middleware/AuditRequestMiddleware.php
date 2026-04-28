<?php

namespace App\Http\Middleware;

use App\Services\AuditLogService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AuditRequestMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->shouldAudit($request)) {
            return $next($request);
        }

        try {
            /** @var Response $response */
            $response = $next($request);

            $this->log($request, $response->getStatusCode());

            return $response;
        } catch (Throwable $exception) {
            $this->log($request, 500, $exception->getMessage());

            throw $exception;
        }
    }

    protected function shouldAudit(Request $request): bool
    {
        if (! config('audit.enabled', true)) {
            return false;
        }

        if ($request->isMethod('GET')) {
            return str($request->path())->contains(['export', 'pdf', 'excel', 'report']);
        }

        return in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    protected function log(Request $request, int $statusCode, ?string $error = null): void
    {
        app(AuditLogService::class)->log(
            action: 'admin_request.'.strtolower($request->method()),
            module: 'admin_request',
            description: $request->method().' '.$request->path(),
            metadata: [
                'status_code' => $statusCode,
                'route_name' => $request->route()?->getName(),
            ],
            status: $error !== null || $statusCode >= 500 ? 'failed' : ($statusCode >= 400 ? 'warning' : 'success'),
        );
    }
}
