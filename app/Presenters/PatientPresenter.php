<?php

namespace App\Presenters;

use Laracasts\Presenter\Presenter;

class PatientPresenter extends Presenter
{
    public function getCode(): string
    {
        return $this->code ?? '';
    }
}
