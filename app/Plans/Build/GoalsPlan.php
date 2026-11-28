<?php
declare(strict_types=1);

namespace App\Plans\Build;

use App\Plans\{Cfg, Goals, Plan, Undo};
use App\Plans\Snapshot\PmaxSnapshot;

/**
 * Conversion goals (port of build-goals.ts): the campaign goal level, which category/origin pairs take part in bidding
 * (biddable / onlyBiddable) and primary/secondary actions (primaryForGoal), for a campaign (scope=campaign) or the account (scope=account).
 */
final class GoalsPlan
{
    /** Snapshot with only the account part (goals and actions), for the fingerprint and simulation when scope=account. */
    // Pitfall: account-level (CUSTOMER) vs campaign-level (CAMPAIGN) goals. A campaign inherits the account goals until goalConfigLevel is set to CAMPAIGN.
    private static function accountSnapshot(array $a): array
    {
        return ['customerGoals' => $a['customerGoals'], 'conversionActions' => $a['conversionActions'], 'campaignGoals' => [],
            'goalConfig' => ['conversionGoalCampaignConfig' => ['goalConfigLevel' => 'CUSTOMER']]];
    }

    // Fingerprint of the account part only. Also used when approving a scope=account plan, to detect goal changes between planning and execution.
    public static function accountFingerprint(array $a): string
    {
        return PmaxSnapshot::fingerprint(self::accountSnapshot($a), ['customerGoals', 'conversionActions']);
    }

    private static function yn(bool $b): string
    {
        return $b ? 'yes' : 'no';
    }

    private static function pk(mixed $c, mixed $o): string
    {
        return "$c/$o";
    }

    /** @param ?array $s campaign snapshot (scope=campaign) or null; $account: customerGoals, conversionActions, campaignsAtCustomerLevel */
    /**
     * Builds the plan for the prepare_conversion_goals tool. Flow: (1) target biddable values from the request, (2) goal operations
     * (category/origin), (3) a possible change of the campaign goal level, (4) primary/secondary actions, (5) simulation of the "after" state in the diff.
     * Rule: changing biddable on a campaign that uses account goals automatically switches it to the CAMPAIGN level (otherwise the change
     * would overwrite the goals of the whole account). Going back to CUSTOMER together with a biddable change is an error (fail-closed).
     * Safeguard: a plan after which no action would be used for bidding is rejected.
     */
    public static function build(array $req, ?array $s, array $account): array
    {
        $d = new Draft();
        $isCampaign = $req['scope'] === 'campaign';
        $campaignId = $isCampaign ? (string) ($s['campaign']['id'] ?? $req['campaign'] ?? '') : '';
        if ($isCampaign && (empty($req['campaign']) || !$s)) {
            $d->error('scope=campaign requires the campaign field (an existing campaign)');
        }
        if (!$isCampaign && isset($req['goalLevel'])) {
            $d->error('goalLevel applies only to scope=campaign');
        }
        $base = $isCampaign && $s ? $s : self::accountSnapshot($account);
        $currentLevel = $isCampaign ? (($s['goalConfig']['conversionGoalCampaignConfig']['goalConfigLevel'] ?? null) === 'CAMPAIGN' ? 'CAMPAIGN' : 'CUSTOMER') : 'CUSTOMER';
        $pairs = self::pairs($isCampaign ? ($base['campaignGoals'] ?? []) : ($base['customerGoals'] ?? []));
        $target = self::targets($req, $pairs, $d);

        $goalOpName = $isCampaign ? 'campaignConversionGoalOperation' : 'customerConversionGoalOperation';
        $goalOps = [];
        $simPairs = [];
        foreach ($pairs as $p) {
            $next = $target[self::pk($p['category'], $p['origin'])] ?? null;
            $simPairs[] = $next ?? $p['biddable'];
            if ($next === null || $next === $p['biddable']) {
                continue;
            }
            $goalOps[] = [$goalOpName => ['update' => ['resourceName' => $p['resourceName'], 'biddable' => $next], 'updateMask' => 'biddable']];
            $d->add('Conversion goals', self::pk($p['category'], $p['origin']), 'bidding: ' . self::yn($p['biddable']), 'bidding: ' . self::yn($next), 'change');
        }
        $ops = [];
        $newLevel = $currentLevel;
        if ($isCampaign) {
            if (isset($req['goalLevel']) && $req['goalLevel'] !== $currentLevel) {
                $newLevel = $req['goalLevel'];
            } elseif (!isset($req['goalLevel']) && $goalOps && $currentLevel === 'CUSTOMER') {
                $newLevel = 'CAMPAIGN';
            }
            if ($newLevel !== $currentLevel) {
                $ops[] = ['conversionGoalCampaignConfigOperation' => ['update' => [
                    'resourceName' => $s['goalConfig']['conversionGoalCampaignConfig']['resourceName'] ?? 'customers/' . Cfg::customerId() . "/conversionGoalCampaignConfigs/$campaignId",
                    'goalConfigLevel' => $newLevel], 'updateMask' => 'goalConfigLevel']];
                $lbl = fn($l) => $l === 'CAMPAIGN' ? 'campaign' : 'account';
                $d->add('Conversion goals', 'Goal level', $lbl($currentLevel), $lbl($newLevel), 'change');
            }
        }
        array_push($ops, ...$goalOps);

        [$primaryTarget, $manualSkipped] = self::primaryOps($req, $account, $d, $ops);
        self::simulate($isCampaign, $base, $account, $currentLevel, $newLevel, $simPairs, $primaryTarget, $d, $ops, $target);

        if (!$isCampaign && $account['campaignsAtCustomerLevel']) {
            $d->warn('The change will also affect campaigns that use the account goals: ' . implode(', ', array_map(fn($c) => $c['name'], $account['campaignsAtCustomerLevel'])));
        }
        if ($isCampaign && ($s['campaign']['status'] ?? null) === 'ENABLED') {
            $d->warn('The campaign is enabled; the change takes effect immediately');
        }
        if (!$ops && !$d->errors && $manualSkipped === 0) {
            $d->error('Nothing to change');
        }
        if (!$ops && !$d->errors) {
            array_unshift($d->warnings, 'Nothing to change via the API: all requested changes must be set manually in the Google Ads UI (instructions below).');
        }
        // Undo: the goal level may be absent from the snapshot (defaults to account), so we pass it explicitly under the name used in the operation.
        $goalExtras = $isCampaign ? [['resourceName' => $s['goalConfig']['conversionGoalCampaignConfig']['resourceName'] ?? 'customers/' . Cfg::customerId() . "/conversionGoalCampaignConfigs/$campaignId", 'goalConfigLevel' => $currentLevel]] : [];
        $fp = $isCampaign && $s ? PmaxSnapshot::fingerprint($s, ['goalConfig', 'campaignGoals', 'customerGoals', 'conversionActions']) : self::accountFingerprint($account);
        $name = $s['campaign']['name'] ?? $req['campaign'] ?? '';
        $plan = array_merge(Plan::base(), ['kind' => 'conversion_goals'], $isCampaign ? ['campaignId' => $campaignId] : [], ['goalScope' => $req['scope']], [
            'summary' => $isCampaign ? "Conversion goals of campaign \"{$name}\" (scope: campaign)" : 'Account conversion goals (scope: account)',
            'steps' => $ops ? [['title' => 'Conversion goals', 'operations' => $ops]] : [],
            'beforeFingerprint' => $fp,
        ], $d->common());
        return Undo::attach($plan, $isCampaign && $s ? $s : null, $account, $goalExtras);
    }

