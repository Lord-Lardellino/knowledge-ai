<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\HorizonServiceProvider::class,
    // TelescopeServiceProvider NON qui: Telescope è dipendenza dev, in produzione
    // (--no-dev) la classe non esiste. Registrato condizionatamente in AppServiceProvider.
];
