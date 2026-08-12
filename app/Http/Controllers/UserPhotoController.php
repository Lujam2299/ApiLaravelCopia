<?php

namespace App\Http\Controllers;

use App\Models\DocumentacionAltas;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UserPhotoController extends Controller
{
    public function show(DocumentacionAltas $documentacion): StreamedResponse
    {
        $path = $this->normalizePublicPath($documentacion->arch_foto);

        $disk = Storage::disk('erp_public');

        abort_unless($path && $disk->exists($path), 404);

        return $disk->response($path, null, [
            'Cache-Control' => 'private, max-age=21600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function normalizePublicPath(?string $path): ?string
    {
        if (! $path || filter_var($path, FILTER_VALIDATE_URL)) {
            return null;
        }

        $path = str_replace('\\', '/', trim($path));
        $path = preg_replace('#^/?(?:public/)?storage/#', '', $path);

        return ($path = ltrim($path ?? '', '/')) !== '' ? $path : null;
    }
}
