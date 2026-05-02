<?php

namespace App\Http\Controllers\Help;

use App\Services\UserGuidePdfService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UserGuideDownloadController
{
    public function __invoke(Request $request, UserGuidePdfService $pdfService): StreamedResponse
    {
        abort_unless($request->user()?->canAccessPanel(filament()->getCurrentPanel()), 403);

        return $pdfService->download();
    }
}
