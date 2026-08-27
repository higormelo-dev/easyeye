<?php

namespace App\Presenters;

use App\Models\SystemProfile;
use Laracasts\Presenter\Presenter;

class EntityUserPresenter extends Presenter
{
    public function getClientRule(): string
    {
        return SystemProfile::labelFor(SystemProfile::CONTEXT_CLIENT, $this->rule);
    }

    public function getRule(): string
    {
        return SystemProfile::labelFor(SystemProfile::CONTEXT_SAAS, $this->rule);
    }
}
