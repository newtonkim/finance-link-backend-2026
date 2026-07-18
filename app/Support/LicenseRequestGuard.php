<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Classifies a request as a read or a mutation for license enforcement.
 *
 * Most reads in this app are served over POST (list/dropdown endpoints are
 * generated as POST routes), so an expired tenant cannot be limited to the GET
 * verb alone — that would block viewing entirely. An expired tenant may reach
 * any read endpoint (identified by a read token in its path) but is blocked
 * from every create/update/delete.
 */
class LicenseRequestGuard
{
    private const READ_TOKENS = [
        'list', 'lists', 'dropdown', 'details', 'detail', 'show', 'view', 'index',
        'get', 'export', 'print', 'download', 'template', 'templates', 'statement',
        'statements', 'receipt', 'receipts', 'options', 'search', 'summary',
        'analysis', 'analytics', 'profile', 'completeness', 'balances', 'balance',
        'transactions', 'preview', 'count', 'counts', 'chart', 'charts', 'report',
        'reports', 'history', 'filter-options',
    ];

    public static function isReadRequest(Request $request): bool
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return true;
        }

        if (! $request->isMethod('POST')) {
            return false;
        }

        $segments = preg_split('#[/_-]#', strtolower($request->path())) ?: [];

        return (bool) array_intersect($segments, self::READ_TOKENS);
    }
}
