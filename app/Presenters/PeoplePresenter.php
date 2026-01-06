<?php

namespace App\Presenters;

use App\Models\People;
use Laracasts\Presenter\Presenter;

class PeoplePresenter extends Presenter
{
    public function getNationalRegistry(): string
    {
        return preg_replace(
            '/(\d{3})(\d{3})(\d{3})(\d{2})/',
            '$1.$2.$3-$4',
            $this->national_registry
        );
    }

    public function getBirthDate(): string
    {
        return $this->birth_date ? $this->birth_date->format('d/m/Y') : '';
    }

    public function getGender(): string
    {
        return $this->gender !== null ? People::$genders[$this->gender] : '';
    }

    public function getMaritalStatus(): string
    {
        return $this->marital_status ? People::$maritalStatuses[$this->marital_status] : '';
    }

    public function getStateRegistryDate(): string
    {
        return $this->state_registry_date ? $this->state_registry_date->format('d/m/Y') : '';
    }

    public function getTelephone(): string
    {
        return $this->telephone ?
            preg_replace('/(\d{2})(\d{4})(\d{4})/', '($1) $2-$3', $this->telephone) : '';
    }

    public function getCellphone(): string
    {
        return $this->cellphone ?
            preg_replace('/(\d{2})(\d{5})(\d{4})/', '($1) $2-$3', $this->cellphone) : '';
    }

    public function getZipcode(): string
    {
        return $this->zipcode ?
            preg_replace('/(\d{5})(\d{3})/', '$1-$2', $this->zipcode) : '';
    }
}
