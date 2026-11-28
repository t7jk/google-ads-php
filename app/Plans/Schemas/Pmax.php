<?php
declare(strict_types=1);

namespace App\Plans\Schemas;

final class Pmax
{
    /** Input of prepare_asset_group_changes (Performance Max campaigns): asset group texts, images, videos, final URL, URL paths (path1/path2 max 15 characters) and creating a new group (newGroup). */
    public static function assetGroupChanges(): array
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
                'assetGroup' => [
                    'description' => 'asset group ID or exact name; omit with newGroup',
                    'type' => 'string',
                ],
                'finalUrl' => [
                    'type' => 'string',
                ],
                'path1' => [
                    'type' => 'string',
                    'maxLength' => 15,
                ],
                'path2' => [
                    'type' => 'string',
                    'maxLength' => 15,
                ],
                'rename' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'maxLength' => 128,
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => ['PAUSED', 'ENABLED'],
                ],
                'texts' => [
                    'type' => 'object',
                    'properties' => [
                        'add' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'fieldType' => [
                                        'type' => 'string',
                                        'enum' => ['HEADLINE', 'LONG_HEADLINE', 'DESCRIPTION', 'BUSINESS_NAME'],
                                    ],
                                    'text' => [
                                        'type' => 'string',
                                    ],
                                ],
                                'required' => ['fieldType', 'text'],
                            ],
                        ],
                        'remove' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'fieldType' => [
                                        'type' => 'string',
                                    ],
                                    'text' => [
                                        'type' => 'string',
                                    ],
                                    'assetId' => [
                                        'type' => 'string',
                                    ],
                                ],
                            ],
                        ],
                        'replace' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'fieldType' => [
                                        'type' => 'string',
                                    ],
                                    'from' => [
                                        'type' => 'string',
                                        'description' => 'current text (exact) or assetId',
                                    ],
                                    'to' => [
                                        'type' => 'string',
                                    ],
                                ],
                                'required' => ['from', 'to'],
                            ],
                        ],
                    ],
                ],
                'images' => [
                    'type' => 'object',
                    'properties' => [
                        'add' => [
                            'maxItems' => 10,
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'role' => [
                                        'type' => 'string',
                                        'enum' => ['image', 'logo'],
                                    ],
                                    'url' => [
                                        'type' => 'string',
                                    ],
                                    'uploadId' => [
                                        'type' => 'string',
                                    ],
                                    'base64' => [
                                        'type' => 'string',
                                    ],
                                    'name' => [
                                        'type' => 'string',
                                    ],
                                ],
                                'required' => ['role'],
                            ],
                        ],
                        'remove' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'assetId' => [
                                        'type' => 'string',
                                    ],
                                    'size' => [
                                        'description' => 'e.g. 300x300',
                                        'type' => 'string',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'videos' => [
                    'type' => 'object',
                    'properties' => [
                        'add' => [
                            'description' => 'video ID or YouTube URL',
                            'type' => 'array',
                            'items' => [
                                'type' => 'string',
                            ],
                        ],
                        'remove' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'string',
                            ],
                        ],
                    ],
                ],
                'newGroup' => [
                    'description' => 'new group, always PAUSED; the full minimum set of assets in texts/images',
                    'type' => 'object',
                    'properties' => [
                        'name' => [
                            'type' => 'string',
                        ],
                        'finalUrl' => [
                            'type' => 'string',
                        ],
                    ],
                    'required' => ['name', 'finalUrl'],
                ],
            ],
            'required' => ['campaign'],
            'additionalProperties' => false,
        ];
    }

    /** Input of prepare_campaign_assets: campaign assets: sitelinks (text max 25, descriptions max 35 characters), callouts (max 25), call, lead form, business name (max 25), logos. */
    public static function campaignAssets(): array
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
                'sitelinks' => [
                    'type' => 'object',
                    'properties' => [
                        'add' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'text' => [
                                        'type' => 'string',
                                        'maxLength' => 25,
                                    ],
                                    'description1' => [
                                        'type' => 'string',
                                        'maxLength' => 35,
                                    ],
                                    'description2' => [
                                        'type' => 'string',
                                        'maxLength' => 35,
                                    ],
                                    'url' => [
                                        'type' => 'string',
                                    ],
                                ],
                                'required' => ['text', 'url'],
                            ],
                        ],
                        'remove' => [
                            'description' => 'link text or assetId',
                            'type' => 'array',
                            'items' => [
                                'type' => 'string',
                            ],
                        ],
                        'replaceUrl' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'text' => [
                                        'type' => 'string',
                                    ],
                                    'url' => [
                                        'type' => 'string',
                                    ],
                                ],
                                'required' => ['text', 'url'],
                            ],
                        ],
                    ],
                ],
                'callouts' => [
                    'type' => 'object',
                    'properties' => [
                        'add' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'string',
                                'maxLength' => 25,
                            ],
                        ],
                        'remove' => [
                            'description' => 'callout text or assetId',
                            'type' => 'array',
                            'items' => [
                                'type' => 'string',
                            ],
                        ],
                    ],
                ],
                'call' => [
                    'anyOf' => [
                        [
                            'type' => 'object',
                            'properties' => [
                                'phone' => [
                                    'type' => 'string',
                                ],
                                'countryCode' => [
                                    'default' => 'PL',
                                    'type' => 'string',
                                ],
                            ],
                            'required' => ['phone'],
                        ],
                        [
                            'type' => 'string',
                            'const' => 'remove',
                        ],
                    ],
                ],
                'leadForm' => [
                    'type' => 'string',
                    'const' => 'remove',
                ],
                'businessName' => [
                    'description' => 'only when brand guidelines are enabled',
                    'type' => 'string',
                    'maxLength' => 25,
                ],
                'logos' => [
                    'description' => 'only when brand guidelines are enabled; logo images (1:1 or 4:1)',
                    'type' => 'object',
                    'properties' => [
                        'remove' => [
                            'description' => 'campaign logo to detach: resource name or assetId',
                            'maxItems' => 10,
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'name' => [
                                        'type' => 'string',
                                    ],
                                    'assetId' => [
                                        'type' => 'string',
                                    ],
                                ],
                            ],
                        ],
                        'add' => [
                            'maxItems' => 10,
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'uploadId' => [
                                        'type' => 'string',
                                    ],
                                    'url' => [
                                        'type' => 'string',
                                    ],
                                    'base64' => [
                                        'type' => 'string',
                                    ],
                                    'name' => [
                                        'type' => 'string',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'required' => ['campaign'],
            'additionalProperties' => false,
        ];
    }

    /** Input of prepare_asset_group_signals: PMax asset group signals: search themes (max 80 characters) and audiences (audience); removeAudience removes a signal. */
    public static function assetGroupSignals(): array
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
                'assetGroup' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'description' => 'asset group ID or its exact name',
                ],
                'searchThemes' => [
                    'description' => 'search themes (signals): up to 50 per group, text up to 80 characters and 10 words',
                    'type' => 'object',
                    'properties' => [
                        'add' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'string',
                                'maxLength' => 80,
                            ],
                        ],
                        'remove' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'string',
                            ],
                        ],
                    ],
                ],
                'audience' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => [
                            'description' => 'name of the new audience (required when creating)',
                            'type' => 'string',
                        ],
                        'existingAudienceId' => [
                            'description' => 'ID of an existing audience; only adds a signal',
                            'type' => 'string',
                        ],
                        'customAudienceIds' => [
                            'description' => 'custom segment IDs (from prepare_custom_audience)',
                            'type' => 'array',
                            'items' => [
                                'type' => 'string',
                            ],
                        ],
                        'userListIds' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'string',
                            ],
                        ],
                        'ageRanges' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'min' => [
                                        'anyOf' => [
                                            [
                                                'type' => 'number',
                                                'const' => 18,
                                            ],
                                            [
                                                'type' => 'number',
                                                'const' => 25,
                                            ],
                                            [
                                                'type' => 'number',
                                                'const' => 35,
                                            ],
                                            [
                                                'type' => 'number',
                                                'const' => 45,
                                            ],
                                            [
                                                'type' => 'number',
                                                'const' => 55,
                                            ],
                                            [
                                                'type' => 'number',
                                                'const' => 65,
                                            ],
                                        ],
                                    ],
                                    'max' => [
                                        'anyOf' => [
                                            [
                                                'type' => 'number',
                                                'const' => 24,
                                            ],
                                            [
                                                'type' => 'number',
                                                'const' => 34,
                                            ],
                                            [
                                                'type' => 'number',
                                                'const' => 44,
                                            ],
                                            [
                                                'type' => 'number',
                                                'const' => 54,
                                            ],
                                            [
                                                'type' => 'number',
                                                'const' => 64,
                                            ],
                                        ],
                                    ],
                                ],
                                'required' => ['min', 'max'],
                            ],
                        ],
                        'genders' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'string',
                                'enum' => ['FEMALE', 'MALE', 'UNDETERMINED'],
                            ],
                        ],
                        'parentalStatus' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'string',
                                'enum' => ['PARENT', 'NOT_A_PARENT', 'UNDETERMINED'],
                            ],
                        ],
                    ],
                ],
                'removeAudience' => [
                    'description' => 'removes the group\'s audience signals',
                    'type' => 'boolean',
                ],
            ],
            'required' => ['campaign', 'assetGroup'],
            'additionalProperties' => false,
        ];
    }

    /** Input of prepare_custom_audience: a custom audience built from keywords and URLs. */
    public static function customAudience(): array
    {
        return [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'description' => 'custom segment name',
                ],
                'keywords' => [
                    'description' => 'keywords (each up to 80 characters and 10 words)',
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                    ],
                ],
                'urls' => [
                    'description' => 'page URLs (https?://, up to 2048 characters); can be competitor or topical pages',
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                    ],
                ],
            ],
            'required' => ['name'],
            'additionalProperties' => false,
        ];
    }
}
