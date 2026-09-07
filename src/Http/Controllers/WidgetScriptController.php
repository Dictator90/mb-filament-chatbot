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

        /*
         * Revalidated every time, and never served stale.
         *
         * The URL carries no version and cannot: the tag naming it sits on a
         * page this application does not control, and nobody edits that page
         * again after the day it was pasted. So the only thing able to tell a
         * browser the file changed is the file itself — and any max-age at all
         * is a window during which an upgraded installation serves new markup
         * and new endpoints to a visitor still running the old script. It was an
         * hour, and it is the one combination that breaks the widget rather than
         * merely dating it: found by upgrading a real installation and watching
         * a feature that was plainly enabled fail to appear.
         *
         * The cost is one conditional request per page load, answered for nearly
         * all of them by a 304 with no body — a few hundred bytes of headers
         * against a widget that is otherwise a release behind on every site it
         * is embedded on, with nothing on any of them to say so.
         */
        $response = (new BinaryFileResponse($path))
            ->setPublic()
            ->setAutoEtag()
            ->setAutoLastModified();

        $response->headers->set('Cache-Control', 'public, no-cache');

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
