<?php
declare(strict_types=1);

namespace App\Plans\Schemas;

final class Campaign
{
    /** Input of prepare_campaign_changes (built in Build/Changes.php): changes to a Search campaign and its ad groups; the campaign is given by campaignId or campaign (ID/name). */
    public static function campaignChanges(): array
    {
        return [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type' => 'object',
            'properties' => [
                'campaignId' => [
                    'description' => 'campaign ID (digits); alternative to the campaign field',
                    'type' => 'string',
                    'pattern' => '^\\d+$',
                ],
                'rename' => [
                    'type' => 'string',
                    'minLength' => 1,
                ],
                'dailyBudgetPln' => [
                    'type' => 'number',
                ],
                'bidding' => [
                    'type' => 'object',
                    'properties' => [
                        'type' => [
                            'type' => 'string',
                            'enum' => ['MANUAL_CPC', 'MAXIMIZE_CLICKS', 'MAXIMIZE_CONVERSIONS'],
                        ],
                        'maxCpcPln' => [
                            'type' => 'number',
                            'exclusiveMinimum' => 0,
                        ],
                    ],
                    'required' => ['type'],
                ],
                'networks' => [
                    'type' => 'object',
                    'properties' => [
                        'searchPartners' => [
                            'type' => 'boolean',
                        ],
                        'display' => [
                            'type' => 'boolean',
                        ],
                    ],
                    'required' => ['searchPartners', 'display'],
                ],
                'location' => [
                    'type' => 'object',
                    'properties' => [
                        'proximity' => [
                            'type' => 'object',
                            'properties' => [
                                'lat' => [
                                    'type' => 'number',
                                    'minimum' => -90,
                                    'maximum' => 90,
                                ],
                                'lng' => [
                                    'type' => 'number',
                                    'minimum' => -180,
                                    'maximum' => 180,
                                ],
                                'radiusKm' => [
                                    'type' => 'number',
                                    'minimum' => 1,
                                    'maximum' => 800,
                                ],
                            ],
                            'required' => ['lat', 'lng', 'radiusKm'],
                        ],
                    ],
                    'required' => ['proximity'],
                ],
                'campaignParam' => [
                    'type' => 'string',
                ],
                'campaignNegatives' => [
                    'type' => 'object',
                    'properties' => [
                        'add' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'text' => [
                                        'type' => 'string',
                                        'minLength' => 1,
                                    ],
                                    'match' => [
                                        'type' => 'string',
                                        'enum' => ['EXACT', 'PHRASE', 'BROAD'],
                                    ],
                                ],
                                'required' => ['text', 'match'],
                            ],
                        ],
                        'removeCriterionIds' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'string',
                            ],
                        ],
                    ],
                ],
                'adGroups' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => [
                                'type' => 'string',
                            ],
                            'createName' => [
                                'type' => 'string',
                                'minLength' => 1,
                            ],
                            'status' => [
                                'type' => 'string',
                                'enum' => ['ENABLED', 'PAUSED'],
                            ],
                            'rename' => [
                                'type' => 'string',
                                'minLength' => 1,
                            ],
                            'defaultCpcPln' => [
                                'type' => 'number',
                                'exclusiveMinimum' => 0,
                            ],
                            'adGroupParam' => [
                                'type' => 'string',
                            ],
                            'keywords' => [
                                'type' => 'object',
                                'properties' => [
                                    'add' => [
                                        'type' => 'array',
                                        'items' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'text' => [
                                                    'type' => 'string',
                                                    'minLength' => 1,
                                                ],
                                                'match' => [
                                                    'type' => 'string',
                                                    'enum' => ['EXACT', 'PHRASE', 'BROAD'],
                                                ],
                                            ],
                                            'required' => ['text', 'match'],
                                        ],
                                    ],
                                    'pauseIds' => [
                                        'type' => 'array',
                                        'items' => [
                                            'type' => 'string',
                                        ],
                                    ],
                                    'removeIds' => [
                                        'type' => 'array',
                                        'items' => [
                                            'type' => 'string',
                                        ],
                                    ],
                                ],
                            ],
                            'negatives' => [
                                'type' => 'object',
                                'properties' => [
                                    'add' => [
                                        'type' => 'array',
                                        'items' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'text' => [
                                                    'type' => 'string',
                                                    'minLength' => 1,
                                                ],
                                                'match' => [
                                                    'type' => 'string',
                                                    'enum' => ['EXACT', 'PHRASE', 'BROAD'],
                                                ],
                                            ],
                                            'required' => ['text', 'match'],
                                        ],
                                    ],
                                ],
                            ],
                            'ads' => [
                                'type' => 'object',
                                'properties' => [
                                    'add' => [
                                        'type' => 'array',
                                        'items' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'headlines' => [
                                                    'type' => 'array',
                                                    'items' => [
                                                        'type' => 'string',
                                                    ],
                                                ],
                                                'descriptions' => [
                                                    'type' => 'array',
                                                    'items' => [
                                                        'type' => 'string',
                                                    ],
                                                ],
                                                'path1' => [
                                                    'type' => 'string',
                                                ],
                                                'path2' => [
                                                    'type' => 'string',
                                                ],
                                                'finalUrl' => [
                                                    'type' => 'string',
                                                ],
                                            ],
                                            'required' => ['headlines', 'descriptions', 'finalUrl'],
                                        ],
                                    ],
                                    'pauseIds' => [
                                        'type' => 'array',
                                        'items' => [
                                            'type' => 'string',
                                        ],
                                    ],
                                    'removeIds' => [
                                        'type' => 'array',
                                        'items' => [
                                            'type' => 'string',
                                        ],
                                    ],
                                    'setFinalUrl' => [
                                        'type' => 'array',
                                        'items' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'adId' => [
                                                    'type' => 'string',
                                                ],
                                                'finalUrl' => [
                                                    'type' => 'string',
                                                ],
                                            ],
                                            'required' => ['adId', 'finalUrl'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'campaign' => [
                    'description' => 'campaign ID or its exact name',
                    'type' => 'string',
                    'minLength' => 1,
                ],
            ],
        ];
    }

    /** Input of prepare_new_campaign: a new Search campaign (budget, strategy, location, networks, negatives, ad groups with RSA ads). The new campaign is created PAUSED; enabling it is a separate, deliberate step. */
    public static function newCampaign(): array
    {
        return [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                ],
                'dailyBudgetPln' => [
                    'type' => 'number',
                ],
                'bidding' => [
                    'type' => 'object',
                    'properties' => [
                        'type' => [
                            'type' => 'string',
                            'enum' => ['MANUAL_CPC', 'MAXIMIZE_CLICKS', 'MAXIMIZE_CONVERSIONS'],
                        ],
                        'maxCpcPln' => [
                            'type' => 'number',
                            'exclusiveMinimum' => 0,
                        ],
                    ],
                    'required' => ['type'],
                ],
                'location' => [
                    'type' => 'object',
                    'properties' => [
                        'proximity' => [
                            'type' => 'object',
                            'properties' => [
                                'lat' => [
                                    'type' => 'number',
                                    'minimum' => -90,
                                    'maximum' => 90,
                                ],
                                'lng' => [
                                    'type' => 'number',
                                    'minimum' => -180,
                                    'maximum' => 180,
                                ],
                                'radiusKm' => [
                                    'type' => 'number',
                                    'minimum' => 1,
                                    'maximum' => 800,
                                ],
                            ],
                            'required' => ['lat', 'lng', 'radiusKm'],
                        ],
                    ],
                    'required' => ['proximity'],
                ],
                'networks' => [
                    'type' => 'object',
                    'properties' => [
                        'searchPartners' => [
                            'type' => 'boolean',
                        ],
                        'display' => [
                            'type' => 'boolean',
                        ],
                    ],
                    'required' => ['searchPartners', 'display'],
                ],
                'campaignParam' => [
                    'type' => 'string',
                ],
                'campaignNegatives' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'text' => [
                                'type' => 'string',
                                'minLength' => 1,
                            ],
                            'match' => [
                                'type' => 'string',
                                'enum' => ['EXACT', 'PHRASE', 'BROAD'],
                            ],
                        ],
                        'required' => ['text', 'match'],
                    ],
                ],
                'adGroups' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => [
                                'type' => 'string',
                            ],
                            'defaultCpcPln' => [
                                'type' => 'number',
                                'exclusiveMinimum' => 0,
                            ],
                            'adGroupParam' => [
                                'type' => 'string',
                            ],
                            'keywords' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'text' => [
                                            'type' => 'string',
                                            'minLength' => 1,
                                        ],
                                        'match' => [
                                            'type' => 'string',
                                            'enum' => ['EXACT', 'PHRASE', 'BROAD'],
                                        ],
                                    ],
                                    'required' => ['text', 'match'],
                                ],
                            ],
                            'negatives' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'text' => [
                                            'type' => 'string',
                                            'minLength' => 1,
                                        ],
                                        'match' => [
                                            'type' => 'string',
                                            'enum' => ['EXACT', 'PHRASE', 'BROAD'],
                                        ],
                                    ],
                                    'required' => ['text', 'match'],
                                ],
                            ],
                            'ads' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'headlines' => [
                                            'type' => 'array',
                                            'items' => [
                                                'type' => 'string',
                                            ],
                                        ],
                                        'descriptions' => [
                                            'type' => 'array',
                                            'items' => [
                                                'type' => 'string',
                                            ],
                                        ],
                                        'path1' => [
                                            'type' => 'string',
                                        ],
                                        'path2' => [
                                            'type' => 'string',
                                        ],
                                        'finalUrl' => [
                                            'type' => 'string',
                                        ],
                                    ],
                                    'required' => ['headlines', 'descriptions', 'finalUrl'],
                                ],
                            ],
                        ],
                        'required' => ['name', 'keywords', 'ads'],
                    ],
                ],
            ],
            'required' => ['name', 'dailyBudgetPln', 'bidding', 'adGroups'],
        ];
    }

    /** Input of prepare_campaign_settings (Build/Settings.php): dates (YYYY-MM-DD or "none"), location option, locations (name or geoTargetConstantId, radius km) and asset automation (map type => bool). */
    public static function campaignSettings(): array
    {
        return [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type' => 'object',
            'properties' => [
                'campaign' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'description' => 'campaign ID or its exact name',
                ],
                'endDate' => [
                    'description' => 'YYYY-MM-DD or "none"',
                    'type' => 'string',
                ],
                'startDate' => [
                    'description' => 'future date only',
                    'type' => 'string',
                    'pattern' => '^\\d{4}-\\d{2}-\\d{2}$',
                ],
                'geoTargetType' => [
                    'type' => 'string',
                    'enum' => ['PRESENCE', 'PRESENCE_OR_INTEREST'],
                ],
                'locations' => [
                    'type' => 'object',
                    'properties' => [
                        'add' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'name' => [
                                        'type' => 'string',
                                    ],
                                    'geoTargetConstantId' => [
                                        'type' => 'string',
                                    ],
                                    'radiusKm' => [
                                        'type' => 'number',
                                        'minimum' => 1,
                                        'maximum' => 800,
                                    ],
                                ],
                            ],
                        ],
                        'remove' => [
                            'description' => 'criterion ID or name',
                            'type' => 'array',
                            'items' => [
                                'type' => 'string',
                            ],
                        ],
                        'exclude' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'name' => [
                                        'type' => 'string',
                                    ],
                                    'geoTargetConstantId' => [
                                        'type' => 'string',
                                    ],
                                ],
                            ],
                        ],
                        'unexclude' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'string',
                            ],
                        ],
                    ],
                ],
                'assetAutomation' => [
                    'description' => 'type => true (enable) / false (disable)',
                    'type' => 'object',
                    'propertyNames' => [
                        'type' => 'string',
                        'enum' => ['FINAL_URL_EXPANSION_TEXT_ASSET_AUTOMATION', 'TEXT_ASSET_AUTOMATION', 'GENERATE_IMAGE_EXTRACTION', 'GENERATE_IMAGE_ENHANCEMENT', 'GENERATE_ENHANCED_YOUTUBE_VIDEOS', 'AUTOMATED_VIDEO_CRAWL'],
                    ],
                    'additionalProperties' => [
                        'type' => 'boolean',
                    ],
                ],
            ],
            'required' => ['campaign'],
            'additionalProperties' => false,
        ];
    }

    /** Input of prepare_negative_keywords (Build/Negatives.php): up to 200 words per request; target is a campaign or a shared list (sharedList, optionally attachTo). The length limits of a single word are checked by Negatives (80 characters, 10 words). */
    public static function negativeKeywords(): array
    {
        return [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type' => 'object',
            'properties' => [
                'keywords' => [
                    'minItems' => 1,
                    'maxItems' => 200,
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                    ],
                    'description' => 'negative keywords to add (1-200; up to 80 characters and 10 words each)',
                ],
                'matchType' => [
                    'description' => 'match type: EXACT, PHRASE (default) or BROAD',
                    'type' => 'string',
                    'enum' => ['EXACT', 'PHRASE', 'BROAD'],
                ],
                'target' => [
                    'anyOf' => [
                        [
                            'type' => 'object',
                            'properties' => [
                                'campaign' => [
                                    'type' => 'string',
                                    'minLength' => 1,
                                    'description' => 'campaign ID or its exact name (Search or Performance Max)',
                                ],
                            ],
                            'required' => ['campaign'],
                            'additionalProperties' => false,
                        ],
                        [
                            'type' => 'object',
                            'properties' => [
                                'sharedList' => [
                                    'type' => 'string',
                                    'minLength' => 1,
                                    'description' => 'name of the shared negative list (created if it does not exist)',
                                ],
                                'attachTo' => [
                                    'description' => 'campaigns (IDs or names) to attach the list to',
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'string',
                                        'minLength' => 1,
                                    ],
                                ],
                            ],
                            'required' => ['sharedList'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                'remove' => [
                    'description' => 'negatives to remove (text, matched using matchType)',
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                    ],
                ],
                'checkCollisions' => [
                    'description' => 'default true: warns (does not block) when a negative matches, as whole words, the campaign\'s offer texts (headlines, descriptions, groups, keywords)',
                    'type' => 'boolean',
                ],
            ],
            'required' => ['keywords', 'target'],
            'additionalProperties' => false,
        ];
    }

    /** Input of prepare_account_exclusions (Build/Exclusions.php): add/remove (placements, YouTube channels and videos, apps, app categories, content labels) and presets. */
    public static function accountExclusions(): array
    {
        return [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type' => 'object',
            'properties' => [
                'add' => [
                    'type' => 'object',
                    'properties' => [
                        'placements' => [
                            'description' => 'website domains, e.g. example.com',
                            'type' => 'array',
                            'items' => [
                                'type' => 'string',
                            ],
                        ],
                        'youtubeChannels' => [
                            'description' => 'YouTube channel IDs (UC…, 24 characters)',
                            'type' => 'array',
                            'items' => [
                                'type' => 'string',
                            ],
                        ],
                        'youtubeVideos' => [
                            'description' => 'YouTube video IDs (11 characters)',
                            'type' => 'array',
                            'items' => [
                                'type' => 'string',
                            ],
                        ],
                        'mobileApps' => [
                            'description' => 'apps: 1-<App Store number> or 2-<Google Play package>',
                            'type' => 'array',
                            'items' => [
                                'type' => 'string',
                            ],
                        ],
                        'mobileAppCategories' => [
                            'description' => 'constant app category IDs (e.g. 60008 = Games on Google Play)',
                            'type' => 'array',
                            'items' => [
                                'type' => 'string',
                            ],
                        ],
                        'contentLabels' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'string',
                                'enum' => ['SEXUALLY_SUGGESTIVE', 'BELOW_THE_FOLD', 'PARKED_DOMAIN', 'JUVENILE', 'PROFANITY', 'TRAGEDY', 'VIDEO', 'VIDEO_RATING_DV_G', 'VIDEO_RATING_DV_PG', 'VIDEO_RATING_DV_T', 'VIDEO_RATING_DV_MA', 'VIDEO_NOT_YET_RATED', 'EMBEDDED_VIDEO', 'LIVE_STREAMING_VIDEO', 'SOCIAL_ISSUES', 'BRAND_SUITABILITY_CONTENT_FOR_FAMILIES', 'BRAND_SUITABILITY_GAMES_FIGHTING', 'BRAND_SUITABILITY_GAMES_MATURE', 'BRAND_SUITABILITY_HEALTH_SENSITIVE', 'BRAND_SUITABILITY_HEALTH_SOURCE_UNDETERMINED', 'BRAND_SUITABILITY_NEWS_RECENT', 'BRAND_SUITABILITY_NEWS_SENSITIVE', 'BRAND_SUITABILITY_NEWS_SOURCE_NOT_FEATURED', 'BRAND_SUITABILITY_POLITICS', 'BRAND_SUITABILITY_RELIGION'],
                            ],
                        ],
                    ],
                    'additionalProperties' => false,
                ],
                'remove' => [
                    'type' => 'object',
                    'properties' => [
                        'placements' => [
                            'description' => 'website domains, e.g. example.com',
                            'type' => 'array',
                            'items' => [
                                'type' => 'string',
                            ],
                        ],
                        'youtubeChannels' => [
                            'description' => 'YouTube channel IDs (UC…, 24 characters)',
                            'type' => 'array',
                            'items' => [
                                'type' => 'string',
                            ],
                        ],
                        'youtubeVideos' => [
                            'description' => 'YouTube video IDs (11 characters)',
                            'type' => 'array',
                            'items' => [
                                'type' => 'string',
                            ],
                        ],
                        'mobileApps' => [
                            'description' => 'apps: 1-<App Store number> or 2-<Google Play package>',
                            'type' => 'array',
                            'items' => [
                                'type' => 'string',
                            ],
                        ],
                        'mobileAppCategories' => [
                            'description' => 'constant app category IDs (e.g. 60008 = Games on Google Play)',
                            'type' => 'array',
                            'items' => [
                                'type' => 'string',
                            ],
                        ],
                        'contentLabels' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'string',
                                'enum' => ['SEXUALLY_SUGGESTIVE', 'BELOW_THE_FOLD', 'PARKED_DOMAIN', 'JUVENILE', 'PROFANITY', 'TRAGEDY', 'VIDEO', 'VIDEO_RATING_DV_G', 'VIDEO_RATING_DV_PG', 'VIDEO_RATING_DV_T', 'VIDEO_RATING_DV_MA', 'VIDEO_NOT_YET_RATED', 'EMBEDDED_VIDEO', 'LIVE_STREAMING_VIDEO', 'SOCIAL_ISSUES', 'BRAND_SUITABILITY_CONTENT_FOR_FAMILIES', 'BRAND_SUITABILITY_GAMES_FIGHTING', 'BRAND_SUITABILITY_GAMES_MATURE', 'BRAND_SUITABILITY_HEALTH_SENSITIVE', 'BRAND_SUITABILITY_HEALTH_SOURCE_UNDETERMINED', 'BRAND_SUITABILITY_NEWS_RECENT', 'BRAND_SUITABILITY_NEWS_SENSITIVE', 'BRAND_SUITABILITY_NEWS_SOURCE_NOT_FEATURED', 'BRAND_SUITABILITY_POLITICS', 'BRAND_SUITABILITY_RELIGION'],
                            ],
                        ],
                    ],
                    'additionalProperties' => false,
                ],
                'presets' => [
                    'description' => 'ready-made sets: kids_games_and_apps (app categories: games, family, kids), sensitive_content (labels: sexually suggestive, profanity, tragedy, social issues).',
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                        'enum' => ['kids_games_and_apps', 'sensitive_content'],
                    ],
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    /** Input of tools that only identify a campaign (e.g. prepare_campaign_pause): campaign ID or its exact name. */
    public static function campaignRef(): array
    {
        return [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type' => 'object',
            'properties' => [
                'campaign' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'description' => 'campaign ID or its exact name',
                ],
            ],
            'required' => ['campaign'],
        ];
    }

    /** Input of prepare_undo: planId (32 hex characters) or, by default, the last executed plan. */
    public static function undo(): array
    {
        return [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type' => 'object',
            'properties' => [
                'planId' => [
                    'description' => 'ID of the plan to undo (32 characters); defaults to the last executed plan',
                    'type' => 'string',
                    'pattern' => '^[0-9a-f]{32}$',
                ],
            ],
        ];
    }

    /** Empty schema for tools without parameters. */
    public static function empty(): array
    {
        return [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type' => 'object',
            'properties' => [],
        ];
    }

    /** Input of show_plan: planId of a stored plan (read-only; opens the preview). */
    public static function showPlan(): array
    {
        return [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type' => 'object',
            'properties' => [
                'planId' => [
                    'type' => 'string',
                    'minLength' => 1,
                ],
            ],
            'required' => ['planId'],
        ];
    }
}
