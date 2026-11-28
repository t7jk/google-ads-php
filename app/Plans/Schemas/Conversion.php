<?php
declare(strict_types=1);

namespace App\Plans\Schemas;

final class Conversion
{
    /** Input of prepare_conversion_goals (Build/GoalsPlan.php): scope=campaign|account, goal level, category/origin pairs (biddable/onlyBiddable) and primary/secondary actions (primaryForGoal). */
    public static function conversionGoals(): array
    {
        return [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type' => 'object',
            'properties' => [
                'scope' => [
                    'type' => 'string',
                    'enum' => ['campaign', 'account'],
                ],
                'campaign' => [
                    'description' => 'required for scope=campaign: campaign ID or its exact name',
                    'type' => 'string',
                    'minLength' => 1,
                ],
                'goalLevel' => [
                    'description' => 'scope=campaign only',
                    'type' => 'string',
                    'enum' => ['CAMPAIGN', 'CUSTOMER'],
                ],
                'biddable' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'category' => [
                                'type' => 'string',
                                'description' => 'e.g. SUBMIT_LEAD_FORM',
                            ],
                            'origin' => [
                                'type' => 'string',
                                'description' => 'e.g. WEBSITE',
                            ],
                            'biddable' => [
                                'type' => 'boolean',
                            ],
                        ],
                        'required' => ['category', 'origin', 'biddable'],
                    ],
                ],
                'onlyBiddable' => [
                    'description' => 'shortcut: these pairs true, all other existing pairs false',
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'category' => [
                                'type' => 'string',
                            ],
                            'origin' => [
                                'type' => 'string',
                            ],
                        ],
                        'required' => ['category', 'origin'],
                    ],
                ],
                'primaryForGoal' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'conversionActionId' => [
                                'type' => 'string',
                            ],
                            'primary' => [
                                'type' => 'boolean',
                            ],
                        ],
                        'required' => ['conversionActionId', 'primary'],
                    ],
                ],
            ],
            'required' => ['scope'],
            'additionalProperties' => false,
        ];
    }

    /** Input of prepare_conversion_action: mode=create|update; types WEBPAGE, AD_CALL, UPLOAD_CLICKS. Click-through conversion window 1-90 days, name max 100 characters. Actions are never deleted, only hidden (status HIDDEN). */
    public static function conversionAction(): array
    {
        return [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type' => 'object',
            'properties' => [
                'mode' => [
                    'description' => 'create = new action, update = change an existing one; if omitted: the LP2 action from the config',
                    'type' => 'string',
                    'enum' => ['create', 'update'],
                ],
                'type' => [
                    'description' => 'Type of the new action (mode=create only)',
                    'type' => 'string',
                    'enum' => ['WEBPAGE', 'AD_CALL', 'UPLOAD_CLICKS'],
                ],
                'conversionActionId' => [
                    'description' => 'ID of the action to change (mode=update only)',
                    'type' => 'string',
                    'pattern' => '^\\d+$',
                ],
                'name' => [
                    'description' => 'Action name (required for AD_CALL and UPLOAD_CLICKS)',
                    'type' => 'string',
                    'minLength' => 1,
                    'maxLength' => 100,
                ],
                'phoneCallDurationSeconds' => [
                    'description' => 'AD_CALL: after how many seconds of the call to count a conversion (default 60)',
                    'type' => 'integer',
                    'minimum' => 0,
                    'maximum' => 10000,
                ],
                'countingType' => [
                    'description' => 'ONE_PER_CLICK or MANY_PER_CLICK',
                    'type' => 'string',
                    'enum' => ['ONE_PER_CLICK', 'MANY_PER_CLICK'],
                ],
                'clickThroughLookbackWindowDays' => [
                    'description' => 'Click-through conversion window, days (default 30)',
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 90,
                ],
                'defaultValue' => [
                    'description' => 'Default conversion value in PLN (valueSettings.defaultValue)',
                    'type' => 'number',
                    'minimum' => 0,
                ],
                'alwaysUseDefaultValue' => [
                    'description' => 'Always use the default value',
                    'type' => 'boolean',
                ],
                'primaryForGoal' => [
                    'description' => 'Whether this is a primary conversion (UPLOAD_CLICKS defaults to false so a sign-up is not counted twice together with the tag)',
                    'type' => 'boolean',
                ],
                'status' => [
                    'description' => 'mode=update only: ENABLED or HIDDEN (there is no deletion)',
                    'type' => 'string',
                    'enum' => ['ENABLED', 'HIDDEN'],
                ],
            ],
        ];
    }

    /** Input of prepare_offline_conversions: the UPLOAD_CLICKS action and the date range of leads (from/to; default last 30 days, max range 90 days) to upload as offline conversions. */
    public static function offlineConversions(): array
    {
        return [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type' => 'object',
            'properties' => [
                'conversionActionId' => [
                    'description' => 'ID of the UPLOAD_CLICKS conversion action; if omitted: the only such action in the account',
                    'type' => 'string',
                    'pattern' => '^\\d+$',
                ],
                'from' => [
                    'description' => 'Start of the lead date range (default 30 days back; max range 90 days)',
                    'type' => 'string',
                    'pattern' => '^\\d{4}-\\d{2}-\\d{2}$',
                ],
                'to' => [
                    'description' => 'End of the lead date range (default today)',
                    'type' => 'string',
                    'pattern' => '^\\d{4}-\\d{2}-\\d{2}$',
                ],
            ],
        ];
    }

    /** Input of prepare_ga4_key_events: mark/unmark GA4 events as key events (max 20 names, max 40 characters each) and countingMethod. */
    public static function ga4KeyEvents(): array
    {
        return [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type' => 'object',
            'properties' => [
                'mark' => [
                    'default' => [],
                    'description' => 'Event names to mark as key events',
                    'maxItems' => 20,
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                        'minLength' => 1,
                        'maxLength' => 40,
                    ],
                ],
                'unmark' => [
                    'default' => [],
                    'description' => 'Key event names to unmark (remove from the key events list)',
                    'maxItems' => 20,
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                        'minLength' => 1,
                        'maxLength' => 40,
                    ],
                ],
                'countingMethod' => [
                    'default' => 'ONCE_PER_EVENT',
                    'description' => 'Counting method of new key events: ONCE_PER_EVENT (every event) or ONCE_PER_SESSION (once per session)',
                    'type' => 'string',
                    'enum' => ['ONCE_PER_EVENT', 'ONCE_PER_SESSION'],
                ],
            ],
            'additionalProperties' => false,
        ];
    }
}
