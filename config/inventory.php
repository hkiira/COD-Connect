<?php

return [
    /*
    | Refuse exit slips and transfers that take more than the stock available in the
    | source warehouse. Leave false until existing negative stock has been reconciled
    | (Catalog > Stock > Reconciliation), then set INVENTORY_BLOCK_NEGATIVE_STOCK=true.
    */
    'block_negative_stock' => env('INVENTORY_BLOCK_NEGATIVE_STOCK', false),

    /* Default low-stock threshold when a product has none (products.low_stock_threshold). */
    'default_low_stock_threshold' => (int) env('INVENTORY_DEFAULT_LOW_STOCK', 10),

    /* Sales window (days) used to estimate daily demand and days of cover. */
    'velocity_window_days' => (int) env('INVENTORY_VELOCITY_DAYS', 30),
];
