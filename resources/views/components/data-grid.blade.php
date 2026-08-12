{{--
    <x-data-grid grid="controls" />

    Thin Blade wrapper so index views mount the shared Livewire grid without
    knowing Livewire is underneath. The grid name must be registered in
    App\Grids\GridRegistry.
--}}
@props(['grid'])

@livewire('data-grid', ['grid' => $grid], key('data-grid-'.$grid))
