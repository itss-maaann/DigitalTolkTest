<?php

namespace DTApi\Providers;

use DTApi\Services\BaseService;
use DTApi\Services\BookingService;
use DTApi\Repository\BaseRepository;
use DTApi\Repository\BookingRepository;
use Illuminate\Support\ServiceProvider;
use DTApi\Contracts\Services\BaseServiceInterface;
use DTApi\Contracts\Services\BookingServiceInterface;
use DTApi\Contracts\Repositories\BaseRepositoryInterface;
use DTApi\Contracts\Repositories\BookingRepositoryInterface;

class AppServiceProvider extends ServiceProvider //ServiceProvider does not exist in this code repo but usually it's always in framework, this is just for an example
{
    public function register()
    {
        $this->app->bind(BaseRepositoryInterface::class, BaseRepository::class);
        $this->app->bind(BookingRepositoryInterface::class, BookingRepository::class);

        $this->app->bind(BaseServiceInterface::class, BaseService::class);
        $this->app->bind(BookingServiceInterface::class, BookingService::class);
    }

    public function boot()
    {
        // Any logic to be executed while registering service providers
    }
}


//In the config/app.php, we have to register this service provider as well but as this is a test repo and we do not have config here so just adding here for an example:

    // 'providers' => [
    //     App\Providers\RepositoryServiceProvider::class,
    // ],
    
