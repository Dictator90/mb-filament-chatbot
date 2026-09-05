<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the widget's own script and stylesheet.
 *
 * Through a route rather than by publishing into `public/`, so that installing
 * the package is enough to embed the widget. Publishing would put one more step
 * between a host and a working chat, and — worse — a step nobody repeats after
 * an upgrade, leaving a stale widget talking to a newer endpoint.
 *
 * Cached for an hour with a validator, not forever.
 *
 * "Immutable" would be right if the URL carried a version, and it cannot: the
 * host writes this URL by hand into a script tag on a page the package will
 * never see, so there is nothing to bump on an upgrade. A year-long immutable
 * cache against a fixed URL means a browser that fetched the widget once is
 * never asked again — an upgrade reaches nobody, and the symptom is a widget
 * from last year talking to this year's endpoints. An hour plus an ETag costs
 * one conditional request and cannot get stuck.
 *
 * No origin check here on purpose. This is a public script tag, the same for
 * every site; the allow-list guards the endpoints that carry a conversation.
 * Checking the origin of a `<script src>` would only break the browsers that do
 * not send one.
 */
class WidgetScriptController
{
    public function script(Request $request): Response
    {
        return $this->file($request, __DIR__.'/../../../resources/widget/widget.js', 'application/javascript');
    }

    public function styles(Request $request): Response
    {
        return $this->file($request, __DIR__.'/../../../resources/widget/widget.css', 'text/css');
    }

    /**
     * A missing file is a 404 rather than an exception.
     *
     * The one way this happens is an installation whose vendor directory was
     * pruned, and a stack trace in a script tag on a customer's marketing page
     * is a worse outcome than a script that quietly does not load.
     */
    protected function file(Request $request, string $path, string $type): Response
    {
        if (! is_file($path)) {
            abort(404);
        }

        $response = (new BinaryFileResponse($path))
            ->setPublic()
            ->setMaxAge(3600)
            ->setAutoEtag()
            ->setAutoLastModified();

        $response->isNotModified($request);

        $response->headers->set('Content-Type', $type.'; charset=utf-8');

        /*
         * A wildcard here and nowhere else. The file is the same bytes for every
         * site, so there is nothing to leak by letting any of them fetch it —
         * and refusing would break the widget on exactly the pages it is for.
         * The endpoints that carry a conversation are guarded by the allow-list.
         */
        $response->headers->set('Access-Control-Allow-Origin', '*');

        return $response;
    }
}
