<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\TransactionDirection;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

final class TransactionSeeder extends Seeder
{
    /** @var Collection<string, int> */
    private Collection $categoryMap;

    public function run(): void
    {
        $user = User::where('email', 'test@example.com')->firstOrFail();
        $account = $user->accounts()->firstOrFail();
        $this->categoryMap = Category::pluck('id', 'name');

        foreach ($this->transactions() as $txn) {
            $amountCents = $txn['amount'];
            $direction = $amountCents >= 0 ? TransactionDirection::Credit : TransactionDirection::Debit;

            Transaction::factory()
                ->for($user)
                ->for($account)
                ->state(['direction' => $direction])
                ->create([
                    'description' => $txn['description'],
                    'amount' => abs($amountCents),
                    'post_date' => $txn['post_date'],
                    'transaction_date' => $txn['transaction_date'] ?? null,
                    'category_id' => $this->resolveCategory($txn['description']),
                ]);
        }
    }

    private function resolveCategory(string $description): ?int
    {
        $keywords = [
            'WOOLWORTHS' => 'Groceries',
            'Netflix' => 'Streaming',
            'APPLE.COM/BILL' => 'Subscriptions',
            'TWITCHINTER' => 'Streaming',
            'THANGS 3D' => 'Specialty Retail',
            'LUCENTGLOBE' => 'Specialty Retail',
            'NIB - ' => 'Insurance Premiums',
            'GOLDEN INSURANCE' => 'Insurance Premiums',
            'QBE Insurance' => 'Insurance Premiums',
            'Fair Go Finance' => 'Loan Repayments',
            'MCF - MCF Loa' => 'Loan Repayments',
            'Real Living WV' => 'Rent',
            'Sekisui House' => 'Rent',
            'Direct Debit Spaceship' => 'Investment Fees',
            'WINABLE PAYROLL' => 'Salary',
            'COMPARE BUILD' => 'Salary',
            'WILDE H - YouTube' => 'Freelance',
            'SUSANNA PILOTTI' => 'Refunds',
            'MRS NIKOLAI HELENE TAYLOR' => 'Refunds',
            'Returned Item Credit' => 'Refunds',
            'Round Up transfer' => 'Transfers',
            'Transfer Optimus' => 'Transfers',
            'Transfer Op to' => 'Transfers',
            'Transfer  to SAV' => 'Transfers',
            'Transfer From Spaceship' => 'Transfers',
            'Transfer Savings' => 'Transfers',
            'Osko Payment' => 'Transfers',
            'Ext Tfr' => 'Transfers',
            'SMS Alert Fee' => 'Bank Fees',
            'ATM Withdrawal Fee' => 'Bank Fees',
            'Int Tran Fee' => 'Bank Fees',
            'ATM#' => 'Transfers',
            'Direct Debit GO ' => 'Public Transport',
            'TMR-Product' => 'Vehicle Maintenance',
        ];

        foreach ($keywords as $keyword => $categoryName) {
            if (str_contains($description, $keyword)) {
                return $this->categoryMap->get($categoryName);
            }
        }

        return null;
    }

