<?php

namespace App\Filament\App\Resources;

use App\Enums\MenuGroupsEnum;
use App\Enums\MenuSortEnum;
use App\Filament\App\Resources\ClientResource\Pages\ViewClient;
use App\Filament\App\Resources\TaskResource\Pages\ViewTask;
use App\Filament\App\Resources\WorkSessionResource\Pages;
use App\Filament\App\Resources\WorkSessionResource\Pages\CreateWorkSession;
use App\Helpers\PejotaHelper;
use App\Models\WorkSession;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists\Components\Actions;
use Filament\Infolists\Components\Actions\Action;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\Split;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Support\Colors\Color;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use App\Filament\App\Resources\ProjectResource\Pages\ViewProject;

class WorkSessionResource extends Resource
{
    protected static ?string $model = WorkSession::class;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?int $navigationSort = MenuSortEnum::WORK_SESSIONS->value;

    public static function getModelLabel(): string
    {
        return __('Work session');
    }

    /**
     * @return string|null
     */
    public static function getPluralModelLabel(): string
    {
        return __('Work sessions');
    }

    public static function getNavigationGroup(): ?string
    {
        return __(MenuGroupsEnum::DAILY_WORK->value);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->columns(1)
            ->schema(
                self::getFormSchema()
            );
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('start', 'desc')
            ->striped()
            ->columns([
                Tables\Columns\ToggleColumn::make('is_running')
                    ->translateLabel()
                    ->sortable()
                    ->updateStateUsing(function (bool $state, WorkSession $record) {
                        if ($state) {
                            return false;
                        }

                        return self::infolistFinish($record);
                    }),
                Tables\Columns\TextColumn::make('start')
                    ->label('Started at')
                    ->translateLabel()
                    ->dateTime(PejotaHelper::getUserDateTimeFormat())
                    ->timezone(PejotaHelper::getUserTimeZone())
                    ->sortable(),
                Tables\Columns\TextColumn::make('end')
                    ->label('End at')
                    ->translateLabel()
                    ->dateTime(PejotaHelper::getUserDateTimeFormat())
                    ->timezone(PejotaHelper::getUserTimeZone())
                    ->sortable()
                    ->hidden(fn($livewire) => isset($livewire->activeTab) ? $livewire->activeTab === 'running' : true)
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('duration')
                    ->label('Time')
                    ->tooltip(
                        fn($record) => $record?->end?->tz(PejotaHelper::getUserTimeZone())->format(
                            PejotaHelper::getUserDateTimeFormat()
                        )
                    )
                    ->translateLabel()
                    ->formatStateUsing(fn($state) => PejotaHelper::formatDuration($state))
                    ->hidden(fn($livewire) => isset($livewire->activeTab) ? $livewire->activeTab === 'running' : true)
                    ->toggleable()
                    ->summarize(
                        Tables\Columns\Summarizers\Sum::make()
                            ->formatStateUsing(fn($state) => PejotaHelper::formatDuration($state))
                            ->label('Total time')
                    ),
                Tables\Columns\TextColumn::make('title')
                    ->translateLabel()
                    ->searchable(),
                Tables\Columns\TextColumn::make('value')
                    ->translateLabel()
                    ->numeric()
                    ->hidden(fn($livewire) => isset($livewire->activeTab) ? $livewire->activeTab === 'running' : true)
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('currency')
                    ->translateLabel()
                    ->hidden(fn($livewire) => isset($livewire->activeTab) ? $livewire->activeTab === 'running' : true)
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('task.title')
                    ->translateLabel()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('project.name')
                    ->translateLabel()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('client.labelName')
                    ->translateLabel()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->translateLabel()
                    ->dateTime(PejotaHelper::getUserDateTimeFormat())
                    ->timezone(PejotaHelper::getUserTimeZone())
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->translateLabel()
                    ->dateTime(PejotaHelper::getUserDateTimeFormat())
                    ->timezone(PejotaHelper::getUserTimeZone())
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->groups([
                Tables\Grouping\Group::make('client.name')
                    ->label(__('Client'))
                    ->collapsible(),
                Tables\Grouping\Group::make('project.name')
                    ->label(__('Project'))
                    ->collapsible(),
                Tables\Grouping\Group::make('start')
                    ->label(__('Date'))
                    ->date()
                    ->collapsible(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('client')
                    ->translateLabel()
                    ->relationship('client', 'name'),
                Tables\Filters\SelectFilter::make('project')
                    ->translateLabel()
                    ->relationship('project', 'name'),
                Tables\Filters\Filter::make('start')
                    ->form([
                        Forms\Components\DateTimePicker::make('from')
                            ->translateLabel(),
                        Forms\Components\DateTimePicker::make('to')
                            ->translateLabel(),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'],
                                fn(Builder $query, $date): Builder => $query->where('start', '>=', $data['from'])
                            )
                            ->when(
                                $data['to'],
                                fn(Builder $query, $date): Builder => $query->where('start', '<=', $data['to'])
                            );
                    })
                    ->indicateUsing(function (array $data): ?string {
                        if ($data['from'] || $data['to']) {
                            return __('Start') . ': ' . $data['from'] . ' - ' . $data['to'];
                        }

                        return null;
                    }),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\ActionGroup::make([
                        Tables\Actions\Action::make(__('Clone'))
                            ->tooltip(__('Clone this session with same time and details, updating to current date'))
                            ->icon('heroicon-o-document-duplicate')
                            ->color(Color::Amber)
                            ->action(fn(WorkSession $record) => self::clone($record)),
                    ]),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\BulkAction::make(__('Clone selected'))
                        ->tooltip(__('Clone this session with same time and details, updating to current date'))
                        ->icon('heroicon-o-document-duplicate')
                        ->color(Color::Amber)
                        ->action(fn(Collection $records) => self::cloneCollection($records))
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion(),
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
            'index' => Pages\ListWorkSessions::route('/'),
            'create' => Pages\CreateWorkSession::route('/create'),
            'view' => Pages\ViewWorkSession::route('/{record}'),
            'edit' => Pages\EditWorkSession::route('/{record}/edit'),
        ];
    }

    public static function getFormSchema(): array
    {
        return [
            Forms\Components\TextInput::make('title')
                ->placeholder(__('Title'))
                ->hiddenLabel()
                ->required()
                ->translateLabel(),

            Forms\Components\Grid::make([
                'default' => 2,
                'sm' => 2,
                'md' => 6,
            ])->schema([
                Forms\Components\DateTimePicker::make('start')
                    ->label('Start at')
                    ->translateLabel()
                    ->timezone(PejotaHelper::getUserTimeZone())
                    ->seconds(false)
                    ->required()
                    ->default(fn(): string => now()->toDateTimeString())
                    ->live()
                    ->afterStateUpdated(
                        fn(
                            Forms\Get $get,
                            Forms\Set $set
                        ): mixed => self::formSetTimers(
                            fromDuration: true,
                            get: $get,
                            set: $set
                        )
                    ),

                Forms\Components\DateTimePicker::make('end')
                    ->label('End at')
                    ->translateLabel()
                    ->timezone(PejotaHelper::getUserTimeZone())
                    ->seconds(false)
                    ->required(fn(Forms\Get $get): bool => !$get('is_running'))
                    ->live()
                    ->afterStateUpdated(
                        fn(
                            Forms\Get $get,
                            Forms\Set $set
                        ): mixed => self::formSetTimers(
                            fromDuration: false,
                            get: $get,
                            set: $set
                        )
                    ),

                Forms\Components\TextInput::make('duration')
                    ->translateLabel()
                    ->required(fn(Forms\Get $get): bool => !$get('is_running'))
                    ->numeric()
                    ->integer()
                    ->default(0)
//                    ->helperText(__('Duration in minutes. If you enter manually end time, it will be calculated.'))
                    ->prefixIcon('heroicon-o-play')
                    ->live()
                    ->afterStateUpdated(
                        fn(
                            Forms\Get $get,
                            Forms\Set $set
                        ): mixed => self::formSetTimers(
                            fromDuration: true,
                            get: $get,
                            set: $set
                        )
                    ),

                Forms\Components\TextInput::make('rate')
                    ->translateLabel()
                    ->required()
                    ->numeric()
                    ->default(0),

                Forms\Components\Toggle::make('is_running')
                    ->label(fn(bool $state) => $state ? 'Running' : 'Finished')
                    ->onIcon('heroicon-o-stop')
                    ->offIcon('heroicon-o-play')
                    ->offColor('danger')
                    ->translateLabel()
                    ->inline(false)
                    ->default(true)
                    ->live()
                    ->afterStateUpdated(function (bool $state, Forms\Get $get, Forms\Set $set) {
                        if ($state) {
                            $set('end', null);
                            $set('duration', 0);
                        } else {
                            $set('end', now()->timezone(PejotaHelper::getUserTimeZone())->format('Y-m-d H:i'));
                            self::formSetTimers(false, $get, $set);
                        }
                    }),

                Forms\Components\TextInput::make('time')
                    ->translateLabel()
                    ->label('Session time')
                    ->disabled(),
            ]),

            Forms\Components\Grid::make(3)->schema([
                Forms\Components\Select::make('client')
                    ->translateLabel()
                    ->relationship('client', 'name')
                    ->searchable()
                    ->preload()
                    ->createOptionForm(ClientResource::getSchema()),
                Forms\Components\Select::make('project')
                    ->label('Project')
                    ->translateLabel()
                    ->relationship(
                        'project',
                        'name',
                        fn(Builder $query, Forms\Get $get) => $query->byClient($get('client'))->orderBy('name')
                    )
                    ->searchable()
                    ->preload()
                    ->createOptionForm(ProjectResource::getFormComponents()),
                Forms\Components\Select::make('task')
                    ->translateLabel()
                    ->relationship('task', 'title')
                    ->searchable(),

            ]),


            Forms\Components\Section::make(__('Description'))->schema([

                Forms\Components\RichEditor::make('description')
                    ->hiddenLabel()
                    ->fileAttachmentsDisk('work_sessions')
                    ->fileAttachmentsDirectory(auth()->user()->company->id)
                    ->fileAttachmentsVisibility('private'),
            ])->collapsible()->collapsed()->translateLabel(),
        ];
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Split::make([
                    Section::make([
                        Grid::make([
                            'default' => 1,
                            'md' => 2,
                        ])->schema([
                            TextEntry::make('project.name')
                                ->hiddenLabel()
                                ->icon(ProjectResource::getNavigationIcon())
                                ->hidden(fn($state) => !$state)
                                ->url(fn($record) => ViewProject::getUrl([$record->project_id])),

                            TextEntry::make('client.name')
                                ->hiddenLabel()
                                ->icon(ClientResource::getNavigationIcon())
                                ->hidden(fn($state) => !$state)
                                ->url(fn($record) => ViewClient::getUrl([$record->client_id])),

                            TextEntry::make('task.title')
                                ->hiddenLabel()
                                ->icon(TaskResource::getNavigationIcon())
                                ->hidden(fn($state) => !$state)
                                ->url(fn($record) => ViewTask::getUrl([$record->task_id])),

                        ]),

                        Grid::make([
                            'default' => 2,
                            'md' => 5,
                        ])->schema([
                            TextEntry::make('start')
                                ->translateLabel()
                                ->formatStateUsing(
                                    fn(string $state): string => Carbon::parse($state)
                                        ->tz(PejotaHelper::getUserTimeZone())
                                        ->format(PejotaHelper::getUserDateTimeFormat())
                                ),

                            TextEntry::make('end')
                                ->translateLabel()
                                ->formatStateUsing(
                                    fn(string $state): string => Carbon::parse($state)
                                        ->tz(PejotaHelper::getUserTimeZone())
                                        ->format(PejotaHelper::getUserDateTimeFormat())
                                ),

                            TextEntry::make('duration')
                                ->translateLabel(),
                            TextEntry::make('rate')
                                ->translateLabel(),
                            TextEntry::make('time')
                                ->translateLabel()
                                ->getStateUsing(
                                    fn(Model $record): string => PejotaHelper::formatDuration($record->duration)
                                ),
                        ]),

                        TextEntry::make('description')
                            ->translateLabel()
                            ->formatStateUsing(fn(string $state): HtmlString => new HtmlString($state))
                            ->icon('heroicon-o-document-text')
                            ->hidden(fn($state) => !$state),

                    ]),

                    Section::make([
                        Grid::make(2)->schema([
                            IconEntry::make('is_running')
                                ->translateLabel()
                                ->boolean()
                                ->tooltip('If the work session is running'),


                        ]),

                        Actions::make([
                            Actions\Action::make('list')
                                ->translateLabel()
                                ->url(
                                    fn(Model $record) => './.'
                                )
                                ->icon('heroicon-o-chevron-left')
                                ->color(Color::Neutral),

                            Actions\Action::make('edit')
                                ->translateLabel()
                                ->url(
                                    fn(Model $record) => "{$record->id}/edit"
                                )
                                ->icon('heroicon-o-pencil'),

                            Action::make('finish')
                                ->translateLabel()
                                ->icon(WorkSessionResource::getNavigationIcon())
                                ->color(Color::Red)
                                ->hidden(fn($record) => !$record->is_running)
                                ->action(function ($record) {
                                    self::infolistFinish($record);
                                }),
                        ]),
                    ])
                        ->grow(false), // Section at right
                ])
                    ->from('md')
                    ->columnSpanFull()
            ]);
    }

    public static function cloneCollection(Collection $records)
    {
        $records->each(fn($record) => self::clone($record));
    }

    public static function clone(WorkSession $record)
    {
        $newModel = $record->replicate();
        $newModel->start = Carbon::now()
            ->timezone(PejotaHelper::getUserTimeZone())
            ->setTime(
                $record->start->hour,
                $record->start->minute,
                $record->start->second
            );
        $newModel->end = Carbon::now()
            ->timezone(PejotaHelper::getUserTimeZone())
            ->setTime(
                $record->end->hour,
                $record->end->minute,
                $record->end->second
            );
        $newModel->save();

        return redirect(Pages\ViewWorkSession::getUrl([$newModel->id]));
    }

    public static function formSetTimers(bool $fromDuration, Forms\Get $get, Forms\Set $set)
    {
        $start = $get('start');
        $end = $get('end');
        $duration = (int)$get('duration');

        if (!$end && !$duration) {
            return;
        }

        $start = CarbonImmutable::parse($start);

        $end = $fromDuration ? $start->addMinutes($duration) : Carbon::parse($end);

        $set('end', $end->toDateTimeString());

        $duration = (int)$start->diffInMinutes($end);

        $set('duration', $duration);

        $set('time', PejotaHelper::formatDuration((int)$get('duration')));

        $set('is_running', $duration == 0);
    }

    public static function infolistFinish(WorkSession $record): bool
    {
        return $record->finish();
    }

}
