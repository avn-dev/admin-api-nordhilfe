<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TrainingSessionResource\Pages;
use App\Filament\Resources\TrainingSessionResource\RelationManagers;
use App\Models\TrainingSession;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Carbon;

class TrainingSessionResource extends Resource
{
    protected static ?string $model = TrainingSession::class;

    protected static ?string $navigationIcon = 'tabler-certificate';

    protected static ?string $slug = 'schulungen';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('course_id')
                    ->relationship('course', 'name')
                    ->required()
                    ->label('Course')
                    ->native(false),
                
                Forms\Components\Select::make('location_id')
                    ->label('Location')
                    ->options(
                        \App\Models\Location::all()
                            ->mapWithKeys(fn ($location) => [
                                $location->id => $location->full_address_with_name
                            ])
                    )
                    ->required()
                    ->native(false),

                Forms\Components\DatePicker::make('session_date')
                    ->required()
                    ->maxDate(now()->addYear())
                    ->displayFormat('d. mm Y')
                    ->native(false),
                Forms\Components\TimePicker::make('start_time')
                    ->required()
                    ->displayFormat('H:i')
                    ->native(false)
                    ->seconds(false),
                Forms\Components\TimePicker::make('end_time')
                    ->required()
                    ->displayFormat('H:i')
                    ->native(false)
                    ->seconds(false),
                
                Forms\Components\TextInput::make('max_participants')
                    ->placeholder('Leer lassen, um keine Begrenzung zu setzen')
                    ->numeric(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->query(
                static::getModel()::query()->withCount('participants')
            )
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->sortable(),
                Tables\Columns\TextColumn::make('course.name')
                    ->label('Kurs')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('location.full_address_with_name')
                    ->label('Ort')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('session_date')
                    ->label('Datum')
                    ->date()
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('start_time')
                    ->label('Beginn')
                    ->time('H:i')
                    ->searchable(),
                Tables\Columns\TextColumn::make('end_time')
                    ->label('Ende')
                    ->time('H:i')
                    ->searchable(),

                Tables\Columns\TextColumn::make('participants_display')
                    ->label('Teilnehmer')
                    ->getStateUsing(function ($record) {
                        $count = $record->participants_count;
                        $max = $record->max_participants;
                        $percentage = $max ? number_format((100 / $max * $count), 1) : null;
                        if ($max && $count > $max) {
                            return "{$count} / {$max} ({$percentage}%) - Überbelegt!";
                        }

                        return $max ? "{$count} / {$max} ({$percentage}%)" : "{$count}";
                    }),
            ])
            ->filters([
                Tables\Filters\Filter::make('past')
                    ->query(fn (Builder $query): Builder => $query->whereDate('session_date', '<', Carbon::today()))
                    ->label('Vergangen'),
                Tables\Filters\Filter::make('upcoming')
                    ->query(fn (Builder $query): Builder => $query->whereDate('session_date', '>=', Carbon::today()))
                    ->label('Bevorstehend'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ParticipantsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTrainingSessions::route('/'),
            'create' => Pages\CreateTrainingSession::route('/create'),
            'edit' => Pages\EditTrainingSession::route('/{record}/edit'),
        ];
    }

    public static function getPluralModelLabel(): string
    {
        return 'Schulungen';
    }
    public static function getModelLabel(): string
    {
        return 'Schulung';
    }
    public static function getNavigationLabel(): string
    {
        return 'Schulungen';
    }
}