    /** @return list<array{description: string, amount: int, post_date: string, transaction_date?: string}> */
    private function transactions(): array
    {
        return [
            ['description' => 'VISA -Netflix.com Melbourne AU 724493 #2892', 'amount' => -2899, 'post_date' => '2026-01-01'],
            ['description' => 'Round Up transfer to 03774599: VISA -Netflix.com Melbourne AU 724493 #2892', 'amount' => -101, 'post_date' => '2026-01-01'],
            ['description' => 'Ext Tfr - NET#4491106306 to 554078 Real Living WV WBC - 260 Queen Street', 'amount' => -75000, 'post_date' => '2026-01-02'],
            ['description' => 'Osko Payment From COMPARE BUILD PTY LTD Ref#884905699', 'amount' => 150000, 'post_date' => '2026-01-03'],
            ['description' => 'Osko Payment To 86400 Account 10386227 YOU - UBank Ref#884906433', 'amount' => -20000, 'post_date' => '2026-01-03'],
            ['description' => 'Osko Payment To Nikolai Taylor Account 188722557 ANZ - Indooroo Ref#884906443', 'amount' => -6000, 'post_date' => '2026-01-03'],
            ['description' => 'Transfer Optimus to CC to SAV 03914373 NET#2422732337', 'amount' => -25000, 'post_date' => '2026-01-03'],
            ['description' => 'Direct Debit Fair Go Finance - DT.4y16g4 FGF 2472', 'amount' => -8500, 'post_date' => '2026-01-06'],
            ['description' => 'Direct Debit TMR-Product Payt - 1056574545', 'amount' => -4720, 'post_date' => '2026-01-06'],
            ['description' => 'Direct Debit GO 050126 - 005218933538256636', 'amount' => -4487, 'post_date' => '2026-01-06'],
            ['description' => 'Direct Debit NIB - 64699390', 'amount' => -9059, 'post_date' => '2026-01-08'],
            ['description' => 'Ext Tfr - NET#4491106306 to 554078 Real Living WV WBC - 260 Queen Street', 'amount' => -75000, 'post_date' => '2026-01-09'],
            ['description' => 'Osko Payment To Nikolai Taylor Account 188722557 ANZ - Indooroo Ref#885214478', 'amount' => -6000, 'post_date' => '2026-01-10'],
            ['description' => 'Transfer Optimus to cc to SAV 03914373 MOBILE#2425889951', 'amount' => -5000, 'post_date' => '2026-01-11'],
            ['description' => 'Direct Credit WILDE H - YouTube Family', 'amount' => 1650, 'post_date' => '2026-01-12'],
            ['description' => 'Direct Debit Fair Go Finance - DT.4yx8ph FGF 2472', 'amount' => -8500, 'post_date' => '2026-01-13'],
            ['description' => 'Transfer From Spaceship Ref#885324573', 'amount' => 50000, 'post_date' => '2026-01-13'],
            ['description' => 'Transfer Optimus to CCC to SAV 03914373 NET#2426685354', 'amount' => -10000, 'post_date' => '2026-01-13'],
            ['description' => 'Osko Payment To 86400 Account 10386227 YOU - UBank Ref#885324884', 'amount' => -15000, 'post_date' => '2026-01-13'],
            ['description' => 'Direct Debit MCF - MCF Loa(N11590247)', 'amount' => -17390, 'post_date' => '2026-01-13'],
            ['description' => 'Osko Payment To 86400 Account 10386227 YOU - UBank Ref#885376551', 'amount' => -5000, 'post_date' => '2026-01-14'],
            ['description' => 'Direct Debit GOLDEN INSURANCE - PLCY 082212484-015', 'amount' => -7017, 'post_date' => '2026-01-14'],
            ['description' => 'Returned Item Credit', 'amount' => 7017, 'post_date' => '2026-01-15', 'transaction_date' => '2026-01-14'],
            ['description' => 'Direct Credit WINABLE PAYROLL - WINABLE PAYROLL', 'amount' => 142058, 'post_date' => '2026-01-15'],
            ['description' => 'Transfer Optimus to cc to SAV 03914373 MOBILE#2427688030', 'amount' => -25000, 'post_date' => '2026-01-15'],
            ['description' => 'Osko Payment To 86400 Account 10386227 YOU - UBank Ref#885462593', 'amount' => -25000, 'post_date' => '2026-01-15'],
            ['description' => 'Ext Tfr - NET#4491106306 to 554078 Real Living WV WBC - 260 Queen Street', 'amount' => -75000, 'post_date' => '2026-01-16'],
            ['description' => 'Direct Debit Spaceship - DT.4zhoeo E5MRRQ7T', 'amount' => -10000, 'post_date' => '2026-01-16'],
            ['description' => 'Osko Payment From COMPARE BUILD PTY LTD Ref#885524543', 'amount' => 150000, 'post_date' => '2026-01-16'],
            ['description' => 'POS - #470642 - PAYPAL *APPLE.COM/BILL Sydney AU #2892', 'amount' => -1499, 'post_date' => '2026-01-17'],
            ['description' => 'Transfer Optimus to CC to SAV 03914373 MOBILE#2428514397', 'amount' => -25000, 'post_date' => '2026-01-17'],
            ['description' => 'Round Up transfer to 03774599: POS - #470642 - PAYPAL *APPLE.COM/BILL Sydney AU #2892', 'amount' => -1, 'post_date' => '2026-01-17'],
            ['description' => 'Osko Payment To Nikolai Taylor Account 188722557 ANZ - Indooroo Ref#885583422', 'amount' => -6000, 'post_date' => '2026-01-18'],
            ['description' => 'Direct Debit Fair Go Finance - DT.4zw65d FGF 2472', 'amount' => -8500, 'post_date' => '2026-01-20'],
            ['description' => 'Transfer Optimus to CC to SAV 03914373 MOBILE#2430220585', 'amount' => -20000, 'post_date' => '2026-01-21'],
            ['description' => 'Direct Debit NIB - 64699390', 'amount' => -9059, 'post_date' => '2026-01-22'],
            ['description' => 'Ext Tfr - NET#4491106306 to 554078 Real Living WV WBC - 260 Queen Street', 'amount' => -75000, 'post_date' => '2026-01-23'],
            ['description' => 'Transfer Optimus to CC to SAV 03914373 NET#2431018291', 'amount' => -10000, 'post_date' => '2026-01-23'],
            ['description' => 'Direct Debit Spaceship - DT.50ftll H4EGKTGC', 'amount' => -10000, 'post_date' => '2026-01-23'],
            ['description' => 'Returned Item Credit', 'amount' => 10000, 'post_date' => '2026-01-27', 'transaction_date' => '2026-01-23'],
            ['description' => 'Osko Payment From SUSANNA PILOTTI Ref#885967786', 'amount' => 10940, 'post_date' => '2026-01-26'],
            ['description' => 'Transfer  to SAV 03914373 MOBILE#2432365706', 'amount' => -3500, 'post_date' => '2026-01-26'],
            ['description' => 'Osko Payment From MRS NIKOLAI HELENE TAYLOR Ref#885969744', 'amount' => 11000, 'post_date' => '2026-01-26'],
            ['description' => 'Transfer Op to SAV 03914373 MOBILE#2432374302', 'amount' => -11000, 'post_date' => '2026-01-26'],
            ['description' => 'Direct Debit Fair Go Finance - DT.50k780 FGF 2472', 'amount' => -8500, 'post_date' => '2026-01-27'],
            ['description' => 'Direct Debit MCF - MCF Loa(N11666672)', 'amount' => -17390, 'post_date' => '2026-01-27'],
            ['description' => 'Osko Payment From COMPARE BUILD PTY LTD Ref#886026214', 'amount' => 150000, 'post_date' => '2026-01-27'],
            ['description' => 'Osko Payment To Nikolai Taylor Account 188722557 ANZ - Indooroo Ref#886026349', 'amount' => -17000, 'post_date' => '2026-01-27'],
            ['description' => 'Transfer Optimus to CC to SAV 03914373 NET#2432800579', 'amount' => -50000, 'post_date' => '2026-01-27'],
            ['description' => 'Direct Debit GOLDEN INSURANCE - PLCY 082212484-016', 'amount' => -14034, 'post_date' => '2026-01-28'],
            ['description' => 'Osko Payment To 86400 Account 10386227 YOU - UBank Ref#886061267', 'amount' => -15000, 'post_date' => '2026-01-28'],
            ['description' => 'Direct Debit QBE Insurance - bcx:31104032', 'amount' => -6058, 'post_date' => '2026-01-28'],
            ['description' => 'Osko Payment To Taylor Shingler Account 10549624 CBA - Mount Gr Ref#886111604', 'amount' => -14000, 'post_date' => '2026-01-29'],
            ['description' => 'Direct Credit WINABLE PAYROLL - WINABLE PAYROLL', 'amount' => 570660, 'post_date' => '2026-01-29'],
            ['description' => 'Transfer Optimus to CC to SAV 03914373 NET#2433714499', 'amount' => -200000, 'post_date' => '2026-01-29'],
            ['description' => 'Transfer Optimus to Savings to SAV 03774599 NET#2433714609', 'amount' => -50000, 'post_date' => '2026-01-29'],
            ['description' => 'Ext Tfr - NET#4491106306 to 554078 Real Living WV WBC - 260 Queen Street', 'amount' => -75000, 'post_date' => '2026-01-30'],
            ['description' => 'Direct Debit Spaceship - DT.51ckj6 KESBUNMW', 'amount' => -117398, 'post_date' => '2026-01-30'],
            ['description' => 'ATM#009198-BOUNDARY ST - WEST END BRISBANE AU 2892', 'amount' => -8000, 'post_date' => '2026-01-31'],
            ['description' => 'ATM Withdrawal Fee 009198 BOUNDARY ST - WEST END BRISBANE AU', 'amount' => -275, 'post_date' => '2026-01-31'],
            ['description' => 'Osko Payment To 86400 Account 10386227 YOU - UBank Ref#886227800', 'amount' => -20000, 'post_date' => '2026-01-31'],
            ['description' => 'SMS Alert Fee', 'amount' => -1066, 'post_date' => '2026-01-31'],
            ['description' => 'VISA -Netflix.com Melbourne AU 805504 #2892', 'amount' => -2899, 'post_date' => '2026-02-01'],
            ['description' => 'Round Up transfer to 03774599: VISA -Netflix.com Melbourne AU 805504 #2892', 'amount' => -101, 'post_date' => '2026-02-01'],
            ['description' => 'Direct Debit Spaceship - DT.51mgwm XAUVGTYP', 'amount' => -20000, 'post_date' => '2026-02-02'],
            ['description' => 'Transfer From Spaceship Ref#886342454', 'amount' => 90000, 'post_date' => '2026-02-03'],
            ['description' => 'Direct Debit Fair Go Finance - DT.51qez0 FGF 2472', 'amount' => -8500, 'post_date' => '2026-02-03'],
            ['description' => 'Direct Debit NIB - 64699390', 'amount' => -9059, 'post_date' => '2026-02-05'],
            ['description' => 'Direct Debit TMR-Product Payt - 1057615504', 'amount' => -4720, 'post_date' => '2026-02-05'],
            ['description' => 'Ext Tfr - NET#4491106306 to 554078 Real Living WV WBC - 260 Queen Street', 'amount' => -75000, 'post_date' => '2026-02-06'],
            ['description' => 'Direct Debit GO 050226 - 005218933538256636', 'amount' => -4386, 'post_date' => '2026-02-06'],
            ['description' => 'Direct Debit GO 050226 - SC5218933538256636', 'amount' => -25000, 'post_date' => '2026-02-06'],
            ['description' => 'Osko Payment To Sekisui House MAST Account 554078 WBC - 260 Que Ref#886551345', 'amount' => -11429, 'post_date' => '2026-02-06'],
            ['description' => 'Osko Payment To Hunter Wilde Account 260399396 NAB - Cleveland Ref#886604740', 'amount' => -5000, 'post_date' => '2026-02-07'],
            ['description' => 'VISA -Including Cash OutWOOLWORTHS/111 BOUNDARY SWESTEND AU 485145 #2892', 'amount' => -10379, 'post_date' => '2026-02-08'],
            ['description' => 'Round Up transfer to 03774599: VISA -Including Cash OutWOOLWORTHS/111 BOUNDARY SWESTEND AU 485145 #2892', 'amount' => -121, 'post_date' => '2026-02-08'],
            ['description' => 'Direct Debit Fair Go Finance - DT.52nqi9 FGF 2472', 'amount' => -8500, 'post_date' => '2026-02-10'],
            ['description' => 'Direct Debit MCF - MCF Loa(N11764012)', 'amount' => -17390, 'post_date' => '2026-02-10'],
            ['description' => 'Direct Debit GOLDEN INSURANCE - PLCY 082212484-017', 'amount' => -7017, 'post_date' => '2026-02-11'],
            ['description' => 'Transfer Savings to optimus from SAV 03774599 MOBILE#2439237776', 'amount' => 20000, 'post_date' => '2026-02-11'],
            ['description' => 'Direct Credit WILDE H - YouTube Family', 'amount' => 1650, 'post_date' => '2026-02-12'],
            ['description' => 'Direct Credit WINABLE PAYROLL - WINABLE PAYROLL', 'amount' => 523374, 'post_date' => '2026-02-12'],
            ['description' => 'Transfer Optimus to CC to SAV 03914373 NET#2439697976', 'amount' => -250000, 'post_date' => '2026-02-12'],
            ['description' => 'Osko Payment To 86400 Account 10386227 YOU - UBank Ref#886835363', 'amount' => -20000, 'post_date' => '2026-02-12'],
            ['description' => 'Ext Tfr - NET#4789778169 to 554078 Sekisui House MAST WBC - 260 Queen Street', 'amount' => -77000, 'post_date' => '2026-02-13'],
            ['description' => 'VISA -Including Cash OutWOOLWORTHS/111 BOUNDARY SWESTEND AU 100244 #2892', 'amount' => -9400, 'post_date' => '2026-02-15'],
            ['description' => 'POS - #526090 - PAYPAL *APPLE.COM/BILL Sydney AU #2892', 'amount' => -749, 'post_date' => '2026-02-15'],
            ['description' => 'POS - #537797 - PAYPAL *APPLE.COM/BILL Sydney AU #2892', 'amount' => -1599, 'post_date' => '2026-02-15'],
            ['description' => 'Round Up transfer to 03774599: VISA -Including Cash OutWOOLWORTHS/111 BOUNDARY SWESTEND AU 100244 #2892', 'amount' => -100, 'post_date' => '2026-02-15'],
            ['description' => 'Round Up transfer to 03774599: POS - #526090 - PAYPAL *APPLE.COM/BILL Sydney AU #2892', 'amount' => -251, 'post_date' => '2026-02-15'],
            ['description' => 'Round Up transfer to 03774599: POS - #537797 - PAYPAL *APPLE.COM/BILL Sydney AU #2892', 'amount' => -401, 'post_date' => '2026-02-15'],
            ['description' => 'Direct Debit Spaceship - DT.53i1nh PIO5T4FQ', 'amount' => -20000, 'post_date' => '2026-02-16'],
            ['description' => 'Osko Payment To 86400 Account 10386227 YOU - UBank Ref#937030751', 'amount' => -20000, 'post_date' => '2026-02-16'],
            ['description' => 'POS - #142815 - PAYPAL *APPLE.COM/BILL Sydney AU #2892', 'amount' => -1499, 'post_date' => '2026-02-17'],
            ['description' => 'Direct Debit Fair Go Finance - DT.53lz42 FGF 2472', 'amount' => -8500, 'post_date' => '2026-02-17'],
            ['description' => 'VISA -PAYPAL *TWITCHINTER 4029357733 US 019895 #2892', 'amount' => -2427, 'post_date' => '2026-02-17'],
            ['description' => 'Int Tran Fee - PAYPAL *TWITCHINTER US - 919895', 'amount' => -73, 'post_date' => '2026-02-17'],
            ['description' => 'Round Up transfer to 03774599: POS - #142815 - PAYPAL *APPLE.COM/BILL Sydney AU #2892', 'amount' => -1, 'post_date' => '2026-02-17'],
            ['description' => 'Round Up transfer to 03774599: VISA -PAYPAL *TWITCHINTER 4029357733 US 019895 #2892', 'amount' => -73, 'post_date' => '2026-02-17'],
            ['description' => 'Direct Debit NIB - 64699390', 'amount' => -9059, 'post_date' => '2026-02-19'],
            ['description' => 'Ext Tfr - NET#4789778169 to 554078 Sekisui House MAST WBC - 260 Queen Street', 'amount' => -77000, 'post_date' => '2026-02-20'],
            ['description' => 'VISA -Including Cash OutWOOLWORTHS/111 BOUNDARY SWESTEND AU 092430 #2892', 'amount' => -13196, 'post_date' => '2026-02-22'],
            ['description' => 'VISA -PAYPAL *THANGS 3D 4029357733 US 016662 #2892', 'amount' => -16464, 'post_date' => '2026-02-22'],
            ['description' => 'Int Tran Fee - PAYPAL *THANGS 3D US - 916662', 'amount' => -494, 'post_date' => '2026-02-22'],
            ['description' => 'POS - #489742 - PAYPAL *APPLE.COM/BILL Sydney AU #2892', 'amount' => -5999, 'post_date' => '2026-02-22'],
            ['description' => 'Round Up transfer to 03774599: VISA -Including Cash OutWOOLWORTHS/111 BOUNDARY SWESTEND AU 092430 #2892', 'amount' => -304, 'post_date' => '2026-02-22'],
            ['description' => 'Round Up transfer to 03774599: VISA -PAYPAL *THANGS 3D 4029357733 US 016662 #2892', 'amount' => -36, 'post_date' => '2026-02-22'],
            ['description' => 'Round Up transfer to 03774599: POS - #489742 - PAYPAL *APPLE.COM/BILL Sydney AU #2892', 'amount' => -1, 'post_date' => '2026-02-22'],
            ['description' => 'Direct Debit Spaceship - DT.54f9nu MLW77GP2', 'amount' => -20000, 'post_date' => '2026-02-23'],
            ['description' => 'Returned Item Credit', 'amount' => 20000, 'post_date' => '2026-02-24', 'transaction_date' => '2026-02-23'],
            ['description' => 'Direct Debit Fair Go Finance - DT.54iscj FGF 2472', 'amount' => -8500, 'post_date' => '2026-02-24'],
            ['description' => 'Direct Debit MCF - MCF Loa(N11850455)', 'amount' => -17390, 'post_date' => '2026-02-24'],
            ['description' => 'Returned Item Credit', 'amount' => 17390, 'post_date' => '2026-02-25', 'transaction_date' => '2026-02-24'],
            ['description' => 'Direct Debit GOLDEN INSURANCE - PLCY 082212484-018', 'amount' => -7017, 'post_date' => '2026-02-25'],
            ['description' => 'Returned Item Credit', 'amount' => 7017, 'post_date' => '2026-02-26', 'transaction_date' => '2026-02-25'],
            ['description' => 'Direct Credit WINABLE PAYROLL - WINABLE PAYROLL', 'amount' => 570660, 'post_date' => '2026-02-26'],
            ['description' => 'Transfer Optimus to CC to SAV 03914373 MOBILE#2445883560', 'amount' => -200000, 'post_date' => '2026-02-26'],
            ['description' => 'Ext Tfr - NET#4789778169 to 554078 Sekisui House MAST WBC - 260 Queen Street', 'amount' => -77000, 'post_date' => '2026-02-27'],
            ['description' => 'Direct Debit QBE Insurance - bcx:31264648', 'amount' => -6058, 'post_date' => '2026-02-27'],
            ['description' => 'Osko Payment To 86400 Account 10386227 YOU - UBank Ref#937598029', 'amount' => -40000, 'post_date' => '2026-02-27'],
            ['description' => 'POS - #030841 - PAYPAL *LUCENTGLOBE Sydney AU #2892', 'amount' => -2665, 'post_date' => '2026-02-28'],
            ['description' => 'Round Up transfer to 03774599: POS - #030841 - PAYPAL *LUCENTGLOBE Sydney AU #2892', 'amount' => -335, 'post_date' => '2026-02-28'],
            ['description' => 'SMS Alert Fee', 'amount' => -910, 'post_date' => '2026-02-28'],
        ];
    }
}
