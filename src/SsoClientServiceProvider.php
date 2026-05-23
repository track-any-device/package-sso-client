<?php

namespace TrackAnyDevice\SsoClient;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Laravel\Socialite\Contracts\Factory as SocialiteFactory;
use Laravel\Socialite\SocialiteManager;
use TrackAnyDevice\SsoClient\Socialite\SsoProvider;

class SsoClientServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->callAfterResolving(SocialiteFactory::class, function (SocialiteManager $socialite) {
            // Arrow function seals $this = ServiceProvider before extend() can rebind it.
            // Manager::extend() calls Closure::bindTo($socialiteManager) on whatever you
            // pass it, so any plain closure with $this would see SocialiteManager as $this,
            // hit __call(), call driver(null), and throw "No Socialite driver was specified".
            $resolve = fn (Application $app) => $this->resolveConfig($app);

            $socialite->extend('sso', function (Application $app) use ($resolve) {
                $config = $resolve($app);

                return (new SsoProvider(
                    $app['request'],
                    $config['client_id'] ?? '',
                    $config['client_secret'] ?? '',
                    $config['redirect'] ?? '',
                ))->setConfig($config);
            });
        });
    }

    /**
     * Resolve Socialite config from the database using APP_SURFACE (kind),
     * falling back to config('services.sso') if the DB is unavailable or
     * the surface has no matching row.
     *
     * @return array<string, mixed>
     */
    protected function resolveConfig(Application $app): array
    {
        $serverUrl = $app['config']['services.sso.server_url']
            ?? env('SSO_SERVER_URL', '');

        $surface = env('APP_SURFACE', '');

        if ($surface !== '') {
            try {
                $client = DB::table('oauth_clients')
                    ->where('kind', $surface)
                    ->where('is_active', true)
                    ->first();

                if ($client) {
                    $uris = json_decode($client->redirect_uris ?? '[]', true);

                    return [
                        'client_id'     => $client->client_id,
                        'client_secret' => $client->client_secret_hash,
                        'redirect'      => $uris[0] ?? '',
                        'server_url'    => $serverUrl,
                    ];
                }
            } catch (\Throwable) {
                // DB unavailable — fall through to static config
            }
        }

        // Fallback: explicit config/env (useful for local dev before seeding)
        return array_merge(
            $app['config']['services.sso'] ?? [],
            ['server_url' => $serverUrl],
        );
    }
}
