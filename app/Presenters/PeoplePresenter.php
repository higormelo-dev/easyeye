<?php

namespace App\Presenters;

use App\Support\BrazilianFormat;
use Laracasts\Presenter\Presenter;

class PeoplePresenter extends Presenter
{
    public function getNationalRegistry(): string
    {
        return BrazilianFormat::cpf($this->national_registry) ?? '';
    }

    public function getBirthDate(): string
    {
        return $this->birth_date ? $this->birth_date->format('d/m/Y') : '';
    }

    public function getAge(): string
    {
        if (! $this->birth_date) {
            return __('actions.age.not_informed');
        }

        $age = $this->birth_date->diff(now());

        if ($age->y > 1) {
            $months = $age->m > 0
                ? ', ' . trans_choice('actions.age.month', $age->m)
                : '';

            return trans_choice('actions.age.year', $age->y) . $months;
        }

        if ($age->m > 0) {
            return trans_choice('actions.age.month', $age->m);
        }

        return trans_choice('actions.age.day', max($age->d, 1));
    }

    public function getGender(): string
    {
        return match ($this->gender) {
            0       => __('actions.female'),
            1       => __('actions.male'),
            default => '',
        };
    }

    public function getMaritalStatus(): string
    {
        return $this->marital_status
            ? __('actions.marital_status.' . $this->marital_status)
            : '';
    }

    public function getStateRegistryDate(): string
    {
        return $this->state_registry_date ? $this->state_registry_date->format('d/m/Y') : '';
    }

    // Telefones/CPF/CEP delegam para BrazilianFormat: tamanho dinâmico (fixo x
    // celular, DDI legado) e valor fora do padrão volta intacto.
    public function getTelephone(): string
    {
        return BrazilianFormat::phone($this->telephone) ?? '';
    }

    public function getCellphone(): string
    {
        return BrazilianFormat::phone($this->cellphone) ?? '';
    }

    public function getZipcode(): string
    {
        return BrazilianFormat::cep($this->zipcode) ?? '';
    }
}
