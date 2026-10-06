<?php

return [
    /*
    | Kill switch for the automatic account (tenant) scope applied by the
    | BelongsToAccount trait. Keep it true in every environment; set
    | TENANCY_ENFORCE=false only to diagnose a suspected scoping regression.
    */
    'enforce' => env('TENANCY_ENFORCE', true),
];
