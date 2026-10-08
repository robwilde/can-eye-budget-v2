<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\ContextDevServiceContract;
use App\Contracts\GitHubServiceContract;
use App\Contracts\GmailServiceContract;
use App\Contracts\ScheduleSource;
use App\Contracts\TypeSafeServiceContract;
use App\Services\Bnpl\GmailScheduleSource;
use App\Services\CategoryRuleGenerator;
use App\Services\ContextDevService;
use App\Services\GitHubService;
use App\Services\GmailService;
use App\Services\MerchantBrands\ContextDevCreditBalance;
use App\Services\MerchantBrands\ContextDevCreditBudget;
use App\Services\PipelineStages\IdentifyPrimaryAccountStage;
use App\Services\PipelineStages\IdentifyRecurringTransactionsStage;
use App\Services\PipelineStages\MatchPlannedTransactionsStage;
use App\Services\PipelineStages\RuleMiningStage;
use App\Services\PipelineStages\SetPayCycleStage;
use App\Services\PipelineStages\TransferDetectionStage;
use App\Services\PipelineStages\UserRulesStage;
use App\Services\RedbarkClientFactory;
use App\Services\TransactionAnalysisPipeline;
use App\Services\TypeSafeService;
use App\Support\Email\ScheduleParser;
use App\Support\Email\Schedules\AfterpayOrderStrategy;
use App\Support\Email\Schedules\PayPalReceiptStrategy;
use App\View\Composers\LayoutShellComposer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(RedbarkClientFactory::class, fn (): RedbarkClientFactory => new RedbarkClientFactory(
            baseUrl: (string) config('services.redbark.base_url'),
        ));

        $this->app->singleton(ContextDevServiceContract::class, function (): ContextDevService {
            $apiKey = (string) config('services.context_dev.api_key');

            throw_if(blank($apiKey), RuntimeException::class, 'CONTEXT_DEV_API_KEY is not configured.');

            return ContextDevService::withApiKey($apiKey, balance: $this->app->make(ContextDevCreditBalance::class));
        });

        $this->app->alias(ContextDevServiceContract::class, ContextDevService::class);

        $this->app->singleton(TypeSafeServiceContract::class, function (): TypeSafeService {
            $apiKey = (string) config('services.typesafe.api_key');

            throw_if(blank($apiKey), RuntimeException::class, 'TYPESAFE_API_KEY is not configured.');

            return new TypeSafeService(
                apiKey: $apiKey,
                baseUrl: (string) config('services.typesafe.base_url'),
                model: (string) config('services.typesafe.model'),
            );
        });

        $this->app->bind(ContextDevCreditBudget::class, fn (): ContextDevCreditBudget => new ContextDevCreditBudget(
            cache: $this->app->make('cache.store'),
            dailyCap: (int) config('services.context_dev.daily_credit_cap'),
        ));

        $this->app->bind(ContextDevCreditBalance::class, fn (): ContextDevCreditBalance => new ContextDevCreditBalance(
            cache: $this->app->make('cache.store'),
        ));

        $this->app->singleton(
            GmailServiceContract::class,
            fn (): GmailService => new GmailService(app(CategoryRuleGenerator::class)),
        );

        $this->app->alias(GmailServiceContract::class, GmailService::class);

        $this->app->singleton(ScheduleParser::class, fn (): ScheduleParser => new ScheduleParser([
            new PayPalReceiptStrategy,
            new AfterpayOrderStrategy,
        ]));

        $this->app->bind(ScheduleSource::class, GmailScheduleSource::class);

        $this->app->singleton(GitHubServiceContract::class, fn (): GitHubService => new GitHubService(
            token: (string) config('services.github.token'),
            repo: (string) config('services.github.feedback_repo'),
            releaseId: (string) config('services.github.feedback_release_id'),
        ));

        $this->app->alias(GitHubServiceContract::class, GitHubService::class);

        $this->app->singleton(TransactionAnalysisPipeline::class, fn (): TransactionAnalysisPipeline => new TransactionAnalysisPipeline(
            stages: [
                $this->app->make(TransferDetectionStage::class, ['suggest' => false]),
                $this->app->make(IdentifyPrimaryAccountStage::class),
                $this->app->make(SetPayCycleStage::class),
                $this->app->make(RuleMiningStage::class),
                $this->app->make(UserRulesStage::class),
                $this->app->make(TransferDetectionStage::class, [
                    'stageKey' => 'transfer-detection-after-rules',
                    'stageLabel' => 'Transfer Detection (after rules)',
                ]),
                $this->app->make(IdentifyRecurringTransactionsStage::class),
                $this->app->make(MatchPlannedTransactionsStage::class),
            ],
        ));

        $this->app->scoped(LayoutShellComposer::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        View::composer(
            ['*app.sidebar', '*app.partials.topbar'],
            static fn ($view) => app(LayoutShellComposer::class)->compose($view),
        );
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(static fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