    /** @return list<array{category: string, origin: string, biddable: bool, resourceName: string}> */
    // Goals are identified by the "category/origin" pair (e.g. PURCHASE/WEBSITE), not by id: the API gives no separate goal id; resourceName is used for the update.
    private static function pairs(array $rows): array
    {
        return array_map(function ($r) {
            $g = $r['campaignConversionGoal'] ?? $r['customerConversionGoal'] ?? [];
            return ['category' => (string) ($g['category'] ?? ''), 'origin' => (string) ($g['origin'] ?? ''), 'biddable' => ($g['biddable'] ?? null) === true, 'resourceName' => (string) ($g['resourceName'] ?? '')];
        }, $rows);
    }

    /** Target biddable values (onlyBiddable + biddable) as a map "category/origin" => bool. */
    private static function targets(array $req, array $pairs, Draft $d): array
    {
        $find = fn(string $c, string $o) => array_filter($pairs, fn($p) => $p['category'] === $c && $p['origin'] === $o);
        $available = fn() => implode(', ', array_map(fn($p) => self::pk($p['category'], $p['origin']), $pairs)) ?: 'none';
        $target = [];
        if (($req['goalLevel'] ?? null) === 'CUSTOMER' && (!empty($req['biddable']) || isset($req['onlyBiddable']))) {
            $d->error('Going back to account goals overwrites the campaign goals; do not combine it with a biddable change');
        }
        if (isset($req['onlyBiddable'])) {
            $want = [];
            foreach ($req['onlyBiddable'] as $x) {
                if ($find($x['category'], $x['origin'])) {
                    $want[self::pk($x['category'], $x['origin'])] = true;
                } else {
                    $d->error('No such goal: ' . self::pk($x['category'], $x['origin']) . '. Available: ' . $available());
                }
            }
            foreach ($pairs as $p) {
                $target[self::pk($p['category'], $p['origin'])] = isset($want[self::pk($p['category'], $p['origin'])]);
            }
        }
        foreach ($req['biddable'] ?? [] as $x) {
            if (!$find($x['category'], $x['origin'])) {
                $d->error('No such goal: ' . self::pk($x['category'], $x['origin']) . '. Available: ' . $available());
                continue;
            }
            $target[self::pk($x['category'], $x['origin'])] = (bool) $x['biddable'];
        }
        return $target;
    }

