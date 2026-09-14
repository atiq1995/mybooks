<?php

declare(strict_types=1);

namespace App\Domain\Banking\Services;

use App\Domain\Banking\Data\ParsedStatementLine;
use App\Domain\Banking\Exceptions\StatementUnreadable;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * OFX and QFX — the format behind "download for accounting software".
 *
 * Read with regular expressions rather than an XML parser, deliberately. OFX
 * 1.x is SGML, not XML: tags are routinely left unclosed, and every real XML
 * parser rejects the files banks actually produce. OFX 2.x is XML and happens
 * to parse identically under the same expressions, so one reader covers both.
 *
 * The useful thing OFX has that CSV does not is `FITID` — the bank's own
 * identifier for the transaction. Where it is present it becomes the
 * reference, which makes a re-import recognisable by the bank's own idea of
 * identity rather than by what a description happened to say.
 */
final class OfxStatementParser implements StatementParser
{
    /**
     * @return list<ParsedStatementLine>
     */
    public function parse(string $contents, string $filename): array
    {
        if (preg_match_all('~<STMTTRN>(.*?)</STMTTRN>~is', $contents, $matches) === 0) {
            throw StatementUnreadable::notOfx($filename);
        }

        $lines = [];

        foreach ($matches[1] as $index => $block) {
            $amountRaw = $this->tag($block, 'TRNAMT');
            $dateRaw = $this->tag($block, 'DTPOSTED') ?? $this->tag($block, 'DTUSER');

            if ($amountRaw === null || $dateRaw === null) {
                continue;
            }

            try {
                $amount = BigDecimal::of(str_replace([',', ' '], '', $amountRaw))
                    ->toScale(4, RoundingMode::HalfUp);
            } catch (MathException) {
                throw StatementUnreadable::unreadableAmount($amountRaw, $index + 1);
            }

            if ($amount->isZero()) {
                continue;
            }

            $name = $this->tag($block, 'NAME');
            $memo = $this->tag($block, 'MEMO');

            /*
             * NAME is the counterparty and MEMO is the free text. Banks fill
             * in one, the other, or both, so the description is whichever
             * exists — with both joined when they say different things.
             */
            $description = match (true) {
                $name !== null && $memo !== null && $name !== $memo => "{$name} — {$memo}",
                $name !== null => $name,
                $memo !== null => $memo,
                default => 'Bank transaction',
            };

            $lines[] = new ParsedStatementLine(
                date: $this->parseDate($dateRaw, $index + 1),
                description: mb_substr($description, 0, 500),
                amount: (string) $amount,
                reference: $this->reference($block),
                payee: $name === null ? null : mb_substr($name, 0, 200),
            );
        }

        if ($lines === []) {
            throw StatementUnreadable::empty($filename);
        }

        return $lines;
    }

    /**
     * The bank's own identifier, preferred over the cheque number.
     */
    private function reference(string $block): ?string
    {
        $fitId = $this->tag($block, 'FITID');
        $cheque = $this->tag($block, 'CHECKNUM');

        $reference = $fitId ?? $cheque;

        return $reference === null ? null : mb_substr($reference, 0, 160);
    }

    /**
     * One SGML/XML tag's value.
     *
     * The closing tag is optional in OFX 1.x, so the value runs either to
     * `</TAG>` or to the start of the next tag — whichever comes first.
     */
    private function tag(string $block, string $tag): ?string
    {
        $pattern = sprintf('~<%1$s>([^<\r\n]*)~i', preg_quote($tag, '~'));

        if (preg_match($pattern, $block, $matches) !== 1) {
            return null;
        }

        $value = trim($matches[1]);

        return $value === '' ? null : $value;
    }

    /**
     * OFX dates are YYYYMMDD, optionally followed by a time and a timezone.
     *
     * The time is discarded: a statement line belongs to a day, and keeping a
     * timestamp would make two imports of the same transaction differ because
     * one download happened to carry a timezone suffix.
     */
    private function parseDate(string $raw, int $index): Carbon
    {
        if (preg_match('~^(\d{4})(\d{2})(\d{2})~', trim($raw), $matches) !== 1) {
            throw StatementUnreadable::unreadableDate($raw, $index);
        }

        try {
            $date = Carbon::createFromFormat('Ymd', $matches[1].$matches[2].$matches[3]);
        } catch (Throwable) {
            throw StatementUnreadable::unreadableDate($raw, $index);
        }

        if (! $date instanceof Carbon) {
            throw StatementUnreadable::unreadableDate($raw, $index);
        }

        return $date->startOfDay();
    }
}
