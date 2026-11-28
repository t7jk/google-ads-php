<?php
// D2 plan kinds outside the regular Ads::mutate write: conversion action update, offline conversions (uploadClickConversions) and GA4 key events.
// Definition format: see App\Plans\Kinds. Offline and GA4 have their own 'run' (the Executor does not route them through Ads::mutate).
declare(strict_types=1);

use App\Google\Ads;
use App\Plans\Build\{ConversionActions, Ga4KeyEventsPlan};
use App\Plans\{Clock, Ga4Run, OfflineRun};

return [
    // Update of an existing conversion action: fingerprint of its fields (the same ones the builder sees).
    'conversion_action_update' => ['fingerprint' => function (array $plan): string {
        $id = (string) ($plan['conversionActionId'] ?? '');
        if ($id === '') {
            throw new \RuntimeException('Plan does not specify a conversion action');
        }
        return ConversionActions::fingerprint($id, Ads::search(ConversionActions::detailQuery($id))[0]['conversionAction'] ?? null);
    }],
    // Offline conversions: own validation (validateOnly in uploadClickConversions) and own execution with an upload log.
    'offline_conversions' => ['validate' => [OfflineRun::class, 'validate'], 'run' => [OfflineRun::class, 'run']],
    // GA4 key events: no validateOnly (local validation in the builder), executed one by one, own undo.
    'ga4_key_events' => [
        'fingerprint' => fn(array $plan): string => Ga4KeyEventsPlan::fingerprint(Ga4Run::listKeyEvents()),
        'validate' => fn(array &$plan): bool => true,
        'run' => [Ga4Run::class, 'run'],
        'undo' => fn(array $plan): array => Ga4KeyEventsPlan::undoPlan($plan, Ga4Run::listOrNull(), Clock::now()),
    ],
];
