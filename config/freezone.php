<?php

// FreeZone, owner's (asmo) variant: free-account players are never forced to spectate, no
// player-mode lock, no upgrade button. What is left is the web-service side. Everything is
// off by default: the Nadeo web service (ws.trackmania.com) is probably gone.
return [
    'enabled'  => (bool) env('FREEZONE_ENABLED', false),   // master switch

    // Web service (HTTP Basic, JSON). user empty = the server login.
    'ws_url'      => env('FREEZONE_WS_URL', 'http://ws.trackmania.com'),
    'ws_user'     => env('FREEZONE_WS_USER', ''),
    'ws_password' => env('FREEZONE_WS_PASSWORD', ''),
    'testmode'    => (bool) env('FREEZONE_TESTMODE', false),  // no web calls at all

    // Features (each needs 'enabled'; the web ones also a password and no testmode)
    'ban_sync'     => (bool) env('FREEZONE_BAN_SYNC', false),      // kick players the web service bans
    'advert'       => (bool) env('FREEZONE_ADVERT', false),        // free-account advert in chat
    'slang_report' => (bool) env('FREEZONE_SLANG_REPORT', false),  // silent slang telemetry
    'stats'        => (bool) env('FREEZONE_STATS', false),         // hourly stats POST

    'advert_minutes'   => 13,
    'slang_threshold'  => 10,     // hits within the window that put a player on the report
    'slang_window'     => 3600,   // seconds
    'refresh_hours'    => 6,      // slang list reload + local banned list reset
    'stats_minutes'    => 60,     // stats POST, slang report, stale record cleanup
    // UNKNOWN in the spec: not derivable, placeholder
    'notice_minutes'   => 10,     // at most one "Bad API Password" notice per this many minutes
    'class_hash'       => '6f116833b419fe7cb9c912fdaefb774845f60e79',
    'manialive_revision' => 239,  // version check id of the ManiaLive the plugin impersonates
];
