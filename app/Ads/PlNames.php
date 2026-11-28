<?php
declare(strict_types=1);

namespace App\Ads;

/** Port src/ads/pl-names.ts: English display names for Google Ads enum values (unknown values: returned as-is from the API). Class name kept from the Polish version. */
final class PlNames
{
    public const DAYS = ['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY', 'SUNDAY'];
    public const AGES = ['AGE_RANGE_18_24', 'AGE_RANGE_25_34', 'AGE_RANGE_35_44', 'AGE_RANGE_45_54', 'AGE_RANGE_55_64', 'AGE_RANGE_65_UP', 'AGE_RANGE_UNDETERMINED'];
    public const GENDERS = ['MALE', 'FEMALE', 'UNDETERMINED'];

    private const DEVICE = ['MOBILE' => 'mobile', 'DESKTOP' => 'desktop', 'TABLET' => 'tablet', 'CONNECTED_TV' => 'connected TV', 'OTHER' => 'other', 'UNKNOWN' => 'unknown', 'UNSPECIFIED' => 'unknown'];
    private const DAY = ['MONDAY' => 'Monday', 'TUESDAY' => 'Tuesday', 'WEDNESDAY' => 'Wednesday', 'THURSDAY' => 'Thursday', 'FRIDAY' => 'Friday', 'SATURDAY' => 'Saturday', 'SUNDAY' => 'Sunday'];
    private const AGE = ['AGE_RANGE_18_24' => '18–24', 'AGE_RANGE_25_34' => '25–34', 'AGE_RANGE_35_44' => '35–44', 'AGE_RANGE_45_54' => '45–54', 'AGE_RANGE_55_64' => '55–64', 'AGE_RANGE_65_UP' => '65+', 'AGE_RANGE_UNDETERMINED' => 'undetermined'];
    private const GENDER = ['MALE' => 'men', 'FEMALE' => 'women', 'UNDETERMINED' => 'undetermined'];

    /** Short "why" for policy restriction types (PolicyTopicEntryType). */
    private const POLICY_TYPE = [
        'PROHIBITED' => 'prohibited: the ad does not serve',
        'FULLY_LIMITED' => 'heavily limited: serves only in selected situations',
        'LIMITED' => 'limited: reduced reach',
        'DESCRIPTIVE' => 'informational: no effect on serving',
        'BROADENING' => 'broadening reach',
        'AREA_OF_INTEREST_ONLY' => 'only for people interested in the topic',
    ];
    /** Short "why" for the most common policy topics. */
    private const POLICY_TOPIC = [
        'DESTINATION_NOT_WORKING' => 'landing page not working',
        'DESTINATION_MISMATCH' => 'landing page does not match the ad',
        'DESTINATION_NOT_RESPONDING' => 'landing page not responding',
        'TRADEMARKS_IN_AD_TEXT' => 'trademark in ad text',
        'TRADEMARKS_IN_AD_TEXT_REGISTERED' => 'trademark in ad text',
        'EDITORIAL' => 'editorial rules (e.g. capitalisation, punctuation)',
        'EDITORIAL_AND_PROFESSIONAL_STANDARDS' => 'editorial and professional standards',
        'PUNCTUATION_AND_SYMBOLS' => 'punctuation and symbols',
        'MISLEADING_CLAIMS' => 'misleading claims',
        'UNACCEPTABLE_BUSINESS_PRACTICES' => 'unacceptable business practices',
        'MISREPRESENTATION' => 'misrepresentation',
        'ADULT_CONTENT' => 'adult content',
        'HEALTH_IN_PERSONALIZED_ADS' => 'health in personalized ads',
        'PERSONALIZED_ADS' => 'personalized ads restrictions',
        'CHILDREN' => 'content directed at children',
        'KIDS' => 'content directed at children',
        'GAMBLING' => 'gambling',
        'ALCOHOL' => 'alcohol',
        'FINANCIAL_SERVICES' => 'financial services',
        'IMAGE_QUALITY' => 'image quality',
        'LOW_QUALITY_IMAGE' => 'image quality',
        'ASSET_REJECTED' => 'asset rejected',
        'COPYRIGHT' => 'copyright',
    ];

    public static function deviceName(mixed $v): string
    {
        return self::lookup(self::DEVICE, $v);
    }
    public static function dayName(mixed $v): string
    {
        return self::lookup(self::DAY, $v);
    }
    public static function ageName(mixed $v): string
    {
        return self::lookup(self::AGE, $v);
    }
    public static function genderName(mixed $v): string
    {
        return self::lookup(self::GENDER, $v);
    }
    public static function policyTypeWhy(mixed $v): string
    {
        return self::lookup(self::POLICY_TYPE, $v);
    }
    public static function policyTopicWhy(mixed $v): string
    {
        return self::lookup(self::POLICY_TOPIC, $v);
    }

    private static function lookup(array $map, mixed $v): string
    {
        $key = is_scalar($v) ? (string) $v : '';
        return $map[$key] ?? $key;
    }
}
