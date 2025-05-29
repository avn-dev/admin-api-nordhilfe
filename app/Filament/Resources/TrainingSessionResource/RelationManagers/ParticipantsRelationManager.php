<?php

namespace App\Filament\Resources\TrainingSessionResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Models\Participant;
use App\Filament\Resources\ParticipantResource;

class ParticipantsRelationManager extends RelationManager
{
    protected static string $relationship = 'participants';

    public function form(Form $form): Form
    {
        return ParticipantResource::form($form);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('full_name')
            ->columns([
                Tables\Columns\TextColumn::make('full_name')
                    ->label('Name')
                    ->searchable(['first_name', 'last_name'])
                    ->sortable(),
                    
                Tables\Columns\TextColumn::make('birth_date')
                    ->date('d.m.Y')
                    ->label('Geburtsdatum')
                    ->sortable(),
                    
                Tables\Columns\TextColumn::make('email')
                    ->label('E-Mail')
                    ->searchable(),
                    
                Tables\Columns\TextColumn::make('phone')
                    ->label('Telefon')
                    ->searchable(),
                    
                Tables\Columns\ToggleColumn::make('attended')
                    ->label('Teilnahme'),
            ])
            ->filters([
                // Tables\Filters\Filter::make('recent')
                //     ->query(fn (Builder $query): Builder => $query->where('created_at', '>=', now()->subDays(7)))
                //     ->label('Letzte 7 Tage'),
            ])
            ->headerActions([
                Tables\Actions\Action::make('add_participant')
                    ->label('Teilnehmer hinzufügen')
                    ->icon('heroicon-o-users')
                    ->color('info')
                    ->form([
                        Forms\Components\Select::make('existing_participant')
                            ->label('Existierenden auswählen oder neuen erstellen')
                            ->options(function () {
                                $currentParticipantIds = $this->getOwnerRecord()->participants()->pluck('id')->toArray();
                                
                                return Participant::whereNotIn('id', $currentParticipantIds)
                                    ->get()
                                    ->mapWithKeys(fn ($participant) => [
                                        $participant->id => $participant->full_name . ($participant->email? ' geb. am ' . $participant->formatted_birth_date .' ('.$participant->email.')' : '')
                                    ]);
                            })
                            ->searchable()
                            ->placeholder('Teilnehmer suchen oder unten neuen erstellen')
                            ->native(false)
                            ->createOptionForm([
                                Forms\Components\TextInput::make('first_name')
                                    ->required()
                                    ->maxLength(255)
                                    ->label('Vorname'),
                                    
                                Forms\Components\TextInput::make('last_name')
                                    ->required()
                                    ->maxLength(255)
                                    ->label('Nachname'),
                                    
                                Forms\Components\DatePicker::make('birth_date')
                                    ->required()
                                    ->maxDate(now())
                                    ->displayFormat('d.m.Y')
                                    ->label('Geburtsdatum')
                                    ->native(false),
                                    
                                Forms\Components\TextInput::make('email')
                                    ->email()
                                    ->maxLength(255)
                                    ->label('E-Mail'),
                                    
                                Forms\Components\TextInput::make('phone')
                                    ->nullable()
                                    ->maxLength(15)
                                    ->label('Telefon'),
                            ])
                            ->createOptionUsing(function (array $data): int {
                                $participant = Participant::create([
                                    ...$data,
                                    'training_session_id' => $this->getOwnerRecord()->id
                                ]);
                                return $participant->id;
                            }),
                    ])
                    ->action(function (array $data): void {
                        if (isset($data['existing_participant']) && $data['existing_participant']) {
                            $participant = Participant::find($data['existing_participant']);
                            $participant->update(['training_session_id' => $this->getOwnerRecord()->id]);
                        }
                    })
                    ->successNotificationTitle('Teilnehmer wurde hinzugefügt'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                
                Tables\Actions\Action::make('remove_from_session')
                    ->label('Von Schulung entfernen')
                    ->icon('heroicon-o-x-mark')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->action(function (Participant $record): void {
                        // Teilnehmer von der Session entfernen (training_session_id auf null setzen)
                        $record->update(['training_session_id' => null]);
                    })
                    ->successNotificationTitle('Teilnehmer wurde von der Schulung entfernt'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\Action::make('remove_bulk_from_session')
                        ->label('Von Schulung entfernen')
                        ->icon('heroicon-o-x-mark')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->action(function (\Illuminate\Support\Collection $records): void {
                            $records->each(function (Participant $record) {
                                $record->update(['training_session_id' => null]);
                            });
                        })
                        ->successNotificationTitle('Teilnehmer wurden von der Schulung entfernt'),
                ]),
            ])
            ->emptyStateHeading('Keine Teilnehmer')
            ->emptyStateDescription('Fügen Sie den ersten Teilnehmer für diese Schulung hinzu.')
            ->emptyStateIcon('heroicon-o-users');
    }
}