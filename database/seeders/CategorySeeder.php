<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

final class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $divisions = [
            [
                'name' => 'Retail Trade',
                'anzsic_division' => 'G',
                'icon' => 'shopping-cart',
                'color' => '#4F46E5',
                'children' => ['Groceries', 'Supermarkets', 'Clothing', 'Electronics', 'Home & Garden', 'Specialty Retail'],
            ],
            [
                'name' => 'Accommodation & Food Services',
                'anzsic_division' => 'H',
                'icon' => 'utensils',
                'color' => '#DC2626',
                'children' => ['Restaurants', 'Takeaway', 'Coffee & Cafes', 'Bars & Pubs', 'Accommodation'],
            ],
            [
                'name' => 'Financial & Insurance Services',
                'anzsic_division' => 'K',
                'icon' => 'landmark',
                'color' => '#059669',
                'children' => ['Bank Fees', 'Insurance Premiums', 'Loan Repayments', 'Investment Fees', 'Interest Charges'],
            ],
            [
                'name' => 'Transport, Postal & Warehousing',
                'anzsic_division' => 'I',
                'icon' => 'car',
                'color' => '#D97706',
                'children' => ['Fuel', 'Public Transport', 'Parking', 'Rideshare', 'Tolls', 'Vehicle Maintenance'],
            ],
            [
                'name' => 'Health Care & Social Assistance',
                'anzsic_division' => 'Q',
                'icon' => 'heart-pulse',
                'color' => '#DB2777',
                'children' => ['Doctor', 'Dental', 'Pharmacy', 'Optical', 'Allied Health', 'Hospital'],
            ],
            [
                'name' => 'Education & Training',
                'anzsic_division' => 'P',
                'icon' => 'graduation-cap',
                'color' => '#7C3AED',
                'children' => ['Tuition', 'Books & Supplies', 'Courses', 'Childcare'],
            ],
            [
                'name' => 'Arts & Recreation Services',
                'anzsic_division' => 'R',
                'icon' => 'ticket',
                'color' => '#0891B2',
                'children' => ['Streaming', 'Cinema', 'Events & Concerts', 'Fitness & Gym', 'Sports'],
            ],
            [
                'name' => 'Electricity, Gas, Water & Waste',
                'anzsic_division' => 'D',
                'icon' => 'zap',
                'color' => '#CA8A04',
                'children' => ['Electricity', 'Gas', 'Water', 'Internet', 'Phone'],
            ],
            [
                'name' => 'Rental, Hiring & Real Estate',
                'anzsic_division' => 'L',
                'icon' => 'home',
                'color' => '#2563EB',
                'children' => ['Rent', 'Mortgage', 'Property Insurance', 'Strata & Body Corp'],
            ],
            [
                'name' => 'Personal & Other Services',
                'anzsic_division' => 'S',
                'icon' => 'user',
                'color' => '#6366F1',
                'children' => ['Beauty & Hair', 'Subscriptions', 'Pet Care', 'Laundry & Dry Cleaning'],
            ],
            [
                'name' => 'Income',
                'anzsic_division' => null,
                'icon' => 'wallet',
                'color' => '#16A34A',
                'children' => ['Salary', 'Freelance', 'Interest', 'Government Benefits', 'Refunds'],
            ],
            [
                'name' => 'Transfers',
                'anzsic_division' => null,
                'icon' => 'arrow-left-right',
                'color' => '#64748B',
                'children' => [],
            ],
        ];

        foreach ($divisions as $division) {
            $parent = Category::create([
                'name' => $division['name'],
                'anzsic_division' => $division['anzsic_division'],
                'icon' => $division['icon'],
                'color' => $division['color'],
            ]);

            foreach ($division['children'] as $childName) {
                Category::create([
                    'name' => $childName,
                    'parent_id' => $parent->id,
                    'anzsic_division' => $division['anzsic_division'],
                ]);
            }
        }
    }
}
