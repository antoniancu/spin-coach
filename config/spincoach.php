<?php

declare(strict_types=1);

return [

    'spotify_playlists' => [
        'easy' => env('SPOTIFY_PLAYLIST_EASY', ''),
        'medium' => env('SPOTIFY_PLAYLIST_MEDIUM', ''),
        'hard' => env('SPOTIFY_PLAYLIST_HARD', ''),
    ],

    'volume_ducking' => [
        'warmup' => 70,
        'work' => 90,
        'rest' => 55,
        'cooldown' => 65,
    ],

];
