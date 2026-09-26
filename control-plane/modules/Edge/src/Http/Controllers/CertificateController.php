<?php

namespace Kiln\Edge\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Kiln\Edge\Application\Actions\DeleteCertificate;
use Kiln\Edge\Application\Actions\UploadCertificate;
use Kiln\Edge\Domain\Models\Certificate;
use Kiln\Kernel\Http\Controller;

final class CertificateController extends Controller
{
    use ResolvesSite;

    public function store(Request $request, string $site, UploadCertificate $upload): RedirectResponse
    {
        $siteData = $this->site($request, $site, 'edge.manage');

        $data = $request->validate([
            'certificate' => ['required', 'string', 'max:65536'],
            'private_key' => ['required', 'string', 'max:65536'],
            'chain' => ['nullable', 'string', 'max:65536'],
        ]);

        $upload($siteData, $data['certificate'], $data['private_key'], $data['chain'] ?? null, $request->user()?->getAuthIdentifier());

        return back();
    }

    public function destroy(Request $request, string $site, string $certificate, DeleteCertificate $delete): RedirectResponse
    {
        $siteData = $this->site($request, $site, 'edge.manage');

        $delete(Certificate::query()->where('site_id', $siteData->id)->findOrFail($certificate));

        return back();
    }
}
