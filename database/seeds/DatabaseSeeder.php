<?php

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        $this->call(RolSeeder::class);
        $this->call(AddressSeeder::class);
        $this->call(BranchOfficesSeeder::class);
        $this->call(UserSeeder::class);
        $this->call(BrandSeeder::class);
        $this->call(CategorySeeder::class);
        $this->call(BoxSeeder::class);
        $this->call(CashClosingSeeder::class);
        $this->call(ShoppingCartSeeder::class);
        $this->call(ProductSeeder::class);
        $this->call(CashClosingSeeder::class);
        $this->call(SaleSeeder::class);
        $this->call(ProductInSaleSeeder::class);
        $this->call(ExpenseSeeder::class);
        $this->call(InitialCashSeeder::class);
    }
}
