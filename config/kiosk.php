<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Kiosk API
    |--------------------------------------------------------------------------
    |
    | Shared token used to authenticate requests from native kiosk devices
    | against the /api/kiosk/* endpoints. Sent by the client in the
    | X-Kiosk-Token header.
    |
    */

    'token' => env('KIOSK_API_TOKEN', ''),

];
