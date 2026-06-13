<?php

namespace Database\Seeders;

use App\Enums\FinancialEntryType;
use App\Models\FinancialCategory;
use Illuminate\Database\Seeder;

class FinancialCategoriesSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => 'Receita Convênio', 'type' => FinancialEntryType::Income],
            ['name' => 'Receita Particular', 'type' => FinancialEntryType::Income],
            ['name' => 'Receita Procedimentos', 'type' => FinancialEntryType::Income],
            ['name' => 'Outras Receitas', 'type' => FinancialEntryType::Income],

            ['name' => 'Folha Salarial', 'type' => FinancialEntryType::Expense],
            ['name' => 'Aluguel', 'type' => FinancialEntryType::Expense],
            ['name' => 'Insumos Clínicos', 'type' => FinancialEntryType::Expense],
            ['name' => 'Tributos', 'type' => FinancialEntryType::Expense],
            ['name' => 'Marketing', 'type' => FinancialEntryType::Expense],
            ['name' => 'Outras Despesas', 'type' => FinancialEntryType::Expense],
        ];

        foreach ($categories as $category) {
            FinancialCategory::query()->updateOrCreate(
                [
                    'entity_id' => null,
                    'name'      => $category['name'],
                    'type'      => $category['type'],
                ],
                [
                    'active'    => true,
                    'is_system' => true,
                ],
            );
        }
    }
}
