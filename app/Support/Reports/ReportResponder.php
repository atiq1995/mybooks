<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Domain\Reports\Data\ReportTable;
use App\Domain\Reports\Services\ReportCsvWriter;
use App\Domain\Reports\Services\ReportXlsxWriter;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * One report, four ways out: the screen, a CSV, a spreadsheet, or print.
 *
 * All four render the SAME {@see ReportTable}. That is the point of the
 * class: a figure a person exported and a figure they saw have to be the same
 * figure, and the reliable way to guarantee it is for the four renderers to
 * have no route to the ledger of their own.
 *
 * "Print" rather than a generated PDF file, and deliberately. The print view
 * is a real document — paginated headers, repeated column headings, no
 * navigation — which the browser saves as a PDF at the reader's own paper
 * size and margins. A server-rendered PDF would fix those choices for
 * everybody and would need a rendering engine in the image to do worse.
 */
final readonly class ReportResponder
{
    public function __construct(
        private ReportCsvWriter $csv,
        private ReportXlsxWriter $xlsx,
    ) {}

    /**
     * @param  array<string, mixed>  $props  everything the screen needs beyond
     *                                       the table itself — filters, options
     */
    public function respond(
        Request $request,
        ReportTable $table,
        string $component,
        array $props = [],
    ): Response|SymfonyResponse {
        return match ($request->string('format')->toString()) {
            'csv' => $this->download(
                $this->csv->write($table),
                $table->slug().'.csv',
                'text/csv; charset=UTF-8',
            ),
            'xlsx' => $this->download(
                $this->xlsx->write($table),
                $table->slug().'.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ),
            'print' => $this->print($table, $props),
            default => Inertia::render($component, [
                'report' => $table->toArray(),
                ...$props,
            ]),
        };
    }

    private function download(string $contents, string $filename, string $contentType): HttpResponse
    {
        return new HttpResponse($contents, 200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Content-Length' => (string) strlen($contents),
            // A financial report is not something a proxy or a browser should
            // hold on to: the figures change the moment anything posts.
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * @param  array<string, mixed>  $props
     */
    private function print(ReportTable $table, array $props): HttpResponse
    {
        $organization = $props['organizationName'] ?? null;

        return new HttpResponse(
            view('reports.print', [
                'table' => $table,
                'organizationName' => is_string($organization) ? $organization : null,
            ])->render(),
            200,
            ['Cache-Control' => 'no-store, private'],
        );
    }
}
