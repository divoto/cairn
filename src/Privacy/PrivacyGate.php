<?php

declare(strict_types=1);

namespace Divoto\Cairn\Privacy;

use Closure;
use Divoto\Cairn\Contracts\BotDetector;
use Divoto\Cairn\Contracts\ConsentResolver;
use Divoto\Cairn\Enums\DeclineReason;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Throwable;

/**
 * The single decision about whether a request may be recorded at all.
 *
 * Every recording path goes through here, so there is exactly one place to
 * read to know what Cairn will and will not measure, and exactly one place a
 * mistake could be made.
 *
 * Order matters. The visitor's own expressed wishes — Do Not Track, Global
 * Privacy Control, an explicit opt-out, a consent resolver — are evaluated
 * before anything else, so that no amount of configuration further down can
 * cause a request to be recorded against them.
 */
final readonly class PrivacyGate
{
    /**
     * Headers that indicate a speculative fetch rather than a visit.
     *
     * `Sec-Purpose` is the standard; the others are what Chrome and Firefox
     * sent before it existed and still send in places.
     *
     * @var array<string, string>
     */
    private const PREFETCH_HEADERS = [
        'Sec-Purpose' => 'prefetch',
        'Purpose' => 'prefetch',
        'X-Purpose' => 'preview',
        'X-Moz' => 'prefetch',
    ];

    public function __construct(
        private Config $config,
        private BotDetector $bots,
        private ConsentResolver $consent,
    ) {}

    /**
     * Whether this request may be recorded.
     */
    public function allows(Request $request, float $sampleRate = 1.0, ?string $recorder = null): bool
    {
        return ! $this->decide($request, $sampleRate, $recorder) instanceof DeclineReason;
    }

    /**
     * Why this request may not be recorded, or null if it may.
     *
     * @param  float  $sampleRate  The calling recorder's sample rate, 0.0–1.0.
     * @param  string|null  $recorder  The recorder's config key, for its ignore rules.
     */
    public function decide(Request $request, float $sampleRate = 1.0, ?string $recorder = null): ?DeclineReason
    {
        if ($this->config->get('cairn.enabled') !== true) {
            return DeclineReason::Disabled;
        }

        // The visitor's own wishes come first, and nothing below can override
        // them.
        if ($this->signalsDoNotTrack($request)) {
            return DeclineReason::DoNotTrack;
        }

        if ($this->signalsGlobalPrivacyControl($request)) {
            return DeclineReason::GlobalPrivacyControl;
        }

        if (! $this->hasConsent($request)) {
            return DeclineReason::ConsentDenied;
        }

        if ($this->isPrefetch($request)) {
            return DeclineReason::Prefetch;
        }

        if ($this->bots->isBot($request->userAgent())) {
            return DeclineReason::Bot;
        }

        if ($this->isIgnored($request, $recorder)) {
            return DeclineReason::Ignored;
        }

        if (! $this->passesSampling($sampleRate)) {
            return DeclineReason::Sampled;
        }

        return null;
    }

    /**
     * Whether the request carries `DNT: 1` and Cairn is configured to honour
     * it.
     */
    public function signalsDoNotTrack(Request $request): bool
    {
        return $this->config->get('cairn.privacy.respect_dnt') === true
            && $request->header('DNT') === '1';
    }

    /**
     * Whether the request carries `Sec-GPC: 1` and Cairn is configured to
     * honour it.
     */
    public function signalsGlobalPrivacyControl(Request $request): bool
    {
        return $this->config->get('cairn.privacy.respect_gpc') === true
            && $request->header('Sec-GPC') === '1';
    }

    /**
     * Whether the browser was speculatively fetching rather than visiting.
     */
    public function isPrefetch(Request $request): bool
    {
        foreach (self::PREFETCH_HEADERS as $header => $needle) {
            $value = $request->header($header);

            if (is_string($value) && str_contains(strtolower($value), $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the path, route name or a configured closure excludes this
     * request.
     *
     * Patterns are matched against both the path and the route name, so
     * `cairn*` covers the dashboard whether it is referred to by URL or by
     * name.
     */
    public function isIgnored(Request $request, ?string $recorder = null): bool
    {
        $path = $request->path();
        $routeName = $request->route() instanceof Route
            ? $request->route()->getName()
            : null;

        foreach ($this->ignoreRules($recorder) as $rule) {
            if ($rule instanceof Closure) {
                if ($rule($request) === true) {
                    return true;
                }

                continue;
            }

            if (! is_string($rule)) {
                continue;
            }

            if (Str::is($rule, $path) || Str::is($rule, '/'.$path)) {
                return true;
            }

            if ($routeName !== null && Str::is($rule, $routeName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ask the configured consent resolver, treating failure as refusal.
     *
     * A resolver that throws is not evidence of consent. The safe direction is
     * to record nothing.
     */
    private function hasConsent(Request $request): bool
    {
        try {
            return $this->consent->granted($request);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Apply a sample rate.
     *
     * A rate of 1.0 always records and a rate of 0.0 never does — neither
     * touches the random number generator, so a fully-sampled or fully-off
     * recorder is deterministic.
     */
    private function passesSampling(float $sampleRate): bool
    {
        if ($sampleRate >= 1.0) {
            return true;
        }

        if ($sampleRate <= 0.0) {
            return false;
        }

        return (random_int(1, 1_000_000) / 1_000_000) <= $sampleRate;
    }

    /**
     * The ignore rules for a recorder.
     *
     * @return array<int, mixed>
     */
    private function ignoreRules(?string $recorder): array
    {
        if ($recorder === null) {
            return [];
        }

        $rules = $this->config->get('cairn.recorders.'.$recorder.'.ignore');

        return is_array($rules) ? array_values($rules) : [];
    }
}
