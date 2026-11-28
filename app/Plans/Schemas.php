<?php
declare(strict_types=1);

namespace App\Plans;

/**
 * JSON Schema (draft-07) of the MCP plan tools' input, as a thin facade: every method returns the schema array
 * of one tool. Originally generated from the Node zod schemas (z.toJSONSchema); now maintained by hand.
 *
 * Where each schema lives:
 *  - Schemas/Campaign.php   campaignChanges, newCampaign, campaignSettings, negativeKeywords, accountExclusions,
 *                           campaignRef, undo, empty, showPlan
 *  - Schemas/Pmax.php       assetGroupChanges, campaignAssets, assetGroupSignals, customAudience
 *  - Schemas/Conversion.php conversionGoals, conversionAction, offlineConversions, ga4KeyEvents
 *
 * Schemas are the FIRST line of defence (shape, lengths, enums); strict business validation and fail-closed
 * checks happen later in Plans/Guard.php and Plans/Build/*. Fields with a `description` are text shown to the AI
 * model. `additionalProperties => false` closes an object (unknown fields are an error). Text limits mirror Google
 * Ads API limits (e.g. URL path 15, business name 25 characters). Proximity radiusKm max is 800 km (API limit).
 * The previewOnly field is not listed here; Prepare::tool adds it.
 */
final class Schemas
{
    public static function campaignChanges(): array
    {
        return Schemas\Campaign::campaignChanges();
    }

    public static function newCampaign(): array
    {
        return Schemas\Campaign::newCampaign();
    }

    public static function campaignSettings(): array
    {
        return Schemas\Campaign::campaignSettings();
    }

    public static function negativeKeywords(): array
    {
        return Schemas\Campaign::negativeKeywords();
    }

    public static function accountExclusions(): array
    {
        return Schemas\Campaign::accountExclusions();
    }

    public static function campaignRef(): array
    {
        return Schemas\Campaign::campaignRef();
    }

    public static function undo(): array
    {
        return Schemas\Campaign::undo();
    }

    public static function empty(): array
    {
        return Schemas\Campaign::empty();
    }

    public static function showPlan(): array
    {
        return Schemas\Campaign::showPlan();
    }

    public static function assetGroupChanges(): array
    {
        return Schemas\Pmax::assetGroupChanges();
    }

    public static function campaignAssets(): array
    {
        return Schemas\Pmax::campaignAssets();
    }

    public static function assetGroupSignals(): array
    {
        return Schemas\Pmax::assetGroupSignals();
    }

    public static function customAudience(): array
    {
        return Schemas\Pmax::customAudience();
    }

    public static function conversionGoals(): array
    {
        return Schemas\Conversion::conversionGoals();
    }

    public static function conversionAction(): array
    {
        return Schemas\Conversion::conversionAction();
    }

    public static function offlineConversions(): array
    {
        return Schemas\Conversion::offlineConversions();
    }

    public static function ga4KeyEvents(): array
    {
        return Schemas\Conversion::ga4KeyEvents();
    }
}
