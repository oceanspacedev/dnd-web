<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RedirectLegacyRoutesToAdmin
{
    /**
     * Legacy route prefixes that should be redirected to /admin.
     *
     * @var array<int, string>
     */
    protected array $legacyPrefixes = [
        'dashboard',
        'daily',
        'weekly',
        'monthly',
        'result',
        'request',
        'req',
        'teams',
        'dash-kpi',
        'dash-daily',
        'dash-weekly',
        'dash-monthly',
        'kpi',
        'leaderboard',
        'attendance',
        'employee_reviews',
        'user',
        'kpicategory',
        'kpidescription',
        'kpitype',
        'position',
        'setting',
        'admin/daily',
        'admin/weekly',
        'admin/monthly',
        'admin/report',
        'admin/overopen',
    ];

    public function handle(Request $request, Closure $next)
    {
        $path = ltrim($request->path(), '/');

        foreach ($this->legacyPrefixes as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return redirect('/admin');
            }
        }

        return $next($request);
    }
}
