<?php

declare(strict_types=1);

namespace AgentHarness\Laravel\Http\Controllers;

use AgentHarness\Laravel\Models\Artifact;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves artifact bytes under the application's authorization.
 *
 * A temporary URL is preferred where the disk supports one, so large downloads
 * never pass through PHP.
 */
class ArtifactController
{
    public function __invoke(Request $request, string $artifact): Response
    {
        $model = Artifact::query()->with('session')->findOrFail($artifact);

        $model->session->authorizeFor($request->user());

        if (($url = $model->temporaryUrl()) !== null) {
            return redirect()->away($url);
        }

        return new StreamedResponse(function () use ($model): void {
            $stream = $model->readStream();

            fpassthru($stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }, Response::HTTP_OK, [
            'Content-Type' => $model->mime_type ?? 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.addslashes($model->name).'"',
            'Content-Length' => (string) ($model->size_bytes ?? ''),
        ]);
    }
}
