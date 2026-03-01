<?php

namespace App\Presenters;

use App\Models\User;
use Laracasts\Presenter\Presenter;

class EntityUserPresenter extends Presenter
{
    public function getClientRule(): string
    {
        return $this->rule ? User::$rolesOfClients[$this->rule] : '';
    }

    public function getRule(): string
    {
        return $this->rule ? User::$rolesOfManager[$this->rule] : '';
    }
}
