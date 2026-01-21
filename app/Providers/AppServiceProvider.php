<?php

namespace App\Providers;

use Filament\Forms\Components\Field;
use Filament\Forms\Components\Placeholder;
use Filament\Infolists\Components\Entry;
use Filament\Support\Components\Component;
use Filament\Support\Concerns\Configurable;
use Filament\Tables\Columns\Column;
use Filament\Tables\Filters\BaseFilter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;
use Filament\Forms;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    protected function translatableComponents(): void
    {
        foreach ([Field::class, BaseFilter::class, Placeholder::class, Column::class, Entry::class] as $component) {
            /* @var Configurable $component */
            $component::configureUsing(function (Component $translatable): void {
                /** @phpstan-ignore method.notFound */
                $translatable->translateLabel();
            });
        }
    }

    private function configureCommands(): void
    {
        DB::prohibitDestructiveCommands($this->app->isProduction());
    }

    private function configureModels(): void
    {
        Model::shouldBeStrict(! app()->isProduction());
    }

    private function configureValidationRules(): void
    {
        Validator::extend('file_put_contents', function (string $attribute, mixed $value, array $parameters, $validator): bool {
            Log::warning('Unexpected validation rule encountered.', [
                'rule' => 'file_put_contents',
                'attribute' => $attribute,
            ]);

            return false;
        });
    }

    public function boot(): void
    {
        $this->configureCommands();
        $this->configureModels();
        $this->configureValidationRules();
        $this->translatableComponents();

        Forms\Components\TextInput::configureUsing(function (Forms\Components\TextInput $textInput): void {
            $textInput->dehydrateStateUsing(function (?string $state): ?string {
                return is_string($state) ? trim($state) : $state;
            });
        });
    }
}
