<?php

namespace App\Filament\Resources\Videos\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class VideoForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->label('Title')
                    ->required()
                    ->maxLength(255),

                TextInput::make('slug')
                    ->label('Slug')
                    ->required()
                    ->unique()
                    ->maxLength(255)
                    ->helperText(
                        'Public video URL slug.'
                    ),

                Textarea::make('description')
                    ->label('Description')
                    ->rows(5)
                    ->columnSpanFull(),

                TextInput::make('embed_url')
                    ->label('Embed URL')
                    ->required()
                    ->url()
                    ->maxLength(2048)
                    ->columnSpanFull()
                    ->helperText(
                        'External embed URL. The video file is not uploaded to Xurvexa.'
                    ),

                TextInput::make('thumbnail')
                    ->label('Thumbnail URL')
                    ->url()
                    ->maxLength(2048)
                    ->columnSpanFull()
                    ->helperText(
                        'External thumbnail image URL.'
                    ),

                TextInput::make('video_source')
                    ->label('Video Source')
                    ->maxLength(255)
                    ->placeholder(
                        'youtube, vimeo, external...'
                    ),

                Select::make('category_id')
                    ->label('Category')
                    ->relationship(
                        name: 'category',
                        titleAttribute: 'name'
                    )
                    ->searchable()
                    ->preload()
                    ->nullable(),

                TextInput::make('duration')
                    ->label('Duration (seconds)')
                    ->numeric()
                    ->minValue(0)
                    ->default(0),

                TextInput::make('views')
                    ->label('Views')
                    ->numeric()
                    ->minValue(0)
                    ->default(0)
                    ->required(),

                Toggle::make('is_hd')
                    ->label('HD')
                    ->default(false),

                Toggle::make('is_4k')
                    ->label('4K')
                    ->default(false),

                Toggle::make('is_featured')
                    ->label('Featured')
                    ->default(false),

                Toggle::make('is_premium')
                    ->label('Premium')
                    ->default(false),

                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true),
            ]);
    }
}
