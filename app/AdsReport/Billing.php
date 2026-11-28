<?php
declare(strict_types=1);

namespace App\AdsReport;

use App\Ads\Errors;
use App\Ads\Time;
use App\Ads\UserError;
use App\Google\Ads;

/** C10: account budgets, billing setups and invoices for a chosen month (read-only). */
final class Billing
{
    private const MONTHS = ['JANUARY', 'FEBRUARY', 'MARCH', 'APRIL', 'MAY', 'JUNE', 'JULY', 'AUGUST', 'SEPTEMBER', 'OCTOBER', 'NOVEMBER', 'DECEMBER'];
    private const NO_BUDGET = 'The account has no account budgets (account_budget): no monthly invoicing, payments go through a payments profile (card, bank transfer or direct debit), so monthly invoices may not exist. Check billing in Google Ads: Billing.';
    private const DENIED = 'Access denied (Explorer-access account or no billing permissions): ';

    /** Invoice month: YYYY-MM; defaults to the previous month (account time zone). Year 2019 or later, not in the future. @return array{year:int,month:int,label:string,enumName:string} */
    public static function resolveMonth(?string $month, \DateTimeInterface $now, string $tz): array
    {
        $today = Time::dateInZone($now, $tz);
        $y = (int) substr($today, 0, 4);
        $m = (int) substr($today, 5, 2);
        if ($month === null) {
            if (--$m === 0) {
                $m = 12;
                $y--;
            }
        } else {
            if (!preg_match('/^(\d{4})-(\d{2})$/', $month, $t) || (int) $t[2] < 1 || (int) $t[2] > 12) {
                throw new UserError('month must have the format YYYY-MM (e.g. 2026-10).');
            }
            [$y, $m] = [(int) $t[1], (int) $t[2]];
            if ($y < 2019) {
                throw new UserError('Invoices are available only from 2019 (month >= 2019-01).');
            }
            if ($y * 12 + $m > (int) substr($today, 0, 4) * 12 + (int) substr($today, 5, 2)) {
                throw new UserError("month $month is in the future (today is $today).");
            }
        }
        return ['year' => $y, 'month' => $m, 'label' => sprintf('%d-%02d', $y, $m), 'enumName' => self::MONTHS[$m - 1]];
    }

    private static function limitZl(mixed $type, mixed $micros): ?string
    {
        if ($type === 'INFINITE') {
            return 'no limit';
        }
        return $micros !== null ? (string) Fmt::zl($micros) : null;
    }

    private static function endOf(mixed $type, mixed $dt): ?string
    {
        return $type === 'FOREVER' ? 'indefinite' : Fmt::day($dt);
    }

    public static function view(array $a): array
    {
        $mo = self::resolveMonth($a['month'], $a['now'], $a['tz']);
        $bud = Fmt::guarded(fn() => Ads::search(Queries::accountBudget()));
        $setup = Fmt::guarded(fn() => Ads::search(Queries::billingSetup()));
        $notes = [];
        if ($bud['denied']) {
            $notes[] = self::DENIED . 'account budgets unavailable.';
        }
        if ($setup['denied']) {
            $notes[] = self::DENIED . 'billing setups unavailable.';
        }

        $rows = [];
        foreach ($bud['value'] ?? [] as $r) {
            $rows[] = self::budgetRow($r['accountBudget'] ?? []);
        }
        $setups = [];
        foreach ($setup['value'] ?? [] as $r) {
            $s = $r['billingSetup'] ?? [];
            $info = $s['paymentsAccountInfo'] ?? [];
            $setups[] = ['id' => (string) ($s['id'] ?? ''), 'resource' => (string) ($s['resourceName'] ?? ''), 'status' => $s['status'] ?? ''];
            $rows[] = ['kind' => 'billing setup', 'id' => (string) ($s['id'] ?? ''), 'name' => $info['paymentsProfileName'] ?? $info['paymentsAccountName'] ?? null, 'status' => $s['status'] ?? null,
                'paymentsProfileId' => $info['paymentsProfileId'] ?? null, 'paymentsAccount' => $info['paymentsAccountName'] ?? null,
                'from' => Fmt::day($s['startDateTime'] ?? null), 'to' => self::endOf($s['endTimeType'] ?? null, $s['endDateTime'] ?? null)];
        }

        $invoices = self::invoices($setups, $setup['denied'], $mo, $notes);
        array_push($rows, ...$invoices);

        $budgets = count(array_filter($rows, fn($r) => $r['kind'] === 'account budget'));
        $noBudget = !$bud['denied'] && !$budgets ? [self::NO_BUDGET] : [];
        $header = "Billing: account budgets $budgets, billing setups " . count($setups) . ", invoices for {$mo['label']} " . count($invoices) . '.';
        $text = implode(' ', [...$noBudget, ...$notes]);
        return [
            'header' => $header, 'rows' => $rows,
            'extra' => ['month' => $mo['label'], 'budgets' => $budgets, 'billingSetups' => count($setups), 'invoices' => count($invoices), 'notes' => [...$noBudget, ...$notes]],
            'empty' => trim("$header $text"),
            'footer' => ($text !== '' ? "$text " : '') . 'Amounts in PLN (currency per the currency column, if other than PLN). pdfUrl is a link to the invoice copy: downloading requires authentication (OAuth token), and in a browser it may not open without being signed in to Google Ads.',
        ];
    }

