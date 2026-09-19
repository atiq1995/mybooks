<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reports\Concerns;

use App\Domain\Access\Enums\Permission;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Reports\Data\ReportPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * One way of reading a period off a request, shared by every report.
 *
 * Not a convenience. If each report parsed its own dates, two of them opened
 * from the same menu with the same preset could cover different spans, and
 * the first anybody would know of it is a profit figure that does not match
 * the balance sheet it sits beside.
 */
trait ResolvesReportPeriod
{
    /**
     * Reading a report and taking it out of the building are different acts.
     *
     * `reports.view` opens a statement on screen, where it is bounded by a
     * session and an audit trail. A CSV, a spreadsheet or a print document is
     * a copy of the company's figures that leaves with whoever asked for it,
     * so it takes `reports.export` — which a bookkeeper, an approver and a
     * viewer deliberately do not have.
     */
    private function authorizeReport(Request $request): void
    {
        $this->authorize(Permission::ReportsView->value);

        if ($request->string('format')->toString() !== '') {
            $this->authorize(Permission::ReportsExport->value);
        }
    }

    private function reportPeriod(Request $request, Organization $organization): ReportPeriod
    {
        $from = $this->date($request->query('from'));
        $to = $this->date($request->query('to'));

        /*
         * An explicit span wins over a preset, and both bounds are required
         * for it: half a range is a mistake rather than an instruction, and
         * guessing the other half would silently report something nobody
         * asked for.
         */
        if ($from !== null && $to !== null) {
            return ReportPeriod::custom($from, $to);
        }

        return ReportPeriod::preset(
            $request->string('preset')->toString() ?: 'this_year',
            $organization,
        );
    }

    private function comparisonPeriod(Request $request, ReportPeriod $period): ?ReportPeriod
    {
        return $period->comparison($request->string('compare')->toString());
    }

    private function asOf(Request $request): Carbon
    {
        return $this->date($request->query('as_of')) ?? Carbon::now();
    }

    private function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            // A malformed date in a URL is not worth a 500. The report falls
            // back to its default span and the screen shows which one it used.
            return null;
        }
    }

    /**
     * The presets every report offers, in the order they are chosen.
     *
     * @return list<array{value: string, label: string}>
     */
    private function presetOptions(): array
    {
        return [
            ['value' => 'this_month', 'label' => 'This month'],
            ['value' => 'last_month', 'label' => 'Last month'],
            ['value' => 'this_quarter', 'label' => 'This quarter'],
            ['value' => 'last_quarter', 'label' => 'Last quarter'],
            ['value' => 'to_date', 'label' => 'Year to date'],
            ['value' => 'this_year', 'label' => 'This financial year'],
            ['value' => 'last_year', 'label' => 'Last financial year'],
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function comparisonOptions(): array
    {
        return [
            ['value' => '', 'label' => 'No comparison'],
            ['value' => 'previous', 'label' => 'Previous period'],
            ['value' => 'last_year', 'label' => 'Same period last year'],
        ];
    }
}
