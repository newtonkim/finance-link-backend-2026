# Savings Product: Compulsory Adjustable Monthly Fee Implementation Guide

This guide outlines the step-by-step plan for implementing a flexible, compulsory monthly fee for Savings Products that can be adjusted per member from the perspective of both UX/UI Design and Backend Architecture.

## Phase 1: UX/UI Design & User Flow

### 1. Savings Product Settings (Admin View)

- **Goal**: Allow admins to configure default monthly fees for a specific savings product.
- **UI Addition**: In the "Create/Edit Savings Product" form (`SavingsProductForm.vue`), add a new section titled **"Periodic Charges"** or **"Monthly Maintenance Fees"**.
- **Inputs**:
    - **Enable Monthly Fee**: A toggle switch to enable/disable.
    - **Fee Type**: A dropdown or segmented control (Radio buttons: `Percentage (%)` or `Fixed Amount`).
    - **Default Amount/Rate**: A numeric input for the base fee configured for this product.
    - **Deduction Day**: A dropdown (1st to 28th, or "End of Month") to specify when the system deducts the fee automatically.

### 2. Member Account Override (Admin/Manager View)

- **Goal**: Allow managers to adjust (override) the compulsory fee for specific members or accounts.
- **UI Addition**: When viewing a specific Member's Savings Account details (`MemberSavingsAccountView.vue`), introduce a **"Fee Adjustments"** or **"Custom Rules"** tab.
- **Inputs**:
    - **Override Product Default**: A toggle switch.
    - **Custom Fee Type**: Segmented control (`Percentage (%)` or `Fixed Amount`).
    - **Custom Amount/Rate**: Numeric input.
    - **Reason for Adjustment**: An optional text area for auditing (e.g., "VIP Member discount").

### 3. Frontend User Experience Principles

- **Clarity**: Use clear helper text below the Percentage input detailing what it calculates against (e.g., "Percentage calculated against the account's average monthly balance").
- **Visibility**: In the Savings Products list and the Member Account summary, add visual indicators (badges or tooltips) showing whether an account is using the "Default Fee" or a "Custom Fee".

---

## Phase 2: Database & Backend Architecture

### 1. Schema Updates (Database Migrations)

- **Savings Products Table**: Add columns to define the baseline rules.
    - `monthly_fee_enabled` (boolean)
    - `monthly_fee_type` (enum: 'percentage', 'amount')
    - `monthly_fee_amount` (decimal)
    - `monthly_fee_deduction_day` (integer)
- **Savings Accounts Table (Member specific instance of a product)**: Add columns to handle overrides.
    - `custom_monthly_fee_enabled` (boolean)
    - `custom_monthly_fee_type` (enum: 'percentage', 'amount')
    - `custom_monthly_fee_amount` (decimal)

### 2. API Modifications

- **Savings Products API**: Update the CRUD operations (Resource, Form Requests, Controller) to accept and return the new base fee fields.
- **Savings Accounts API**: Update endpoints to allow submitting the custom override values for a specific member's account.

---

## Phase 3: Core Business Logic & Automation

### 1. The Fee Resolution Logic

Because the fee must be adjustable per member, the backend needs a specific helper service (e.g., `FeeCalculatorService`) to determine which fee to apply.

- **Rule Engine**:
    1. Check the `SavingsAccount`. Does it have `custom_monthly_fee_enabled = true`?
    2. If YES: Use the custom type and custom amount on the account.
    3. If NO: Fall back to the parent `SavingsProduct` baseline configuration.

### 2. The Calculation Logic

- If the resolved type is `amount`: The deduction is simply the flat numeric value.
- If the resolved type is `percentage`: The system must define the basis of the percentage. Usually, in banking, this is either the _Current Balance at the time of deduction_ or the _Average Daily Balance of the month_. (This needs to be standardized in the service).

### 3. Automated Processing (Cron Jobs / Background Tasks)

- **Scheduled Command**: Create a Laravel console command (e.g., `php artisan savings:process-monthly-fees`) scheduled to run daily at midnight via the Task Scheduler.
- **Execution Flow**:
    1. Query all active Savings Accounts where the associated product has monthly fees enabled, or the account has a custom fee override.
    2. Filter for accounts where today matches the `monthly_fee_deduction_day`.
    3. Loop through the accounts (preferably in chunks to save memory).
    4. Call the `FeeCalculatorService` to determine the exact deduction amount.
    5. Execute a system withdrawal transaction on the account (ensure the system double-entry accounting journals are balanced: Debit Member Account, Credit Sacco Fee Income Account).
    6. Ensure the transaction is tagged with a specific transaction type or description (e.g., "Monthly Account Maintenance Fee").

## Summary of the Developer Workflow

1.  **Database**: Write migrations to add the fee fields to both `savings_products` and `savings_accounts`.
2.  **API Backend**: Update the controllers and request validators to handle the new fields.
3.  **Frontend UX**: Build the UI inputs on the Product Creation form, and build the overrides UI on the Member Account management page.
4.  **Logic & Automation**: Create the Calculator Service and the automated scheduled job to execute the deductions accurately at the end of every cycle.
