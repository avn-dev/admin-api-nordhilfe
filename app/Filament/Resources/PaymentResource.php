<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PaymentResource\Pages;
use App\Filament\Resources\PaymentResource\RelationManagers;
use App\Models\Payment;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Models\Participant;

class PaymentResource extends Resource
{
  protected static ?string $model = Payment::class;

  protected static ?string $navigationIcon = 'heroicon-o-credit-card';
  protected static ?string $slug = 'zahlungen';

  public static function form(Form $form): Form
  {
    return $form
      ->schema([
        Forms\Components\Select::make('participant_id')
          ->label('Teilnehmer')
          ->required()
          ->options(
            Participant::all()
              ->mapWithKeys(fn($participant) => [
                $participant->id => $participant->full_name
              ])
          )
          ->required()
          ->native(false)
          ->searchable(),
        Forms\Components\Select::make('method')
          ->label('Zahlungsmethode')
          ->selectablePlaceholder(false)
          ->options([
            'paypal' => 'PayPal',
            //'bank_transfer' => 'Banküberweisung',
            'cash' => 'Barzahlung',
          ])
          ->required()
          ->native(false),
        Forms\Components\Select::make('status')
          ->label('Status')
          ->options([
            'unpaid' => 'Unbezahlt',
            'paid' => 'Bezahlt',
            'refunded' => 'Erstattet',
          ])
          // "X" entfernen
          ->selectablePlaceholder(false)
          ->default('unpaid')
          ->required()
          ->native(false),
        Forms\Components\TextInput::make('amount')
          ->label('Betrag')
          ->numeric()
          ->required()
          ->minValue(0)
          ->maxValue(1000000)
          ->default(0),
        Forms\Components\TextInput::make('currency')
          ->label('Währung')
          ->default('EUR')
          ->required(),
        Forms\Components\TextInput::make('external_id')
          ->label('Transaktions ID')
          ->maxLength(255),
      ]);
  }

  public static function table(Table $table): Table
  {
    return $table
      ->columns([
        Tables\Columns\TextColumn::make('participant.full_name')
          ->label('Teilnehmer')
          ->searchable()
          ->sortable(),
        Tables\Columns\TextColumn::make('method')
          ->label('Zahlungsmethode')
          ->searchable()
          ->sortable(),
        Tables\Columns\TextColumn::make('status')
          ->label('Status')
          ->searchable()
          ->sortable(),
        Tables\Columns\TextColumn::make('amount')
          ->label('Betrag')
          ->money(fn($record) => $record->currency, true)
          ->sortable(),
        Tables\Columns\TextColumn::make('currency')
          ->label('Währung')
          ->sortable(),
        Tables\Columns\TextColumn::make('external_id')
          ->label('Transaktions ID')
          ->searchable()
      ])
      ->filters([
        //
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
      //
    ];
  }

  public static function getPages(): array
  {
    return [
      'index' => Pages\ListPayments::route('/'),
      'create' => Pages\CreatePayment::route('/create'),
      'edit' => Pages\EditPayment::route('/{record}/edit'),
    ];
  }

  public static function getPluralModelLabel(): string
  {
    return 'Zahlungen';
  }

  public static function getModelLabel(): string
  {
    return 'Zahlung';
  }

  public static function getNavigationLabel(): string
  {
    return 'Zahlungen';
  }
}
