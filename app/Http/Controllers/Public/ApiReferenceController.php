<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Views\ApiReference;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Public API reference (#151): /api renders resources/api/openapi.json on the
 * server, and /api/openapi.json serves the document itself for client generators.
 */
final class ApiReferenceController extends Controller
{
    public function index(): View
    {
        app()->setLocale('pt_BR');

        return view('site.api', [
            'locale' => 'pt_BR',
            'api' => new ApiReference,
        ]);
    }

    public function document(): BinaryFileResponse
    {
        return response()->file(ApiReference::path(), [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
            'Access-Control-Allow-Origin' => '*',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
