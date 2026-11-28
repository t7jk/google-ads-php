<?php
declare(strict_types=1);

namespace App\Ads;

use App\Google\ReauthRequiredError;

/** Port of src/ads/errors.ts: builds AdsApiError from a response and produces English messages for the model. */
final class Errors
{
    /** Builds an AdsApiError from the raw response body (never contains a token). */
    public static function fromResponse(int $status, string $raw): AdsApiError
    {
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            return new AdsApiError("Google Ads returned HTTP error $status", $status, [], null);
        }
        $err = $body['error'] ?? null;
        $codes = [];
        $msgs = [];
        foreach (self::list($err['details'] ?? null) as $detail) {
            foreach (self::list($detail['errors'] ?? null) as $x) {
                if (isset($x['errorCode']) && is_array($x['errorCode'])) {
                    foreach ($x['errorCode'] as $v) {
                        if (is_string($v)) {
                            $codes[] = $v;
                        }
                    }
                }
                if (isset($x['message']) && is_string($x['message'])) {
                    $msgs[] = $x['message'];
                }
            }
        }
        if (isset($err['status']) && is_string($err['status'])) {
            $codes[] = $err['status'];
        }
        $message = $msgs[0] ?? (isset($err['message']) && is_string($err['message']) ? $err['message'] : "HTTP $status");
        return new AdsApiError($message, $status, $codes, $body);
    }

    /** Message for the model. $op: 'read' | 'write' (only affects the wording of the generic API error). */
    public static function describe(\Throwable $e, string $op = 'write'): string
    {
        if ($e instanceof ReauthRequiredError || $e instanceof UserError) {
            return $e->getMessage();
        }
        if ($e instanceof AdsApiError) {
            return self::describeAds($e, $op);
        }
        return 'Error: ' . $e->getMessage();
    }

    private static function describeAds(AdsApiError $e, string $op): string
    {
        $has = fn(string $c): bool => in_array($c, $e->codes, true);
        $msg = $e->getMessage();
        $detailsJson = json_encode($e->details ?? '', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';

        if ($has('CLOUD_PROJECT_NOT_APPROVED_FOR_PRODUCTION')) {
            return 'Your Google Cloud project does not have Explorer access to the Google Ads API yet.';
        }
        if ($has('AUTHORIZATION_ERROR') || $has('PERMISSION_DENIED')) {
            return 'No permission for the Google Ads account (authorization error). Sign in again with the correct account.';
        }
        if ($has('QUOTA_ERROR') || $has('RESOURCE_EXHAUSTED')) {
            return 'Google Ads API request quota exceeded. Try again later.';
        }
        if ($has('DUPLICATE_NAME')) {
            return 'An item with this name already exists - choose a different name.';
        }
        if ($has('MISSING_EU_POLITICAL_ADVERTISING_SELF_DECLARATION')) {
            return 'The EU political advertising declaration is missing (the campaign must include the statement).';
        }
        if ($has('MUTATE_NOT_ALLOWED') || preg_match('/Mutates are not allowed/i', $msg)) {
            return 'Google does not allow changing this resource through the API (e.g. a conversion action created in the UI without code) - change it in the Google Ads UI';
        }
        $exists = $has('RESOURCE_ALREADY_EXISTS') || $has('ID_EXISTS_IN_MULTIPLE_MUTATES') || preg_match('/already exists/i', $msg);
        if ($exists && ($has('ID_EXISTS_IN_MULTIPLE_MUTATES') || preg_match('/image|asset/i', $msg . $detailsJson))) {
            return 'An identical image (same file content) already exists in the account or campaign - renaming will not help; use the existing asset or a different file.';
        }
        if ($has('RESOURCE_ALREADY_EXISTS') || preg_match('/resource being created already exists/i', $msg)) {
            return 'This resource already exists in the account (e.g. an image with the same name) - use a different name or the existing resource';
        }
        if ($has('TOO_LONG')) {
            return "The text is longer than Google Ads allows: $msg";
        }
        if ($has('POLICY_FINDING')) {
            $topics = self::policyTopics($e->details);
            return 'The ad violates Google Ads policies' . ($topics ? ' (topic: ' . implode(', ', $topics) . ')' : '') . ": $msg";
        }
        return $op === 'read' ? "Google Ads returned an error: $msg" : "Google Ads rejected the change: $msg";
    }

    /** @return list<string> */
    private static function policyTopics(mixed $details): array
    {
        $out = [];
        foreach (self::list($details['error']['details'] ?? null) as $d) {
            foreach (self::list($d['errors'] ?? null) as $x) {
                foreach (self::list($x['details']['policyFindingDetails']['policyTopicEntries'] ?? null) as $t) {
                    if (!empty($t['topic'])) {
                        $out[] = (string) $t['topic'];
                    }
                }
            }
        }
        return array_values(array_unique($out));
    }

    private static function list(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
