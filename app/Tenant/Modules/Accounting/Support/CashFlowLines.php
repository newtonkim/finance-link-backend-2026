<?php

namespace App\Tenant\Modules\Accounting\Support;

/**
 * How the statement of cash flows is laid out, and which accounts feed each line.
 *
 * The statement uses the direct method (IAS 7.18(a)). Every posted journal entry
 * that moves cash is split by its other lines: what each of those lines brought in
 * or paid out is the cash flow of that account's category. Categories follow IAS 7
 * for a financial institution: loans to members and member savings are operating
 * activities (IAS 7.15, 7.24), as are interest received and paid (IAS 7.33).
 */
final class CashFlowLines
{
    public const SECTIONS = [
        'operating' => 'Cash flows from operating activities',
        'investing' => 'Cash flows from investing activities',
        'financing' => 'Cash flows from financing activities',
        'other' => 'Balances brought onto the system',
    ];

    /**
     * Rows in statement order: key => [section, category, direction, label, hint].
     * Direction 'in' shows only money received, 'out' only money paid, 'net' both.
     */
    public const ROWS = [
        'interest_received' => ['operating', 'interest_received', 'net', 'Interest and penalties received on loans', 'Loan interest and default interest collected in cash.'],
        'fees_received' => ['operating', 'fees_received', 'net', 'Fees and commissions received', 'Loan fees, account charges and other fees collected.'],
        'investment_income' => ['operating', 'investment_income', 'net', 'Investment income received', 'Interest and returns received on the SACCO\'s investments.'],
        'other_receipts' => ['operating', 'other_receipts', 'net', 'Other operating income received', 'Other income collected in cash.'],
        'interest_paid' => ['operating', 'interest_paid', 'net', 'Interest paid on savings and borrowings', 'Interest paid to members on savings and to lenders.'],
        'staff_paid' => ['operating', 'staff_paid', 'net', 'Payments to and on behalf of staff', 'Salaries, NSSF and other staff costs paid.'],
        'suppliers_paid' => ['operating', 'suppliers_paid', 'net', 'Administrative and other expenses paid', 'Rent, utilities, professional fees, suppliers and other running costs.'],
        'income_tax_paid' => ['operating', 'income_tax_paid', 'net', 'Income tax paid', 'Tax paid on the SACCO\'s surplus.'],
        'loans_disbursed' => ['operating', 'loans', 'out', 'Loans disbursed to members', 'Loan amounts paid out, before any fees deducted at disbursement.'],
        'loans_repaid' => ['operating', 'loans', 'in', 'Loan principal repaid by members', 'Principal collected in cash. Repayments made from savings move no cash and are not included.'],
        'savings_deposited' => ['operating', 'member_deposits', 'in', 'Savings deposited by members and groups', 'Cash paid into member and group savings accounts.'],
        'savings_withdrawn' => ['operating', 'member_deposits', 'out', 'Savings withdrawn by members and groups', 'Cash paid out of member and group savings accounts.'],
        'other_operating' => ['operating', 'other_operating', 'net', 'Other operating cash flows', 'Receivables, payables and accounts not yet classified for this report.'],
        'ppe_purchased' => ['investing', 'ppe', 'out', 'Purchase of property, equipment and software', 'Buildings, vehicles, computers, furniture and software bought.'],
        'ppe_sold' => ['investing', 'ppe', 'in', 'Proceeds from sale of property and equipment', 'Cash received from disposing of fixed assets.'],
        'investments_made' => ['investing', 'investments', 'out', 'Investments made', 'Government securities and other investments bought.'],
        'investments_realised' => ['investing', 'investments', 'in', 'Investments matured or sold', 'Cash received back from investments.'],
        'shares_subscribed' => ['financing', 'share_capital', 'in', 'Share capital subscribed by members', 'Cash received for new shares.'],
        'shares_refunded' => ['financing', 'share_capital', 'out', 'Share capital refunded to members', 'Cash paid out for shares withdrawn or refunded.'],
        'borrowings_received' => ['financing', 'borrowings', 'in', 'External borrowings received', 'Loans received from banks and other lenders.'],
        'borrowings_repaid' => ['financing', 'borrowings', 'out', 'External borrowings repaid', 'Principal repaid to banks and other lenders.'],
        'dividends_paid' => ['financing', 'dividends_paid', 'net', 'Dividends paid to members', 'Dividends and interest on shares paid out.'],
        'equity_other' => ['financing', 'equity_other', 'net', 'Other movements in members\' funds', 'Cash posted directly to reserves or retained surplus.'],
        'opening_balances' => ['other', 'opening_balances', 'net', 'Opening balances brought onto the system', 'Cash balances recorded when accounts were set up or migrated. These are not money received in the period.'],
    ];

    /** Template accounts identified by their exact code, before their subtype is looked at. */
    public const BY_GL_CODE = [
        '11500' => 'interest_received',  // Interest receivable
        '11600' => 'interest_received',  // Penalty receivable
        '11700' => 'fees_received',      // Charges receivable
        '11800' => 'other_operating',    // Prepayments & other receivables
        '11900' => 'investments',        // Government securities
        '21300' => 'interest_paid',      // Accrued interest on savings
        '21400' => 'dividends_paid',     // Dividends payable
        '21600' => 'suppliers_paid',     // Tax payable (PAYE, VAT, WHT)
        '33300' => 'dividends_paid',     // Dividends declared
        '33900' => 'opening_balances',   // Opening balance control
    ];

    /** Balance sheet accounts by account_subtype. */
    public const BY_SUBTYPE = [
        'Loan' => 'loans',
        'Member Savings' => 'member_deposits',
        'Member Deposit' => 'member_deposits',
        'Investment' => 'investments',
        'Fixed Asset' => 'ppe',
        'Intangible' => 'ppe',
        'Share Capital' => 'share_capital',
        'Borrowings' => 'borrowings',
        'Payable' => 'suppliers_paid',
        'Accrued Asset' => 'other_operating',
        'Contra Asset' => 'other_operating',
        'Regulatory Reserve' => 'equity_other',
        'General Reserve' => 'equity_other',
        'Retained Earnings' => 'equity_other',
    ];

    /** Income and expense accounts by the line the income statement maps them to. */
    public const BY_INCOME_STATEMENT_LINE = [
        'interest_income' => 'interest_received',
        'fee_income' => 'fees_received',
        'other_income' => 'other_receipts',
        'investment_income' => 'investment_income',
        'interest_expense' => 'interest_paid',
        'staff_costs' => 'staff_paid',
        'administration' => 'suppliers_paid',
        'other_expenses' => 'suppliers_paid',
        'depreciation' => 'suppliers_paid',
        'impairment' => 'other_operating',
        'income_tax' => 'income_tax_paid',
    ];

    /** Subtypes that mark an account as cash or a cash equivalent. */
    public const CASH_SUBTYPES = ['Cash', 'Bank'];

    /** The template's Cash & Cash Equivalents group: every account under it is cash. */
    public const CASH_GROUP_GL_CODE = '11100';
}
