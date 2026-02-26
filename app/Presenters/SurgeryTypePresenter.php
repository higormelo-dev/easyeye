<?php

namespace App\Presenters;

use App\Models\SurgeryType;
use Laracasts\Presenter\Presenter;

class SurgeryTypePresenter extends Presenter
{
    public function getCategory(): string
    {
        return SurgeryType::$categories[$this->category];
    }
}
