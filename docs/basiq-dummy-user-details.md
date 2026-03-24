# Basiq Dummy User Details

Test credentials for the Basiq sandbox environment.

## Quick Reference (Credentials)

| loginId         | password      | name                | phone      | email                            |
|-----------------|---------------|---------------------|------------|----------------------------------|
| Wentworth-Smith | whislter      | Max Wentworth-Smith | 0419000000 | maxsmith@micr0soft.com           |
| Whistler        | ShowBox       | Whistler Smith      | 0405000000 | whistler@h0tmail.com             |
| Gilfoyle        | PiedPiper     | Gilfoyle Bertram    | 0405000000 | gilfoyle@mgail.com               |
| gavinBelson     | hooli2016     | Gavin Belson        | 0490000000 | gavinbelson@h0tmail.com          |
| jared           | django        | Jared Dunn          | 0405000000 | Jared.D@h0tmail.com              |
| richard         | tabsnotspaces | Richard Birtles     | 0482000000 | r.birtles@tetlerjones.c0m.au     |
| laurieBream     | business2024  | Laurie Bream        | 0490000000 | business@manlyaccountants.com.au |
| ashMann         | hooli2024     | Ash Mann            | 0497000000 | ashmann@gamil.com                |

## Account Numbers

| loginId         | Accounts                                                                                                    |
|-----------------|-------------------------------------------------------------------------------------------------------------|
| Wentworth-Smith | transaction: 45678945678901, credit card: 23456723456789, savings: 34567834567890, mortgage: 12345612345678 |
| Whistler        | transaction: 000001919644181                                                                                |
| Gilfoyle        | transaction: 000001919644171, credit card: 000001919644170, savings: 000001919644172                        |
| gavinBelson     | transaction: 000001000002, credit card: 000001004381, savings: 000001002935, loan: 000001002955             |
| jared           | transaction: 000001023480, credit card: 000001023483, term deposit: 000001023482                            |
| richard         | transaction: 000001077380, credit card: 000001077379, mortgage: 000001077381                                |
| laurieBream     | transaction: 062245684154861747642, credit card: 772245684154861747642                                      |
| ashMann         | transaction: 090001077738, credit card: 090001077779                                                        |

## Addresses

| loginId         | Address                                      |
|-----------------|----------------------------------------------|
| Wentworth-Smith | 13/91 Fisher Rd, Dee Why NSW 2099, Australia |
| Whistler        | 201 Sussex St, Sydney NSW 2000, Australia    |
| Gilfoyle        | Dee Why NSW 2099, Australia                  |
| gavinBelson     | YARDARINO WA 6525, Australia                 |
| jared           | Tuggerah NSW 2259, Australia                 |
| richard         | 51 Dabinett Rd, Ponde SA 5238, Australia     |
| laurieBream     | 21 Sydney Rd, Manly NSW 2095, Australia      |
| ashMann         | 201 Sussex St, Sydney NSW 2000, Australia    |

## Persona Details

### Wentworth-Smith

Joint account.

- **Income** (transaction account): 2 sources of salary — monthly stable salary & fortnightly stable salary
- **Liabilities**: mortgage (mortgage account) and car loan (payments in the transaction account)
- **Expenses**: predictable expenses (credit-card account)
- **Use cases**: Verify income, Review expenses, Affordability assessment, Assess liabilities, PFM, Access transaction data, Identify spending patterns,
  Personalised financial advice, Capture account details

### Whistler

- **Income** (transaction account): 1 source of salary, fortnightly salary
- **Liabilities**: BNPL (transaction account), no mortgage / personal loan
- **Expenses**: no daily expenses
- **Risk flags**: large amount of external transfer (debit to Jared)
- **Use cases**: Verify income, Assess liabilities, Access transaction data, Personalised financial advice, Capture account details

### Gilfoyle

- **Income** (transaction account): stopped fortnightly salary, unemployment benefits
- **Liabilities**: increase in BNPL (transaction account)
- **Expenses**: predictable expenses (credit-card account)
- **Risk flags**: late fee (credit-card account)
- **Use cases**: Verify income, Assess liabilities, Expense check, Affordability assessment, Identify spending patterns, PFM, Analyse creditworthiness, Access
  transaction data, Personalised financial advice, Capture account details

### gavinBelson

- **Income** (transaction account): 1 salary + 1 additional earning, increase monthly salary + extra income (tutoring weekly volatile)
- **Liabilities**: personal loan (loan account)
- **Expenses**: predictable expenses (credit-card account)
- **Bank**: HooliGov Bank (AU00004)
- **Use cases**: Verify income, Assess liabilities, Expense check, Affordability assessment, Identify spending patterns, PFM, Analyse creditworthiness, Access
  transaction data, Personalised financial advice, Capture account details

### jared

- **Income** (transaction account): weekly volatile income from uber + credit transfers from Whistler
- **Liabilities**: unshared mortgage account (payments in transaction account), car loan (payments in transaction account)
- **Expenses**: predictable expenses (credit-card account)
- **Assets**: term deposit
- **Use cases**: Verify income, Assess liabilities, Expense check, Affordability assessment, Identify spending patterns, PFM, Analyse creditworthiness, Access
  transaction data, Personalised financial advice, Capture account details

### richard

- **Income** (transaction account): high stable fortnightly income, 2 rental incomes
- **Liabilities**: 3 mortgages (1 shared mortgage account & 2 unshared mortgages), 2 car loans (payments in transaction account), 4 credit cards (1 shared & 3
  unshared)
- **Expenses**: predictable expenses (credit-card account)
- **Use cases**: Verify income, Assess liabilities, Expense check, Affordability assessment, Identify spending patterns, PFM, Analyse creditworthiness, Access
  transaction data, Personalised financial advice, Capture account details

### laurieBream

Happy path persona with business fields and business consumer consent.

- **Use cases**: Business Consumer Consent

### ashMann

- **Income** (transaction account): 1 salary monthly income, rental income
- **Liabilities**: unshared credit card transactions (payments in transaction account). BPAY-related CDR fields (`billerCode`, `billerName`, `crn`) disclosed
  under "Bankwest credit card" transactions
- **Expenses**: predictable expenses (credit-card account), rental expense
- **Risk flags**: gambling behaviours, cash withdrawal, crypto exchange
- **Use cases**: Verify income, Assess liabilities, Expense check, Affordability assessment, Identify spending patterns, PFM, Analyse creditworthiness, Access
  transaction data, Personalised financial advice, Capture account details
