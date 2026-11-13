<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tax rates
    |--------------------------------------------------------------------------
    |
    | Basis points (1 bp = 0.01%), so rates stay integers and no float maths is
    | needed: 500 = 5%. GST applies to everything; BC PST applies only to
    | carbonated drinks. Read by App\Services\TaxCalculator.
    |
    */

    'gst_bp' => 500,

    'pst_bp' => 700,

];