    /** Primary/secondary actions: operations + warnings for read-only actions. @return array{array<string,bool>, int} */
    // Actions of types the API does not allow to change (Goals::isNonMutableActionType) are not an error: we skip them and give manual instructions, so the rest of the plan can run.
    // A missing primaryForGoal field in the snapshot means the action is primary by default (true), hence the "!== false" comparison.
    private static function primaryOps(array $req, array $account, Draft $d, array &$ops): array
    {
        $byId = [];
        foreach ($account['conversionActions'] as $r) {
            $byId[(string) ($r['conversionAction']['id'] ?? '')] = $r['conversionAction'] ?? [];
        }
        $primaryTarget = [];
        $manualSkipped = 0;
        foreach ($req['primaryForGoal'] ?? [] as $x) {
            $a = $byId[(string) $x['conversionActionId']] ?? null;
            if (!$a || ($a['status'] ?? null) === 'REMOVED') {
                $d->error("No such conversion action: {$x['conversionActionId']}");
                continue;
            }
            if (Goals::isNonMutableActionType($a['type'] ?? null)) {
                // Not an error: the action drops out of the operations, the rest of the plan runs; the user gets manual instructions.
                $manual = "Action \"{$a['name']}\" ({$x['conversionActionId']}, type {$a['type']}) cannot be changed via the API. Change it in Google Ads: Goals → Conversions → {$a['name']} → Settings (Primary/Secondary action)";
                $d->warn($manual);
                $manualSkipped++;
                $d->diff[] = ['section' => 'Conversion actions', 'label' => "{$a['name']} ({$x['conversionActionId']})", 'before' => null, 'after' => null, 'change' => 'info', 'level' => 'warn', 'note' => $manual];
                continue;
            }
            $primaryTarget[(string) $x['conversionActionId']] = (bool) $x['primary'];
            if ((($a['primaryForGoal'] ?? null) !== false) !== (bool) $x['primary']) {
                $ops[] = ['conversionActionOperation' => ['update' => ['resourceName' => $a['resourceName'], 'primaryForGoal' => (bool) $x['primary']], 'updateMask' => 'primaryForGoal']];
            }
        }
        return [$primaryTarget, $manualSkipped];
    }

    /** Before/after simulation: which actions are used for bidding (the "Conversion actions" diff) + warnings about duplicates within a category. */
    // The simulation computes the "after" state with the same function as "before" (Goals::effective) on a modified copy of the snapshot, so the diff shows the real effect (actions used for bidding), not just the raw operations.
    private static function simulate(bool $isCampaign, array $base, array $account, string $currentLevel, string $newLevel, array $simPairs, array $primaryTarget, Draft $d, array $ops, array $target): void
    {
        $level = fn(string $l) => array_merge($base, ['goalConfig' => ['conversionGoalCampaignConfig' => ['goalConfigLevel' => $l]]]);
        $before = Goals::effective($level($isCampaign ? $currentLevel : 'CUSTOMER'));
        $withBiddable = function (array $rows, string $key) use ($simPairs): array {
            $out = [];
            foreach (array_values($rows) as $i => $r) {
                $out[] = [$key => array_merge($r[$key] ?? [], ['biddable' => $simPairs[$i]])];
            }
            return $out;
        };
        $after = array_merge($level($isCampaign ? $newLevel : 'CUSTOMER'), [
            'campaignGoals' => $isCampaign ? $withBiddable($base['campaignGoals'] ?? [], 'campaignConversionGoal') : ($base['campaignGoals'] ?? []),
            'customerGoals' => !$isCampaign ? $withBiddable($base['customerGoals'] ?? [], 'customerConversionGoal') : ($base['customerGoals'] ?? []),
            'conversionActions' => array_map(function ($r) use ($primaryTarget) {
                $id = (string) ($r['conversionAction']['id'] ?? '');
                return array_key_exists($id, $primaryTarget) ? array_merge($r, ['conversionAction' => array_merge($r['conversionAction'], ['primaryForGoal' => $primaryTarget[$id]])]) : $r;
            }, $account['conversionActions']),
        ]);
        $afterGoals = Goals::effective($after);
        $where = $isCampaign ? 'in this campaign' : 'in the account';
        $fmt = fn(array $g) => 'counted in Conversions: ' . self::yn($g['primary']) . " · bidding $where: " . self::yn($g['biddable']);
        $beforeById = [];
        foreach ($before as $g) {
            $beforeById[$g['actionId']] = $g;
        }
        foreach ($afterGoals as $g) {
            $bs = isset($beforeById[$g['actionId']]) ? $fmt($beforeById[$g['actionId']]) : null;
            $as = $fmt($g);
            $d->add('Conversion actions', "{$g['name']} ({$g['actionId']}) · " . self::pk($g['category'], $g['origin']), $bs, $as, $bs !== $as ? 'change' : 'info');
        }
        if (!$d->errors && $ops && !array_filter($afterGoals, fn($g) => $g['biddable'])) {
            $d->error('After the change no conversion action will be used for bidding');
        }
        foreach (array_keys(array_filter($target)) as $k) {
            $same = array_values(array_filter($afterGoals, fn($g) => self::pk($g['category'], $g['origin']) === $k && $g['biddable']));
            if (count($same) > 1) {
                $d->warn('Also used for bidding in the same category: ' . implode(', ', array_map(fn($g) => "{$g['name']} ({$g['actionId']})", $same)));
            }
        }
    }
}
