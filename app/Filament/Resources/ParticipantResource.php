<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ParticipantResource\Pages;
use App\Filament\Resources\ParticipantResource\RelationManagers;
use App\Models\Participant;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use App\Models\TrainingSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ParticipantResource extends Resource
{
    protected static ?string $model = Participant::class;

    protected static ?string $navigationIcon = 'hugeicons-students';

    protected static ?string $slug = 'teilnehmer';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('first_name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('last_name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Section::make('Adresse')
                    ->schema([
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('address')
                                    ->label('Straße')
                                    ->required()
                                    ->maxLength(255),

                                Forms\Components\TextInput::make('house_number')
                                    ->label('Hausnummer (& Zusatz)')
                                    ->required()
                                    ->maxLength(50),
                            ]),

                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('post_code')
                                    ->label('PLZ')
                                    ->required()
                                    ->maxLength(5),

                                Forms\Components\TextInput::make('city')
                                    ->label('Stadt')
                                    ->required()
                                    ->maxLength(255),
                            ]),
                    ])
                    ->columns(1),
                Forms\Components\DatePicker::make('birth_date')
                    ->required()
                    ->maxDate(now())
                    ->displayFormat('d.m.Y')
                    ->native(false),
                Forms\Components\Select::make('training_session_id')
                    ->relationship('trainingSession', 'id')
                    ->getOptionLabelFromRecordUsing(fn($record) => $record->description)
                    ->required()
                    ->label('Training Session')
                    ->native(false)
                    ->hiddenOn(TrainingSessionResource\RelationManagers\ParticipantsRelationManager::class),
                Forms\Components\Toggle::make('vision_test')
                    ->label('Sehtest gebucht'),
                Forms\Components\Toggle::make('passport_photos')
                    ->label('Passfotos gebucht'),
                Forms\Components\TextInput::make('email')
                    ->email()
                    ->maxLength(255),
                Forms\Components\TextInput::make('phone')
                    ->nullable()
                    ->maxLength(15),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('first_name')
                    ->label('Vorname')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('last_name')
                    ->label('Nachname')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('full_address')
                    ->label('Anschrift')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('birth_date')
                    ->label('Geburtsdatum')
                    ->date('d.m.Y')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\IconColumn::make('vision_test')
                    ->label('Sehtest')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('info')
                    ->falseColor('warning'),
                Tables\Columns\IconColumn::make('passport_photos')
                    ->label('Passfotos')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('info')
                    ->falseColor('warning'),
                Tables\Columns\TextColumn::make('trainingSession.short_description')
                    ->label('Schulung')
                    ->searchable()
                    ->sortable()
                    ->url(
                        fn($record) =>
                        $record->trainingSession && $record->trainingSession->course
                            ? TrainingSessionResource::getUrl('edit', ['record' => $record->trainingSession->id])
                            : null
                    )
                    ->openUrlInNewTab(),
                Tables\Columns\TextColumn::make('email')
                    ->searchable(),
                Tables\Columns\TextColumn::make('phone')
                    ->label('Telefon')
                    ->searchable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Angemeldet am')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('training_session_id')
                    ->label('Schulung')
                    ->options(
                        \App\Models\TrainingSession::with('course')
                            ->get()
                            ->mapWithKeys(fn($session) => [
                                $session->id => $session->short_description
                            ])
                            ->toArray()
                    )
                    ->searchable()
                    ->native(false),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
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
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListParticipants::route('/'),
            'create' => Pages\CreateParticipant::route('/create'),
            'edit' => Pages\EditParticipant::route('/{record}/edit'),
        ];
    }

    public static function getPluralModelLabel(): string
    {
        return 'Teilnehmer';
    }

    public static function getModelLabel(): string
    {
        return 'Teilnehmer';
    }

    public static function getNavigationLabel(): string
    {
        return 'Teilnehmer';
    }
}
