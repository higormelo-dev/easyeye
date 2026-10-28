<?php

namespace App\Presenters;

use Laracasts\Presenter\Presenter;

class SchedulePresenter extends Presenter
{
    public function getDateTime(): string
    {
        return $this->date_time ? $this->date_time->format('d/m/Y H:i') : '';
    }

    public function getTime(): string
    {
        return $this->date_time ? $this->date_time->format('H:i') : '';
    }
}