    private static function budgetRow(array $b): array
    {
        return ['kind' => 'account budget', 'id' => (string) ($b['id'] ?? ''), 'name' => $b['name'] ?? null, 'status' => $b['status'] ?? null,
            'limitApproved' => self::limitZl($b['approvedSpendingLimitType'] ?? null, $b['approvedSpendingLimitMicros'] ?? null),
            'limitProposed' => self::limitZl($b['proposedSpendingLimitType'] ?? null, $b['proposedSpendingLimitMicros'] ?? null),
            'spent' => Fmt::zl($b['amountServedMicros'] ?? null), 'adjustments' => Fmt::zl($b['totalAdjustmentsMicros'] ?? null),
            'from' => Fmt::day($b['approvedStartDateTime'] ?? null) ?? Fmt::day($b['proposedStartDateTime'] ?? null),
            'to' => self::endOf($b['approvedEndTimeType'] ?? null, $b['approvedEndDateTime'] ?? null) ?? self::endOf($b['proposedEndTimeType'] ?? null, $b['proposedEndDateTime'] ?? null),
            'billingSetup' => Fmt::lastId($b['billingSetup'] ?? null) ?: null];
    }

    /** Invoices from billing setups other than cancelled ones; the first error breaks the loop and goes to $notes. */
    private static function invoices(array $setups, bool $setupDenied, array $mo, array &$notes): array
    {
        $valid = array_filter($setups, fn($s) => $s['id'] !== '' && $s['status'] !== 'CANCELLED' && str_contains($s['resource'], '/billingSetups/') && preg_match('#^customers/\d+/#', $s['resource']));
        if (!$valid) {
            if (!$setupDenied) {
                $notes[] = 'Invoices: no active billing setup (billing_setup), so there is nothing to fetch.';
            }
            return [];
        }
        $invoices = [];
        $failed = false;
        foreach ($valid as $s) {
            try {
                foreach (Ads::listInvoices($s['resource'], $mo['year'], $mo['enumName']) as $i) {
                    $invoices[] = ['kind' => 'invoice', 'id' => (string) ($i['id'] ?? ''), 'name' => null, 'status' => null, 'type' => $i['type'] ?? null,
                        'issueDate' => Fmt::day($i['issueDate'] ?? null), 'dueDate' => Fmt::day($i['dueDate'] ?? null),
                        'subtotal' => Fmt::zl($i['subtotalAmountMicros'] ?? null), 'tax' => Fmt::zl($i['taxAmountMicros'] ?? null), 'total' => Fmt::zl($i['totalAmountMicros'] ?? null),
                        'currency' => $i['currencyCode'] ?? null, 'from' => Fmt::day(Fmt::get($i, 'serviceDateRange.startDate')), 'to' => Fmt::day(Fmt::get($i, 'serviceDateRange.endDate')),
                        'paymentsProfileId' => $i['paymentsProfileId'] ?? null, 'pdfUrl' => $i['pdfUrl'] ?? null];
                }
            } catch (\Throwable $e) {
                $notes[] = Fmt::isAccessDenied($e)
                    ? self::DENIED . "invoices for {$mo['label']} unavailable (the invoices API is only for accounts with monthly invoicing and appropriate access). Google response: " . mb_substr($e->getMessage(), 0, 160)
                    : "Invoices for {$mo['label']}: " . Errors::describe($e, 'read');
                $failed = true;
                break;
            }
        }
        if (!$invoices && !$failed) {
            $notes[] = "Invoices: no invoices issued in {$mo['label']}.";
        }
        return $invoices;
    }
}
